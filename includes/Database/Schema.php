<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	/**
	 * Bump when create_tables() changes or a stored shape needs a migration; Core\Plugin re-runs it once per bump.
	 *
	 * 2 — the recorded chain tip carries a signature (MerkleTreeEngine::migrate_tip_signature()).
	 * 3 — the records table gains `categories`, `policy_version` and `signature_version`. dbDelta adds them to an
	 *     existing table with their defaults, so every row written before carries signature format 1, no categories
	 *     and no policy version, which is exactly what format 1 verifies.
	 */
	public const VERSION = '3';

	/** Option that records the last schema version dbDelta was run for. */
	public const VERSION_OPTION = 'wm_consent_vault_db_version';

	/** Required PHP extensions for UTF-8 user-agent bounding and canonical JSON v2 signatures. */
	public const REQUIRED_EXTENSIONS = [ 'mbstring', 'json' ];

	/**
	 * Verify required PHP extensions before activating; deactivate cleanly via wp_die() when missing.
	 *
	 * @param callable(string):bool|null $probe Optional probe for unit testing.
	 */
	public static function enforce_prerequisites( ?callable $probe = null ): void {
		$check   = $probe ?? static fn( string $ext ): bool => extension_loaded( $ext );
		$missing = array_values( array_filter( self::REQUIRED_EXTENSIONS, static fn( string $ext ): bool => ! $check( $ext ) ) );
		if ( [] === $missing ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'deactivate_plugins' ) && defined( 'WM_CONSENT_VAULT_FILE' ) ) {
			deactivate_plugins( plugin_basename( WM_CONSENT_VAULT_FILE ) );
		}
		if ( function_exists( 'wp_die' ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of missing PHP extensions */
						__( 'WM Sovereign Consent Vault konnte nicht aktiviert werden, da folgende erforderliche PHP-Erweiterungen fehlen: %s.', 'wm-sovereign-consent-vault' ),
						implode( ', ', $missing )
					)
				),
				esc_html__( 'Fehlende PHP-Erweiterung', 'wm-sovereign-consent-vault' ),
				[ 'back_link' => true ]
			);
		}
	}

	/**
	 * Run dbDelta only when the stored schema version is behind. The engine used to call
	 * create_tables() on every consent — two dbDelta per anonymous request.
	 *
	 * @return bool True when this call performed the upgrade, so the caller can run the migrations
	 *              that belong to it (the tip signature of version 2).
	 */
	public static function maybe_upgrade(): bool {
		if ( self::VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return false;
		}
		self::create_tables();
		update_option( self::VERSION_OPTION, self::VERSION, false );
		return true;
	}

	public static function get_records_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_consent_records';
	}

	public static function get_merkle_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_consent_merkle_nodes';
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_records = self::get_records_table();
		$table_merkle  = self::get_merkle_table();

		$sql_records = "CREATE TABLE {$table_records} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			consent_uuid VARCHAR(64) NOT NULL,
			ip_hmac VARCHAR(64) NOT NULL,
			essential TINYINT(1) NOT NULL DEFAULT 1,
			analytics TINYINT(1) NOT NULL DEFAULT 0,
			marketing TINYINT(1) NOT NULL DEFAULT 0,
			categories VARCHAR(64) NOT NULL DEFAULT '',
			policy_version VARCHAR(32) NOT NULL DEFAULT '',
			signature_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			user_agent VARCHAR(255) NOT NULL,
			signature_hash VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY consent_uuid_idx (consent_uuid),
			KEY created_at_idx (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_merkle = "CREATE TABLE {$table_merkle} (
			node_index BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			record_id BIGINT UNSIGNED NOT NULL,
			foreign_id VARCHAR(128) NOT NULL DEFAULT '',
			prev_hash VARCHAR(64) NOT NULL,
			record_hash VARCHAR(64) NOT NULL,
			merkle_root VARCHAR(64) NOT NULL,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (node_index),
			KEY record_id_idx (record_id)
		) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_records );
		dbDelta( $sql_merkle );
	}
}
