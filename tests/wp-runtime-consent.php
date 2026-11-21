<?php
/**
 * Read-only runtime check for wm-sovereign-consent-vault inside a real WordPress.
 *
 *   wp eval-file wp-content/plugins/wm-sovereign-consent-vault/tests/wp-runtime-consent.php --user=1
 *
 * Safe on a live site: it writes nothing. The plugin's options, its rate-limit buckets and the row counts
 * of both tables are compared before and after; /record and /nonce are never called (the record route
 * writes, and the rate limit writes a transient); the banner, the placeholder filter and the chain
 * verification run in memory. What it checks that the standalone suite cannot: that the real MySQL
 * queries run, that the routes are registered in this install, and what the deployed files actually say.
 *
 * @package WMCyber\Consent\Vault
 */

defined( 'ABSPATH' ) || exit;

use WMCyber\Consent\Vault\Blocker\OutputBlocker;
use WMCyber\Consent\Vault\Blocker\ScriptIframeBlocker;
use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Crypto\MerkleTreeEngine;
use WMCyber\Consent\Vault\Database\Schema;
use WMCyber\Consent\Vault\Frontend\ConsentBanner;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

global $wpdb;

$GLOBALS['wm_cv_pass'] = 0;
$GLOBALS['wm_cv_fail'] = 0;

/**
 * Report one check.
 *
 * @param string $label Description.
 * @param bool   $ok    Result.
 * @param string $note  Measured value.
 * @return void
 */
function wm_cv_check( $label, $ok, $note = '' ) {
	if ( $ok ) {
		++$GLOBALS['wm_cv_pass'];
		echo "  [PASS] {$label}\n";
		return;
	}
	++$GLOBALS['wm_cv_fail'];
	echo "  [FAIL] {$label}" . ( '' !== $note ? ": {$note}" : '' ) . "\n";
}

$records = Schema::get_records_table();
$merkle  = Schema::get_merkle_table();

/**
 * Snapshot of everything the plugin owns in the database.
 *
 * @return array
 */
function wm_cv_state() {
	global $wpdb;
	$records = Schema::get_records_table();
	$merkle  = Schema::get_merkle_table();
	$state   = [];
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$state['options'] = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name',
			$wpdb->options,
			$wpdb->esc_like( 'wm_consent' ) . '%',
			$wpdb->esc_like( '_transient_wm_cv_' ) . '%'
		),
		ARRAY_A
	);
	foreach ( [ $records, $merkle ] as $table ) {
		$state[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) )
			: null;
	}
	// phpcs:enable
	return $state;
}

$before = wm_cv_state();

echo "\n=== WM SOVEREIGN CONSENT VAULT — RUNTIME CHECK ===\n\n";

// 1-2. Tables and schema version.
wm_cv_check( 'both consent tables exist', null !== $before[ $records ] && null !== $before[ $merkle ], wp_json_encode( [ $before[ $records ], $before[ $merkle ] ] ) );
wm_cv_check( 'the schema version option is the current one', Schema::VERSION === (string) get_option( Schema::VERSION_OPTION, '' ), 'stored: ' . (string) get_option( Schema::VERSION_OPTION, '(none)' ) );

// 2b. The columns the categories and the signature format live in, as this install holds them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$columns = [];
foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $records ) ) as $column ) {
	$columns[ (string) $column->Field ] = (string) $column->Default;
}
// phpcs:enable
wm_cv_check(
	'the records table carries categories, policy_version and signature_version, and older rows default to format 1',
	array_key_exists( 'categories', $columns ) && array_key_exists( 'policy_version', $columns ) && '1' === ( $columns['signature_version'] ?? '' ),
	wp_json_encode( array_intersect_key( $columns, array_flip( [ 'categories', 'policy_version', 'signature_version' ] ) ) )
);

// 3. Routes, with the argument schema that keeps "false" from meaning true.
$routes      = rest_get_server()->get_routes();
$record_args = (array) ( $routes['/wm-consent/v1/record'][0]['args'] ?? [] );
wm_cv_check( 'the three routes are registered', isset( $routes['/wm-consent/v1/nonce'], $routes['/wm-consent/v1/record'], $routes['/wm-consent/v1/verify-merkle'] ) );
wm_cv_check(
	'the record route declares its flags as booleans',
	'boolean' === ( $record_args['analytics']['type'] ?? '' ) && 'boolean' === ( $record_args['marketing']['type'] ?? '' ) && 'boolean' === ( $record_args['essential']['type'] ?? '' ),
	wp_json_encode( array_map( static fn( $a ) => $a['type'] ?? '?', $record_args ) )
);
wm_cv_check(
	'the record route accepts the categories and the policy version of a decision',
	'array' === ( $record_args['categories']['type'] ?? '' ) && Categories::SLUGS === ( $record_args['categories']['items']['enum'] ?? null ) && 'string' === ( $record_args['policy_version']['type'] ?? '' ),
	wp_json_encode( $record_args['categories'] ?? null )
);

