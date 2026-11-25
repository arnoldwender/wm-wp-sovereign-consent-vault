<?php
/**
 * Standalone Automated Test Runner & Unit Suite for wm-sovereign-consent-vault.
 *
 * Provides in-memory test harness verifying the linear HMAC-SHA256 consent chain,
 * tamper detection, node deletion prevention, and §25 TDDDG iframe blocker.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Setup mock WordPress constants
$mock_wp_dir = sys_get_temp_dir() . '/wm_consent_vault_wp/';
@mkdir($mock_wp_dir . 'wp-admin/includes', 0777, true);
// dbDelta is counted: the record route used to run it on every anonymous request (P0-8).
file_put_contents($mock_wp_dir . 'wp-admin/includes/upgrade.php', "<?php\n// Mock upgrade.php\nif (!function_exists('dbDelta')) {\n    function dbDelta(\$sql): void { \$GLOBALS['wm_dbdelta_calls'] = (\$GLOBALS['wm_dbdelta_calls'] ?? 0) + 1; \$GLOBALS['wm_dbdelta_sqls'][] = \$sql; }\n}\n");
$GLOBALS['wm_dbdelta_calls'] = 0;
$GLOBALS['wm_dbdelta_sqls'] = [];

if (!defined('ABSPATH')) {
    define('ABSPATH', $mock_wp_dir);
}
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'mock-auth-key-consent-vault-secret-2026');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Global Mocks & In-Memory Store
global $mock_options, $mock_filters, $mock_actions, $mock_db_tables, $wpdb;
$mock_options   = [];
$mock_filters   = [];
$mock_actions   = [];
$mock_db_tables = [
    'wp_wm_consent_records'      => [],
    'wp_wm_consent_merkle_nodes' => [],
    // WordPress writes options through $wpdb, so the fake options table moves insert_id the way the
    // real one does; that is what caught the node number being read after the tip was stored.
    'wp_options'                 => [],
];

class Mock_Consent_WPDB {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    public array $table_auto_increment = [];

    /** GET_LOCK answer; false makes the append behave like a busy chain. */
    public bool $lock_granted = true;
    /** Number of upcoming GET_LOCK calls returning '0' before falling back to $lock_granted. */
    public int $lock_fail_count = 0;
    /** Observed timeout arguments passed to SELECT GET_LOCK(name, timeout). */
    public array $lock_timeouts = [];
    /** Next insert into this table returns false, as a refused write does. */
    public ?string $fail_insert_table = null;
    /** Ordered record of locks, inserts and deletes, so a test can see what happened under the lock. */
    public array $journal = [];

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    public int $insert_calls = 0;
    public bool $suppress = false;

    public function suppress_errors(bool $suppress = true): bool {
        $previous = $this->suppress;
        $this->suppress = $suppress;
        return $previous;
    }

    public function insert(string $table, array $data, array $format = []): int|false {
        global $mock_db_tables;
        $this->insert_calls++;
        $this->journal[] = 'insert:' . $table;
        if (null !== $this->fail_insert_table && $this->fail_insert_table === $table) {
            $this->fail_insert_table = null;
            $this->insert_id = 0;
            return false;
        }
        // UNIQUE KEY consent_uuid_idx: a second record with the same uuid fails, as MySQL would.
        if ($table === 'wp_wm_consent_records' && isset($data['consent_uuid'])) {
            foreach ($mock_db_tables[$table] ?? [] as $existing) {
                if (($existing->consent_uuid ?? null) === $data['consent_uuid']) {
                    $this->insert_id = 0;
                    return false;
                }
            }
        }
        if (!isset($this->table_auto_increment[$table])) {
            $this->table_auto_increment[$table] = 0;
        }
        $this->table_auto_increment[$table]++;
        $id = $this->table_auto_increment[$table];
        $this->insert_id = $id;
        $id_col = ($table === 'wp_wm_consent_merkle_nodes') ? 'node_index' : 'id';
        $row = array_merge([$id_col => $id], $data);
        $mock_db_tables[$table][$id] = (object) $row;
        return 1;
    }

    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []): int {
        global $mock_db_tables;
        $updated = 0;
        if (!isset($mock_db_tables[$table])) {
            return 0;
        }
        foreach ($mock_db_tables[$table] as $id => $row) {
            $match = true;
            foreach ($where as $w_key => $w_val) {
                if (!isset($row->$w_key) || (string)$row->$w_key !== (string)$w_val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                foreach ($data as $d_key => $d_val) {
                    $row->$d_key = $d_val;
                }
                $mock_db_tables[$table][$id] = $row;
                $updated++;
            }
        }
        return $updated;
    }

    public function delete(string $table, array $where, array $where_format = []): int {
        global $mock_db_tables;
        $deleted = 0;
        foreach ($mock_db_tables[$table] ?? [] as $id => $row) {
            $match = true;
            foreach ($where as $w_key => $w_val) {
                if (!isset($row->$w_key) || (string)$row->$w_key !== (string)$w_val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                unset($mock_db_tables[$table][$id]);
                $this->journal[] = 'delete:' . $table;
                $deleted++;
            }
        }
        return $deleted;
    }

    public array $queries = [];

    public function query(string $query): int {
        global $mock_db_tables;
        $this->queries[] = $query;
        // Transaction statements go to the journal too, so a test can order them against the inserts.
        if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK)\b/i', $query, $tx)) {
            $this->journal[] = strtoupper($tx[1]);
        }
        if (preg_match('/TRUNCATE\s+TABLE\s+([^\s]+)/i', $query, $m)) {
            $mock_db_tables[$m[1]] = [];
            $this->table_auto_increment[$m[1]] = 0;
            return 1;
        }
        return 1;
    }

    public function prepare(string $query, ...$args): string {
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        // %i is an identifier: core writes it without quotes, and the uninstaller uses it for wp_options.
        return (string) preg_replace_callback('/%%|%([sdfFi])/', function (array $m) use (&$i, $args): string {
            if ('%%' === $m[0]) {
                return '%';
            }
            $arg = $args[$i++] ?? '';
            if ('i' === $m[1]) {
                return (string) $arg;
            }
            return is_numeric($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
        }, $query);
    }

    public function get_row(string $query, string $output = 'OBJECT') {
        global $mock_db_tables;
        // AdminDashboard::record_stats(): one aggregate over the records table, computed here the way MySQL would.
        if (preg_match('/^\s*SELECT\s+COUNT\(\*\)\s+AS\s+(\w+),(.*)\s+FROM\s+(\S+)\s*$/is', $query, $m)) {
            // Each key is the alias the query asks for, so renaming one in the code changes what the caller reads.
            $rows = $mock_db_tables[$m[3]] ?? [];
            $agg  = [$m[1] => count($rows)];
            preg_match_all('/(SUM\((.*?)\)|MAX\((\w+)\))\s+AS\s+(\w+)/i', $m[2], $columns, PREG_SET_ORDER);
            foreach ($columns as $column) {
                $expression = $column[2];
                $alias      = $column[4];
                $agg[$alias] = null;
                foreach ($rows as $row) {
                    if ('' !== $column[3]) {
                        $agg[$alias] = max((string) $agg[$alias], (string) ($row->{$column[3]} ?? ''));
                    } elseif (preg_match("/FIND_IN_SET\('([a-z]+)',\s*categories\)/i", $expression, $f)) {
                        $agg[$alias] += in_array($f[1], explode(',', (string) ($row->categories ?? '')), true) ? 1 : 0;
                    } elseif (preg_match("/^\s*(\w+)\s*=\s*'?([^']*)'?\s*$/", $expression, $c)) {
                        $agg[$alias] += (string) ($row->{$c[1]} ?? '') === $c[2] ? 1 : 0;
                    }
                }
            }
            return $output === ARRAY_A ? $agg : (object) $agg;
        }
        if (preg_match('/SELECT\s+.*FROM\s+([^\s]+)\s+ORDER\s+BY\s+node_index\s+DESC\s+LIMIT\s+1/i', $query, $m)) {
            $table = $m[1];
            $rows = $mock_db_tables[$table] ?? [];
            if (!empty($rows)) {
                $last = end($rows);
                return $output === ARRAY_A ? (array)$last : $last;
            }
            return null;
        }
        if (preg_match('/SELECT\s+.*FROM\s+([^\s]+)\s+WHERE\s+(?:id|node_index)\s*=\s*(\d+)/i', $query, $m)) {
            $table = $m[1];
            $id = (int)$m[2];
            $row = $mock_db_tables[$table][$id] ?? null;
            if ($row && $output === ARRAY_A) {
                return (array)$row;
            }
            return $row;
        }
        return null;
    }

    public function get_results(string $query, string $output = 'OBJECT'): array {
        global $mock_db_tables;
        if (preg_match('/SELECT\s+.*FROM\s+([^\s]+)\s+ORDER\s+BY\s+node_index\s+ASC/i', $query, $m)) {
            $table = $m[1];
            $rows = array_values($mock_db_tables[$table] ?? []);
            if ($output === ARRAY_A) {
                return array_map(fn($r) => (array)$r, $rows);
            }
            return $rows;
        }
        return [];
    }

    public function get_var(string $query) {
        global $mock_db_tables;
        // MySQL named locks: '1' granted, '0' timeout. The engine writes a node only while it holds one.
        if (preg_match('/^\s*SELECT\s+GET_LOCK\(\s*[^,]+,\s*(\d+)\s*\)/i', $query, $m)) {
            $this->journal[] = 'GET_LOCK';
            $this->lock_timeouts[] = (int) $m[1];
            if ($this->lock_fail_count > 0) {
                $this->lock_fail_count--;
                return '0';
            }
            return $this->lock_granted ? '1' : '0';
        }
        if (preg_match('/^\s*SELECT\s+RELEASE_LOCK\(/i', $query)) {
            $this->journal[] = 'RELEASE_LOCK';
            return '1';
        }
        if (preg_match('/COUNT\(DISTINCT\s+record_id\)\s+FROM\s+(\S+)\s+WHERE\s+record_id\s*>\s*0/i', $query, $m)) {
            $ids = [];
            foreach ($mock_db_tables[$m[1]] ?? [] as $row) {
                if ((int) ($row->record_id ?? 0) > 0) {
                    $ids[(int) $row->record_id] = true;
                }
            }
            return (string) count($ids);
        }
        if (preg_match("/SHOW\s+TABLES\s+LIKE\s+'([^']+)'/i", $query, $m)) {
            return array_key_exists($m[1], $mock_db_tables) ? $m[1] : null;
        }
        if (preg_match("/SELECT\s+id\s+FROM\s+(\S+)\s+WHERE\s+consent_uuid\s*=\s*'([^']*)'/i", $query, $m)) {
            foreach ($mock_db_tables[$m[1]] ?? [] as $row) {
                if (($row->consent_uuid ?? null) === stripslashes($m[2])) {
                    return (string) $row->id;
                }
            }
            return null;
        }
        if (preg_match('/COUNT\(\*\)\s+FROM\s+([^\s]+)/i', $query, $m)) {
            $table = $m[1];
            return count($mock_db_tables[$table] ?? []);
        }
        return null;
    }
}

$wpdb = new Mock_Consent_WPDB();

// Mock WP Core Functions
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool {
        global $wpdb;
        if ($wpdb instanceof Mock_Consent_WPDB) {
            $wpdb->journal[] = 'cache_delete:' . $group . ':' . $key;
        }
        return true;
    }
}
if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugins): void {
        $GLOBALS['mock_deactivated_plugins'][] = $plugins;
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string {
        return basename(dirname($file)) . '/' . basename($file);
    }
}
if (!defined('WM_CONSENT_VAULT_FILE')) {
    define('WM_CONSENT_VAULT_FILE', dirname(__DIR__) . '/wm-sovereign-consent-vault.php');
}
if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $option, $value, $autoload = null): bool {
        global $mock_options, $wpdb;
        $existed = array_key_exists($option, $mock_options);
        $mock_options[$option] = $value;
        // A new option is an INSERT into wp_options, which moves $wpdb->insert_id — the reason the
        // record route used to answer with an option row id where the chain node number belongs.
        if (!$existed && $wpdb instanceof Mock_Consent_WPDB) {
            $wpdb->insert('wp_options', ['option_name' => $option, 'option_value' => 'stored']);
        }
        return true;
    }
}
if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean($value): bool {
        if (is_string($value)) {
            $value = strtolower($value);
            if (in_array($value, ['false', '0'], true)) {
                return false;
            }
        }
        return (bool) $value;
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = []): bool {
        $GLOBALS['rest_routes'][$namespace . $route] = $args;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        global $mock_options;
        unset($mock_options[$option]);
        return true;
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return 'mock-salt-consent-vault-key-2026';
    }
}
if (!function_exists('current_time')) {
    function current_time(string $type, $gmt = 0) {
        return $type === 'mysql' ? date('Y-m-d H:i:s') : time();
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return $text;
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return (bool) ($GLOBALS['mock_is_admin'] ?? false);
    }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool {
        return false;
    }
}
if (!function_exists('dbDelta')) {
    function dbDelta($sql): void { $GLOBALS['wm_dbdelta_calls'] = ($GLOBALS['wm_dbdelta_calls'] ?? 0) + 1; $GLOBALS['wm_dbdelta_sqls'][] = $sql; }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1): bool { return (bool) ($GLOBALS['mock_nonce_valid'] ?? false); }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1): string { return 'test-nonce'; }
}
if (!function_exists('get_transient')) {
    function get_transient(string $key) { return $GLOBALS['mock_transients'][$key] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl = 0): bool { $GLOBALS['mock_transients'][$key] = $value; return true; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code; public string $message; public $data;
        public function __construct(string $code = '', string $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params = []; private array $headers = [];
        public function __construct(string $method = 'GET', string $route = '') {}
        public function set_param(string $key, $value): void { $this->params[$key] = $value; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
        public function set_header(string $key, string $value): void { $this->headers[strtolower($key)] = $value; }
        public function get_header(string $key): ?string { return $this->headers[strtolower($key)] ?? null; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data; public int $status; public array $headers = [];
        public function __construct($data = null, int $status = 200, array $headers = []) {
            $this->data = $data;
            $this->status = $status;
            foreach ($headers as $k => $v) {
                $this->header((string) $k, (string) $v);
            }
        }
        public function header(string $key, string $value, bool $replace = true): void {
            if ($replace || !isset($this->headers[$key])) {
                $this->headers[$key] = $value;
            }
        }
        public function get_headers(): array { return $this->headers; }
        public function get_status(): int { return $this->status; }
        public function get_data() { return $this->data; }
    }
}
if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server { public const READABLE = 'GET'; public const CREATABLE = 'POST'; }
}

// --- Mocks for the categories, settings, services, blocker and admin handlers (2026-09-17) ---
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('WM_CONSENT_VAULT_URL')) {
    define('WM_CONSENT_VAULT_URL', 'https://example.test/wp-content/plugins/wm-sovereign-consent-vault/');
}
if (!defined('WM_CONSENT_VAULT_VERSION')) {
    define('WM_CONSENT_VAULT_VERSION', '1.2.2');
}
/** Thrown by the redirect and die mocks, so a handler under test stops where WordPress would exit. */
class Mock_Halt extends \RuntimeException {
    public string $kind; public string $target;
    public function __construct(string $kind, string $target) { parent::__construct($kind . ' ' . $target); $this->kind = $kind; $this->target = $target; }
}
$GLOBALS['mock_filters_applied'] = [];
$GLOBALS['mock_filter_callbacks'] = [];
$GLOBALS['mock_actions_fired'] = [];
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args) {
        $GLOBALS['mock_filters_applied'][] = $hook;
        foreach ($GLOBALS['mock_filter_callbacks'][$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }
}
if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args): void { $GLOBALS['mock_actions_fired'][] = [$hook, $args]; }
}
if (!function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $accepted = 1): bool { $GLOBALS['mock_hooks'][$hook][] = [$callback, $priority, $accepted]; return true; }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted = 1): bool { $GLOBALS['mock_hooks'][$hook][] = [$callback, $priority, $accepted]; return true; }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, $callback): void { $GLOBALS['mock_shortcodes'][$tag] = $callback; }
}
if (!function_exists('did_action')) {
    function did_action(string $hook): int { return (int) ($GLOBALS['mock_did_action'][$hook] ?? 0); }
}
if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron(): bool { return false; }
}
if (!function_exists('is_feed')) {
    function is_feed(): bool { return (bool) ($GLOBALS['mock_is_feed'] ?? false); }
}
if (!function_exists('is_robots')) {
    function is_robots(): bool { return false; }
}
if (!function_exists('is_trackback')) {
    function is_trackback(): bool { return false; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) { return is_string($str) ? trim(strip_tags($str)) : ''; }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url, $protocols = null) {
        $url = trim((string) $url);
        return 1 === preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : '';
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-'); }
}
if (!function_exists('get_privacy_policy_url')) {
    function get_privacy_policy_url(): string { return (string) ($GLOBALS['mock_privacy_url'] ?? ''); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
}
if (!function_exists('is_ssl')) {
    function is_ssl(): bool { return true; }
}
if (!function_exists('nocache_headers')) {
    function nocache_headers(): void { $GLOBALS['mock_nocache_calls'] = ($GLOBALS['mock_nocache_calls'] ?? 0) + 1; }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string { return 1 === $number ? $single : $plural; }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, bool $display = true) { $out = (string) $checked === (string) $current ? " checked='checked'" : ''; if ($display) { echo $out; } return $out; }
}
if (!function_exists('selected')) {
    function selected($selected, $current = true, bool $display = true) { $out = (string) $selected === (string) $current ? " selected='selected'" : ''; if ($display) { echo $out; } return $out; }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce'): string { $f = '<input type="hidden" name="' . $name . '" value="test-nonce" />'; echo $f; return $f; }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, ...$args): bool { return (bool) ($GLOBALS['mock_can'] ?? false); }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $query_arg = '_wpnonce') {
        $GLOBALS['mock_referer_checked'][] = $action;
        if (empty($GLOBALS['mock_nonce_valid'])) { throw new Mock_Halt('nonce', (string) $action); }
        return 1;
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []): void { throw new Mock_Halt('die', (string) $message); }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool { throw new Mock_Halt('redirect', $location); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 1; }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool { unset($GLOBALS['mock_transients'][$key]); return true; }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(...$args): void { $GLOBALS['mock_enqueued']['style'][] = $args; }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(...$args): void { $GLOBALS['mock_enqueued']['script'][] = $args; }
}
if (!function_exists('register_setting')) {
    function register_setting(string $option_group, string $option_name, array $args = []): void {
        $GLOBALS['mock_registered_settings'][$option_name] = ['group' => $option_group] + $args;
    }
}
if (!function_exists('register_block_bindings_source')) {
    function register_block_bindings_source(string $source_name, array $source_properties): bool {
        $GLOBALS['mock_block_bindings'][$source_name] = $source_properties;
        return true;
    }
}
if (!class_exists('GenerateBlocks_Register_Dynamic_Tag')) {
    class GenerateBlocks_Register_Dynamic_Tag {
        public function __construct(array $args) {
            $GLOBALS['mock_gb_dynamic_tags'][(string) ($args['tag'] ?? '')] = $args;
        }
    }
}
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, $deprecated = false, string $plugin_rel_path = ''): bool { return true; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string { return 'wm-sovereign-consent-vault/' . basename($file); }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string { return rtrim(dirname($file), '/') . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/'; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void {
        $GLOBALS['mock_activation_hook'] = $callback;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool { return (bool) ($GLOBALS['mock_is_multisite'] ?? false); }
}
if (!function_exists('get_sites')) {
    function get_sites(array $args = []): array { return (array) ($GLOBALS['mock_sites'] ?? [1]); }
}
if (!function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network(string $plugin): bool {
        return (bool) ($GLOBALS['mock_network_active'][$plugin] ?? false);
    }
}
$GLOBALS['mock_blog_stack'] = [];
$GLOBALS['mock_current_blog_id'] = 1;
$GLOBALS['mock_blog_options'] = [];
$GLOBALS['mock_blog_switches'] = [];
if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $new_blog_id): bool {
        global $wpdb, $mock_options;
        $prev_blog = (int) $GLOBALS['mock_current_blog_id'];
        $GLOBALS['mock_blog_options'][$prev_blog] = $mock_options;
        $GLOBALS['mock_blog_stack'][] = [$prev_blog, $wpdb->prefix];
        $GLOBALS['mock_current_blog_id'] = $new_blog_id;
        $wpdb->prefix = 1 === $new_blog_id ? 'wp_' : 'wp_' . $new_blog_id . '_';
        $mock_options = $GLOBALS['mock_blog_options'][$new_blog_id] ?? [];
        $GLOBALS['mock_blog_switches'][] = ['switch', $new_blog_id];
        return true;
    }
}
if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): bool {
        global $wpdb, $mock_options;
        $curr_blog = (int) $GLOBALS['mock_current_blog_id'];
        $GLOBALS['mock_blog_options'][$curr_blog] = $mock_options;
        $prev = array_pop($GLOBALS['mock_blog_stack']);
        if (is_array($prev)) {
            $GLOBALS['mock_current_blog_id'] = $prev[0];
            $wpdb->prefix = $prev[1];
            $mock_options = $GLOBALS['mock_blog_options'][$prev[0]] ?? [];
        }
        $GLOBALS['mock_blog_switches'][] = ['restore', $GLOBALS['mock_current_blog_id']];
        return true;
    }
}
if (!class_exists('WP_Site')) {
    class WP_Site {
        public int $blog_id;
        public function __construct(int $blog_id = 1) { $this->blog_id = $blog_id; }
    }
}

