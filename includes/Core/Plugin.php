<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Frontend\ConsentBanner;
use WMCyber\Consent\Vault\Frontend\EcosystemIntegration;
use WMCyber\Consent\Vault\Blocker\OutputBlocker;
use WMCyber\Consent\Vault\Blocker\ScriptIframeBlocker;
use WMCyber\Consent\Vault\Admin\AdminDashboard;
use WMCyber\Consent\Vault\Admin\SettingsScreen;
use WMCyber\Consent\Vault\API\RestController;
use WMCyber\Consent\Vault\CLI\ConsentCommand;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

final class Plugin {
	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', function (): void {
			load_plugin_textdomain(
				'wm-sovereign-consent-vault',
				false,
				dirname( plugin_basename( WM_CONSENT_VAULT_FILE ) ) . '/languages'
			);
			// Schema self-heal once per version bump, instead of dbDelta on every consent. Version 2
			// signs the recorded chain tip of installs that wrote it unsigned.
			if ( \WMCyber\Consent\Vault\Database\Schema::maybe_upgrade() ) {
				\WMCyber\Consent\Vault\Crypto\MerkleTreeEngine::migrate_tip_signature();
			}
		} );

		// One registry and one placeholder renderer per request, shared by the banner and both blockers.
		$registry = new ServiceRegistry();
		$embeds   = new ScriptIframeBlocker();

		// Frontend Banner, Shortcodes, WP 6.5+ Block Bindings & GenerateBlocks 2.x Dynamic Tags
		( new ConsentBanner( $registry ) )->boot();
		( new EcosystemIntegration( $registry ) )->boot();

		// Content Iframe Blocker (post content; kept on the_content for output that never reaches the page buffer,
		// such as a REST response carrying rendered content)
		add_filter( 'the_content', [ $embeds, 'filter_content_iframes' ], 20 );

		// Page-wide blocking of registered scripts and iframes until their category is accepted
		( new OutputBlocker( $registry, $embeds ) )->boot();

		// Admin Dashboard and the settings / services screens with their form handlers
		if ( is_admin() ) {
			AdminDashboard::instance()->boot();
			( new SettingsScreen() )->boot();
		}

		// REST API
		add_action( 'rest_api_init', function (): void {
			( new RestController() )->register_routes();
		} );

		// WP-CLI
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'consent', ConsentCommand::class );
		}

		/**
		 * Fires once WM Sovereign Consent Vault has registered its hooks and services.
		 *
		 * @param Plugin          $plugin   Plugin bootstrap instance.
		 * @param ServiceRegistry $registry Active service registry.
		 */
		do_action( 'wm_consent_ready', $this, $registry );
	}
}
