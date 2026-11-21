<?php
/**
 * WM Suite Sovereign Consent Vault — Suite Hub Spoke Adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface): health and telemetry
 * from the consent chain, one self-test action.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;
use WMCyber\Consent\Vault\Admin\AdminDashboard;
use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Crypto\MerkleTreeEngine;
use WMCyber\Consent\Vault\Database\Schema;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

final class Consent_Vault_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-sovereign-consent-vault';
	}

	public function get_name(): string {
		return 'WM Sovereign Consent Vault';
	}

	public function get_version(): string {
		return defined( 'WM_CONSENT_VAULT_VERSION' ) ? WM_CONSENT_VAULT_VERSION : '1.2.2';
	}

	public function get_description(): string {
		return 'Einwilligungsdialog nach Kategorien, Blockieren von Skripten und iframes eingetragener Dienste bis zur Einwilligung, 2-Klick-Platzhalter für YouTube, Vimeo und Google Maps und HMAC-verkettetes Protokoll der Entscheidungen.';
	}

	/**
	 * HEALTHY only for a verified chain with at least one node; NEEDS_CONFIG while nothing was recorded;
	 * ERROR when verification fails. Until 2026-09-14 an empty vault — a banner that never stored a
	 * decision, or tables that were emptied — read as HEALTHY.
	 */
	public function get_health_status(): string {
		$audit = ( new MerkleTreeEngine() )->verify_chain_integrity();
		if ( ! $audit['valid'] ) {
			return 'ERROR';
		}
		return 'EMPTY_CHAIN' === ( $audit['status'] ?? '' ) ? 'NEEDS_CONFIG' : 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-sovereign-consent-vault@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_CONSENT_VAULT_FILE ),
				],
			],
			// The plugin header and LICENSE are GPLv2 or later since e598ac2; the SBOM still named a proprietary
			// licence, so the hub reported a licence the package does not carry.
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			// Features, not legal labels: the plugin records decisions, it does not establish compliance.
			'properties'  => [
				[
					'name'  => 'wm:features:banner',
					'value' => 'Consent dialog with categories (necessary, statistics, marketing, media); decision stored in localStorage and the wm_consent cookie and posted to wm-consent/v1/record',
				],
				[
					'name'  => 'wm:features:blocking',
					'value' => 'Scripts and iframes of registered services held back until their category is accepted (output buffer and script filters)',
				],
				[
					'name'  => 'wm:features:embeds',
					'value' => 'Click-to-load placeholder for YouTube, youtube-nocookie, Vimeo and Google Maps iframes',
				],
				[
					'name'  => 'wm:features:chain',
					'value' => 'HMAC-SHA256 chained log of recorded decisions (signature formats 1 and 2) with a signed tip outside the chain tables',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-consent-vault' );
	}

	public function get_telemetry_metrics(): array {
		global $wpdb;
		$table_records = Schema::get_records_table();

		// null when the table is missing: a count of 0 would read as "no visitor decided yet".
		$total_consents = null;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_records ) ) === $table_records ) {
			$total_consents = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_records ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		}

		$audit = ( new MerkleTreeEngine() )->verify_chain_integrity();
		$stats = AdminDashboard::record_stats();

		return [
			'total_consents_logged'        => $total_consents,
			'chain_integrity'              => $audit['status'] ?? ( $audit['valid'] ? 'INTACT' : 'TAMPERED' ),
			'chain_error'                  => $audit['error'] ?? null,
			'ledger_nodes_count'           => $audit['total_nodes'] ?? null,
			'key_source'                   => $audit['key_source'] ?? null,
			'chain_tip'                    => ( 'INTACT' === ( $audit['status'] ?? '' ) && isset( $audit['merkle_root'] ) ) ? substr( (string) $audit['merkle_root'], 0, 16 ) : null,
			// Since the categories (2026-09-17). Counts are null without the table, like total_consents_logged.
			'consents_by_category'         => $stats['exists'] ? [
				Categories::STATISTICS => $stats['analytics'],
				Categories::MARKETING  => $stats['marketing'],
				Categories::MEDIA      => $stats['media'],
			] : null,
			'policy_version'               => Settings::policy_version(),
			'consents_current_policy'      => $stats['current_policy'],
			// Only for an intact chain: the per-format count of a broken one would describe rows that did not verify.
			'records_by_signature_version' => $audit['records_by_signature_version'] ?? null,
			'blocking_enabled'             => Settings::blocking_enabled(),
			'services_registered'          => count( ServiceRegistry::stored() ),
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
			case 'audit_merkle':
				$audit = ( new MerkleTreeEngine() )->verify_chain_integrity();
				if ( ! $audit['valid'] ) {
					return [
						'success' => false,
						'message' => sprintf( 'Einwilligungsprotokoll fehlerhaft: %s (Knoten %s).', (string) ( $audit['error'] ?? 'unbekannt' ), null === ( $audit['broken_node'] ?? null ) ? '—' : '#' . (int) $audit['broken_node'] ),
					];
				}
				if ( 'EMPTY_CHAIN' === ( $audit['status'] ?? '' ) ) {
					return [
						'success' => false,
						'message' => 'Noch keine Entscheidung protokolliert: nichts zu prüfen.',
					];
				}
				return [
					'success' => true,
					'message' => sprintf( 'Einwilligungsprotokoll geprüft: %d Knoten, Schlüsselquelle %s.', (int) ( $audit['total_nodes'] ?? 0 ), (string) ( $audit['key_source'] ?? '—' ) ),
				];

			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}
}