// Load Plugin Classes
require_once dirname(__DIR__) . '/includes/Database/Schema.php';
require_once dirname(__DIR__) . '/includes/Core/Categories.php';
require_once dirname(__DIR__) . '/includes/Core/Settings.php';
require_once dirname(__DIR__) . '/includes/Core/ConsentCookie.php';
require_once dirname(__DIR__) . '/includes/Services/ServiceRegistry.php';
require_once dirname(__DIR__) . '/includes/Frontend/ConsentBanner.php';
require_once dirname(__DIR__) . '/includes/Frontend/EcosystemIntegration.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/Crypto/MerkleTreeEngine.php';
require_once dirname(__DIR__) . '/includes/Blocker/ScriptIframeBlocker.php';
require_once dirname(__DIR__) . '/includes/Blocker/OutputBlocker.php';
require_once dirname(__DIR__) . '/includes/Admin/SettingsScreen.php';

use WMCyber\Consent\Vault\Crypto\MerkleTreeEngine;
use WMCyber\Consent\Vault\Database\Schema;
use WMCyber\Consent\Vault\Blocker\ScriptIframeBlocker;

$passed_count = 0;
$failed_count = 0;

function assert_test(string $name, bool $condition, string $failure_details = ''): void {
    global $passed_count, $failed_count;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed_count++;
    } else {
        echo "[FAIL] {$name}: {$failure_details}\n";
        $failed_count++;
    }
}

echo "============================================================\n";
echo " WM SOVEREIGN CONSENT VAULT — COMPREHENSIVE TEST SUITE\n";
echo "============================================================\n\n";

$engine = new MerkleTreeEngine();

// ------------------------------------------------------------
// Test 1: Key Source Resolution
// ------------------------------------------------------------
$key_src = MerkleTreeEngine::key_source();
assert_test(
    'Key Source resolves to wp-config when AUTH_KEY is defined',
    $key_src === 'wp-config',
    "Expected 'wp-config', got '{$key_src}'"
);

// ------------------------------------------------------------
// Test 2: Genesis Hash & Append First Record
// ------------------------------------------------------------
$res1 = $engine->append_record(
    'uuid-consent-001',
    '127.0.0.1',
    true,
    false,
    false,
    'Mozilla/5.0'
);

$root1 = $res1['merkle_root'] ?? '';
$tip_opt = get_option(MerkleTreeEngine::TIP_OPTION);
$tip1 = is_array($tip_opt) ? ($tip_opt['hash'] ?? '') : '';

assert_test(
    'Genesis record binds to 64-zero parent hash and updates external tip option',
    !empty($root1) && strlen($root1) === 64 && $tip1 === $root1,
    "Root: {$root1}, Tip: {$tip1}"
);

// ------------------------------------------------------------
// Test 3: Append Second & Third Records and Verify Chain Integrity
// ------------------------------------------------------------
$res2 = $engine->append_record(
    'uuid-consent-002',
    '192.168.1.100',
    true,
    true,
    false,
    'Mozilla/5.0 (iPhone)'
);

$res3 = $engine->append_record(
    'uuid-consent-003',
    '10.0.0.50',
    true,
    true,
    true,
    'Mozilla/5.0 (Macintosh)'
);

$audit_clean = $engine->verify_chain_integrity();

assert_test(
    'Linear HMAC chain verifies valid=true across 3 linked consent nodes',
    ($audit_clean['valid'] ?? false) === true && ($audit_clean['total_nodes'] ?? 0) === 3,
    "Valid: " . var_export($audit_clean['valid'] ?? false, true) . ", Nodes: " . ($audit_clean['total_nodes'] ?? 0)
);

// ------------------------------------------------------------
// Test 4: Tamper Detection (Marketing Consent Flip Attack)
// ------------------------------------------------------------
// Attempt to flip record 1 marketing from 0 to 1 without re-signing
$mock_db_tables['wp_wm_consent_records'][1]->marketing = 1;
$audit_tampered = $engine->verify_chain_integrity();

assert_test(
    'Tamper Detection catches unauthorized marketing flag mutation',
    ($audit_tampered['valid'] ?? true) === false && ($audit_tampered['error'] ?? '') === 'CONSENT_RECORD_ALTERED',
    "Expected CONSENT_RECORD_ALTERED, got: " . ($audit_tampered['error'] ?? 'none')
);

// Restore record 1
$mock_db_tables['wp_wm_consent_records'][1]->marketing = 0;
$audit_restored = $engine->verify_chain_integrity();
assert_test(
    'Chain validity restores to valid=true after reverting malicious mutation',
    ($audit_restored['valid'] ?? false) === true,
    "Valid: " . var_export($audit_restored['valid'] ?? false, true)
);

// ------------------------------------------------------------
// Test 5: Tip Truncation Detection (Tail Deletion Attack)
// ------------------------------------------------------------
// Malicious actor deletes newest node (node 3)
unset($mock_db_tables['wp_wm_consent_merkle_nodes'][3]);
unset($mock_db_tables['wp_wm_consent_records'][3]);

$audit_truncated = $engine->verify_chain_integrity();

assert_test(
    'Chain Integrity detects deleted tail node via external tip comparison',
    ($audit_truncated['valid'] ?? true) === false && ($audit_truncated['error'] ?? '') === 'CHAIN_TRUNCATED',
    "Expected CHAIN_TRUNCATED, got: " . ($audit_truncated['error'] ?? 'none')
);

// ------------------------------------------------------------
// Test 6: § 25 TDDDG 2-Click Iframe Blocker
// ------------------------------------------------------------
$blocker = new ScriptIframeBlocker();
$raw_html = '<p>Sehen Sie unser Video:</p><iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" frameborder="0"></iframe><p>Ende.</p>';
$blocked_html = $blocker->filter_content_iframes($raw_html);

assert_test(
    'ScriptIframeBlocker converts unconsented YouTube iframe to accessible 2-click lock widget',
    !str_contains($blocked_html, '<iframe') && str_contains($blocked_html, 'wm-2click-wrapper') && str_contains($blocked_html, 'Externer Inhalt blockiert (YouTube)'),
    "HTML Output: " . substr($blocked_html, 0, 100)
);

// ------------------------------------------------------------
// Test 7: The public record route is gated and cheap (P0-8, fleet audit 2026-09-13 §6.2 #20)
// ------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/API/RestController.php';
use WMCyber\Consent\Vault\API\RestController;

$dbdelta_before = (int) $GLOBALS['wm_dbdelta_calls'];
$engine->append_record('uuid-consent-004', '203.0.113.4', true, false, false, 'UA/2');
$engine->append_record('uuid-consent-005', '203.0.113.5', true, false, false, 'UA/2');
assert_test(
    'append_record no longer runs dbDelta on every request (activation and the version check own the schema)',
    (int) $GLOBALS['wm_dbdelta_calls'] === $dbdelta_before,
    'dbDelta calls during two appends: ' . ((int) $GLOBALS['wm_dbdelta_calls'] - $dbdelta_before)
);

$controller = new RestController();
$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_nonce_valid'] = false;
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';

$req_no_nonce = new WP_REST_Request('POST', '/wm-consent/v1/record');
$perm_no_nonce = method_exists($controller, 'check_record_permission') ? $controller->check_record_permission($req_no_nonce) : null;
assert_test(
    '/record without a valid REST nonce is refused (403)',
    $perm_no_nonce instanceof WP_Error && 403 === (int) ($perm_no_nonce->data['status'] ?? 0),
    'permission returned ' . var_export($perm_no_nonce, true)
);

$GLOBALS['mock_nonce_valid'] = true;
$req_nonce = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_nonce->set_header('X-WP-Nonce', 'test-nonce');
$accepted = 0;
$limited  = null;
for ($i = 0; $i < 12; $i++) {
    $verdict = method_exists($controller, 'check_record_permission') ? $controller->check_record_permission($req_nonce) : null;
    if ($verdict === true) {
        $accepted++;
    } elseif ($verdict instanceof WP_Error) {
        $limited = $verdict;
        break;
    }
}
assert_test(
    '/record accepts 10 requests per minute per client and answers 429 on the 11th',
    $accepted === 10 && $limited instanceof WP_Error && 429 === (int) ($limited->data['status'] ?? 0),
    sprintf('accepted=%d limited=%s', $accepted, $limited instanceof WP_Error ? (string) ($limited->data['status'] ?? '?') : 'none')
);

$nodes_before = count($mock_db_tables['wp_wm_consent_merkle_nodes']);
$insert_calls_before = $wpdb->insert_calls;
$req_dup = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_dup->set_param('uuid', 'uuid-consent-004');
$req_dup->set_param('essential', true);
$res_dup = $controller->handle_record_consent($req_dup);
assert_test(
    'A duplicate consent uuid answers 409 and leaves no orphan chain node behind',
    409 === $res_dup->get_status() && count($mock_db_tables['wp_wm_consent_merkle_nodes']) === $nodes_before,
    sprintf('status=%d nodes=%d (before %d)', $res_dup->get_status(), count($mock_db_tables['wp_wm_consent_merkle_nodes']), $nodes_before)
);
// The duplicate is answered before the INSERT: MySQL's "Duplicate entry" printed by $wpdb under
// WP_DEBUG_DISPLAY went out before the REST headers and turned the 409 into a 200 (a local WordPress install, 2026-09-14).
assert_test(
    'A duplicate uuid is refused before any INSERT is attempted',
    $wpdb->insert_calls === $insert_calls_before,
    'insert calls during the duplicate request: ' . ($wpdb->insert_calls - $insert_calls_before)
);

$req_bad_uuid = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_bad_uuid->set_param('uuid', '<script>alert(1)</script>');
$res_bad_uuid = $controller->handle_record_consent($req_bad_uuid);
assert_test(
    'A uuid outside [A-Za-z0-9_-]{8,64} answers 400',
    400 === $res_bad_uuid->get_status(),
    'status=' . $res_bad_uuid->get_status()
);

// The banner script moved from an inline <script> in ConsentBanner.php to assets/js/wm-consent-vault.js on 2026-09-17;
// the source checks read both, so they still pin the behaviour wherever it lives.
$banner_js_path = dirname(__DIR__) . '/assets/js/wm-consent-vault.js';
$banner_source  = (string) file_get_contents(dirname(__DIR__) . '/includes/Frontend/ConsentBanner.php') . (string) @file_get_contents($banner_js_path);
assert_test(
    'The banner script sends the REST nonce it fetched from the uncached /nonce route',
    str_contains($banner_source, 'X-WP-Nonce') && str_contains($banner_source, 'wm-consent/v1/nonce'),
    'banner does not send X-WP-Nonce from the nonce route'
);

// ------------------------------------------------------------
// Test 8: Emptying the whole chain is tampering, not an empty vault (2026-09-14)
// ------------------------------------------------------------
echo "\n[TEST GROUP 8] Whole-chain deletion\n";
$reset_vault = static function (): void {
    global $mock_db_tables, $wpdb;
    $mock_db_tables['wp_wm_consent_records'] = [];
    $mock_db_tables['wp_wm_consent_merkle_nodes'] = [];
    $wpdb->table_auto_increment = [];
    delete_option(MerkleTreeEngine::TIP_OPTION);
};
$reset_vault();
$engine->append_record('uuid-trunc-001', '203.0.113.9', true, false, false, 'UA/3');
$engine->append_record('uuid-trunc-002', '203.0.113.9', true, true, true, 'UA/3');
$wpdb->query('TRUNCATE TABLE wp_wm_consent_merkle_nodes');
$audit_emptied = $engine->verify_chain_integrity();
assert_test(
    'Emptying every chain node while the tip option says 2 were written is CHAIN_TRUNCATED (it verified as EMPTY_CHAIN)',
    false === $audit_emptied['valid'] && 'CHAIN_TRUNCATED' === ($audit_emptied['error'] ?? '') && 'TAMPERED' === $audit_emptied['status'],
    'result: ' . var_export($audit_emptied, true)
);
$reset_vault();
$audit_fresh = $engine->verify_chain_integrity();
assert_test(
    'A vault that never recorded anything is still EMPTY_CHAIN',
    true === $audit_fresh['valid'] && 'EMPTY_CHAIN' === $audit_fresh['status'],
    'result: ' . var_export($audit_fresh, true)
);

// ------------------------------------------------------------
// Test 9: Hub adapter (2026-09-14)
// ------------------------------------------------------------
echo "\n[TEST GROUP 9] Hub adapter\n";
if (!defined('WM_CONSENT_VAULT_FILE')) {
    define('WM_CONSENT_VAULT_FILE', dirname(__DIR__) . '/wm-sovereign-consent-vault.php');
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
}
require_once __DIR__ . '/fixtures/hub-interface.php';
require_once dirname(__DIR__) . '/includes/Admin/AdminDashboard.php';
require_once dirname(__DIR__) . '/includes/Adapters/Consent_Vault_Spoke_Adapter.php';
$adapter = new \WMCyber\Consent\Vault\Adapters\Consent_Vault_Spoke_Adapter();