// 4. A request without the REST nonce is refused before anything is written.
$no_nonce = ( new \WMCyber\Consent\Vault\API\RestController() )->check_record_permission( new WP_REST_Request( 'POST', '/wm-consent/v1/record' ) );
wm_cv_check( 'a record request without the REST token is refused with 403', is_wp_error( $no_nonce ) && 403 === (int) ( $no_nonce->get_error_data()['status'] ?? 0 ) );

// 5-7. The chain as this install holds it. A failing chain is a finding, not a crash: the check reports
// what verification says and whether the tip is signed.
$audit = ( new MerkleTreeEngine() )->verify_chain_integrity();
$tip   = get_option( MerkleTreeEngine::TIP_OPTION, null );
echo '  [INFO] chain: ' . (string) ( $audit['status'] ?? '?' ) . ( isset( $audit['error'] ) ? ' (' . $audit['error'] . ')' : '' ) . ', nodes: ' . (string) ( $audit['total_nodes'] ?? '?' ) . ", key source: " . (string) ( $audit['key_source'] ?? '?' ) . "\n";
wm_cv_check( 'verification answers with a status this plugin defines', in_array( $audit['status'] ?? '', [ 'INTACT', 'EMPTY_CHAIN', 'TAMPERED' ], true ), wp_json_encode( $audit ) );
wm_cv_check(
	'a chain with nodes carries a signed tip',
	null === $tip || 0 === (int) ( $audit['total_nodes'] ?? 0 ) || ( is_array( $tip ) && isset( $tip['sig'] ) ),
	'tip: ' . wp_json_encode( is_array( $tip ) ? array_keys( $tip ) : $tip )
);

// 8-10. What visitors get.
$placeholder = ( new ScriptIframeBlocker() )->filter_content_iframes( '<iframe src="//www.youtube.com/embed/probe" title="Probe" height="315"></iframe>' );
wm_cv_check( 'a protocol-relative embed is replaced by the placeholder', ! str_contains( $placeholder, '<iframe' ) && str_contains( $placeholder, 'data-wm-embed-src="https://www.youtube.com/embed/probe"' ), substr( $placeholder, 0, 120 ) );
wm_cv_check( 'the placeholder keeps the embed title and claims no compliance with a statute', str_contains( $placeholder, 'data-wm-embed-title="Probe"' ) && ! str_contains( $placeholder, 'TDDDG' ), substr( $placeholder, 0, 160 ) );

ob_start();
( new ConsentBanner() )->render_banner();
$banner = (string) ob_get_clean();
$banner_js = (string) file_get_contents( WM_CONSENT_VAULT_PATH . 'assets/js/wm-consent-vault.js' );
wm_cv_check(
	'the banner describes the stored data as it is and its script resends an unconfirmed decision',
	str_contains( $banner, 'im Klartext' ) && str_contains( $banner_js, 'wm_consent_recorded' ),
	substr( $banner, 0, 160 )
);
wm_cv_check(
	'the banner is a modal dialog with a name, a description and a switch per category',
	str_contains( $banner, 'role="dialog" aria-modal="true"' ) && str_contains( $banner, 'aria-labelledby="wm-consent-vault-title"' ) && 4 === substr_count( $banner, 'role="switch"' ),
	substr( $banner, 0, 300 )
);
$config_json = [];
if ( 1 === preg_match( '/<script type="application\/json" id="wm-consent-config">(.*?)<\/script>/s', $banner, $config_match ) ) {
	$config_json = json_decode( $config_match[1], true );
}
wm_cv_check(
	'the script gets the policy version, the duration and the routes of this install',
	is_array( $config_json ) && Settings::policy_version() === ( $config_json['policyVersion'] ?? null ) && Settings::duration_days() === ( $config_json['durationDays'] ?? null )
		&& str_contains( (string) ( $config_json['recordUrl'] ?? '' ), 'wm-consent/v1/record' ),
	wp_json_encode( $config_json )
);

