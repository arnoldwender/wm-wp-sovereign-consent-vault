<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Crypto\MerkleTreeEngine;
use WP_CLI;

final class ConsentCommand {
	/**
	 * Recompute every consent signature and walk the chain.
	 *
	 * Exits with an error when the chain fails verification; warns when nothing was recorded yet.
	 *
	 * ## EXAMPLES
	 *
	 *     wp consent audit_merkle
	 *
	 * @when after_wp_load
	 */
	public function audit_merkle( array $args, array $assoc_args ): void {
		WP_CLI::line( 'Verifying the consent chain...' );

		$result = ( new MerkleTreeEngine() )->verify_chain_integrity();

		if ( ! $result['valid'] ) {
			WP_CLI::error( 'Consent chain BROKEN at node #' . ( $result['broken_node'] ?? '-' ) . ' (Error: ' . $result['error'] . ')' );
		}

		if ( 'EMPTY_CHAIN' === ( $result['status'] ?? '' ) ) {
			// An empty vault verifies trivially; saying "intact" would read as "decisions are being recorded".
			WP_CLI::warning( 'No consent decision recorded yet: nothing to verify.' );
			return;
		}

		WP_CLI::success( 'Consent chain intact: every record still matches its signature and every link its predecessor. Tamper-evident with the site key -- whoever reads wp-config.php holds the key.' );
		WP_CLI::line( '• Nodes verified:  ' . $result['total_nodes'] );
		WP_CLI::line( '• Chain tip:       ' . $result['merkle_root'] );
		WP_CLI::line( '• Key source:      ' . ( $result['key_source'] ?? 'unknown' ) );
		// Rows are verified with the signature format they were written in (MerkleTreeEngine::SIGNATURE_VERSION).
		$by_version = (array) ( $result['records_by_signature_version'] ?? [] );
		WP_CLI::line( '• Decisions, format 1 (before categories): ' . (int) ( $by_version[1] ?? 0 ) );
		WP_CLI::line( '• Decisions, format 2 (with categories):   ' . (int) ( $by_version[2] ?? 0 ) );
		WP_CLI::line( '• Policy version:  ' . Settings::policy_version() );
		// Was: "Proof of Consistency: VERIFIED IN COURT (GoBD Compliant)". A command cannot
		// assert that a court accepted anything, nor that an installation is GoBD compliant.
		if ( 'wp-config' !== ( $result['key_source'] ?? '' ) ) {
			WP_CLI::warning( 'AUTH_KEY ist nicht definiert: der Schlüssel stammt aus der Datenbank, also von dort, wo ein Angreifer ohnehin schreiben würde.' );
		}
	}
}