$reset_vault();
assert_test(
    'An empty vault is NEEDS_CONFIG, not HEALTHY',
    'NEEDS_CONFIG' === $adapter->get_health_status(),
    'health: ' . $adapter->get_health_status()
);
$self_empty = $adapter->execute_action('self_test');
assert_test(
    'self_test on an empty vault fails (nothing to verify)',
    false === $self_empty['success'],
    var_export($self_empty, true)
);
$engine->append_record('uuid-adapter-01', '203.0.113.10', true, false, false, 'UA/4');
assert_test(
    'A verified chain with a node is HEALTHY and self_test succeeds',
    'HEALTHY' === $adapter->get_health_status() && true === $adapter->execute_action('self_test')['success'],
    'health: ' . $adapter->get_health_status()
);
$mock_db_tables['wp_wm_consent_records'][1]->analytics = 1;
assert_test(
    'An altered record makes the adapter report ERROR',
    'ERROR' === $adapter->get_health_status(),
    'health: ' . $adapter->get_health_status()
);
$mock_db_tables['wp_wm_consent_records'][1]->analytics = 0;
$tele = $adapter->get_telemetry_metrics();
assert_test(
    'Telemetry counts records and names the chain state',
    1 === $tele['total_consents_logged'] && 'INTACT' === $tele['chain_integrity'] && 1 === $tele['ledger_nodes_count'],
    var_export($tele, true)
);
$missing_records = $mock_db_tables['wp_wm_consent_records'];
unset($mock_db_tables['wp_wm_consent_records']);
$tele_missing = $adapter->get_telemetry_metrics();
$mock_db_tables['wp_wm_consent_records'] = $missing_records;
assert_test(
    'A missing records table reports null, not 0 decisions',
    null === $tele_missing['total_consents_logged'],
    var_export($tele_missing, true)
);
assert_test(
    'SBOM properties describe features, not legal compliance labels',
    !str_contains(json_encode($adapter->get_sbom_data(), JSON_UNESCAPED_UNICODE), 'wm:compliance') && !str_contains($adapter->get_description(), 'TDDDG'),
    json_encode($adapter->get_sbom_data()['properties'])
);

// ------------------------------------------------------------
// Test 10: Blocker coverage and wording checks on banner and admin (2026-09-14)
// ------------------------------------------------------------
echo "\n[TEST GROUP 10] Blocker coverage, banner and admin wording\n";
$nocookie = $blocker->filter_content_iframes('<iframe src="https://www.youtube-nocookie.com/embed/abc123"></iframe>');
assert_test(
    'youtube-nocookie.com embeds get the placeholder too (they still connect to Google)',
    !str_contains($nocookie, '<iframe') && str_contains($nocookie, 'wm-2click-wrapper'),
    substr($nocookie, 0, 120)
);
$multiline = $blocker->filter_content_iframes("<iframe src=\"https://player.vimeo.com/video/1\">\n<p>Fallback</p>\n</iframe>");
assert_test(
    'A Vimeo player iframe whose fallback spans lines is replaced',
    !str_contains($multiline, '<iframe') && str_contains($multiline, 'Vimeo'),
    substr($multiline, 0, 120)
);

$code_only = static function (string $path): string {
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($tok) ? $tok[1] : $tok;
    }
    return $out;
};
$emoji_re = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u';
$banner_code = $code_only(dirname(__DIR__) . '/includes/Frontend/ConsentBanner.php');
assert_test(
    'The banner no longer tells visitors the site uses cookies only locally or that modules load only after consent',
    !str_contains($banner_code, 'ausschließlich lokal') && !str_contains($banner_code, 'Medienmodule werden erst') && !str_contains($banner_code, 'DSK Compliant'),
    'banner still carries a claim about the operator site'
);
$blocker_code = $code_only(dirname(__DIR__) . '/includes/Blocker/ScriptIframeBlocker.php');
assert_test(
    'Banner, settings shortcode and placeholder use inline SVG, not emoji',
    0 === preg_match($emoji_re, $banner_code) && 0 === preg_match($emoji_re, $blocker_code) && str_contains($banner_code, 'aria-hidden="true"'),
    'emoji found'
);
$admin_code = $code_only(dirname(__DIR__) . '/includes/Admin/AdminDashboard.php');
assert_test(
    'Admin: no fixed claims (CERO FUGAS, SIN PATRONES OSCUROS, 100% INTACTO, Prueba legal certificada, BLOQUEO AUTOMÁTICO) and no emoji',
    !preg_match('/CERO FUGAS|PATRONES OSCUROS|100% INTACTO|Prueba legal certificada|BLOQUEO AUTOMÁTICO|Personalizar|100%/u', $admin_code) && 0 === preg_match($emoji_re, $admin_code),
    'forbidden wording or emoji in the admin screen'
);
assert_test(
    'Admin: the widget counts the real records table (it counted wm_consent_audit_log, which does not exist)',
    !str_contains($admin_code, 'wm_consent_audit_log') && str_contains($admin_code, 'Schema::get_records_table()'),
    'widget reads a table nothing creates'
);
assert_test(
    'Admin: no dbDelta on page views, family sheets under the hub and on the dashboard, no innerHTML',
    !str_contains($admin_code, 'Schema::create_tables()') && str_contains($admin_code, "'index.php' !== \$hook") && str_contains($admin_code, 'str_contains( $hook, self::SLUG )') && !str_contains($admin_code, 'innerHTML'),
    'render or enqueue regressed'
);

// ------------------------------------------------------------
// Test 11: Uninstall preserves data by default and removes it when opted in (D-UNINSTALL)
// ------------------------------------------------------------
echo "\n[TEST GROUP 11] Uninstall\n";
if (!function_exists('delete_site_option')) {
    function delete_site_option(string $option): bool { return true; }
}
if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', 'wm-sovereign-consent-vault/wm-sovereign-consent-vault.php');
}
delete_option('wm_consent_delete_data_on_uninstall');
update_option('wm_consent_chain_tip', ['hash' => 'x', 'count' => 1]);
update_option('wm_consent_vault_db_version', '1');
$wpdb->queries = [];
(static function (): void {
    global $wpdb;
    require dirname(__DIR__) . '/uninstall.php';
})();
assert_test(
    'Uninstall preserves options and both consent tables by default when wm_consent_delete_data_on_uninstall is off',
    false !== get_option('wm_consent_chain_tip')
        && '1' === get_option('wm_consent_vault_db_version')
        && 0 === count(array_filter($wpdb->queries, static fn($q) => str_starts_with($q, 'DROP TABLE'))),
    'queries on default uninstall: ' . implode(' | ', $wpdb->queries)
);

update_option('wm_consent_delete_data_on_uninstall', '1');
$wpdb->queries = [];
(static function (): void {
    global $wpdb;
    require dirname(__DIR__) . '/uninstall.php';
})();
assert_test(
    'Uninstall deletes the chain tip, schema version and opt-in option when explicitly enabled',
    false === get_option('wm_consent_chain_tip')
        && false === get_option('wm_consent_vault_db_version')
        && false === get_option('wm_consent_delete_data_on_uninstall'),
    'options left: ' . var_export(array_intersect_key($mock_options, array_flip(['wm_consent_chain_tip', 'wm_consent_vault_db_version', 'wm_consent_delete_data_on_uninstall'])), true)
);
assert_test(
    'Uninstall drops both consent tables when wm_consent_delete_data_on_uninstall is enabled',
    2 === count(array_filter($wpdb->queries, static fn($q) => str_starts_with($q, 'DROP TABLE IF EXISTS wp_wm_consent_'))),
    'queries: ' . implode(' | ', $wpdb->queries)
);
assert_test(
    // Compared without backslashes: esc_like() escapes the underscores and prepare() doubles the
    // backslash, so the query text carries \_transient\_wm\_cv\_rl\_ and a literal search never matches.
    'Uninstall removes the rate-limit buckets of the REST routes when opted in',
    1 === count(array_filter($wpdb->queries, static fn($q) => str_contains(str_replace('\\', '', $q), '_transient_wm_cv_rl_') && str_starts_with($q, 'DELETE'))),
    'queries: ' . implode(' | ', $wpdb->queries)
);

// ------------------------------------------------------------
// Test 12: The chain cannot be laundered, forged or written unlocked (2026-09-16)
// ------------------------------------------------------------
echo "\n[TEST GROUP 12] Chain hardening\n";
$reset_vault();
$engine->append_record('uuid-h-001', '203.0.113.21', true, false, false, 'UA/5');
$engine->append_record('uuid-h-002', '203.0.113.22', true, true, false, 'UA/5');
$engine->append_record('uuid-h-003', '203.0.113.23', true, false, true, 'UA/5');
// Cut the newest node, then record one more decision. The append used to read the last surviving row
// and recount the tip with COUNT(*), so the next consent erased the evidence of the cut (measured in
// local WordPress install: node 3 gone, node 4 linked to node 2, status INTACT).
unset($mock_db_tables['wp_wm_consent_merkle_nodes'][max(array_keys($mock_db_tables['wp_wm_consent_merkle_nodes']))]);
$engine->append_record('uuid-h-004', '203.0.113.24', true, false, false, 'UA/5');
$after_cut = $engine->verify_chain_integrity();
assert_test(
    'A consent recorded after a node was cut does not launder the truncation',
    false === $after_cut['valid'] && in_array($after_cut['error'] ?? '', ['PREV_HASH_MISMATCH', 'CHAIN_TRUNCATED'], true),
    var_export($after_cut, true)
);

// A row written straight into the records table is counted on the admin screen and was never verified.
$reset_vault();
$engine->append_record('uuid-h-010', '203.0.113.30', true, false, false, 'UA/6');
$wpdb->insert('wp_wm_consent_records', [
    'consent_uuid'   => 'uuid-h-forged',
    'ip_hmac'        => str_repeat('a', 64),
    'essential'      => 1,
    'analytics'      => 1,
    'marketing'      => 1,
    'user_agent'     => 'UA/6',
    'signature_hash' => str_repeat('b', 64),
    'created_at'     => current_time('mysql'),
]);
$with_forged = $engine->verify_chain_integrity();
assert_test(
    'A consent row inserted with no chain node is reported instead of counted as a decision',
    false === $with_forged['valid'] && 'UNCHAINED_RECORD' === ($with_forged['error'] ?? ''),
    var_export($with_forged, true)
);

// The tip lives in wp_options, where whoever deletes a node can write: only a signature keeps the
// truncation visible.
$reset_vault();
$engine->append_record('uuid-h-020', '203.0.113.40', true, false, false, 'UA/7');
$engine->append_record('uuid-h-021', '203.0.113.41', true, false, false, 'UA/7');
$signed_tip = get_option(MerkleTreeEngine::TIP_OPTION);
assert_test(
    'The recorded tip carries a signature over its hash and count',
    is_array($signed_tip) && isset($signed_tip['sig'])
        && hash_equals(MerkleTreeEngine::tip_signature((string) $signed_tip['hash'], (int) $signed_tip['count']), (string) $signed_tip['sig']),
    var_export($signed_tip, true)
);
// The cut takes the node and the consent row behind it, the way someone covering their tracks would:
// leaving the row is already caught as UNCHAINED_RECORD, and this case is about the tip.
$cut_index = max(array_keys($mock_db_tables['wp_wm_consent_merkle_nodes']));
$cut_node  = $mock_db_tables['wp_wm_consent_merkle_nodes'][$cut_index];
unset($mock_db_tables['wp_wm_consent_merkle_nodes'][$cut_index], $mock_db_tables['wp_wm_consent_records'][(int) $cut_node->record_id]);
$surviving = end($mock_db_tables['wp_wm_consent_merkle_nodes']);
update_option(MerkleTreeEngine::TIP_OPTION, ['hash' => $surviving->merkle_root, 'count' => 1], false);
$rewritten = $engine->verify_chain_integrity();
assert_test(
    'A tip rewritten without the key no longer hides the node that was cut',
    false === $rewritten['valid'] && 'TIP_UNSIGNED' === ($rewritten['error'] ?? ''),
    var_export($rewritten, true)
);
update_option(MerkleTreeEngine::TIP_OPTION, ['hash' => $surviving->merkle_root, 'count' => 1, 'sig' => str_repeat('c', 64)], false);
$bad_sig = $engine->verify_chain_integrity();
assert_test(
    'A tip with a forged signature is detected',
    false === $bad_sig['valid'] && 'TIP_SIGNATURE_INVALID' === ($bad_sig['error'] ?? ''),
    var_export($bad_sig, true)
);

// The count is part of what the tip signs: a chain that lost nodes cannot be described by a tip that
// still counts them, and the signature keeps both values together.
$reset_vault();
$engine->append_record('uuid-h-025', '203.0.113.45', true, false, false, 'UA/7');
$tip_now = get_option(MerkleTreeEngine::TIP_OPTION);
update_option(
    MerkleTreeEngine::TIP_OPTION,
    ['hash' => $tip_now['hash'], 'count' => 99, 'sig' => MerkleTreeEngine::tip_signature((string) $tip_now['hash'], 99)],
    false
);
$count_off = $engine->verify_chain_integrity();
assert_test(
    'A tip that counts more nodes than the table holds is a truncation, not an intact chain',
    false === $count_off['valid'] && 'CHAIN_TRUNCATED' === ($count_off['error'] ?? ''),
    var_export($count_off, true)
);

// Two visitors clicking at the same moment used to read the same predecessor and fork the chain,
// which verification reports as tampering on a site that did nothing wrong.
$reset_vault();
$wpdb->journal = [];
$engine->append_record('uuid-h-030', '203.0.113.50', true, false, false, 'UA/8');
$journal    = $wpdb->journal;
$lock_at    = array_search('GET_LOCK', $journal, true);
$node_at    = array_search('insert:wp_wm_consent_merkle_nodes', $journal, true);
$release_at = array_search('RELEASE_LOCK', $journal, true);
assert_test(
    'The chain node is written while the named database lock is held',
    false !== $lock_at && false !== $node_at && false !== $release_at && $lock_at < $node_at && $node_at < $release_at,
    implode(' > ', $journal)
);
// The consent row and its node are one transaction. A worker killed between the two inserts left a
// node the tip did not know about, and every later verification called that chain tampered for an
// incident nobody caused — plausible on the 128 MB shared hosting the fleet runs on.
$record_at = array_search('insert:wp_wm_consent_records', $journal, true);
$begin_at  = array_search('START TRANSACTION', $journal, true);
$commit_at = array_search('COMMIT', $journal, true);
assert_test(
    'The consent row and its chain node are written inside one transaction, committed before the lock is released',
    false !== $begin_at && false !== $commit_at && false !== $record_at
        && $begin_at < $record_at && $record_at < $node_at && $node_at < $commit_at && $commit_at < $release_at,
    implode(' > ', $journal)
);

$wpdb->lock_granted = false;
$nodes_before_busy  = count($mock_db_tables['wp_wm_consent_merkle_nodes']);
$busy               = $engine->append_record('uuid-h-031', '203.0.113.51', true, false, false, 'UA/8');
$wpdb->lock_granted = true;
assert_test(
    'Without the lock nothing is written and the caller is told (the route answers 503)',
    'chain_busy' === ($busy['error'] ?? '') && $nodes_before_busy === count($mock_db_tables['wp_wm_consent_merkle_nodes']),
    var_export($busy, true)
);

// A consent row whose node could not be written is invisible to verification, so it must not stay.
$reset_vault();
$engine->append_record('uuid-h-040', '203.0.113.60', true, false, false, 'UA/9');
$records_before          = count($mock_db_tables['wp_wm_consent_records']);
$wpdb->fail_insert_table = 'wp_wm_consent_merkle_nodes';
$failed_append           = $engine->append_record('uuid-h-041', '203.0.113.61', true, true, true, 'UA/9');
assert_test(
    'A consent whose chain node was refused leaves no unverifiable row behind',
    str_starts_with((string) ($failed_append['error'] ?? ''), 'record_not_written')
        && $records_before === count($mock_db_tables['wp_wm_consent_records']),
    var_export($failed_append, true) . ' records=' . count($mock_db_tables['wp_wm_consent_records'])
);
$wpdb->fail_insert_table = 'wp_wm_consent_merkle_nodes';
$req_fail = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_fail->set_param('uuid', 'uuid-h-042');
$req_fail->set_param('essential', true);
$req_fail->set_param('analytics', false);
$req_fail->set_param('marketing', false);
$res_fail = (new RestController())->handle_record_consent($req_fail);
assert_test(
    'A decision that was not recorded answers 500, not 409 "already recorded"',
    500 === $res_fail->get_status(),
    'status=' . $res_fail->get_status()
);

// update_option() writes to wp_options and moves insert_id: the node number has to be read before it.
$reset_vault();
delete_option(MerkleTreeEngine::TIP_OPTION);
for ($i = 0; $i < 5; $i++) {
    $wpdb->insert('wp_options', ['option_name' => 'filler_' . $i, 'option_value' => 'x']);
}
$appended = $engine->append_record('uuid-h-050', '203.0.113.70', true, false, false, 'UA/10');
assert_test(
    'The answer names the chain node, not the option row the tip write created',
    (int) ($appended['node_index'] ?? 0) === (int) max(array_keys($mock_db_tables['wp_wm_consent_merkle_nodes'])),
    var_export($appended, true)
);

// record_hash holds 64 characters: a longer anchor hash arrived truncated and broke every later check.
$reset_vault();
$nodes_before_anchor = count($mock_db_tables['wp_wm_consent_merkle_nodes']);
$bad_anchor          = $engine->append_anchor('cert-1', 'not-a-sha256');
$long_anchor         = $engine->append_anchor('cert-2', str_repeat('a', 96));
assert_test(
    'An anchor hash that is not a hex SHA-256 is refused instead of stored truncated',
    'invalid_anchor_hash' === ($bad_anchor['error'] ?? '') && 'invalid_anchor_hash' === ($long_anchor['error'] ?? '')
        && $nodes_before_anchor === count($mock_db_tables['wp_wm_consent_merkle_nodes']),
    var_export([$bad_anchor, $long_anchor], true)
);
$good_anchor = $engine->append_anchor('cert-3', hash('sha256', 'seal'));
assert_test(
    'A hex SHA-256 anchor (what the Privacy Eraser sends) is appended and the chain still verifies',
    isset($good_anchor['merkle_root']) && true === $engine->verify_chain_integrity()['valid'],
    var_export($good_anchor, true)
);

