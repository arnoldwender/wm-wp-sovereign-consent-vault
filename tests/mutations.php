<?php
/**
 * Mutations: each entry undoes one correction of this release, and tests/run-mutations.sh requires the
 * standalone suite to turn red for every one of them. An entry whose search string no longer occurs
 * exactly once is reported as "not applied" and fails the run, so the list cannot rot in silence.
 *
 * Format: [ description, [ [ relative file, search, replace ], ... ] ]
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

return [
	[
		'every IPv4 address in IPv6 notation falls back into one shared rate-limit bucket',
		[
			[
				'includes/API/RestController.php',
				'if ( 16 === strlen( $packed )',
				'if ( false && 16 === strlen( $packed )',
			],
		],
	],
	[
		'the consent row and its chain node stop being one transaction',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"\t\t\t" . '$wpdb->query( \'COMMIT\' );',
				'',
			],
		],
	],
	[
		'append reads the last surviving row again instead of the recorded tip (a new consent launders a cut node)',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'$tip          = get_option( self::TIP_OPTION, null );',
				'$tip          = null;',
			],
		],
	],
	[
		'verification stops looking for consent rows that no node covers',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'if ( $records_stored > $records_chained ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the recorded tip is stored without a signature again',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"'sig'   => self::tip_signature( \$hash, \$count ),",
				"'sig_removed' => '',",
			],
		],
	],
	[
		'verification accepts a tip whose signature is missing or wrong',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'if ( ! $signed && ! ( $tolerate_unsigned_tip && ! isset( $tip[\'sig\'] ) ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the tip no longer has to count the nodes the table holds',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"|| (int) ( \$tip['count'] ?? 0 ) !== count( \$nodes )",
				'',
			],
		],
	],
	[
		'nodes are appended without holding the named database lock',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"return '1' === (string) \$wpdb->get_var( \$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::LOCK_NAME, self::LOCK_TIMEOUT ) );",
				'return true;',
			],
		],
	],
	[
		'a consent row stays behind when its chain node could not be written',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"\$wpdb->delete( \$table_records, [ 'id' => \$record_id ], [ '%d' ] );",
				'// the row stays',
			],
		],
	],
	[
		'the node number is not read from the node insert (the option write moves insert_id)',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'// The node number is read here: update_option() below writes to wp_options and moves
			// insert_id, so the value returned to the caller used to be an option row id.
			$node_index = (int) $wpdb->insert_id;',
				'$node_index = 0;',
			],
		],
	],
	[
		'an anchor hash that is not a hex SHA-256 is stored again (truncated by the column)',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'if ( 1 !== preg_match( self::HASH_PATTERN, $foreign_hash ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the record route casts the consent flags with (bool) again',
		[
			[
				'includes/API/RestController.php',
				"\$analytics  = rest_sanitize_boolean( \$request->get_param( 'analytics' ) );",
				"\$analytics  = (bool) \$request->get_param( 'analytics' );",
			],
		],
	],
	[
		'the record route stops declaring the analytics flag in its schema',
		[
			[
				'includes/API/RestController.php',
				"'analytics' => [",
				"'analytics_undeclared' => [",
			],
		],
	],
	[
		'a decision that was not recorded answers 409 "already recorded" again',
		[
			[
				'includes/API/RestController.php',
				"return new WP_REST_Response( [ 'error' => 'consent not recorded' ], 500 );",
				"return new WP_REST_Response( [ 'error' => 'consent uuid already recorded' ], 409 );",
			],
		],
	],
	[
		'the placeholder filter ignores protocol-relative embeds again',
		[
			[
				'includes/Blocker/ScriptIframeBlocker.php',
				'(?:https?:)?\/\/',
				'https?:\/\/',
			],
		],
	],
	[
		'the placeholder drops the title of the embed it replaces',
		[
			[
				'includes/Blocker/ScriptIframeBlocker.php',
				'$title  = preg_match( \'/\btitle=["\\\']([^"\\\']*)["\\\']/i\', $attributes, $t ) ? $t[1] : \'\';',
				"\$title  = '';",
			],
		],
	],
	[
		'the placeholder claims again that the law makes the content load only after consent',
		[
			[
				'includes/Blocker/ScriptIframeBlocker.php',
				'Dieser Inhalt liegt bei %1$s.',
				'Gemäß § 25 TDDDG und DSGVO wird %1$s erst nach Einwilligung geladen.',
			],
		],
	],
	[
		'the banner drops a decision whose request never arrived',
		[
			[
				'assets/js/wm-consent-vault.js',
				"if (wmConsentRead('wm_consent_recorded') !== '1') {",
				'if (false) {',
			],
		],
	],
	[
		'the audit stops reporting whether the chain key is the wp-config-sample phrase',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"'key_is_sample' => self::key_is_sample(),",
				"'key_checked' => true,",
			],
		],
	],
	[
		'the rate limit counts single IPv6 addresses again instead of the /64',
		[
			[
				'includes/API/RestController.php',
				'self::rate_limit_subject( $ip )',
				'$ip',
			],
		],
	],
	[
		'the placeholder ignores the mobile YouTube host and unquoted src attributes',
		[
			[
				'includes/Blocker/ScriptIframeBlocker.php',
				'src=(["\\\']?)((?:https?:)?\\/\\/(?:www\\.|player\\.|m\\.)?',
				'src=(["\\\'])((?:https?:)?\\/\\/(?:www\\.|player\\.)?',
			],
		],
	],
	[
		'the content filter is fatal again when a filter before it passes null',
		[
			[
				'includes/Blocker/ScriptIframeBlocker.php',
				'public function filter_content_iframes( $content ): string {',
				'public function filter_content_iframes( string $content ): string {',
			],
		],
	],
	[
		'the unlocked embed loses its referrer policy and lazy loading',
		[
			[
				'assets/js/wm-consent-vault.js',
				"iframe.referrerPolicy = 'strict-origin-when-cross-origin';",
				'',
			],
		],
	],
	[
		'reading localStorage is unguarded again, so a browser that blocks site data sees no banner',
		[
			[
				'assets/js/wm-consent-vault.js',
				'try { return localStorage.getItem(key); } catch (e) { return null; }',
				'return localStorage.getItem(key);',
			],
		],
	],
	[
		'the banner tells visitors that their browser identifier is stored as a hash',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'der Kennung Ihres Browsers (User-Agent) im Klartext und dem Zeitpunkt.',
				'und Ihrer Browserkennung.',
			],
		],
	],
	[
		'the schema file loses its direct-access guard',
		[
			[
				'includes/Database/Schema.php',
				"if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n",
				'',
			],
		],
	],
	[
		'uninstall leaves the rate-limit buckets of the record route behind',
		[
			[
				'uninstall.php',
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			],
		],
	],
	// --- 2026-09-17: categories, settings, services, signature format 2, blocking, dialog, admin screens
	[
		'category aliases are ignored again',
		[
			[
				'includes/Core/Categories.php',
				'return self::ALIASES[ $name ] ?? null;',
				'return null;',
			],
		],
	],
	[
		'a decision no longer always includes necessary',
		[
			[
				'includes/Core/Categories.php',
				'$chosen = [ self::NECESSARY => true ];',
				'$chosen = [];',
			],
		],
	],
	[
		'an unknown category in a list is accepted',
		[
			[
				'includes/Core/Categories.php',
				'if ( ! is_string( $name ) || null === self::normalize( $name ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'any policy version is accepted, including one the cookie cannot carry',
		[
			[
				'includes/Core/Settings.php',
				'if ( 1 === preg_match( self::POLICY_VERSION_PATTERN, $version ) ) {',
				'if ( true ) {',
			],
		],
	],
	[
		'the consent duration is stored outside its bounds',
		[
			[
				'includes/Core/Settings.php',
				'&& (int) $days >= self::MIN_DAYS && (int) $days <= self::MAX_DAYS ) {',
				') {',
			],
		],
	],
	[
		'a link that is not http(s) is stored',
		[
			[
				'includes/Core/Settings.php',
				'$url = esc_url_raw( $raw, [ \'http\', \'https\' ] );',
				'$url = $raw;',
			],
		],
	],
	[
		'an unchecked blocking box no longer switches blocking off',
		[
			[
				'includes/Core/Settings.php',
				'$out[\'blocking_enabled\'] = true === $blocking || 1 === $blocking || \'1\' === $blocking;',
				'$out[\'blocking_enabled\'] = \'\' !== (string) $blocking;',
			],
		],
	],
	[
		'a stored text override is ignored',
		[
			[
				'includes/Core/Settings.php',
				'		$stored = self::get()[\'texts\'][ $key ] ?? \'\';
		if ( \'\' !== $stored ) {',
				'		$stored = self::get()[\'texts\'][ $key ] ?? \'\';
		if ( false ) {',
			],
		],
	],
	[
		'the privacy link no longer falls back to the WordPress privacy page',
		[
			[
				'includes/Core/Settings.php',
				'return \'\' !== $url ? $url : (string) get_privacy_policy_url();',
				'return $url;',
			],
		],
	],
	[
		'raising the policy version no longer increments a trailing number',
		[
			[
				'includes/Core/Settings.php',
				'$next = $m[1] . ( (int) $m[2] + 1 );',
				'$next = $current . \'-2\';',
			],
		],
	],
	[
		'a pattern too generic to name a host (no dot or slash) is accepted',
		[
			[
				'includes/Services/ServiceRegistry.php',
				'\'/^(?=.*[.\\/])[a-z0-9._\\/-]{4,100}$/\'',
				'\'/^[a-z0-9._\\/-]{1,100}$/\'',
			],
		],
	],
	[
		'a pattern matches inside a longer host name',
		[
			[
				'includes/Services/ServiceRegistry.php',
				'\'/(?<![a-z0-9-])(?:\' . implode( \'|\', $parts ) . \')(?![a-z0-9-])/i\'',
				'\'/(?:\' . implode( \'|\', $parts ) . \')/i\'',
			],
		],
	],
	[
		'adding a preset twice is no longer reported as existing',
		[
			[
				'includes/Services/ServiceRegistry.php',
				'if ( null !== self::find_stored( $key ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'a service can take another entry\'s id',
		[
			[
				'includes/Services/ServiceRegistry.php',
				'} elseif ( $service[\'id\'] === $existing[\'id\'] ) {',
				'} elseif ( false ) {',
			],
		],
	],
	[
		'what wm_consent_services returns is not validated',
		[
			[
				'includes/Services/ServiceRegistry.php',
				'$this->services = self::clean_list( is_array( $filtered ) ? $filtered : $services );',
				'$this->services = is_array( $filtered ) ? $filtered : $services;',
			],
		],
	],
	[
		'the wm_consent_services filter is not applied',
		[
			[
				'includes/Services/ServiceRegistry.php',
				'$filtered       = apply_filters( \'wm_consent_services\', $services );',
				'$filtered       = $services;',
			],
		],
	],
	[
		'deleting a missing service reports success',
		[
			[
				'includes/Services/ServiceRegistry.php',
				'if ( count( $kept ) === count( $services ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'format 2 stops signing the categories',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'(int) $marketing, $categories, $policy_version, $user_agent, $timestamp ],',
				'(int) $marketing, $policy_version, $user_agent, $timestamp ],',
			],
		],
	],
	[
		'format 2 stops signing the policy version',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'(int) $marketing, $categories, $policy_version, $user_agent, $timestamp ],',
				'(int) $marketing, $categories, $user_agent, $timestamp ],',
			],
		],
	],
	[
		'format 2 stops signing the user agent',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'(int) $marketing, $categories, $policy_version, $user_agent, $timestamp ],',
				'(int) $marketing, $categories, $policy_version, $timestamp ],',
			],
		],
	],
	[
		'new decisions are stored without their signature format',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'					\'signature_version\' => self::SIGNATURE_VERSION,
					\'user_agent\'        => $user_agent,',
				'					\'user_agent\'        => $user_agent,',
			],
		],
	],
	[
		'categories written into a format-1 row are accepted',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'if ( \'\' !== (string) ( $record[\'categories\'] ?? \'\' ) || \'\' !== (string) ( $record[\'policy_version\'] ?? \'\' ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'a row from before schema 3 is verified as format 2',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'		$version = (int) ( $record[\'signature_version\'] ?? self::LEGACY_SIGNATURE_VERSION );',
				'		$version = (int) ( $record[\'signature_version\'] ?? self::SIGNATURE_VERSION );',
			],
		],
	],
	[
		'an unknown category reaches the log through append_record()',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'} elseif ( ! Categories::all_known( $categories )',
				'} elseif ( ( false && ! Categories::all_known( $categories ) )',
			],
		],
	],
	[
		'the statistics flag is no longer checked against the categories',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'|| in_array( Categories::STATISTICS, Categories::canonical( $categories ), true ) !== $analytics',
				'|| false',
			],
		],
	],
	[
		'the marketing flag is no longer checked against the categories',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'|| in_array( Categories::MARKETING, Categories::canonical( $categories ), true ) !== $marketing ) {',
				') {',
			],
		],
	],
	[
		'a malformed policy version is written into the log',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'if ( 1 !== preg_match( self::POLICY_VERSION_PATTERN, $policy_version ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the user agent is cut by bytes again',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'$user_agent = mb_substr( $user_agent, 0, 255, \'UTF-8\' );',
				'$user_agent = substr( $user_agent, 0, 255 );',
			],
		],
	],
	[
		'verification stops counting rows per signature format',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				'++$records_by_version[ $row_version ];',
				'',
			],
		],
	],
	[
		'existing rows get signature format 2 by default after the upgrade',
		[
			[
				'includes/Database/Schema.php',
				'signature_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,',
				'signature_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,',
			],
		],
	],
	[
		'the schema version is not raised for the new columns',
		[
			[
				'includes/Database/Schema.php',
				'public const VERSION = \'3\';',
				'public const VERSION = \'2\';',
			],
		],
	],
	[
		'the record route stops declaring categories',
		[
			[
				'includes/API/RestController.php',
				'\'categories\'     => [',
				'\'categories_undeclared\'     => [',
			],
		],
	],
	[
		'the record route drops an unknown category instead of refusing it',
		[
			[
				'includes/API/RestController.php',
				'if ( ! is_array( $raw_categories ) || ! Categories::all_known( $raw_categories ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the record route stops checking flags against categories',
		[
			[
				'includes/API/RestController.php',
				'if ( in_array( Categories::STATISTICS, $categories, true ) !== $analytics || in_array( Categories::MARKETING, $categories, true ) !== $marketing ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the record route stops checking the policy version',
		[
			[
				'includes/API/RestController.php',
				'if ( null !== $raw_version && 1 !== preg_match( \'/^[A-Za-z0-9._-]{1,32}$/\', $policy_version ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the record route stops passing categories and policy version to the log',
		[
			[
				'includes/API/RestController.php',
				'$user_agent, $categories, $policy_version );',
				'$user_agent );',
			],
		],
	],
	[
		'wm_consent_decision_recorded is no longer fired',
		[
			[
				'includes/API/RestController.php',
				'do_action( \'wm_consent_decision_recorded\', $result );',
				'',
			],
		],
	],
	[
		'the blocker rewrites scripts that are not JavaScript (JSON-LD, text/plain markup)',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( ! in_array( $type, self::JS_TYPES, true ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the blocker rewrites the plugin\'s own tags',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( str_starts_with( (string) ( $attributes[\'id\'] ?? \'\' ), \'wm-consent-\' ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'data-wm-consent-ignore no longer exempts a script',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( array_key_exists( \'data-wm-consent-ignore\', $attributes ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'developer markup with data-wm-consent is no longer held back',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'} elseif ( array_key_exists( \'data-wm-consent\', $attributes ) ) {',
				'} elseif ( false ) {',
			],
		],
	],
	[
		'scripts marked necessary are held back',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'$category = is_string( $category ) ? Categories::normalize( $category ) : null;',
				'$category = is_string( $category ) ? Categories::normalize( $category ) : null; if ( \'necessary\' === $category ) { return \'necessary\'; }',
			],
		],
	],
	[
		'a held-back module loses its type',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( \'\' !== $type && \'text/javascript\' !== $type ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'scripts inside HTML comments are rewritten',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'\'/<(script|style|textarea)\\b|<!--/i\'',
				'\'/<(script|style|textarea)\\b/i\'',
			],
		],
	],
	[
		'iframes inside script text are rewritten',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'$out .= $this->rewrite_script_element( $text, $force_category );',
				'$out .= $this->rewrite_iframes( $this->rewrite_script_element( $text, $force_category ) );',
			],
		],
	],
	[
		'the end of a script is found with a lazy regular expression again',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'$close    = false === $open_end ? false : stripos( $html, \'</\' . $tag, $open_end );',
				'$close    = false === $open_end ? false : ( 1 === preg_match( \'/\\\\A.*?(?=<\\\\/\' . $tag . \')/is\', substr( $html, $open_end ), $lazy ) ? $open_end + strlen( $lazy[0] ) : false );',
			],
		],
	],
	[
		'a flushed chunk goes out before the page is complete',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'ob_clean() no longer discards what was held back',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the blocking switch in the settings is ignored',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( ! Settings::blocking_enabled() ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'feeds are rewritten',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( did_action( \'wp\' ) && ( is_feed() || is_robots() || is_trackback() ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'wm_consent_blocking_enabled is ignored',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'return (bool) apply_filters( \'wm_consent_blocking_enabled\', true );',
				'return true;',
			],
		],
	],
	[
		'boolean script attributes are handed back to WordPress as empty strings',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'null === $value ? true : $value',
				'null === $value ? \'\' : $value',
			],
		],
	],
	[
		'inline code is not matched in wp_inline_script_attributes',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'$this->string_attributes( $attributes ), (string) $data,',
				'$this->string_attributes( $attributes ), \'\',',
			],
		],
	],
	[
		'wm_consent_script_handles is ignored',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'$handles  = apply_filters( \'wm_consent_script_handles\', [] );',
				'$handles  = [];',
			],
		],
	],
	[
		'wm_consent_script_category is ignored',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'apply_filters( \'wm_consent_script_category\', $category,',
				'( static fn( $c ) => $c )( $category,',
			],
		],
	],
	[
		'the buffer opens early on template_redirect, outside a cache plugin\'s buffer',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'add_action( \'template_redirect\', [ $this, \'start_buffer\' ], 99 );',
				'add_action( \'template_redirect\', [ $this, \'start_buffer\' ], 1 );',
			],
		],
	],
	[
		'a registered media iframe keeps its frame instead of the placeholder',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'if ( Categories::MEDIA === $category && ( str_starts_with( $src, \'https://\' ) || str_starts_with( $src, \'//\' ) ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'YouTube, Vimeo and Google Maps iframes outside the post content load again',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'$markup = $this->embeds->filter_content_iframes( $markup );',
				'',
			],
		],
	],
	[
		'an iframe of a registered marketing service loads without consent',
		[
			[
				'includes/Blocker/OutputBlocker.php',
				'return $this->build_tag( \'iframe\', $blocked ) . $m[2] . \'</iframe>\';',
				'return $m[0];',
			],
		],
	],
	[
		'a cookie from another policy version counts',
		[
			[
				'includes/Core/ConsentCookie.php',
				'if ( $m[1] !== Settings::policy_version() ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'an expired or future decision counts',
		[
			[
				'includes/Core/ConsentCookie.php',
				'if ( $decided_at > $now + 300 || $now - $decided_at > Settings::duration_days() * DAY_IN_SECONDS ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'a cookie with an unknown category counts',
		[
			[
				'includes/Core/ConsentCookie.php',
				'if ( ! Categories::all_known( $names ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'necessary is no longer always granted',
		[
			[
				'includes/Core/ConsentCookie.php',
				'} elseif ( Categories::NECESSARY === $slug ) {',
				'} elseif ( false ) {',
			],
		],
	],
	[
		'reading the cookie no longer keeps the page out of full-page caches',
		[
			[
				'includes/Core/ConsentCookie.php',
				'define( \'DONOTCACHEPAGE\', true );',
				'',
			],
		],
	],
	[
		'wm_consent_has_nocache is ignored',
		[
			[
				'includes/Core/ConsentCookie.php',
				'if ( apply_filters( \'wm_consent_has_nocache\', true, $slug ) ) {',
				'if ( true ) {',
			],
		],
	],
	[
		'the wm_consent_has filter is ignored',
		[
			[
				'includes/Core/ConsentCookie.php',
				'return (bool) apply_filters( \'wm_consent_has\', $result, $category );',
				'return $result;',
			],
		],
	],
	[
		'the banner is no longer a modal dialog',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'role="dialog" aria-modal="true"',
				'role="region"',
			],
		],
	],
	[
		'the dialog loses its description',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				' aria-describedby="wm-consent-vault-description"',
				'',
			],
		],
	],
	[
		'"Nur notwendige" gets a weaker style than "Alle akzeptieren"',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'wm-consent__button--choice" data-wm-consent-action="necessary"',
				'wm-consent__button--secondary" data-wm-consent-action="necessary"',
			],
		],
	],
	[
		'the necessary switch can be turned off',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'<?php echo $is_locked ? \' checked disabled\' : \'\'; ?>',
				'<?php echo $is_locked ? \' checked\' : \'\'; ?>',
			],
		],
	],
	[
		'the dialog no longer lists the services of a category',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'<?php if ( [] !== $services ) : ?>',
				'<?php if ( false ) : ?>',
			],
		],
	],
	[
		'the dialog no longer links the legal notice',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'<?php if ( \'\' !== $imprint_url ) : ?>',
				'<?php if ( false ) : ?>',
			],
		],
	],
	[
		'the dialog opens by itself on the privacy policy page',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'$suppress[] = $path;',
				'',
			],
		],
	],
	[
		'revocation cannot find the cookies of a service',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'\'cookies\'  => $service[\'cookies\'],',
				'\'cookies\'  => [],',
			],
		],
	],
	[
		'the banner script is printed in the head',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'[ \'in_footer\' => true ]',
				'[ \'in_footer\' => false ]',
			],
		],
	],
	[
		'the settings shortcode no longer opens the dialog',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'class="wm-btn wm-consent-settings-button" data-wm-consent-open>',
				'class="wm-btn wm-consent-settings-button">',
			],
		],
	],
	[
		'the dialog ignores the texts from the settings',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'$texts[ $key ] = Settings::text( $key );',
				'$texts[ $key ] = self::default_texts()[ $key ];',
			],
		],
	],
	[
		'the placeholder loses its category, so accepting media does not load it',
		[
			[
				'includes/Blocker/ScriptIframeBlocker.php',
				'\' data-wm-consent-category="\' . esc_attr( $category ) . \'" data-wm-consent-service="\' . esc_attr( $service_id ) . \'">\'',
				'\'>\'',
			],
		],
	],
	[
		'the placeholder\'s second button no longer opens the dialog',
		[
			[
				'includes/Blocker/ScriptIframeBlocker.php',
				'\'<button type="button" class="wm-2click-settings" data-wm-consent-open>\'',
				'\'<button type="button" class="wm-2click-settings">\'',
			],
		],
	],
	[
		'muted text drops below 4.5:1 on the dialog surface',
		[
			[
				'assets/css/wm-consent-vault.css',
				'--wm-consent-text-muted: #cbd5e1;',
				'--wm-consent-text-muted: #64748b;',
			],
		],
	],
	[
		'interactive targets shrink below 44 px',
		[
			[
				'assets/css/wm-consent-vault.css',
				'--wm-consent-target: 44px;',
				'--wm-consent-target: 36px;',
			],
		],
	],
	[
		'the choice buttons get a hard-coded colour',
		[
			[
				'assets/css/wm-consent-vault.css',
				'	background: var(--wm-consent-choice-bg);',
				'	background: #ffffff;',
			],
		],
	],
	[
		'Tab escapes the dialog after its last control',
		[
			[
				'assets/js/wm-consent-vault.js',
				'} else if (!event.shiftKey && active === last) {',
				'} else if (false) {',
			],
		],
	],
	[
		'closing the dialog no longer returns focus',
		[
			[
				'assets/js/wm-consent-vault.js',
				'		target.focus();',
				'',
			],
		],
	],
	[
		'Escape closes the first-visit dialog without a choice',
		[
			[
				'assets/js/wm-consent-vault.js',
				'} else if (wmConsentDialogState.mode === \'reopen\') {',
				'} else if (true) {',
			],
		],
	],
	[
		'a decision for an older policy version keeps counting',
		[
			[
				'assets/js/wm-consent-vault.js',
				'decision.policyVersion !== cfg.policyVersion',
				'false',
			],
		],
	],
	[
		'held-back scripts run out of order',
		[
			[
				'assets/js/wm-consent-vault.js',
				'script.async = false;',
				'',
			],
		],
	],
	[
		'revoking a category leaves its cookies',
		[
			[
				'assets/js/wm-consent-vault.js',
				'wmConsentDeleteCookies(cookies);',
				'',
			],
		],
	],
	[
		'the decision is recorded without its categories',
		[
			[
				'assets/js/wm-consent-vault.js',
				'body.categories = ',
				'body.ignored = ',
			],
		],
	],
	[
		'accepting external media no longer loads the placeholders',
		[
			[
				'assets/js/wm-consent-vault.js',
				'wmLoadEmbed(node, false);',
				'',
			],
		],
	],
	[
		'saving settings no longer checks the capability',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'public function handle_save_settings(): void {
		if ( ! current_user_can( \'manage_options\' ) ) {',
				'public function handle_save_settings(): void {
		if ( false ) {',
			],
		],
	],
	[
		'saving settings no longer checks the nonce',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'check_admin_referer( self::ACTION_SETTINGS );',
				'',
			],
		],
	],
	[
		'raising the policy version no longer checks the capability',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'public function handle_bump_policy(): void {
		if ( ! current_user_can( \'manage_options\' ) ) {',
				'public function handle_bump_policy(): void {
		if ( false ) {',
			],
		],
	],
	[
		'raising the policy version no longer checks the nonce',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'check_admin_referer( self::ACTION_BUMP );',
				'',
			],
		],
	],
	[
		'saving a service no longer checks the capability',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'public function handle_save_service(): void {
		if ( ! current_user_can( \'manage_options\' ) ) {',
				'public function handle_save_service(): void {
		if ( false ) {',
			],
		],
	],
	[
		'saving a service no longer checks the nonce',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'check_admin_referer( self::ACTION_SAVE_SERVICE );',
				'',
			],
		],
	],
	[
		'deleting a service no longer checks the capability',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'public function handle_delete_service(): void {
		if ( ! current_user_can( \'manage_options\' ) ) {',
				'public function handle_delete_service(): void {
		if ( false ) {',
			],
		],
	],
	[
		'deleting a service no longer checks the nonce',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'check_admin_referer( self::ACTION_DELETE_SERVICE );',
				'',
			],
		],
	],
	[
		'adding a preset no longer checks the capability',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'public function handle_add_preset(): void {
		if ( ! current_user_can( \'manage_options\' ) ) {',
				'public function handle_add_preset(): void {
		if ( false ) {',
			],
		],
	],
	[
		'adding a preset no longer checks the nonce',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'check_admin_referer( self::ACTION_ADD_PRESET );',
				'',
			],
		],
	],
	[
		'an unchecked blocking box is saved as switched on',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'isset( $_POST[\'blocking_enabled\'] ) ? \'1\' : \'0\',',
				'isset( $_POST[\'blocking_enabled\'] ) ? \'1\' : \'1\',',
			],
		],
	],
	[
		'an invalid service form comes back empty',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'set_transient( self::DRAFT_TRANSIENT . get_current_user_id(), $input + [ \'original_id\' => $original_id ], MINUTE_IN_SECONDS );',
				'',
			],
		],
	],
	[
		'the notice names fields by their key instead of their label',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'$named[] = $field_labels[ $field ] ?? $field;',
				'$named[] = $field;',
			],
		],
	],
	[
		'the service name field loses its label',
		[
			[
				'includes/Admin/SettingsScreen.php',
				'<label class="wm-label" for="wm-consent-service-name">',
				'<label class="wm-label" for="wm-consent-service-title">',
			],
		],
	],
	[
		'the admin screen loses the Dienste tab',
		[
			[
				'includes/Admin/AdminDashboard.php',
				'\'services\' => [ \'label\' => __( \'Dienste\'',
				'\'services_hidden\' => [ \'label\' => __( \'Dienste\'',
			],
		],
	],
	[
		'the statistics count no longer counts media',
		[
			[
				'includes/Admin/AdminDashboard.php',
				'SUM(FIND_IN_SET(%s, categories) > 0) AS media',
				'SUM(FIND_IN_SET(%s, categories) > 0) AS media_count',
			],
		],
	],
	[
		'telemetry stops reporting media decisions',
		[
			[
				'includes/Adapters/Consent_Vault_Spoke_Adapter.php',
				'Categories::MEDIA      => $stats[\'media\'],',
				'Categories::MEDIA      => 0,',
			],
		],
	],
	[
		'telemetry stops reporting signature formats',
		[
			[
				'includes/Adapters/Consent_Vault_Spoke_Adapter.php',
				'\'records_by_signature_version\' => $audit[\'records_by_signature_version\'] ?? null,',
				'\'records_by_signature_version\' => null,',
			],
		],
	],
	[
		'the SBOM names a proprietary licence again',
		[
			[
				'includes/Adapters/Consent_Vault_Spoke_Adapter.php',
				'\'id\' => \'GPL-2.0-or-later\',',
				'\'id\' => \'Proprietary\',',
			],
		],
	],
	[
		'uninstall leaves the service registry behind',
		[
			[
				'uninstall.php',
				'	\'wm_consent_services\',
',
				'',
			],
		],
	],
	[
		'uninstall leaves the drafts of the service form behind',
		[
			[
				'uninstall.php',
				'DELETE FROM %i WHERE (option_name LIKE %s) OR (option_name LIKE %s)',
				'SELECT option_name FROM %i WHERE (option_name LIKE %s) OR (option_name LIKE %s)',
			],
		],
	],
	[
		'uninstall drops tables and options even when wm_consent_delete_data_on_uninstall is disabled',
		[
			[
				'uninstall.php',
				'if ( true !== $wm_consent_vault_delete_opt_in && 1 !== $wm_consent_vault_delete_opt_in && \'1\' !== $wm_consent_vault_delete_opt_in ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'/nonce stops enforcing its transient HMAC rate limit',
		[
			[
				'includes/API/RestController.php',
				'if ( $limit <= 0 || $this->is_rate_limited( $ip, $limit, \'nonce_\' ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'/nonce stops bypassing the rate limit for administrators',
		[
			[
				'includes/API/RestController.php',
				"	public function check_public_nonce_access( ?WP_REST_Request \$request = null ): true|WP_Error {\n\t\tif ( current_user_can( 'manage_options' ) ) {",
				"	public function check_public_nonce_access( ?WP_REST_Request \$request = null ): true|WP_Error {\n\t\tif ( false ) {",
			],
		],
	],
	[
		'saving settings stops persisting the delete_data_on_uninstall opt-in flag',
		[
			[
				'includes/Admin/SettingsScreen.php',
				"update_option( Settings::DELETE_DATA_OPTION, isset( \$_POST['delete_data_on_uninstall'] ) ? '1' : '0', false );",
				'// opt-in not saved',
			],
		],
	],
	[
		'WP 6.5+ Block Bindings source wm-consent/status stops registering',
		[
			[
				'includes/Frontend/EcosystemIntegration.php',
				'register_block_bindings_source(',
				'if ( false ) register_block_bindings_source(',
			],
		],
	],
	[
		'GenerateBlocks 2.x Dynamic Tag wm_consent_status stops registering',
		[
			[
				'includes/Frontend/EcosystemIntegration.php',
				'new \GenerateBlocks_Register_Dynamic_Tag(',
				'if ( false ) new \GenerateBlocks_Register_Dynamic_Tag(',
			],
		],
	],
	[
		'wm_consent_ready action is no longer fired on boot',
		[
			[
				'includes/Core/Plugin.php',
				"do_action( 'wm_consent_ready', \$this, \$registry );",
				'',
			],
		],
	],
	[
		'next_link() reads stale object-cached wm_consent_chain_tip under concurrency instead of evicting cache inside GET_LOCK',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"\t\tif ( function_exists( 'wp_cache_delete' ) ) {\n\t\t\twp_cache_delete( self::TIP_OPTION, 'options' );\n\t\t\twp_cache_delete( 'notoptions', 'options' );\n\t\t\twp_cache_delete( 'alloptions', 'options' );\n\t\t}\n\t\t\$tip          = get_option( self::TIP_OPTION, null );",
				"\t\t\$tip          = get_option( self::TIP_OPTION, null );",
			],
		],
	],
	[
		'Schema::enforce_prerequisites() ignores missing PHP extensions on activation',
		[
			[
				'includes/Database/Schema.php',
				"\t\tif ( [] === \$missing ) {\n\t\t\treturn;\n\t\t}",
				"\t\tif ( true ) {\n\t\t\treturn;\n\t\t}",
			],
		],
	],
	[
		'MerkleTreeEngine reverts GET_LOCK timeout from non-blocking 0s back to blocking 5s stall',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"\tprivate const LOCK_TIMEOUT        = 0;",
				"\tprivate const LOCK_TIMEOUT        = 5;",
			],
		],
	],
	[
		'append_record() skips jittered backoff retries and fails on the first contended GET_LOCK',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"\t\tif ( ! \$this->acquire_lock_with_jitter() ) {\n\t\t\treturn [\n\t\t\t\t'error'        => 'chain_busy',\n\t\t\t\t'consent_uuid' => \$uuid,\n\t\t\t];\n\t\t}",
				"\t\tif ( ! \$this->lock() ) {\n\t\t\treturn [\n\t\t\t\t'error'        => 'chain_busy',\n\t\t\t\t'consent_uuid' => \$uuid,\n\t\t\t];\n\t\t}",
			],
		],
	],
	[
		'append_anchor() skips jittered backoff retries and fails on the first contended GET_LOCK',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"\t\tif ( ! \$this->acquire_lock_with_jitter() ) {\n\t\t\treturn [\n\t\t\t\t'error'      => 'chain_busy',\n\t\t\t\t'foreign_id' => \$foreign_id,\n\t\t\t];\n\t\t}",
				"\t\tif ( ! \$this->lock() ) {\n\t\t\treturn [\n\t\t\t\t'error'      => 'chain_busy',\n\t\t\t\t'foreign_id' => \$foreign_id,\n\t\t\t];\n\t\t}",
			],
		],
	],
	[
		'wm_consent_lock_backoff_enabled filter is ignored by acquire_lock_with_jitter()',
		[
			[
				'includes/Crypto/MerkleTreeEngine.php',
				"\$backoff_enabled = (bool) apply_filters( 'wm_consent_lock_backoff_enabled', true );",
				"\$backoff_enabled = true;",
			],
		],
	],
	[
		'REST 503 chain_busy response omits Retry-After: 2 header',
		[
			[
				'includes/API/RestController.php',
				"return new WP_REST_Response( [ 'error' => 'consent chain busy, retry' ], 503, [ 'Retry-After' => '2' ] );",
				"return new WP_REST_Response( [ 'error' => 'consent chain busy, retry' ], 503 );",
			],
		],
	],
	[
		'wp_initialize_site provisions tables even when the plugin is not network-activated',
		[
			[
				'wm-sovereign-consent-vault.php',
				"if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {",
				"if ( false ) {",
			],
		],
	],
	[
		'wp_initialize_site stops hooking Multisite new-site provisioning',
		[
			[
				'wm-sovereign-consent-vault.php',
				"add_action( 'wp_initialize_site', function ( object \$new_site ): void {",
				"add_action( 'wp_initialize_site_disabled', function ( object \$new_site ): void {",
			],
		],
	],
	[
		'provision_site_schema() omits switch_to_blog() before creating Multisite tables',
		[
			[
				'wm-sovereign-consent-vault.php',
				"\tswitch_to_blog( \$site_id );",
				"\t// switch_to_blog skipped;",
			],
		],
	],
	[
		'provision_site_schema() omits restore_current_blog() after creating Multisite tables',
		[
			[
				'wm-sovereign-consent-vault.php',
				"\t\trestore_current_blog();",
				"\t\t// restore_current_blog skipped;",
			],
		],
	],
	[
		'provision_site_schema() forgets to store Schema::VERSION_OPTION on newly provisioned Multisite blogs',
		[
			[
				'wm-sovereign-consent-vault.php',
				"\ttry {\n\t\tDatabase\\Schema::create_tables();\n\t\tupdate_option( Database\\Schema::VERSION_OPTION, Database\\Schema::VERSION, false );\n\t} finally {",
				"\ttry {\n\t\tDatabase\\Schema::create_tables();\n\t} finally {",
			],
		],
	],
	[
		'the block editor canvas loses the front-end sheet again (bare [wm_consent_settings] button in the editor)',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				"\t\tadd_action( 'enqueue_block_assets', [ \$this, 'enqueue_editor_style' ] );\n",
				'',
			],
		],
	],
	[
		'the editor-canvas hook enqueues the sheet a second time on the front end',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				'if ( ! is_admin() ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'the banner script is loaded into the block editor, where no dialog is rendered',
		[
			[
				'includes/Frontend/ConsentBanner.php',
				"if ( ! is_admin() ) {\n\t\t\treturn;\n\t\t}\n",
				"if ( ! is_admin() ) {\n\t\t\treturn;\n\t\t}\n\t\twp_enqueue_script( self::HANDLE, WM_CONSENT_VAULT_URL . 'assets/js/wm-consent-vault.js' );\n",
			],
		],
	],
];
