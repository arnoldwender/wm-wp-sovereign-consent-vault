<?php
/**
 * Plugin Name:       WM Sovereign Consent Vault (TDDDG § 25 & DSGVO)
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-sovereign-consent-vault/
 * Description:       Einwilligungsdialog nach Kategorien, der Skripte und iframes eingetragener Dienste bis zur Einwilligung zurückhält, 2-Klick-Platzhalter für YouTube, Vimeo und Google Maps und HMAC-verkettetes Protokoll der Entscheidungen in der eigenen Datenbank. Keine rechtliche Bewertung.
 * Version:           1.2.2
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-sovereign-consent-vault
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WM_CONSENT_VAULT_VERSION' ) ) {
	define( 'WM_CONSENT_VAULT_VERSION', '1.2.2' );
}
if ( ! defined( 'WM_CONSENT_VAULT_FILE' ) ) {
	define( 'WM_CONSENT_VAULT_FILE', __FILE__ );
}
if ( ! defined( 'WM_CONSENT_VAULT_PATH' ) ) {
	define( 'WM_CONSENT_VAULT_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WM_CONSENT_VAULT_URL' ) ) {
	define( 'WM_CONSENT_VAULT_URL', plugin_dir_url( __FILE__ ) );
}

// Autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix   = 'WMCyber\\Consent\\Vault\\';
	$base_dir = WM_CONSENT_VAULT_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// wm_consent_has() for themes and other plugins: defined at load, before any theme code runs.
require_once WM_CONSENT_VAULT_PATH . 'includes/functions.php';

/**
 * Provision consent database tables and schema version on a given Multisite blog.
 *
 * @param int $site_id Target blog ID.
 */
function provision_site_schema( int $site_id ): void {
	switch_to_blog( $site_id );
	try {
		Database\Schema::create_tables();
		update_option( Database\Schema::VERSION_OPTION, Database\Schema::VERSION, false );
	} finally {
		restore_current_blog();
	}
}

// Activation hook for DB tables
register_activation_hook( __FILE__, function ( bool $network_wide = false ): void {
	Database\Schema::enforce_prerequisites();
	if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			provision_site_schema( (int) $site_id );
		}
		return;
	}
	Database\Schema::create_tables();
	update_option( Database\Schema::VERSION_OPTION, Database\Schema::VERSION, false );
} );

// Provision consent tables on new Multisite blogs when network-activated.
add_action( 'wp_initialize_site', function ( object $new_site ): void {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}
	if ( ! isset( $new_site->blog_id ) || (int) $new_site->blog_id <= 0 ) {
		return;
	}
	provision_site_schema( (int) $new_site->blog_id );
}, 10, 1 );

// Register as Spoke in WM Suite Hub
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WMCyber\Consent\Vault\Adapters\Consent_Vault_Spoke_Adapter' ) && file_exists( WM_CONSENT_VAULT_PATH . 'includes/Adapters/Consent_Vault_Spoke_Adapter.php' ) ) {
		require_once WM_CONSENT_VAULT_PATH . 'includes/Adapters/Consent_Vault_Spoke_Adapter.php';
	}
	if ( class_exists( 'WMCyber\Consent\Vault\Adapters\Consent_Vault_Spoke_Adapter' ) ) {
		$modules[] = new Adapters\Consent_Vault_Spoke_Adapter();
	}
	return $modules;
} );

// Bootstrap
add_action( 'plugins_loaded', function (): void {
	Core\Plugin::instance()->boot();
} );