// ------------------------------------------------------------
// Test 13: The record route reads booleans as booleans (2026-09-16)
// ------------------------------------------------------------
echo "\n[TEST GROUP 13] Record route arguments\n";
$GLOBALS['rest_routes'] = [];
(new RestController())->register_routes();
$record_args = $GLOBALS['rest_routes']['wm-consent/v1/record']['args'] ?? [];
assert_test(
    'The route declares uuid as a string and the three flags as booleans',
    'string' === ($record_args['uuid']['type'] ?? '')
        && 'boolean' === ($record_args['essential']['type'] ?? '')
        && 'boolean' === ($record_args['analytics']['type'] ?? '')
        && 'boolean' === ($record_args['marketing']['type'] ?? ''),
    json_encode($record_args)
);
$reset_vault();
$req_str = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_str->set_param('uuid', 'uuid-bool-001');
$req_str->set_param('essential', 'true');
$req_str->set_param('analytics', 'false');
$req_str->set_param('marketing', '0');
$res_str = (new RestController())->handle_record_consent($req_str);
$stored  = end($mock_db_tables['wp_wm_consent_records']);
assert_test(
    'A body with "analytics": "false" does not store consent to analytics ((bool) made every string true)',
    200 === $res_str->get_status() && 1 === (int) $stored->essential && 0 === (int) $stored->analytics && 0 === (int) $stored->marketing,
    'status=' . $res_str->get_status() . ' row=' . json_encode($stored)
);

// ------------------------------------------------------------
// Test 14: Placeholder and banner text (2026-09-16)
// ------------------------------------------------------------
echo "\n[TEST GROUP 14] Placeholder and banner\n";
$relative_embed = $blocker->filter_content_iframes('<iframe src="//www.youtube.com/embed/abc123" title="Mein Video" height="315"></iframe>');
assert_test(
    'A protocol-relative YouTube iframe is replaced too (it loaded without any click)',
    !str_contains($relative_embed, '<iframe') && str_contains($relative_embed, 'data-wm-embed-src="https://www.youtube.com/embed/abc123"'),
    substr($relative_embed, 0, 200)
);
assert_test(
    'The placeholder carries the title and height of the embed it replaced',
    str_contains($relative_embed, 'data-wm-embed-title="Mein Video"') && str_contains($relative_embed, 'data-wm-embed-height="315"'),
    substr($relative_embed, 0, 200)
);
assert_test(
    'The placeholder says what a click does and claims no compliance with a statute',
    !str_contains($relative_embed, 'TDDDG') && !str_contains($relative_embed, 'DSGVO') && str_contains($relative_embed, 'IP-Adresse'),
    substr($relative_embed, 0, 300)
);
assert_test(
    'The embed source is not printed into an inline onclick handler',
    !str_contains($relative_embed, 'onclick=') && str_contains($relative_embed, 'wm-2click-unlock'),
    substr($relative_embed, 0, 200)
);
$banner_text = (string) file_get_contents(dirname(__DIR__) . '/includes/Frontend/ConsentBanner.php') . (string) @file_get_contents($banner_js_path);
assert_test(
    'A decision the server never confirmed is sent again on the next page view',
    str_contains($banner_text, "wmConsentRead('wm_consent_recorded') !== '1'")
        && str_contains($banner_text, "wmConsentWrite('wm_consent_recorded', '1')"),
    'the banner drops a decision whose request failed'
);
assert_test(
    'The banner no longer tells visitors their browser identifier is stored as a hash',
    !str_contains($banner_text, 'Hash Ihrer IP-Adresse und Ihrer Browserkennung') && str_contains($banner_text, 'im Klartext'),
    'the banner still describes the user agent as hashed'
);

// ------------------------------------------------------------
// Test 15: Every shipped file guards itself (2026-09-16)
// ------------------------------------------------------------
echo "\n[TEST GROUP 15] Shipped files\n";
$shipped = array_merge(
    glob(dirname(__DIR__) . '/includes/*/*.php') ?: [],
    [dirname(__DIR__) . '/wm-sovereign-consent-vault.php', dirname(__DIR__) . '/uninstall.php']
);
$without_guard = array_values(array_filter($shipped, static fn($f) => !preg_match("/defined\(\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\s*\)/", (string) file_get_contents($f))));
$without_types = array_values(array_filter($shipped, static fn($f) => !str_contains((string) file_get_contents($f), 'declare(strict_types=1)')));
assert_test(
    'Every shipped PHP file carries the direct-access guard (Schema.php did not)',
    [] === $without_guard,
    implode(', ', array_map('basename', $without_guard))
);
assert_test(
    'Every shipped PHP file declares strict types',
    [] === $without_types,
    implode(', ', array_map('basename', $without_types))
);

// ------------------------------------------------------------
// Test 16: Key, rate-limit subject and embeds (reader residuals, 2026-09-16)
// ------------------------------------------------------------
echo "\n[TEST GROUP 16] Key, rate limit and embeds\n";
$reset_vault();
$engine->append_record('uuid-r-001', '203.0.113.80', true, false, false, 'UA/11');
$audit_key = $engine->verify_chain_integrity();
assert_test(
    'The audit says whether the chain key is the phrase wp-config-sample ships with',
    array_key_exists('key_is_sample', $audit_key) && false === $audit_key['key_is_sample'] && false === MerkleTreeEngine::key_is_sample(),
    var_export($audit_key, true)
);
$engine_source = (string) file_get_contents(dirname(__DIR__) . '/includes/Crypto/MerkleTreeEngine.php');
assert_test(
    'The phrase it compares against is the one WordPress ships',
    str_contains($engine_source, "'put your unique phrase here'"),
    'sample phrase not recognised'
);

// A residential IPv6 line hands out a /64, so the budget has to be the /64, not the address.
$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_nonce_valid'] = true;
$controller_v6 = new RestController();
$req_v6 = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_v6->set_header('X-WP-Nonce', 'test-nonce');
$v6_accepted = 0;
$v6_limited  = null;
foreach (['2001:db8:1:2::1', '2001:db8:1:2::2', '2001:db8:1:2::3'] as $address) {
    $_SERVER['REMOTE_ADDR'] = $address;
    for ($i = 0; $i < 5; $i++) {
        $verdict = $controller_v6->check_record_permission($req_v6);
        if (true === $verdict) {
            $v6_accepted++;
        } else {
            $v6_limited = $verdict;
            break 2;
        }
    }
}
assert_test(
    'Three addresses of one IPv6 /64 share the budget: 10 accepted, then 429',
    10 === $v6_accepted && $v6_limited instanceof WP_Error && 429 === (int) ($v6_limited->data['status'] ?? 0),
    sprintf('accepted=%d limited=%s', $v6_accepted, $v6_limited instanceof WP_Error ? (string) ($v6_limited->data['status'] ?? '?') : 'none')
);
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';

// An IPv4 address a dual-stack socket reports as ::ffff:x.x.x.x packs to ten zero bytes, ff ff and
// the four IPv4 bytes, so grouping by the first eight put EVERY such visitor into one bucket:
// ::ffff:203.0.113.5 and ::ffff:198.51.100.9 both produced 0000000000000000::/64, and the site
// answered 429 to the eleventh visitor of the day. Each one keeps its own budget now.
$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_nonce_valid'] = true;
$controller_mapped = new RestController();
$req_mapped = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_mapped->set_header('X-WP-Nonce', 'test-nonce');
$mapped_accepted = 0;
$mapped_refused  = null;
foreach (['::ffff:203.0.113.5', '::ffff:198.51.100.9', '::ffff:192.0.2.44'] as $address) {
    $_SERVER['REMOTE_ADDR'] = $address;
    for ($i = 0; $i < 5; $i++) {
        $verdict = $controller_mapped->check_record_permission($req_mapped);
        if (true === $verdict) {
            $mapped_accepted++;
        } else {
            $mapped_refused = $verdict;
            break 2;
        }
    }
}
assert_test(
    'Three IPv4 addresses in IPv6 notation keep three separate budgets (15 accepted, none refused)',
    15 === $mapped_accepted && null === $mapped_refused,
    sprintf('accepted=%d refused=%s', $mapped_accepted, $mapped_refused instanceof WP_Error ? (string) ($mapped_refused->data['status'] ?? '?') : 'none')
);
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';

$mobile_embed = $blocker->filter_content_iframes('<iframe src="https://m.youtube.com/embed/abc"></iframe>');
$unquoted     = $blocker->filter_content_iframes('<iframe src=https://www.youtube.com/embed/xyz width=560></iframe>');
assert_test(
    'A mobile YouTube host and an unquoted src are replaced too',
    ! str_contains($mobile_embed, '<iframe') && ! str_contains($unquoted, '<iframe')
        && str_contains($unquoted, 'data-wm-embed-src="https://www.youtube.com/embed/xyz"'),
    substr($mobile_embed . ' | ' . $unquoted, 0, 240)
);
// try/catch, because the failure this guards against is a TypeError: without the catch the mutation
// that restores the typed parameter would kill the suite instead of turning one assertion red.
$null_content = 'not reached';
try {
    $null_content = $blocker->filter_content_iframes(null);
} catch (\Throwable $e) {
    $null_content = 'THREW ' . get_class($e);
}
assert_test(
    'A null content value does not make the filter fatal',
    '' === $null_content,
    $null_content
);
$banner_embed_source = (string) file_get_contents(dirname(__DIR__) . '/includes/Frontend/ConsentBanner.php') . (string) @file_get_contents($banner_js_path);
assert_test(
    'The unlocked iframe carries a referrer policy and lazy loading',
    str_contains($banner_embed_source, "iframe.referrerPolicy = 'strict-origin-when-cross-origin'") && str_contains($banner_embed_source, "iframe.loading = 'lazy'"),
    'the inserted iframe keeps the defaults'
);
assert_test(
    'A browser that blocks site data still gets the banner (reading localStorage is guarded)',
    str_contains($banner_embed_source, 'function wmConsentRead(key)') && str_contains($banner_embed_source, 'try { return localStorage.getItem(key); } catch (e) { return null; }'),
    'localStorage access is unguarded'
);

// ============================================================================================================
// 2026-09-17: categories, settings, services, signature format 2, real blocking, banner, admin screens
// ============================================================================================================

use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Core\ConsentCookie;
use WMCyber\Consent\Vault\Services\ServiceRegistry;
use WMCyber\Consent\Vault\Blocker\OutputBlocker;
use WMCyber\Consent\Vault\Frontend\ConsentBanner;
use WMCyber\Consent\Vault\Admin\SettingsScreen;
use WMCyber\Consent\Vault\Admin\AdminDashboard;

/** Clean settings, services, filters and cookie between groups. */
$reset_config = static function (): void {
    delete_option(Settings::OPTION);
    delete_option(Settings::DELETE_DATA_OPTION);
    delete_option(ServiceRegistry::OPTION);
    $GLOBALS['mock_filter_callbacks'] = [];
    $GLOBALS['mock_actions_fired']    = [];
    $GLOBALS['mock_privacy_url']      = '';
    unset($_COOKIE['wm_consent']);
};

// ------------------------------------------------------------
// Test 17: Categories
// ------------------------------------------------------------
echo "\n[TEST GROUP 17] Categories\n";
assert_test(
    'Aliases resolve to the four slugs and anything else is not a category',
    'statistics' === Categories::normalize('Analytics') && 'media' === Categories::normalize('external_media') && 'necessary' === Categories::normalize('essential')
        && null === Categories::normalize('tracking') && null === Categories::normalize(''),
    var_export([Categories::normalize('Analytics'), Categories::normalize('tracking')], true)
);
assert_test(
    'A decision is stored in canonical order with necessary first and no duplicates',
    'necessary,statistics,media' === Categories::to_string(['media', 'statistics', 'media', 'bogus'])
        && ['necessary', 'marketing'] === Categories::from_string('marketing,necessary'),
    Categories::to_string(['media', 'statistics', 'media', 'bogus'])
);
assert_test(
    'A list with an unknown category is reported as such (the route refuses it instead of dropping it)',
    false === Categories::all_known(['statistics', 'tracking']) && true === Categories::all_known(['analytics', 'media']) && false === Categories::all_known([1]),
    'all_known is too lenient'
);

// ------------------------------------------------------------
// Test 18: Settings
// ------------------------------------------------------------
echo "\n[TEST GROUP 18] Settings\n";
$reset_config();
$defaults = Settings::get();
assert_test(
    'Defaults: policy version 1, 365 days, blocking on, no text overrides',
    '1' === $defaults['policy_version'] && 365 === $defaults['duration_days'] && true === $defaults['blocking_enabled'] && '' === $defaults['texts']['heading'],
    var_export($defaults, true)
);
$saved = Settings::sanitize(
    [
        'texts'            => ['heading' => '<b>Ihre Wahl</b>', 'description' => "Zeile 1\nZeile 2"],
        'privacy_url'      => 'javascript:alert(1)',
        'imprint_url'      => 'https://example.test/impressum/',
        'policy_version'   => 'zwei Worte',
        'duration_days'    => '99999',
        'blocking_enabled' => '0',
    ],
    Settings::defaults()
);
assert_test(
    'Invalid policy version, duration and URL keep the current value and are named; markup is stripped; "0" switches blocking off',
    '1' === $saved['settings']['policy_version'] && 365 === $saved['settings']['duration_days'] && '' === $saved['settings']['privacy_url']
        && 'https://example.test/impressum/' === $saved['settings']['imprint_url'] && 'Ihre Wahl' === $saved['settings']['texts']['heading']
        && false === $saved['settings']['blocking_enabled']
        && ['privacy_url', 'policy_version', 'duration_days'] === $saved['errors'],
    var_export($saved, true)
);
Settings::save(['texts' => ['heading' => 'Eigene Überschrift', 'accept_all' => ''], 'policy_version' => '2026.1', 'duration_days' => '30']);
assert_test(
    'A stored override replaces the default text; an empty field keeps the translatable default',
    'Eigene Überschrift' === Settings::text('heading') && 'Alle akzeptieren' === Settings::text('accept_all') && 'Nur notwendige' === Settings::text('necessary_only')
        && '2026.1' === Settings::policy_version() && 30 === Settings::duration_days(),
    Settings::text('heading') . ' / ' . Settings::text('accept_all')
);
$GLOBALS['mock_privacy_url'] = 'https://example.test/datenschutz/';
assert_test(
    'Without a configured URL the privacy link is the WordPress privacy page',
    'https://example.test/datenschutz/' === Settings::privacy_url() && '' === Settings::imprint_url(),
    Settings::privacy_url()
);
$reset_config();
Settings::save(['policy_version' => '3']);
$bumped_numeric = Settings::bump_policy_version();
Settings::save(['policy_version' => 'v2026-1']);
$bumped_suffix = Settings::bump_policy_version();
Settings::save(['policy_version' => 'abc']);
$bumped_text = Settings::bump_policy_version();
assert_test(
    'Raising the policy version increments a trailing number and appends one otherwise',
    '4' === $bumped_numeric && 'v2026-2' === $bumped_suffix && 'abc-2' === $bumped_text && 'abc-2' === Settings::policy_version(),
    var_export([$bumped_numeric, $bumped_suffix, $bumped_text], true)
);
assert_test(
    'The banner defaults claim no conformity with a statute',
    0 === preg_match('/TDDDG|DSGVO|konform|rechtssicher|Planet49/u', implode(' ', ConsentBanner::default_texts()) . ' ' . implode(' ', array_column(Categories::defaults(), 'description'))),
    implode(' | ', ConsentBanner::default_texts())
);