// 10b. What the blocker writes on a page of this install: a registered service, the developer markup and an embed.
$probe_services = ( new ServiceRegistry() )->all();
$probe_html     = ( new OutputBlocker() )->rewrite_html(
	'<script data-wm-consent="marketing">window.probe = 1;</script>'
	. '<script src="/wp-includes/js/wp-embed.min.js" id="wp-embed-js"></script>'
	. '<iframe src="https://www.youtube.com/embed/probe-page" title="Probe"></iframe>'
);
wm_cv_check(
	'the blocker holds back marked scripts and embeds anywhere on the page and leaves the rest alone',
	str_contains( $probe_html, '<script type="text/plain" data-wm-consent-category="marketing">window.probe = 1;</script>' )
		&& str_contains( $probe_html, 'data-wm-embed-src="https://www.youtube.com/embed/probe-page"' ) && ! str_contains( $probe_html, '<iframe' )
		&& str_contains( $probe_html, '<script src="/wp-includes/js/wp-embed.min.js" id="wp-embed-js"></script>' ),
	substr( $probe_html, 0, 300 )
);
echo '  [INFO] registered services: ' . count( $probe_services ) . ', blocking ' . ( Settings::blocking_enabled() ? 'on' : 'off' ) . ', policy version ' . Settings::policy_version() . ", consent valid for " . Settings::duration_days() . " days\n";
wm_cv_check(
	'wm_consent_has() is available to themes and grants the necessary category without a cookie',
	function_exists( 'wm_consent_has' ) && true === wm_consent_has( 'necessary' ) && false === wm_consent_has( 'tracking' ),
	'wm_consent_has missing or too generous'
);

// 10c. The admin screens as WordPress renders them for a user who may see them.
$admin_screen = new \WMCyber\Consent\Vault\Admin\SettingsScreen();
ob_start();
$admin_screen->render_settings_tab();
$settings_html = (string) ob_get_clean();
ob_start();
$admin_screen->render_services_tab();
$services_html = (string) ob_get_clean();
wm_cv_check(
	'the settings screen renders its form with the admin-post action, a nonce and the policy version of this install',
	str_contains( $settings_html, 'name="action" value="wm_consent_save_settings"' ) && str_contains( $settings_html, 'name="_wpnonce"' )
		&& str_contains( $settings_html, 'name="policy_version" value="' . esc_attr( Settings::policy_version() ) . '"' ) && ! str_contains( $settings_html, 'style=' ),
	substr( $settings_html, 0, 200 )
);
wm_cv_check(
	'the services screen renders the registry, the ten presets and the developer API',
	str_contains( $services_html, 'name="action" value="wm_consent_save_service"' ) && str_contains( $services_html, 'Microsoft Clarity' )
		&& str_contains( $services_html, 'wm_consent_script_handles' ) && ! str_contains( $services_html, 'style=' ),
	substr( $services_html, 0, 200 )
);

// 11. The hub adapter agrees with the verification instead of reporting its own mood.
if ( interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	$adapter   = new \WMCyber\Consent\Vault\Adapters\Consent_Vault_Spoke_Adapter();
	$health    = $adapter->get_health_status();
	$telemetry = $adapter->get_telemetry_metrics();
	$expected  = ! $audit['valid'] ? 'ERROR' : ( 'EMPTY_CHAIN' === $audit['status'] ? 'NEEDS_CONFIG' : 'HEALTHY' );
	wm_cv_check( 'the hub health follows the chain verification', $health === $expected, "health {$health}, expected {$expected}" );
	wm_cv_check( 'telemetry reports the chain state and the key source', ( $telemetry['chain_integrity'] ?? '' ) === ( $audit['status'] ?? '' ) && null !== ( $telemetry['key_source'] ?? null ), wp_json_encode( $telemetry ) );
}

// 12. The deployed files, not the ones in the repository.
$dir     = WM_CONSENT_VAULT_PATH;
$shipped = array_merge( glob( $dir . 'includes/*/*.php' ) ?: [], [ $dir . 'wm-sovereign-consent-vault.php', $dir . 'uninstall.php' ] );
$missing = array_values(
	array_filter(
		$shipped,
		static function ( $file ) {
			$src = (string) file_get_contents( $file );
			return ! preg_match( "/defined\(\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\s*\)/", $src ) || ! str_contains( $src, 'declare(strict_types=1)' );
		}
	)
);
wm_cv_check( 'every deployed PHP file carries the guard and strict types', [] === $missing, implode( ', ', array_map( 'basename', $missing ) ) );

// 13. The German catalog belongs to the strings this version ships.
require_once ABSPATH . WPINC . '/pomo/mo.php';
$mo       = new MO();
$imported = $mo->import_from_file( $dir . 'languages/wm-sovereign-consent-vault-de_DE.mo' );
// The source strings are German, so an empty msgstr is not compiled into the German .mo and WordPress
// falls back to the source. What has to hold is that the catalog loads and carries no double-encoded
// entry from the dictionary era.
wm_cv_check(
	'the German catalog loads and carries no double-encoded entry',
	$imported && [] === array_filter( array_keys( $mo->entries ), static fn( $key ) => str_contains( (string) $key, 'Ã' ) ),
	'imported: ' . var_export( $imported, true ) . ', entries: ' . ( $imported ? count( $mo->entries ) : 0 )
);

// 14. Nothing moved.
wm_cv_check( 'the check wrote nothing', wm_cv_state() === $before );

echo "\n  RUNTIME CHECKS: {$GLOBALS['wm_cv_pass']} PASSED | {$GLOBALS['wm_cv_fail']} FAILED\n\n";