// ------------------------------------------------------------
// Test 19: Service registry and presets
// ------------------------------------------------------------
echo "\n[TEST GROUP 19] Service registry\n";
$reset_config();
$presets = ServiceRegistry::presets();
$expected_hosts = [
    'google-analytics'  => 'googletagmanager.com',
    'meta-pixel'        => 'connect.facebook.net',
    'matomo'            => 'matomo.js',
    'hotjar'            => 'hotjar.com',
    'microsoft-clarity' => 'clarity.ms',
    'linkedin-insight'  => 'snap.licdn.com',
    'tiktok-pixel'      => 'analytics.tiktok.com',
    'youtube'           => 'youtube-nocookie.com',
    'vimeo'             => 'player.vimeo.com',
    'google-maps'       => 'google.com/maps',
];
$preset_problems = [];
foreach ($expected_hosts as $key => $host) {
    $preset = $presets[$key] ?? null;
    if (null === $preset || !in_array($host, $preset['hosts'], true) || null === ServiceRegistry::sanitize_service($preset)['service']) {
        $preset_problems[] = $key;
    }
}
assert_test(
    'All ten presets exist, carry their verified host and pass validation',
    10 === count($presets) && [] === $preset_problems,
    implode(', ', $preset_problems)
);
assert_test(
    'Presets sort trackers and embeds into statistics, marketing and media',
    'statistics' === $presets['google-analytics']['category'] && 'marketing' === $presets['meta-pixel']['category'] && 'marketing' === $presets['tiktok-pixel']['category']
        && 'media' === $presets['youtube']['category'] && 'media' === $presets['google-maps']['category'] && in_array('_ga_*', $presets['google-analytics']['cookies'], true),
    'preset categories drifted'
);
$invalid = ServiceRegistry::sanitize_service(['name' => '', 'category' => 'tracking', 'hosts' => ".com\njs", 'cookies' => 'bad cookie']);
assert_test(
    'A service without name, with an unknown category, a pattern too generic to be a host and a malformed cookie is refused field by field',
    null === $invalid['service'] && ['name', 'category', 'hosts', 'cookies', 'id'] === $invalid['errors'],
    var_export($invalid, true)
);
$valid = ServiceRegistry::sanitize_service(['name' => 'Beispiel Video', 'category' => 'external_media', 'hosts' => "Video.Example.org\n", 'cookies' => "vid_*,vid_session", 'privacy_url' => 'https://example.org/privacy']);
assert_test(
    'Text fields are split into lists, patterns lower-cased, the alias category resolved and the id derived from the name',
    null !== $valid['service'] && 'beispiel-video' === $valid['service']['id'] && 'media' === $valid['service']['category']
        && ['video.example.org'] === $valid['service']['hosts'] && ['vid_*', 'vid_session'] === $valid['service']['cookies'],
    var_export($valid, true)
);
$registry_for_match = new ServiceRegistry();
ServiceRegistry::add_preset('google-analytics');
ServiceRegistry::add_preset('matomo');
$registry_for_match = new ServiceRegistry();
assert_test(
    'A pattern matches as a whole host or file name, not inside a longer one',
    null !== $registry_for_match->match('https://www.googletagmanager.com/gtag/js?id=G-1')
        && null === $registry_for_match->match('https://notgoogletagmanager.com/x.js')
        && null !== $registry_for_match->match('//stats.example.org/matomo.js?v=5')
        && null === $registry_for_match->match('/wp-content/matomo.json'),
    'boundary rule broken'
);
assert_test(
    'Adding a preset twice does not overwrite the stored entry; an unknown preset is refused',
    'exists' === ServiceRegistry::add_preset('google-analytics') && 'unknown' === ServiceRegistry::add_preset('bogus') && 2 === count(ServiceRegistry::stored()),
    var_export(ServiceRegistry::stored(), true)
);
$edit_input = ServiceRegistry::find_stored('matomo');
$edit_input['category'] = 'marketing';
$edit_errors = ServiceRegistry::save_service($edit_input, 'matomo');
$dup_input = ServiceRegistry::find_stored('matomo');
$dup_input['id'] = 'google-analytics';
$dup_errors = ServiceRegistry::save_service($dup_input, 'matomo');
assert_test(
    'Editing replaces the entry in place; renaming it onto another entry\'s id is refused',
    [] === $edit_errors && 'marketing' === ServiceRegistry::find_stored('matomo')['category'] && ['duplicate_id'] === $dup_errors,
    var_export([$edit_errors, $dup_errors], true)
);
assert_test(
    'Deleting removes the entry once',
    true === ServiceRegistry::delete_service('matomo') && false === ServiceRegistry::delete_service('matomo') && null === ServiceRegistry::find_stored('matomo'),
    var_export(ServiceRegistry::stored(), true)
);
$GLOBALS['mock_filter_callbacks']['wm_consent_services'][] = static function (array $services): array {
    $services[] = ['id' => 'from-filter', 'name' => 'Filter-Dienst', 'category' => 'marketing', 'hosts' => ['ads.example.net']];
    $services[] = ['id' => 'broken', 'name' => 'Ohne Muster', 'category' => 'marketing', 'hosts' => []];
    return $services;
};
$filtered_ids = array_column((new ServiceRegistry())->all(), 'id');
assert_test(
    'wm_consent_services adds services at runtime and what it returns is validated too',
    in_array('from-filter', $filtered_ids, true) && !in_array('broken', $filtered_ids, true) && in_array('google-analytics', $filtered_ids, true),
    implode(', ', $filtered_ids)
);

// ------------------------------------------------------------
// Test 20: Signature format 2 and format-1 rows
// ------------------------------------------------------------
echo "\n[TEST GROUP 20] Signature formats\n";
$reset_config();
$reset_vault();
$v2 = $engine->append_record('uuid-v2-0001', '203.0.113.90', true, true, false, 'UA/v2', ['statistics', 'media'], '7');
$v2_row = $mock_db_tables['wp_wm_consent_records'][(int) ($v2['record_id'] ?? 0)] ?? null;
assert_test(
    'A new decision is stored with signature format 2, its canonical categories and the policy version',
    null !== $v2_row && 2 === (int) $v2_row->signature_version && 'necessary,statistics,media' === $v2_row->categories && '7' === $v2_row->policy_version
        && ['necessary', 'statistics', 'media'] === ($v2['categories'] ?? null) && 2 === ($v2['signature_version'] ?? null),
    var_export([$v2, $v2_row], true)
);
$v2_audit = $engine->verify_chain_integrity();
assert_test(
    'The chain verifies and counts the decision under format 2',
    true === $v2_audit['valid'] && [1 => 0, 2 => 1] === ($v2_audit['records_by_signature_version'] ?? null),
    var_export($v2_audit, true)
);
foreach (['categories' => 'necessary,statistics,marketing,media', 'policy_version' => '8', 'user_agent' => 'UA/forged'] as $column => $forged) {
    $original = $v2_row->$column;
    $v2_row->$column = $forged;
    $forged_audit = $engine->verify_chain_integrity();
    $v2_row->$column = $original;
    assert_test(
        "Changing {$column} of a format-2 decision is detected",
        false === $forged_audit['valid'] && 'CONSENT_RECORD_ALTERED' === ($forged_audit['error'] ?? ''),
        var_export($forged_audit, true)
    );
}

// A decision written before schema 3: no new columns in the row, the format-1 signature, chained by hand exactly as
// append_record() did then.
$reset_vault();
$legacy_created = '2026-09-01 10:00:00';
$legacy_ip      = hash_hmac('sha256', '203.0.113.91', wp_salt('auth'));
$legacy_sig     = MerkleTreeEngine::record_signature('uuid-legacy-01', $legacy_ip, true, true, false, $legacy_created);
$wpdb->insert('wp_wm_consent_records', ['consent_uuid' => 'uuid-legacy-01', 'ip_hmac' => $legacy_ip, 'essential' => 1, 'analytics' => 1, 'marketing' => 0, 'user_agent' => 'UA/old', 'signature_hash' => $legacy_sig, 'created_at' => $legacy_created]);
$legacy_record_id = $wpdb->insert_id;
$legacy_link      = MerkleTreeEngine::chain_link(MerkleTreeEngine::GENESIS_HASH, $legacy_sig);
$wpdb->insert('wp_wm_consent_merkle_nodes', ['record_id' => $legacy_record_id, 'prev_hash' => MerkleTreeEngine::GENESIS_HASH, 'record_hash' => $legacy_sig, 'merkle_root' => $legacy_link, 'timestamp' => $legacy_created]);
update_option(MerkleTreeEngine::TIP_OPTION, ['hash' => $legacy_link, 'count' => 1, 'sig' => MerkleTreeEngine::tip_signature($legacy_link, 1)], false);
$engine->append_record('uuid-after-legacy', '203.0.113.92', true, false, false, 'UA/new', [], '1');
$mixed_audit = $engine->verify_chain_integrity();
assert_test(
    'A format-1 row written before the categories still verifies next to a format-2 row',
    true === $mixed_audit['valid'] && [1 => 1, 2 => 1] === ($mixed_audit['records_by_signature_version'] ?? null),
    var_export($mixed_audit, true)
);
$legacy_row = $mock_db_tables['wp_wm_consent_records'][$legacy_record_id];
$legacy_row->categories = 'necessary,statistics,marketing';
$legacy_injected = $engine->verify_chain_integrity();
unset($legacy_row->categories);
assert_test(
    'Categories written into a format-1 row (which does not sign them) make it count as altered',
    false === $legacy_injected['valid'] && 'CONSENT_RECORD_ALTERED' === ($legacy_injected['error'] ?? ''),
    var_export($legacy_injected, true)
);
$legacy_row->signature_version = 2;
$legacy_flipped = $engine->verify_chain_integrity();
unset($legacy_row->signature_version);
$legacy_row->user_agent = 'UA/changed';
$legacy_ua = $engine->verify_chain_integrity();
$legacy_row->user_agent = 'UA/old';
assert_test(
    'Relabelling a format-1 row as format 2 is detected; its user agent stays unsigned (known limit of format 1)',
    false === $legacy_flipped['valid'] && 'CONSENT_RECORD_ALTERED' === ($legacy_flipped['error'] ?? '') && true === $legacy_ua['valid'],
    var_export([$legacy_flipped, $legacy_ua], true)
);
$nodes_before_inconsistent = count($mock_db_tables['wp_wm_consent_merkle_nodes']);
// Statistics missing from the flags although the categories carry it, and marketing the other way round: each
// direction is caught by its own comparison.
$inconsistent_stats     = $engine->append_record('uuid-inconsist-1', '203.0.113.93', true, false, true, 'UA', ['statistics', 'marketing'], '1');
$inconsistent_marketing = $engine->append_record('uuid-inconsist-2', '203.0.113.93', true, true, false, 'UA', ['statistics', 'marketing'], '1');
$unknown_in_engine      = $engine->append_record('uuid-inconsist-3', '203.0.113.93', true, false, false, 'UA', ['tracking'], '1');
$bad_version            = $engine->append_record('uuid-badversion', '203.0.113.93', true, false, false, 'UA', [], 'zwei Worte');
assert_test(
    'Flags that contradict the categories, an unknown category or a malformed policy version are refused before anything is written',
    'inconsistent_categories' === ($inconsistent_stats['error'] ?? '') && 'inconsistent_categories' === ($inconsistent_marketing['error'] ?? '')
        && 'inconsistent_categories' === ($unknown_in_engine['error'] ?? '') && 'invalid_policy_version' === ($bad_version['error'] ?? '')
        && $nodes_before_inconsistent === count($mock_db_tables['wp_wm_consent_merkle_nodes']),
    var_export([$inconsistent_stats, $inconsistent_marketing, $unknown_in_engine, $bad_version], true)
);
$long_agent = str_repeat('ä', 300);
$long = $engine->append_record('uuid-long-agent', '203.0.113.94', true, false, false, $long_agent, [], '1');
$long_row = $mock_db_tables['wp_wm_consent_records'][(int) ($long['record_id'] ?? 0)] ?? null;
assert_test(
    'A long user agent is cut to 255 characters, not bytes, and the decision verifies',
    null !== $long_row && 255 === mb_strlen($long_row->user_agent, 'UTF-8') && mb_check_encoding($long_row->user_agent, 'UTF-8') && true === $engine->verify_chain_integrity()['valid'],
    null === $long_row ? 'not stored' : (string) mb_strlen($long_row->user_agent, 'UTF-8')
);
$schema_source = (string) file_get_contents(dirname(__DIR__) . '/includes/Database/Schema.php');
assert_test(
    'Schema 3 adds the three columns with the defaults that make every earlier row a format-1 row',
    '3' === Schema::VERSION && str_contains($schema_source, "categories VARCHAR(64) NOT NULL DEFAULT ''")
        && str_contains($schema_source, "policy_version VARCHAR(32) NOT NULL DEFAULT ''") && str_contains($schema_source, 'signature_version SMALLINT UNSIGNED NOT NULL DEFAULT 1'),
    'schema drifted'
);

// ------------------------------------------------------------
// Test 21: Record route with categories
// ------------------------------------------------------------
echo "\n[TEST GROUP 21] Record route with categories\n";
$reset_config();
$reset_vault();
$GLOBALS['rest_routes'] = [];
(new RestController())->register_routes();
$record_args_v2 = $GLOBALS['rest_routes']['wm-consent/v1/record']['args'] ?? [];
assert_test(
    'The route declares categories as an optional list of the four slugs and the policy version as an optional pattern',
    'array' === ($record_args_v2['categories']['type'] ?? '') && Categories::SLUGS === ($record_args_v2['categories']['items']['enum'] ?? null)
        && false === ($record_args_v2['categories']['required'] ?? null) && '^[A-Za-z0-9._-]{1,32}$' === ($record_args_v2['policy_version']['pattern'] ?? ''),
    json_encode($record_args_v2)
);
$route_request = static function (string $uuid, $analytics, $marketing, $categories = null, $version = null): WP_REST_Request {
    $request = new WP_REST_Request('POST', '/wm-consent/v1/record');
    $request->set_param('uuid', $uuid);
    $request->set_param('essential', true);
    $request->set_param('analytics', $analytics);
    $request->set_param('marketing', $marketing);
    if (null !== $categories) {
        $request->set_param('categories', $categories);
    }
    if (null !== $version) {
        $request->set_param('policy_version', $version);
    }
    return $request;
};
$route = new RestController();
$ok = $route->handle_record_consent($route_request('uuid-route-cat1', true, false, ['statistics', 'media'], '7'));
$ok_row = end($mock_db_tables['wp_wm_consent_records']);
$fired = array_values(array_filter($GLOBALS['mock_actions_fired'], static fn($a) => 'wm_consent_decision_recorded' === $a[0]));
assert_test(
    'A decision with categories and policy version is stored as sent and announced through wm_consent_decision_recorded',
    200 === $ok->get_status() && 'necessary,statistics,media' === $ok_row->categories && '7' === $ok_row->policy_version
        && 1 === count($fired) && ['necessary', 'statistics', 'media'] === ($fired[0][1][0]['categories'] ?? null),
    'status=' . $ok->get_status() . ' row=' . json_encode($ok_row)
);
$legacy_post = $route->handle_record_consent($route_request('uuid-route-old1', true, true));
$legacy_post_row = end($mock_db_tables['wp_wm_consent_records']);
assert_test(
    'A decision from a cached two-button banner (no categories) is stored with categories derived from its flags',
    200 === $legacy_post->get_status() && 'necessary,statistics,marketing' === $legacy_post_row->categories && '' === $legacy_post_row->policy_version,
    json_encode($legacy_post_row)
);
$unknown_cat  = $route->handle_record_consent($route_request('uuid-route-bad1', false, false, ['tracking']));
$mismatch     = $route->handle_record_consent($route_request('uuid-route-bad2', false, false, ['statistics']));
$bad_policy   = $route->handle_record_consent($route_request('uuid-route-bad3', false, false, ['necessary'], 'zwei Worte'));
$records_after_bad = count($mock_db_tables['wp_wm_consent_records']);
assert_test(
    'An unknown category, flags that contradict the categories and a malformed policy version answer 400 and store nothing',
    400 === $unknown_cat->get_status() && 400 === $mismatch->get_status() && 400 === $bad_policy->get_status() && 2 === $records_after_bad,
    sprintf('%d %d %d records=%d', $unknown_cat->get_status(), $mismatch->get_status(), $bad_policy->get_status(), $records_after_bad)
);

// ------------------------------------------------------------
// Test 22: Page-wide blocking
// ------------------------------------------------------------
echo "\n[TEST GROUP 22] Page-wide blocking\n";
$reset_config();
foreach (['google-analytics', 'meta-pixel'] as $preset_key) {
    ServiceRegistry::add_preset($preset_key);
}
ServiceRegistry::save_service(['name' => 'Beispiel Video', 'id' => 'example-video', 'category' => 'media', 'hosts' => 'video.example.org']);
ServiceRegistry::save_service(['name' => 'Beispiel Ads Frame', 'id' => 'example-ads', 'category' => 'marketing', 'hosts' => 'ads.example.net']);
// A pattern that also matches this plugin's own script: the plugin's own tags must stay untouched regardless.
ServiceRegistry::save_service(['name' => 'Zu breites Muster', 'id' => 'too-broad', 'category' => 'marketing', 'hosts' => 'plugins/wm-sovereign-consent-vault']);
$blocker_page = new OutputBlocker(new ServiceRegistry(), new ScriptIframeBlocker());
$page = '<html><head>'
    . '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST&amp;l=dataLayer"></script>'
    . "<script>!function(f,b,e,v,n,t,s){t=b.createElement(e);t.src='https://connect.facebook.net/en_US/fbevents.js';}(window,document,'script');</script>"
    . '<script src="/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>'
    . '<script type="application/ld+json">{"@type":"Organization","url":"https://www.googletagmanager.com/"}</script>'
    . '<script src="/wp-content/plugins/wm-sovereign-consent-vault/assets/js/wm-consent-vault.js" id="wm-consent-vault-js"></script>'
    . '<script data-wm-consent-ignore src="https://www.googletagmanager.com/gtm.js"></script>'
    . '<script data-wm-consent="marketing">window.marked = 1;</script>'
    . '<script type="text/plain" data-wm-consent="statistics">window.dev = 1;</script>'
    . '<script data-wm-consent="necessary">window.needed = 1;</script>'
    . '<script type="module" src="https://www.googletagmanager.com/module.js"></script>'
    . '<!-- <script src="https://www.googletagmanager.com/commented.js"></script> -->'
    . '</head><body>'
    . "<script>var tpl = '<iframe src=\"https://www.youtube.com/embed/intpl\"></iframe>';</script>"
    . '<aside><iframe width="560" height="315" src="https://www.youtube.com/embed/sidebar" title="Seitenleiste"></iframe></aside>'
    . '<iframe src="https://video.example.org/embed/1" title="Registriert" height="360"></iframe>'
    . '<iframe src="https://ads.example.net/frame" width="1" height="1"></iframe>'
    . '<iframe src="https://maps.example.com/unrelated"></iframe>'
    . '</body></html>';
$rewritten = $blocker_page->rewrite_html($page);
assert_test(
    'An external script of a registered statistics service is held back: text/plain, category, service, source moved aside',
    str_contains($rewritten, '<script type="text/plain" async data-wm-consent-src="https://www.googletagmanager.com/gtag/js?id=G-TEST&amp;l=dataLayer" data-wm-consent-category="statistics" data-wm-consent-service="google-analytics"></script>')
        && !str_contains($rewritten, ' src="https://www.googletagmanager.com/gtag/js'),
    $rewritten
);
assert_test(
    'An inline snippet that loads a registered marketing service is held back',
    1 === preg_match('/<script type="text\/plain" data-wm-consent-category="marketing" data-wm-consent-service="meta-pixel">!function/', $rewritten),
    $rewritten
);
assert_test(
    'Unregistered scripts, JSON-LD, the plugin\'s own script, data-wm-consent-ignore and commented markup are left alone',
    str_contains($rewritten, '<script src="/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>')
        && str_contains($rewritten, '<script type="application/ld+json">{"@type":"Organization"')
        && str_contains($rewritten, 'id="wm-consent-vault-js"></script>') && !str_contains($rewritten, 'wm-consent-vault.js" data-wm-consent-src')
        && str_contains($rewritten, '<script data-wm-consent-ignore src="https://www.googletagmanager.com/gtm.js"></script>')
        && str_contains($rewritten, '<!-- <script src="https://www.googletagmanager.com/commented.js"></script> -->'),
    $rewritten
);
assert_test(
    'Developer markup: a JavaScript script with data-wm-consent is held back, text/plain markup and necessary are left alone',
    str_contains($rewritten, '<script type="text/plain" data-wm-consent-category="marketing">window.marked = 1;</script>')
        && str_contains($rewritten, '<script type="text/plain" data-wm-consent="statistics">window.dev = 1;</script>')
        && str_contains($rewritten, '<script data-wm-consent="necessary">window.needed = 1;</script>'),
    $rewritten
);
assert_test(
    'A held-back module keeps its type for activation',
    str_contains($rewritten, 'data-wm-consent-src="https://www.googletagmanager.com/module.js" data-wm-consent-category="statistics" data-wm-consent-service="google-analytics" data-wm-consent-type="module"'),
    $rewritten
);
assert_test(
    'A YouTube iframe outside the post content becomes a media placeholder; an iframe inside script text is not touched',
    str_contains($rewritten, 'data-wm-embed-src="https://www.youtube.com/embed/sidebar"') && str_contains($rewritten, 'data-wm-consent-category="media" data-wm-consent-service="youtube"')
        && !str_contains($rewritten, ' src="https://www.youtube.com/embed/sidebar"')
        && str_contains($rewritten, "var tpl = '<iframe src=\"https://www.youtube.com/embed/intpl\"></iframe>';"),
    $rewritten
);
assert_test(
    'A registered media iframe becomes a placeholder named after its service; a marketing iframe waits with its source aside; others stay',
    str_contains($rewritten, 'data-wm-embed-src="https://video.example.org/embed/1"') && str_contains($rewritten, 'Externer Inhalt blockiert (Beispiel Video)')
        && str_contains($rewritten, '<iframe data-wm-consent-src="https://ads.example.net/frame" width="1" height="1" data-wm-consent-category="marketing" data-wm-consent-service="example-ads"></iframe>')
        && str_contains($rewritten, '<iframe src="https://maps.example.com/unrelated"></iframe>'),
    $rewritten
);
$huge = '<script>' . str_repeat('var x = "' . str_repeat('a', 1000) . '";', 2048) . '</script><script src="https://www.googletagmanager.com/gtag/js?id=G-AFTER"></script>';
$huge_out = $blocker_page->rewrite_html($huge);
assert_test(
    'A script after two megabytes of inline code is still held back (the scanner does not rely on one regular expression)',
    str_contains($huge_out, 'data-wm-consent-src="https://www.googletagmanager.com/gtag/js?id=G-AFTER"') && strlen($huge_out) > 2000000,
    substr($huge_out, -200)
);
$chunks = new OutputBlocker(new ServiceRegistry(), new ScriptIframeBlocker());
$first  = $chunks->buffer_callback('<p>vor</p><script src="https://www.google', PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FLUSH);
$second = $chunks->buffer_callback('tagmanager.com/gtag/js"></script>', PHP_OUTPUT_HANDLER_FINAL);
assert_test(
    'A tag split across two flushed chunks is held back as a whole',
    '' === $first && str_contains($second, 'data-wm-consent-src="https://www.googletagmanager.com/gtag/js"') && str_starts_with($second, '<p>vor</p>'),
    var_export([$first, $second], true)
);
$chunks->buffer_callback('verworfen <script src="https://www.googletagmanager.com/x.js"></script>', PHP_OUTPUT_HANDLER_FLUSH);
$chunks->buffer_callback('', PHP_OUTPUT_HANDLER_CLEAN);
$after_clean = $chunks->buffer_callback('<p>neu</p>', PHP_OUTPUT_HANDLER_FINAL);
assert_test(
    'ob_clean() discards what was held back',
    '<p>neu</p>' === $after_clean,
    $after_clean
);
$GLOBALS['mock_did_action']['wp'] = 1;
$enabled_front = $blocker_page->should_block();
Settings::save(['blocking_enabled' => '0']);
$disabled_setting = $blocker_page->should_block();
Settings::save(['blocking_enabled' => '1']);
$GLOBALS['mock_is_feed'] = true;
$feed = $blocker_page->should_block();
$GLOBALS['mock_is_feed'] = false;
$GLOBALS['mock_filter_callbacks']['wm_consent_blocking_enabled'][] = static fn() => false;
$filtered_off = $blocker_page->should_block();
unset($GLOBALS['mock_filter_callbacks']['wm_consent_blocking_enabled']);
assert_test(
    'Blocking runs on front-end pages and stops for the settings switch, feeds and the wm_consent_blocking_enabled filter',
    true === $enabled_front && false === $disabled_setting && false === $feed && false === $filtered_off,
    var_export([$enabled_front, $disabled_setting, $feed, $filtered_off], true)
);
$attributes = $blocker_page->filter_script_attributes(['src' => 'https://www.googletagmanager.com/gtag/js?id=G-X&l=dl', 'id' => 'ga-js', 'async' => true]);
$inline_attributes = $blocker_page->filter_inline_script_attributes(['id' => 'fb-js-after'], "fbq('init'); s.src='https://connect.facebook.net/en_US/fbevents.js';");
$plain_attributes = $blocker_page->filter_script_attributes(['src' => '/wp-includes/js/wp-embed.min.js', 'id' => 'wp-embed-js']);
assert_test(
    'wp_script_attributes and wp_inline_script_attributes hold back registered scripts before WordPress prints them',
    'text/plain' === ($attributes['type'] ?? '') && !isset($attributes['src']) && 'https://www.googletagmanager.com/gtag/js?id=G-X&l=dl' === ($attributes['data-wm-consent-src'] ?? '')
        && true === ($attributes['async'] ?? null) && 'statistics' === ($attributes['data-wm-consent-category'] ?? '')
        && 'text/plain' === ($inline_attributes['type'] ?? '') && 'marketing' === ($inline_attributes['data-wm-consent-category'] ?? '')
        && ['src' => '/wp-includes/js/wp-embed.min.js', 'id' => 'wp-embed-js'] === $plain_attributes,
    var_export([$attributes, $inline_attributes, $plain_attributes], true)
);
$GLOBALS['mock_filter_callbacks']['wm_consent_script_handles'][] = static fn() => ['my-stats' => 'statistics'];
$handle_tag = $blocker_page->filter_script_loader_tag('<script id="my-stats-js-before">var cfg = 1;</script><script src="/wp-content/themes/x/stats.js" id="my-stats-js"></script>', 'my-stats', '/wp-content/themes/x/stats.js');
unset($GLOBALS['mock_filter_callbacks']['wm_consent_script_handles']);
assert_test(
    'A handle mapped through wm_consent_script_handles is held back with its inline parts, in order',
    2 === substr_count($handle_tag, 'type="text/plain"') && 2 === substr_count($handle_tag, 'data-wm-consent-category="statistics"')
        && strpos($handle_tag, 'var cfg = 1;') < strpos($handle_tag, 'stats.js'),
    $handle_tag
);
$GLOBALS['mock_filter_callbacks']['wm_consent_script_category'][] = static fn($category, $context) => str_contains((string) $context['src'], 'id=G-ALLOWED') ? null : $category;
$allowed = $blocker_page->rewrite_html('<script src="https://www.googletagmanager.com/gtag/js?id=G-ALLOWED"></script>');
unset($GLOBALS['mock_filter_callbacks']['wm_consent_script_category']);
assert_test(
    'wm_consent_script_category can leave a matched script alone',
    '<script src="https://www.googletagmanager.com/gtag/js?id=G-ALLOWED"></script>' === $allowed,
    $allowed
);
$GLOBALS['mock_hooks'] = [];
$blocker_page->boot();
assert_test(
    'The buffer opens late on template_redirect (inside a cache plugin\'s buffer) and the three script filters are registered',
    99 === ($GLOBALS['mock_hooks']['template_redirect'][0][1] ?? null) && isset($GLOBALS['mock_hooks']['wp_script_attributes'], $GLOBALS['mock_hooks']['wp_inline_script_attributes'], $GLOBALS['mock_hooks']['script_loader_tag'])
        && 3 === ($GLOBALS['mock_hooks']['script_loader_tag'][0][2] ?? null),
    var_export(array_keys($GLOBALS['mock_hooks']), true)
);

// ------------------------------------------------------------
// Test 23: wm_consent_has() and the consent cookie
// ------------------------------------------------------------
echo "\n[TEST GROUP 23] wm_consent_has and the cookie\n";
$reset_config();
Settings::save(['policy_version' => '5', 'duration_days' => '10']);
$GLOBALS['mock_filter_callbacks']['wm_consent_has_nocache'][] = static fn() => false;
$_COOKIE['wm_consent'] = '5|necessary,statistics|' . (time() - 60);
$has_without_nocache = wm_consent_has('statistics');
$donotcache_before   = defined('DONOTCACHEPAGE');
unset($GLOBALS['mock_filter_callbacks']['wm_consent_has_nocache']);
assert_test(
    'wm_consent_has_nocache can keep a known-uncached page from being marked',
    true === $has_without_nocache && false === $donotcache_before,
    var_export([$has_without_nocache, $donotcache_before], true)
);
$checks = [
    'necessary without cookie'         => [null, 'necessary', true],
    'statistics without cookie'        => [null, 'statistics', false],
    'accepted statistics'              => ['5|necessary,statistics|' . (time() - 60), 'statistics', true],
    'alias analytics'                  => ['5|necessary,statistics|' . (time() - 60), 'analytics', true],
    'not accepted marketing'           => ['5|necessary,statistics|' . (time() - 60), 'marketing', false],
    'other policy version'             => ['4|necessary,statistics|' . (time() - 60), 'statistics', false],
    'older than the duration'          => ['5|necessary,statistics|' . (time() - 11 * 86400), 'statistics', false],
    'from the future'                  => ['5|necessary,statistics|' . (time() + 3600), 'statistics', false],
    'unknown category in the cookie'   => ['5|necessary,statistics,tracking|' . (time() - 60), 'statistics', false],
    'malformed'                        => ['<script>|statistics|1', 'statistics', false],
    'unknown category asked'           => ['5|necessary,statistics|' . (time() - 60), 'tracking', false],
];
$wrong = [];
foreach ($checks as $label => [$cookie, $category, $expected]) {
    if (null === $cookie) {
        unset($_COOKIE['wm_consent']);
    } else {
        $_COOKIE['wm_consent'] = $cookie;
    }
    if (wm_consent_has($category) !== $expected) {
        $wrong[] = $label;
    }
}
assert_test(
    'wm_consent_has() answers from a cookie given for the current policy version, within the duration, for known categories only',
    [] === $wrong,
    implode(', ', $wrong)
);
assert_test(
    'Reading the cookie marks the response as not cacheable (a page rendered from it must not be served to the next visitor)',
    defined('DONOTCACHEPAGE'),
    'DONOTCACHEPAGE not defined'
);
$GLOBALS['mock_filter_callbacks']['wm_consent_has'][] = static fn($result, $category) => 'media' === $category ? true : $result;
unset($_COOKIE['wm_consent']);
$filtered_has = wm_consent_has('media');
unset($GLOBALS['mock_filter_callbacks']['wm_consent_has']);
assert_test(
    'The answer passes through the wm_consent_has filter',
    true === $filtered_has,
    var_export($filtered_has, true)
);

// ------------------------------------------------------------
// Test 24: Consent dialog markup, placeholder, shortcode
// ------------------------------------------------------------
echo "\n[TEST GROUP 24] Consent dialog\n";
$reset_config();
ServiceRegistry::add_preset('google-analytics');
Settings::save(['imprint_url' => 'https://example.test/impressum/', 'texts' => ['heading' => 'Ihre Datenschutz-Auswahl']]);
$GLOBALS['mock_privacy_url'] = 'https://example.test/datenschutz/';
ob_start();
(new ConsentBanner(new ServiceRegistry()))->render_banner();
$dialog = (string) ob_get_clean();
assert_test(
    'The banner is a modal dialog named by its heading and described by its text',
    1 === preg_match('/role="dialog" aria-modal="true" aria-labelledby="wm-consent-vault-title" aria-describedby="wm-consent-vault-description" tabindex="-1"/', $dialog)
        && str_contains($dialog, '<h2 id="wm-consent-vault-title"') && str_contains($dialog, '<p id="wm-consent-vault-description"')
        && str_contains($dialog, 'Ihre Datenschutz-Auswahl'),
    substr($dialog, 0, 600)
);
preg_match_all('/<button type="button" class="([^"]+)" data-wm-consent-action="(accept-all|necessary)"/', $dialog, $main_buttons, PREG_SET_ORDER);
assert_test(
    '"Alle akzeptieren" and "Nur notwendige" carry the same class, so the same visual weight; "Einstellungen" opens the category view',
    count($main_buttons) >= 2 && $main_buttons[0][1] === $main_buttons[1][1] && str_contains($main_buttons[0][1], 'wm-consent__button--choice')
        && str_contains($dialog, 'data-wm-consent-action="settings" aria-expanded="false" aria-controls="wm-consent-vault-settings"')
        && str_contains($dialog, '>Nur notwendige<'),
    var_export($main_buttons, true)
);
assert_test(
    'Each category has a labelled switch; necessary is checked and cannot be switched off',
    4 === substr_count($dialog, 'role="switch"') && 1 === preg_match('/id="wm-consent-category-necessary" value="necessary"[^>]*checked disabled/', $dialog)
        && 1 === preg_match('/<label class="wm-consent__category-label" for="wm-consent-category-media">/', $dialog),
    substr($dialog, 0, 2000)
);
assert_test(
    'Registered services are listed under their category with their provider\'s privacy policy',
    str_contains($dialog, 'Google Analytics / Google Tag Manager') && str_contains($dialog, 'href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer"'),
    'service details missing'
);
assert_test(
    'The dialog links the privacy policy and the legal notice',
    str_contains($dialog, 'href="https://example.test/datenschutz/"') && str_contains($dialog, 'href="https://example.test/impressum/"'),
    'legal links missing'
);
assert_test(
    'No inline style and no inline handler in the dialog',
    !str_contains($dialog, 'style=') && !str_contains($dialog, 'onclick='),
    'inline style or handler found'
);
preg_match('/<script type="application\/json" id="wm-consent-config">(.*?)<\/script>/s', $dialog, $config_match);
$config = json_decode($config_match[1] ?? '', true);
assert_test(
    'The configuration block carries the policy version, duration, routes, categories, the services\' cookies and the pages the dialog does not open on',
    is_array($config) && '1' === $config['policyVersion'] && 365 === $config['durationDays'] && str_ends_with($config['recordUrl'], 'wm-consent/v1/record')
        && Categories::SLUGS === $config['categories'] && 'google-analytics' === ($config['services'][0]['id'] ?? '') && in_array('_ga', $config['services'][0]['cookies'] ?? [], true)
        && ['/datenschutz/', '/impressum/'] === $config['suppressOn'] && 'wm_consent' === $config['cookieName'],
    var_export($config, true)
);
$shortcode = (new ConsentBanner())->render_settings_shortcode();
assert_test(
    'The settings shortcode opens the dialog through a data attribute, without inline handler or style',
    str_contains($shortcode, 'data-wm-consent-open') && !str_contains($shortcode, 'onclick') && !str_contains($shortcode, 'style='),
    $shortcode
);
$GLOBALS['mock_enqueued'] = [];
(new ConsentBanner())->enqueue_assets();
assert_test(
    'The front-end sheet and script are enqueued, the script in the footer',
    'wm-consent-vault' === ($GLOBALS['mock_enqueued']['style'][0][0] ?? '') && str_ends_with((string) ($GLOBALS['mock_enqueued']['script'][0][1] ?? ''), 'assets/js/wm-consent-vault.js')
        && true === ($GLOBALS['mock_enqueued']['script'][0][4]['in_footer'] ?? null),
    var_export($GLOBALS['mock_enqueued'], true)
);
// Until 1.2.1 the sheet came only through wp_enqueue_scripts, which the block editor's canvas never runs: a block that
// prints [wm_consent_settings] (WM Suite Hub's privacy-center) showed a bare browser button in the editor.
$hooks_before      = $GLOBALS['mock_hooks'] ?? [];
$shortcodes_before = $GLOBALS['mock_shortcodes'] ?? [];
(new ConsentBanner())->boot();
$editor_hooked = in_array(
    'enqueue_editor_style',
    array_map(static fn(array $hook): string => is_array($hook[0]) ? (string) $hook[0][1] : '', $GLOBALS['mock_hooks']['enqueue_block_assets'] ?? []),
    true
);
$GLOBALS['mock_hooks']      = $hooks_before;
$GLOBALS['mock_shortcodes'] = $shortcodes_before;
$GLOBALS['mock_enqueued'] = [];
$GLOBALS['mock_is_admin'] = true;
(new ConsentBanner())->enqueue_editor_style();
$editor_assets = $GLOBALS['mock_enqueued'];
$GLOBALS['mock_enqueued'] = [];
$GLOBALS['mock_is_admin'] = false;
(new ConsentBanner())->enqueue_editor_style();
$front_assets_from_editor_hook = $GLOBALS['mock_enqueued'];
assert_test(
    'The block editor canvas gets the sheet without the script, and on the front end that hook adds nothing',
    $editor_hooked && 'wm-consent-vault' === ($editor_assets['style'][0][0] ?? '') && str_ends_with((string) ($editor_assets['style'][0][1] ?? ''), 'assets/css/wm-consent-vault.css')
        && [] === ($editor_assets['script'] ?? []) && [] === $front_assets_from_editor_hook,
    var_export([$editor_hooked, $editor_assets, $front_assets_from_editor_hook], true)
);
$placeholder_v2 =$blocker->filter_content_iframes('<iframe src="https://player.vimeo.com/video/1"></iframe>');
assert_test(
    'The placeholder carries the media category, a button that opens the dialog, no inline style and no h4',
    str_contains($placeholder_v2, 'data-wm-consent-category="media" data-wm-consent-service="vimeo"') && str_contains($placeholder_v2, 'data-wm-consent-open')
        && !str_contains($placeholder_v2, 'style=') && !str_contains($placeholder_v2, '<h4'),
    $placeholder_v2
);

// ------------------------------------------------------------
// Test 25: Front-end sheet
// ------------------------------------------------------------
echo "\n[TEST GROUP 25] Front-end sheet\n";
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/wm-consent-vault.css');
$css_hits = [];
$in_comment = false;
foreach (preg_split('/\R/', $css) as $i => $line) {
    $trim = trim($line);
    if ($in_comment || str_starts_with($trim, '/*')) {
        $in_comment = !str_contains($line, '*/');
        continue;
    }
    if (str_starts_with($trim, '--') || str_starts_with($trim, '@media')) {
        continue;
    }
    if (preg_match('/#[0-9a-f]{3,8}\b|rgba?\(|\b\d*\.?\d+(px|rem|em|ms)\b/i', $line)) {
        $css_hits[] = ($i + 1) . ': ' . $trim;
    }
}
assert_test(
    'Every colour, size and duration of the front-end sheet sits on a custom-property line',
    [] === $css_hits,
    implode(' | ', array_slice($css_hits, 0, 6))
);
preg_match_all('/--(wm-consent-[a-z0-9-]+):\s*(#[0-9a-f]{6})\s*;/i', $css, $tokens, PREG_SET_ORDER);
$palette = [];
foreach ($tokens as $token) {
    $palette[$token[1]] = $token[2];
}
$luminance = static function (string $hex): float {
    $rgb = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
    $lin = array_map(static fn($c) => ($c /= 255) <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);
    return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
};
$contrast = static function (string $a, string $b) use ($luminance): float {
    [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];
    return ($hi + 0.05) / ($lo + 0.05);
};
$pairs = [
    ['wm-consent-text', 'wm-consent-surface', 4.5], ['wm-consent-text-muted', 'wm-consent-surface', 4.5], ['wm-consent-link', 'wm-consent-surface', 4.5],
    ['wm-consent-text', 'wm-consent-surface-raised', 4.5], ['wm-consent-text-muted', 'wm-consent-surface-raised', 4.5], ['wm-consent-link', 'wm-consent-surface-raised', 4.5],
    ['wm-consent-choice-text', 'wm-consent-choice-bg', 4.5], ['wm-consent-accent-text', 'wm-consent-accent', 4.5],
    ['wm-consent-focus', 'wm-consent-surface', 3.0], ['wm-consent-border', 'wm-consent-surface', 3.0], ['wm-consent-border', 'wm-consent-surface-raised', 3.0],
];
$low = [];
foreach ($pairs as [$fg, $bg, $min]) {
    if (!isset($palette[$fg], $palette[$bg]) || $contrast($palette[$fg], $palette[$bg]) < $min) {
        $low[] = sprintf('%s/%s %.2f', $fg, $bg, isset($palette[$fg], $palette[$bg]) ? $contrast($palette[$fg], $palette[$bg]) : 0);
    }
}
assert_test(
    'Text pairs of the default palette reach 4.5:1, focus ring and control borders 3:1 (WCAG 2.2 formula)',
    [] === $low,
    implode(', ', $low)
);
assert_test(
    'Buttons, switches, links and summaries are at least 44 px targets',
    str_contains($css, '--wm-consent-target: 44px;') && substr_count($css, 'min-height: var(--wm-consent-target);') >= 6
        && str_contains($css, 'height: var(--wm-consent-target);') && 1 === preg_match('/\.wm-consent__button--choice\s*\{[^}]*background: var\(--wm-consent-choice-bg\)/', $css),
    'target sizes or the shared choice style missing'
);

// ------------------------------------------------------------
// Test 26: Banner script (source checks)
// ------------------------------------------------------------
echo "\n[TEST GROUP 26] Banner script\n";
$js = (string) file_get_contents($banner_js_path);
assert_test(
    'Opening moves focus into the dialog; closing returns it to the element that had it',
    str_contains($js, 'wmConsentDialogState.returnFocus = document.activeElement;') && str_contains($js, "dialog.focus();") && str_contains($js, 'target.focus();'),
    'focus handling missing'
);
assert_test(
    'Tab and Shift+Tab stay inside the open dialog',
    str_contains($js, "if (event.key !== 'Tab') {") && str_contains($js, "event.shiftKey && active === first") && str_contains($js, "!event.shiftKey && active === last"),
    'focus trap missing'
);
assert_test(
    'Escape closes only a dialog opened on purpose, never the first-visit dialog',
    str_contains($js, "} else if (wmConsentDialogState.mode === 'reopen') {"),
    'escape handling drifted'
);
assert_test(
    'A decision counts only for the current policy version and within the duration',
    str_contains($js, 'decision.policyVersion !== cfg.policyVersion') && str_contains($js, 'age < cfg.durationDays * 86400000'),
    'decision validity check missing'
);
assert_test(
    'Held-back external scripts run in order: each non-async one is awaited',
    str_contains($js, 'script.async = false;') && str_contains($js, "script.addEventListener('load', finish);") && str_contains($js, "blocked.getAttribute('data-wm-consent-src')"),
    'ordered activation missing'
);
assert_test(
    'Revoking a category deletes its services\' cookies and reloads the page',
    str_contains($js, 'wmConsentDeleteCookies(cookies);') && str_contains($js, 'location.reload();') && str_contains($js, "return category !== 'necessary' && categories.indexOf(category) === -1;"),
    'revocation missing'
);
assert_test(
    'The decision is sent with its categories and policy version and stored in the cookie PHP reads',
    str_contains($js, 'body.categories = ') && str_contains($js, 'body.policy_version = version;') && str_contains($js, "document.cookie = cfg.cookieName + '=' + value"),
    'record body or cookie missing'
);
assert_test(
    'Accepting external media loads the placeholders without a click',
    str_contains($js, "' .wm-2click-wrapper[data-wm-consent-category]'") && str_contains($js, 'wmLoadEmbed(node, false);'),
    'media activation missing'
);

// ------------------------------------------------------------
// Test 27: Settings and services screens
// ------------------------------------------------------------
echo "\n[TEST GROUP 27] Settings and services screens\n";
$reset_config();
$screen = new SettingsScreen();
$run_handler = static function (callable $handler, array $post, bool $can, bool $nonce): Mock_Halt {
    $_POST = $post;
    $GLOBALS['mock_can'] = $can;
    $GLOBALS['mock_nonce_valid'] = $nonce;
    try {
        $handler();
    } catch (Mock_Halt $halt) {
        return $halt;
    } finally {
        $_POST = [];
    }
    return new Mock_Halt('none', '');
};
$settings_post = [
    'texts'                 => ['heading' => 'Neue Überschrift', 'description' => "Zwei\nZeilen"],
    'privacy_url'           => 'https://example.test/privacy/',
    'imprint_url'           => '',
    'category_descriptions' => ['statistics' => 'Eigene Beschreibung'],
    'policy_version'        => '9',
    'duration_days'         => '180',
];
$no_cap   = $run_handler([$screen, 'handle_save_settings'], $settings_post, false, true);
$no_nonce = $run_handler([$screen, 'handle_save_settings'], $settings_post, true, false);
assert_test(
    'Saving settings without manage_options or without a valid nonce stops before anything is written',
    'die' === $no_cap->kind && 'nonce' === $no_nonce->kind && false === get_option(Settings::OPTION),
    $no_cap->kind . ' / ' . $no_nonce->kind
);
$saved_redirect = $run_handler([$screen, 'handle_save_settings'], $settings_post, true, true);
$after_save = Settings::get();
assert_test(
    'A valid form is stored and redirects with a notice; an unchecked blocking box switches blocking off',
    'redirect' === $saved_redirect->kind && str_contains($saved_redirect->target, 'wm_notice=settings_saved') && 'Neue Überschrift' === $after_save['texts']['heading']
        && '9' === $after_save['policy_version'] && 180 === $after_save['duration_days'] && false === $after_save['blocking_enabled']
        && 'Eigene Beschreibung' === Settings::category_description('statistics'),
    $saved_redirect->target . ' ' . var_export($after_save, true)
);
$partial = $run_handler([$screen, 'handle_save_settings'], ['policy_version' => 'zwei Worte', 'duration_days' => '0', 'blocking_enabled' => '1'], true, true);
assert_test(
    'Fields that fail validation keep their value and are named in the notice',
    str_contains($partial->target, 'wm_notice=settings_partial') && str_contains($partial->target, 'fields=policy_version%2Cduration_days') && '9' === Settings::policy_version() && true === Settings::blocking_enabled(),
    $partial->target
);
$bump = $run_handler([$screen, 'handle_bump_policy'], [], true, true);
assert_test(
    'Raising the policy version from the screen asks every visitor again',
    str_contains($bump->target, 'wm_notice=policy_bumped') && str_contains($bump->target, 'version=10') && '10' === Settings::policy_version(),
    $bump->target
);
$GLOBALS['mock_transients'] = [];
$bad_service = $run_handler([$screen, 'handle_save_service'], ['name' => 'Ohne Muster', 'category' => 'marketing', 'hosts' => ''], true, true);
assert_test(
    'An invalid service is not stored, the notice names the field and the form comes back filled in',
    str_contains($bad_service->target, 'wm_notice=service_invalid') && str_contains($bad_service->target, 'fields=hosts') && [] === ServiceRegistry::stored()
        && 'Ohne Muster' === ($GLOBALS['mock_transients'][SettingsScreen::DRAFT_TRANSIENT . '1']['name'] ?? ''),
    $bad_service->target
);
$good_service = $run_handler([$screen, 'handle_save_service'], ['name' => 'Chat-Widget', 'category' => 'marketing', 'hosts' => "chat.example.net\nwidget.example.net/loader.js", 'cookies' => 'chat_*'], true, true);
$edited_service = $run_handler([$screen, 'handle_save_service'], ['original_id' => 'chat-widget', 'id' => 'chat-widget', 'name' => 'Chat-Widget', 'category' => 'statistics', 'hosts' => 'chat.example.net'], true, true);
assert_test(
    'A valid service is added, then edited in place',
    str_contains($good_service->target, 'wm_notice=service_added') && str_contains($edited_service->target, 'wm_notice=service_updated')
        && 1 === count(ServiceRegistry::stored()) && 'statistics' === ServiceRegistry::find_stored('chat-widget')['category']
        && !isset($GLOBALS['mock_transients'][SettingsScreen::DRAFT_TRANSIENT . '1']),
    $good_service->target . ' ' . $edited_service->target
);
$guard_gaps = [];
foreach (['handle_save_settings', 'handle_bump_policy', 'handle_save_service', 'handle_delete_service', 'handle_add_preset'] as $handler_name) {
    $options_before = [get_option(Settings::OPTION), get_option(ServiceRegistry::OPTION)];
    if ('die' !== $run_handler([$screen, $handler_name], ['preset' => 'vimeo', 'id' => 'chat-widget', 'name' => 'X', 'category' => 'marketing', 'hosts' => 'x.example.net'], false, true)->kind) {
        $guard_gaps[] = $handler_name . ' without capability';
    }
    if ('nonce' !== $run_handler([$screen, $handler_name], ['preset' => 'vimeo', 'id' => 'chat-widget', 'name' => 'X', 'category' => 'marketing', 'hosts' => 'x.example.net'], true, false)->kind) {
        $guard_gaps[] = $handler_name . ' without nonce';
    }
    if ($options_before !== [get_option(Settings::OPTION), get_option(ServiceRegistry::OPTION)]) {
        $guard_gaps[] = $handler_name . ' wrote before its checks';
    }
}
assert_test(
    'Every form handler checks manage_options and its own nonce before it writes',
    [] === $guard_gaps,
    implode(', ', $guard_gaps)
);
$preset_added  = $run_handler([$screen, 'handle_add_preset'], ['preset' => 'hotjar'], true, true);
$preset_again  = $run_handler([$screen, 'handle_add_preset'], ['preset' => 'hotjar'], true, true);
$deleted       = $run_handler([$screen, 'handle_delete_service'], ['id' => 'chat-widget'], true, true);
$deleted_again = $run_handler([$screen, 'handle_delete_service'], ['id' => 'chat-widget'], true, true);
$preset_nonce  = $run_handler([$screen, 'handle_add_preset'], ['preset' => 'vimeo'], true, false);
$delete_cap    = $run_handler([$screen, 'handle_delete_service'], ['id' => 'hotjar'], false, true);
assert_test(
    'Presets are added once, services deleted once; the preset and delete handlers check nonce and capability too',
    str_contains($preset_added->target, 'preset_added') && str_contains($preset_again->target, 'preset_exists') && str_contains($deleted->target, 'service_deleted')
        && str_contains($deleted_again->target, 'service_missing') && 'nonce' === $preset_nonce->kind && 'die' === $delete_cap->kind
        && null === ServiceRegistry::find_stored('vimeo') && null !== ServiceRegistry::find_stored('hotjar'),
    implode(' | ', [$preset_added->target, $preset_again->target, $deleted->target, $deleted_again->target, $preset_nonce->kind, $delete_cap->kind])
);
$GLOBALS['mock_nonce_valid'] = true;
$GLOBALS['mock_can'] = false;
ob_start();
$screen->render_settings_tab();
$settings_html = (string) ob_get_clean();
ob_start();
$_GET['edit'] = 'hotjar';
$screen->render_services_tab();
unset($_GET['edit']);
$services_html = (string) ob_get_clean();
preg_match_all('/<label class="wm-(?:label|toggle-label)" for="([^"]+)"/', $settings_html . $services_html, $label_for);
$unlabelled = array_filter($label_for[1], static fn($id) => !str_contains($settings_html . $services_html, 'id="' . $id . '"'));
assert_test(
    'The settings and services forms post to admin-post with their action and nonce, and every label points at a field',
    str_contains($settings_html, 'name="action" value="wm_consent_save_settings"') && str_contains($settings_html, 'name="action" value="wm_consent_bump_policy"')
        && str_contains($services_html, 'name="action" value="wm_consent_save_service"') && str_contains($services_html, 'name="action" value="wm_consent_add_preset"')
        && substr_count($settings_html . $services_html, 'name="_wpnonce"') >= 4 && count($label_for[1]) >= 20 && [] === $unlabelled,
    implode(', ', $unlabelled)
);
assert_test(
    'The services screen edits the chosen entry, lists all ten presets and the developer API',
    str_contains($services_html, 'name="original_id" value="hotjar"') && 9 === substr_count($services_html, 'name="action" value="wm_consent_add_preset"')
        && str_contains($services_html, 'Microsoft Clarity') && str_contains($services_html, 'wm_consent_script_handles') && str_contains($services_html, 'wm_consent_decision_recorded'),
    substr($services_html, 0, 400)
);
assert_test(
    'Neither screen uses inline styles, and the settings screen names the neutral defaults',
    !str_contains($settings_html . $services_html, 'style=') && str_contains($settings_html, 'Standard: Datenschutz-Einstellungen'),
    'inline style found or defaults missing'
);
$dashboard_code = $code_only(dirname(__DIR__) . '/includes/Admin/AdminDashboard.php');
assert_test(
    'The admin screen has the Einstellungen and Dienste tabs and no longer says the decision gates nothing',
    str_contains($dashboard_code, "'settings' => [ 'label' => __( 'Einstellungen'") && str_contains($dashboard_code, "'services' => [ 'label' => __( 'Dienste'")
        && str_contains($dashboard_code, '$settings_screen->render_settings_tab();') && str_contains($dashboard_code, '$settings_screen->render_services_tab();')
        && !str_contains($dashboard_code, 'Die gespeicherte Entscheidung selbst schaltet nichts') && !str_contains($dashboard_code, 'style='),
    'tabs or wording missing'
);
assert_test(
    'Notices exist for every code the handlers redirect with',
    null !== SettingsScreen::notice('settings_saved') && null !== SettingsScreen::notice('settings_partial', 'policy_version') && null !== SettingsScreen::notice('policy_bumped', '', '2')
        && null !== SettingsScreen::notice('service_added') && null !== SettingsScreen::notice('service_updated') && null !== SettingsScreen::notice('service_invalid', 'hosts')
        && null !== SettingsScreen::notice('service_deleted') && null !== SettingsScreen::notice('service_missing') && null !== SettingsScreen::notice('preset_added')
        && null !== SettingsScreen::notice('preset_exists') && null !== SettingsScreen::notice('preset_unknown') && null === SettingsScreen::notice('bogus')
        && str_contains(SettingsScreen::notice('service_invalid', 'hosts')['text'], 'Host- oder Dateimuster'),
    'a notice code has no text'
);

// ------------------------------------------------------------
// Test 28: Hub telemetry, SBOM licence, uninstall of the new options
// ------------------------------------------------------------
echo "\n[TEST GROUP 28] Telemetry, SBOM and uninstall\n";
$reset_config();
$reset_vault();
ServiceRegistry::add_preset('youtube');
Settings::save(['policy_version' => '3']);
$engine->append_record('uuid-tele-0001', '203.0.113.95', true, true, false, 'UA', ['statistics', 'media'], '3');
$engine->append_record('uuid-tele-0002', '203.0.113.96', true, false, true, 'UA', ['marketing'], '2');
$tele_v2 = $adapter->get_telemetry_metrics();
assert_test(
    'Telemetry reports decisions per category, the current policy version and how many decisions were given for it',
    ['statistics' => 1, 'marketing' => 1, 'media' => 1] === $tele_v2['consents_by_category'] && '3' === $tele_v2['policy_version'] && 1 === $tele_v2['consents_current_policy'],
    var_export($tele_v2, true)
);
assert_test(
    'Telemetry reports the signature formats of an intact chain, the blocking switch and the registered services',
    [1 => 0, 2 => 2] === $tele_v2['records_by_signature_version'] && true === $tele_v2['blocking_enabled'] && 1 === $tele_v2['services_registered'],
    var_export($tele_v2, true)
);
assert_test(
    'The SBOM names the licence the plugin header carries',
    'GPL-2.0-or-later' === ($adapter->get_sbom_data()['licenses'][0]['license']['id'] ?? ''),
    json_encode($adapter->get_sbom_data()['licenses'])
);
update_option('wm_consent_settings', ['policy_version' => '3']);
update_option('wm_consent_services', [['id' => 'x']]);
update_option('wm_consent_delete_data_on_uninstall', '1');
$wpdb->queries = [];
(static function (): void {
    global $wpdb;
    require dirname(__DIR__) . '/uninstall.php';
})();
assert_test(
    'Uninstall deletes the settings, the service registry and the drafts of the service form',
    false === get_option('wm_consent_settings') && false === get_option('wm_consent_services')
        && 1 === count(array_filter($wpdb->queries, static fn($q) => str_contains(str_replace('\\', '', $q), '_transient_wm_consent_service_draft_') && str_starts_with($q, 'DELETE'))),
    'queries: ' . implode(' | ', $wpdb->queries)
);

// ------------------------------------------------------------
// Test 29: Nonce rate-limiting, opt-in uninstall setting, Block Bindings, GenerateBlocks & wm_consent_ready
// ------------------------------------------------------------
echo "\n[TEST GROUP 29] Nonce rate limit, opt-in uninstall UI and ecosystem bridges\n";
require_once dirname(__DIR__) . '/includes/Core/Plugin.php';
use WMCyber\Consent\Vault\Core\Plugin;
use WMCyber\Consent\Vault\Frontend\EcosystemIntegration;

$reset_config();
$GLOBALS['rest_routes'] = [];
$nonce_controller = new RestController();
$nonce_controller->register_routes();
$nonce_perm_cb = $GLOBALS['rest_routes']['wm-consent/v1/nonce']['permission_callback'] ?? null;
assert_test(
    '/nonce route uses check_public_nonce_access instead of __return_true',
    is_array($nonce_perm_cb) && 'check_public_nonce_access' === ($nonce_perm_cb[1] ?? ''),
    var_export($nonce_perm_cb, true)
);

$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_can'] = false;
$_SERVER['REMOTE_ADDR'] = '198.51.100.88';
$GLOBALS['mock_filter_callbacks']['wm_consent_rest_rate_limit'][] = static fn(int $limit, string $route) => 'nonce' === $route ? 3 : $limit;
$nonce_req = new WP_REST_Request('GET', '/wm-consent/v1/nonce');
$nonce_ok = 0;
$nonce_limited = null;
for ($i = 0; $i < 5; $i++) {
    $res_perm = $nonce_controller->check_public_nonce_access($nonce_req);
    if (true === $res_perm) {
        $nonce_ok++;
    } else {
        $nonce_limited = $res_perm;
        break;
    }
}
$raw_ip_stored = array_filter(
    array_keys($GLOBALS['mock_transients']),
    static fn(string $k): bool => str_contains($k, '198.51.100.88')
);
assert_test(
    'check_public_nonce_access enforces wm_consent_rest_rate_limit (429 when exceeded) and never stores raw IPs',
    3 === $nonce_ok
        && $nonce_limited instanceof WP_Error
        && 'wm_consent_rate_limited' === $nonce_limited->code
        && 429 === (int) ($nonce_limited->data['status'] ?? 0)
        && [] === $raw_ip_stored,
    sprintf('ok=%d limited=%s', $nonce_ok, var_export($nonce_limited, true))
);

$GLOBALS['mock_can'] = true;
$admin_bypass = $nonce_controller->check_public_nonce_access($nonce_req);
$GLOBALS['mock_can'] = false;
unset($GLOBALS['mock_filter_callbacks']['wm_consent_rest_rate_limit']);
assert_test(
    'check_public_nonce_access bypasses the rate limit for administrators (manage_options)',
    true === $admin_bypass,
    var_export($admin_bypass, true)
);

$GLOBALS['mock_registered_settings'] = [];
$screen->register_settings();
$saved_opt_in = $run_handler(
    [$screen, 'handle_save_settings'],
    ['policy_version' => '4', 'duration_days' => '365', 'blocking_enabled' => '1', 'delete_data_on_uninstall' => '1'],
    true,
    true
);
$opt_in_after_on = Settings::delete_data_on_uninstall();
$run_handler(
    [$screen, 'handle_save_settings'],
    ['policy_version' => '4', 'duration_days' => '365', 'blocking_enabled' => '1'],
    true,
    true
);
$opt_in_after_off = Settings::delete_data_on_uninstall();
assert_test(
    'SettingsScreen registers wm_consent_delete_data_on_uninstall and saves both checked and unchecked states',
    isset($GLOBALS['mock_registered_settings'][Settings::DELETE_DATA_OPTION])
        && 'redirect' === $saved_opt_in->kind
        && true === $opt_in_after_on
        && false === $opt_in_after_off
        && str_contains($settings_html, 'name="delete_data_on_uninstall"'),
    var_export([$GLOBALS['mock_registered_settings'], $opt_in_after_on, $opt_in_after_off], true)
);

$GLOBALS['mock_block_bindings'] = [];
$GLOBALS['mock_gb_dynamic_tags'] = [];
ServiceRegistry::add_preset('matomo');
Settings::save(['policy_version' => '12']);
$eco = new EcosystemIntegration(new ServiceRegistry());
$eco->register_block_bindings();
$eco->register_generateblocks_tags();
$_COOKIE['wm_consent'] = '12|necessary,statistics|' . (time() - 30);
$binding_summary  = $eco->resolve_binding_value([]);
$binding_version  = $eco->resolve_binding_value(['field' => 'policy_version']);
$binding_services = $eco->resolve_binding_value(['field' => 'services_count']);
$binding_granted  = $eco->resolve_binding_value(['category' => 'statistics']);
$binding_blocked  = $eco->resolve_binding_value(['category' => 'marketing']);
$gb_rendered      = $eco->filter_generateblocks_tag('fallback', ['tag' => 'wm_consent_status'], ['field' => 'policy_version']);
$gb_passthrough   = $eco->filter_generateblocks_tag('keep-me', ['tag' => 'other_tag'], []);
unset($_COOKIE['wm_consent']);
assert_test(
    'WP 6.5+ Block Bindings (wm-consent/status) and GenerateBlocks 2.x Dynamic Tag ({{wm_consent_status}}) register and resolve values',
    isset($GLOBALS['mock_block_bindings']['wm-consent/status'], $GLOBALS['mock_gb_dynamic_tags']['wm_consent_status'])
        && 'Richtlinie v12' === $binding_summary
        && '12' === $binding_version
        && '1' === $binding_services
        && 'Freigegeben' === $binding_granted
        && 'Blockiert' === $binding_blocked
        && '12' === $gb_rendered
        && 'keep-me' === $gb_passthrough,
    var_export([$binding_summary, $binding_version, $binding_services, $binding_granted, $binding_blocked, $gb_rendered, $gb_passthrough], true)
);

$GLOBALS['mock_actions_fired'] = [];
Plugin::instance()->boot();
$ready_fired = array_values(array_filter($GLOBALS['mock_actions_fired'], static fn($a) => 'wm_consent_ready' === $a[0]));
assert_test(
    'Plugin::boot() fires the wm_consent_ready extensibility action',
    1 === count($ready_fired) && $ready_fired[0][1][0] instanceof Plugin,
    var_export($ready_fired, true)
);

$wpdb->journal = [];
$cache_probe_engine = new MerkleTreeEngine();
$cache_probe_res    = $cache_probe_engine->append_record('uuid-cache-evict-probe-1', '198.51.100.44', true, true, false, 'Mozilla/5.0');
$lock_pos           = array_search('GET_LOCK', $wpdb->journal, true);
$evict_pos          = array_search('cache_delete:options:wm_consent_chain_tip', $wpdb->journal, true);
$release_pos        = array_search('RELEASE_LOCK', $wpdb->journal, true);
assert_test(
    'MerkleTreeEngine::append_record() evicts cached wm_consent_chain_tip inside GET_LOCK so concurrent workers never read a stale tip',
    empty($cache_probe_res['error'])
        && is_int($lock_pos)
        && is_int($evict_pos)
        && is_int($release_pos)
        && $lock_pos < $evict_pos
        && $evict_pos < $release_pos,
    var_export($wpdb->journal, true)
);

$GLOBALS['mock_deactivated_plugins'] = [];
$prereq_died = '';
try {
    \WMCyber\Consent\Vault\Database\Schema::enforce_prerequisites(static fn(string $ext): bool => 'mbstring' !== $ext);
} catch (Mock_Halt $e) {
    $prereq_died = $e->target;
}
assert_test(
    'Schema::enforce_prerequisites() deactivates cleanly with wp_die() when mbstring is missing',
    str_contains($prereq_died, 'mbstring') && !empty($GLOBALS['mock_deactivated_plugins']),
    $prereq_died
);

// ------------------------------------------------------------
// Test 30: Jitter-backoff GET_LOCK + 503 Retry-After: 2 + Multisite wp_initialize_site (R2-B6 / R2-B8)
// ------------------------------------------------------------
echo "\n[TEST GROUP 30] Jittered lock backoff, 503 Retry-After and Multisite wp_initialize_site\n";
$reset_vault();
$wpdb->journal = [];
$wpdb->lock_timeouts = [];
$wpdb->lock_granted = true;
$wpdb->lock_fail_count = 2;
$GLOBALS['mock_filters_applied'] = [];
$jitter_ok = $engine->append_record('uuid-jitter-ok-1', '198.51.100.11', true, true, false, 'UA/jitter');
assert_test(
    'MerkleTreeEngine::append_record() retries non-blocking GET_LOCK(..., 0) with jittered backoff and succeeds when contention clears',
    empty($jitter_ok['error'])
        && [0, 0, 0] === $wpdb->lock_timeouts
        && in_array('wm_consent_lock_backoff_enabled', $GLOBALS['mock_filters_applied'], true),
    var_export([$jitter_ok, $wpdb->lock_timeouts], true)
);

$wpdb->lock_timeouts = [];
$wpdb->lock_fail_count = 2;
$anchor_jitter_ok = $engine->append_anchor('cert-jitter-1', hash('sha256', 'anchor-jitter'));
assert_test(
    'MerkleTreeEngine::append_anchor() retries non-blocking GET_LOCK(..., 0) with jittered backoff when contention clears',
    empty($anchor_jitter_ok['error']) && [0, 0, 0] === $wpdb->lock_timeouts,
    var_export([$anchor_jitter_ok, $wpdb->lock_timeouts], true)
);

$wpdb->lock_timeouts = [];
$wpdb->lock_fail_count = 0;
$wpdb->lock_granted = false;
$req_busy = new WP_REST_Request('POST', '/wm-consent/v1/record');
$req_busy->set_param('uuid', 'uuid-busy-503-1');
$req_busy->set_param('essential', true);
$req_busy->set_param('analytics', false);
$req_busy->set_param('marketing', false);
$t_busy_start = microtime(true);
$res_busy = (new RestController())->handle_record_consent($req_busy);
$t_busy_elapsed = microtime(true) - $t_busy_start;
$wpdb->lock_granted = true;
assert_test(
    'Contended /record retries 8 non-blocking GET_LOCK(..., 0) attempts under 1.5s and returns 503 with Retry-After: 2',
    503 === $res_busy->get_status()
        && '2' === ($res_busy->get_headers()['Retry-After'] ?? '')
        && 'consent chain busy, retry' === ($res_busy->get_data()['error'] ?? '')
        && 8 === count($wpdb->lock_timeouts)
        && array_fill(0, 8, 0) === $wpdb->lock_timeouts
        && $t_busy_elapsed < 1.5,
    var_export([$res_busy->get_status(), $res_busy->get_headers(), $res_busy->get_data(), $wpdb->lock_timeouts, $t_busy_elapsed], true)
);

$GLOBALS['mock_filter_callbacks']['wm_consent_lock_backoff_enabled'][] = static fn(): bool => false;
$wpdb->lock_timeouts = [];
$wpdb->lock_granted = false;
$fast_busy = $engine->append_record('uuid-nobackoff-1', '198.51.100.12', true, false, false, 'UA/fast');
$wpdb->lock_granted = true;
unset($GLOBALS['mock_filter_callbacks']['wm_consent_lock_backoff_enabled']);
assert_test(
    'wm_consent_lock_backoff_enabled filter returning false disables jittered retries (single GET_LOCK(..., 0) attempt)',
    'chain_busy' === ($fast_busy['error'] ?? '') && [0] === $wpdb->lock_timeouts,
    var_export([$fast_busy, $wpdb->lock_timeouts], true)
);

$GLOBALS['mock_activation_hook'] = null;
unset($GLOBALS['mock_hooks']['wp_initialize_site']);
require_once dirname(__DIR__) . '/wm-sovereign-consent-vault.php';
$init_site_hooks = $GLOBALS['mock_hooks']['wp_initialize_site'] ?? [];
$init_site_cb = $init_site_hooks[0][0] ?? null;
assert_test(
    'Plugin entrypoint registers wp_initialize_site hook for Multisite provisioning',
    is_callable($init_site_cb),
    var_export($init_site_hooks, true)
);

$plugin_key = plugin_basename(WM_CONSENT_VAULT_FILE);
$GLOBALS['mock_is_multisite'] = true;
$GLOBALS['mock_network_active'] = [$plugin_key => false];
$GLOBALS['mock_blog_switches'] = [];
$GLOBALS['mock_blog_options'] = [];
$GLOBALS['wm_dbdelta_sqls'] = [];
if (is_callable($init_site_cb)) {
    $init_site_cb(new WP_Site(7));
}
assert_test(
    'wp_initialize_site skips provisioning when plugin is not network-activated',
    [] === $GLOBALS['mock_blog_switches']
        && [] === $GLOBALS['wm_dbdelta_sqls']
        && !isset($GLOBALS['mock_blog_options'][7][Schema::VERSION_OPTION]),
    var_export([$GLOBALS['mock_blog_switches'], $GLOBALS['wm_dbdelta_sqls'], $GLOBALS['mock_blog_options']], true)
);

$GLOBALS['mock_network_active'] = [$plugin_key => true];
$GLOBALS['mock_blog_switches'] = [];
$GLOBALS['mock_blog_options'] = [];
$GLOBALS['wm_dbdelta_sqls'] = [];
if (is_callable($init_site_cb)) {
    $init_site_cb(new WP_Site(7));
}
$sql_blob_7 = implode("\n", $GLOBALS['wm_dbdelta_sqls']);
$prefix_restored = ('wp_' === $wpdb->prefix && 1 === $GLOBALS['mock_current_blog_id']);
$wpdb->prefix = 'wp_';
$GLOBALS['mock_current_blog_id'] = 1;
assert_test(
    'wp_initialize_site switches to the new blog, creates both wp_<id>_ tables, writes DB version and restores current blog when network-activated',
    $prefix_restored
        && [['switch', 7], ['restore', 1]] === $GLOBALS['mock_blog_switches']
        && str_contains($sql_blob_7, 'CREATE TABLE wp_7_wm_consent_records')
        && str_contains($sql_blob_7, 'CREATE TABLE wp_7_wm_consent_merkle_nodes')
        && Schema::VERSION === ($GLOBALS['mock_blog_options'][7][Schema::VERSION_OPTION] ?? null),
    var_export([$prefix_restored, $GLOBALS['mock_blog_switches'], $GLOBALS['mock_blog_options'], $sql_blob_7], true)
);

$GLOBALS['mock_sites'] = [1, 8, 9];
$GLOBALS['mock_blog_switches'] = [];
$GLOBALS['mock_blog_options'] = [];
$GLOBALS['wm_dbdelta_sqls'] = [];
if (is_callable($GLOBALS['mock_activation_hook'])) {
    ($GLOBALS['mock_activation_hook'])(true);
}
$sql_blob_net = implode("\n", $GLOBALS['wm_dbdelta_sqls']);
$net_prefix_restored = ('wp_' === $wpdb->prefix && 1 === $GLOBALS['mock_current_blog_id']);
$wpdb->prefix = 'wp_';
$GLOBALS['mock_current_blog_id'] = 1;
assert_test(
    'Network-wide activation provisions consent tables and schema version across all Multisite blogs',
    $net_prefix_restored
        && str_contains($sql_blob_net, 'CREATE TABLE wp_wm_consent_records')
        && str_contains($sql_blob_net, 'CREATE TABLE wp_8_wm_consent_records')
        && str_contains($sql_blob_net, 'CREATE TABLE wp_9_wm_consent_records')
        && Schema::VERSION === ($GLOBALS['mock_blog_options'][8][Schema::VERSION_OPTION] ?? null)
        && Schema::VERSION === ($GLOBALS['mock_blog_options'][9][Schema::VERSION_OPTION] ?? null),
    var_export([$net_prefix_restored, $GLOBALS['mock_blog_switches'], $GLOBALS['mock_blog_options']], true)
);
$GLOBALS['mock_is_multisite'] = false;

echo "\n============================================================\n";
echo " TEST RESULTS: {$passed_count} PASSED | {$failed_count} FAILED\n";
echo "============================================================\n\n";

exit($failed_count === 0 ? 0 : 1);
