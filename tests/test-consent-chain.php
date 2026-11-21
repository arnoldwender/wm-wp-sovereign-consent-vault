<?php
/**
 * Falsifiers for the "manipulationssicher" claim on the consent vault.
 *
 * Measured on 2026-08-27, before this release, three separate holes:
 *
 *   1. verify_chain_integrity() never recomputed the record signature. It only checked that
 *      merkle_root equalled sha256( prev_hash . record_hash ) using the STORED record_hash, so
 *      the chain never bound the consent it exists to protect. Flipping `marketing` from 0 to 1
 *      on a stored record — fabricating consent to marketing — left the audit reporting
 *      valid=true.
 *   2. Both hashes were unkeyed plain sha256 over values that are all stored in the row, so
 *      anyone with database write access could rewrite a consent and recompute every hash after
 *      it without holding a secret.
 *   3. Deleting the newest node was invisible: a truncated chain verifies perfectly on its own.
 *
 * It is also not a Merkle tree — no leaves, no internal nodes, no root, just a linear chain. The
 * class and column names are kept to avoid churn across 27 call sites; the docs no longer claim
 * a tree.
 *
 * Mutation checks:
 *   - remove the record-signature comparison → case 2 fails.
 *   - remove the tip comparison → case 4 fails.
 *   - make key() return a constant string → case 6 fails.
 *
 * WARNING: truncates the consent tables. Scratch installs only.
 *
 * Run: wp eval-file wp-content/plugins/wm-sovereign-consent-vault/tests/test-consent-chain.php --allow-root
 *
 * @package WMCyber\Consent\Vault
 */

use WMCyber\Consent\Vault\Crypto\MerkleTreeEngine;
use WMCyber\Consent\Vault\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}

// eval-file runs the file inside a function, so `global` would not bind for the helper below.
$GLOBALS['wm_c_passed'] = 0;
$GLOBALS['wm_c_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_c_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_c_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_c_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
Schema::create_tables();
$records = Schema::get_records_table();
$merkle  = Schema::get_merkle_table();

echo "\n=== CONSENT VAULT CHAIN TEST ===\n\n";

/**
 * Fresh vault with three consent records.
 *
 * @return MerkleTreeEngine
 */
function wm_c_fixture() {
	global $wpdb;
	$records = Schema::get_records_table();
	$merkle  = Schema::get_merkle_table();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "TRUNCATE TABLE {$records}" );
	$wpdb->query( "TRUNCATE TABLE {$merkle}" );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	delete_option( MerkleTreeEngine::TIP_OPTION );

	$engine = new MerkleTreeEngine();
	$engine->append_record( 'probe-u1', '203.0.113.1', true, false, false, 'UA/1' );
	$engine->append_record( 'probe-u2', '203.0.113.2', true, true, true, 'UA/1' );
	$engine->append_record( 'probe-u3', '203.0.113.3', true, false, false, 'UA/1' );

	return $engine;
}

// --- Case 1: an untouched vault verifies -----------------------------------
$engine = wm_c_fixture();
$r = $engine->verify_chain_integrity();
wm_c_assert( true === $r['valid'], 'an untouched vault verifies' );
wm_c_assert( 3 === (int) $r['total_nodes'], 'all three nodes were walked (got ' . (int) $r['total_nodes'] . ')' );

// --- Case 2: fabricating consent is detected -------------------------------
// The visitor refused marketing. Someone sets the column to 1.
$engine = wm_c_fixture();
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "UPDATE {$records} SET marketing = 1 WHERE consent_uuid = 'probe-u1'" );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'], 'flipping marketing on a stored consent is detected' );
wm_c_assert( 'CONSENT_RECORD_ALTERED' === ( $r['error'] ?? '' ), 'the reason names the altered record' );

$still = (int) $wpdb->get_var( "SELECT marketing FROM {$records} WHERE consent_uuid = 'probe-u1'" );
wm_c_assert( 1 === $still, 'the falsified consent is still in place — the chain reports, it does not repair' );

// --- Case 3: deleting the consent record behind a node is detected ---------
$engine = wm_c_fixture();
$wpdb->query( "DELETE FROM {$records} WHERE consent_uuid = 'probe-u2'" );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'], 'deleting the consent record behind a node is detected' );
wm_c_assert( 'CONSENT_RECORD_MISSING' === ( $r['error'] ?? '' ), 'the reason names the missing record' );

// --- Case 4: deleting the newest node is detected --------------------------
$engine = wm_c_fixture();
$max = (int) $wpdb->get_var( "SELECT MAX(node_index) FROM {$merkle}" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$merkle} WHERE node_index = %d", $max ) );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'], 'deleting the newest node is caught by the recorded tip' );

// --- Case 5: removing a node from the middle is detected -------------------
$engine = wm_c_fixture();
$mid = (int) $wpdb->get_var( "SELECT node_index FROM {$merkle} ORDER BY node_index ASC LIMIT 1 OFFSET 1" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$merkle} WHERE node_index = %d", $mid ) );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'], 'removing a node from the middle is detected' );

// --- Case 6: an unkeyed re-sign no longer forges the chain -----------------
// The attack the old design allowed: rewrite a consent and recompute every hash after it with the
// public formula. The tip is in wp_options, which the same attacker can write, so it is fixed up
// too — leaving only the key standing.
$engine = wm_c_fixture();
$nodes  = $wpdb->get_results( "SELECT * FROM {$merkle} ORDER BY node_index ASC", ARRAY_A );
$prev   = MerkleTreeEngine::GENESIS_HASH;

$wpdb->query( "UPDATE {$records} SET marketing = 1 WHERE consent_uuid = 'probe-u1'" );

foreach ( $nodes as $node ) {
	$rec = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$records} WHERE id = %d", (int) $node['record_id'] ), ARRAY_A );
	$sig = hash(
		'sha256',
		$rec['consent_uuid'] . '|' . $rec['ip_hmac'] . '|' . (int) $rec['essential'] . '|' . (int) $rec['analytics'] . '|' . (int) $rec['marketing'] . '|' . $rec['created_at']
	);
	$root = hash( 'sha256', $prev . $sig );
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$merkle} SET prev_hash = %s, record_hash = %s, merkle_root = %s WHERE node_index = %d",
			$prev,
			$sig,
			$root,
			(int) $node['node_index']
		)
	);
	$prev = $root;
}
update_option(
	MerkleTreeEngine::TIP_OPTION,
	array( 'hash' => $prev, 'count' => count( $nodes ) ),
	false
);
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'], 'a full unkeyed re-sign with the tip fixed up is still detected' );

// --- Case 7: the key is not in the database, and not in the source ---------
// No test can prove a secret is secret. What it can prove is that the key is derived from the
// site's own configuration rather than written into a file that ships with the plugin — which is
// the failure this release removes, and which case 6 alone does not catch.
wm_c_assert( 'wp-config' === MerkleTreeEngine::key_source(), 'the chain key comes from wp-config.php on this install' );

$engine_code = implode(
	"\n",
	array_filter(
		file( WM_CONSENT_VAULT_PATH . 'includes/Crypto/MerkleTreeEngine.php', FILE_IGNORE_NEW_LINES ),
		static function ( $line ) {
			$t = ltrim( $line );
			return '' !== $t && 0 !== strpos( $t, '*' ) && 0 !== strpos( $t, '//' ) && 0 !== strpos( $t, '/*' );
		}
	)
);
$key_body = substr( $engine_code, strpos( $engine_code, 'private static function key()' ) );
$key_body = substr( $key_body, 0, strpos( $key_body, '}' ) );
wm_c_assert(
	false !== strpos( $key_body, 'AUTH_KEY' ) && false !== strpos( $key_body, 'wp_salt' ),
	'the key is derived from site configuration, not written into the plugin source'
);

// --- Case 8: every return carries the key source ---------------------------
// It was missing from the empty-chain branch, so `wp consent audit_merkle` printed
// "Key source: unknown" and warned that AUTH_KEY was absent on an install where it is present.
$wpdb->query( "TRUNCATE TABLE {$records}" );
$wpdb->query( "TRUNCATE TABLE {$merkle}" );
delete_option( MerkleTreeEngine::TIP_OPTION );
$empty = ( new MerkleTreeEngine() )->verify_chain_integrity();
wm_c_assert( 'EMPTY_CHAIN' === $empty['status'], 'an empty vault reports EMPTY_CHAIN' );
wm_c_assert( isset( $empty['key_source'] ) && 'wp-config' === $empty['key_source'], 'the empty-chain result still reports the key source' );

$engine = wm_c_fixture();
$wpdb->query( "UPDATE {$records} SET marketing = 1 WHERE consent_uuid = 'probe-u1'" );
$broken = $engine->verify_chain_integrity();
wm_c_assert( isset( $broken['key_source'] ), 'a failing result reports the key source too' );

// --- Case 9: emptying the whole node table is detected (2026-09-14) ---------
// The empty-chain branch returned before comparing the recorded tip, so TRUNCATE verified as valid.
$engine = wm_c_fixture();
$wpdb->query( "TRUNCATE TABLE {$merkle}" );
$emptied = $engine->verify_chain_integrity();
wm_c_assert( false === $emptied['valid'] && 'CHAIN_TRUNCATED' === ( $emptied['error'] ?? '' ), 'emptying every node while the tip says three were written is detected' );

// --- Case 10: a consent row written without a node is detected (2026-09-16) ---
// Verification walked the nodes and never looked at the records table, so a row inserted straight into
// it counted as a protokollierte Entscheidung on the admin screen and passed every check.
$engine = wm_c_fixture();
$wpdb->query(
	$wpdb->prepare(
		"INSERT INTO {$records} (consent_uuid, ip_hmac, essential, analytics, marketing, user_agent, signature_hash, created_at) VALUES (%s, %s, 1, 1, 1, %s, %s, %s)",
		'probe-forged',
		str_repeat( 'a', 64 ),
		'UA/1',
		str_repeat( 'b', 64 ),
		current_time( 'mysql' )
	)
);
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'] && 'UNCHAINED_RECORD' === ( $r['error'] ?? '' ), 'a consent row inserted with no chain node is detected' );

// --- Case 11: a later consent does not launder a cut node ---------------------
// The append read the last surviving row and recounted the tip with COUNT(*), so the next decision
// repaired the chain over the gap. Measured on a local WordPress install before this release.
$engine     = wm_c_fixture();
$max        = (int) $wpdb->get_var( "SELECT MAX(node_index) FROM {$merkle}" );
$cut_record = (int) $wpdb->get_var( $wpdb->prepare( "SELECT record_id FROM {$merkle} WHERE node_index = %d", $max ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$merkle} WHERE node_index = %d", $max ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$records} WHERE id = %d", $cut_record ) );
$engine->append_record( 'probe-u4', '203.0.113.4', true, false, false, 'UA/1' );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'], 'a consent recorded after a node was cut does not hide the cut' );

// --- Case 12: the recorded tip is signed, and a rewritten one is caught -------
$engine = wm_c_fixture();
$tip    = get_option( MerkleTreeEngine::TIP_OPTION );
wm_c_assert( is_array( $tip ) && isset( $tip['sig'] ), 'the recorded tip carries a signature' );
$max        = (int) $wpdb->get_var( "SELECT MAX(node_index) FROM {$merkle}" );
$cut_record = (int) $wpdb->get_var( $wpdb->prepare( "SELECT record_id FROM {$merkle} WHERE node_index = %d", $max ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$merkle} WHERE node_index = %d", $max ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$records} WHERE id = %d", $cut_record ) );
$surviving = (string) $wpdb->get_var( "SELECT merkle_root FROM {$merkle} ORDER BY node_index DESC LIMIT 1" );
update_option( MerkleTreeEngine::TIP_OPTION, array( 'hash' => $surviving, 'count' => 2 ), false );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'] && 'TIP_UNSIGNED' === ( $r['error'] ?? '' ), 'a tip rewritten without the key no longer hides the cut node' );

// --- Case 13: nothing is written while another connection holds the chain lock -
// The falsifier the standalone suite cannot run: a second database connection takes the named lock, so
// the append meets exactly what a second visitor's request would meet.
$engine       = wm_c_fixture();
$other        = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$held         = (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK( %s, %d )', 'wm_consent_chain', 1 ) );
$nodes_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$merkle}" );
$busy         = $engine->append_record( 'probe-busy', '203.0.113.9', true, false, false, 'UA/1' );
$nodes_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$merkle}" );
$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK( %s )', 'wm_consent_chain' ) );
wm_c_assert(
	'1' === $held && 'chain_busy' === ( $busy['error'] ?? '' ) && $nodes_before === $nodes_after,
	'while another connection holds the chain lock, no node and no consent row are written'
);
$after_release = $engine->append_record( 'probe-after-lock', '203.0.113.10', true, false, false, 'UA/1' );
wm_c_assert( isset( $after_release['merkle_root'] ) && true === $engine->verify_chain_integrity()['valid'], 'once the lock is free the decision is recorded and the chain verifies' );

// --- Case 14: a decision with categories, against a real MySQL table (2026-09-17) ---
// Schema 3 added `categories`, `policy_version` and `signature_version`; what the standalone suite cannot check is
// that MySQL stores and returns them the way the signature expects.
$engine = wm_c_fixture();
$engine->append_record( 'probe-cat-1', '203.0.113.5', true, true, false, 'UA/kat', array( 'statistics', 'media' ), '7' );
$stored = $wpdb->get_row( "SELECT * FROM {$records} WHERE consent_uuid = 'probe-cat-1'", ARRAY_A );
wm_c_assert( 'necessary,statistics,media' === ( $stored['categories'] ?? '' ), 'the categories are stored in canonical order' );
wm_c_assert( '7' === ( $stored['policy_version'] ?? '' ) && 2 === (int) ( $stored['signature_version'] ?? 0 ), 'the policy version and signature format 2 are stored' );
$r = $engine->verify_chain_integrity();
wm_c_assert( true === $r['valid'] && 1 === (int) ( $r['records_by_signature_version'][2] ?? 0 ), 'the chain verifies and counts the decision as format 2' );

$wpdb->query( "UPDATE {$records} SET categories = 'necessary,statistics,marketing,media' WHERE consent_uuid = 'probe-cat-1'" );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'] && 'CONSENT_RECORD_ALTERED' === ( $r['error'] ?? '' ), 'adding a category to a stored decision is detected' );

// --- Case 15: the columns of the upgrade, with the default that makes older rows format 1 ---
$columns = array();
foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$records}" ) as $column ) {
	$columns[ (string) $column->Field ] = (string) $column->Default;
}
wm_c_assert( array_key_exists( 'categories', $columns ) && array_key_exists( 'policy_version', $columns ), 'the table has the category and policy columns' );
wm_c_assert( '1' === ( $columns['signature_version'] ?? '' ), 'rows written before the upgrade default to signature format 1' );

// --- Case 16: a row from before the categories still verifies next to a new one -------
$engine     = wm_c_fixture();
$wpdb->query( "TRUNCATE TABLE {$records}" );
$wpdb->query( "TRUNCATE TABLE {$merkle}" );
delete_option( MerkleTreeEngine::TIP_OPTION );
$legacy_time = current_time( 'mysql' );
$legacy_ip   = hash_hmac( 'sha256', '203.0.113.6', wp_salt( 'auth' ) );
$legacy_sig  = MerkleTreeEngine::record_signature( 'probe-legacy', $legacy_ip, true, false, false, $legacy_time );
$wpdb->query(
	$wpdb->prepare(
		"INSERT INTO {$records} (consent_uuid, ip_hmac, essential, analytics, marketing, user_agent, signature_hash, created_at) VALUES (%s, %s, 1, 0, 0, %s, %s, %s)",
		'probe-legacy',
		$legacy_ip,
		'UA/alt',
		$legacy_sig,
		$legacy_time
	)
);
$legacy_id   = (int) $wpdb->insert_id;
$legacy_link = MerkleTreeEngine::chain_link( MerkleTreeEngine::GENESIS_HASH, $legacy_sig );
$wpdb->query(
	$wpdb->prepare(
		"INSERT INTO {$merkle} (record_id, prev_hash, record_hash, merkle_root, timestamp) VALUES (%d, %s, %s, %s, %s)",
		$legacy_id,
		MerkleTreeEngine::GENESIS_HASH,
		$legacy_sig,
		$legacy_link,
		$legacy_time
	)
);
update_option( MerkleTreeEngine::TIP_OPTION, array( 'hash' => $legacy_link, 'count' => 1, 'sig' => MerkleTreeEngine::tip_signature( $legacy_link, 1 ) ), false );
$engine->append_record( 'probe-after-legacy', '203.0.113.7', true, false, false, 'UA/neu', array(), '1' );
$r = $engine->verify_chain_integrity();
wm_c_assert(
	true === $r['valid'] && 1 === (int) ( $r['records_by_signature_version'][1] ?? 0 ) && 1 === (int) ( $r['records_by_signature_version'][2] ?? 0 ),
	'a row written before the categories verifies with format 1 beside a format-2 row'
);
$wpdb->query( "UPDATE {$records} SET categories = 'necessary,marketing' WHERE consent_uuid = 'probe-legacy'" );
$r = $engine->verify_chain_integrity();
wm_c_assert( false === $r['valid'] && 'CONSENT_RECORD_ALTERED' === ( $r['error'] ?? '' ), 'categories written into a format-1 row are detected' );

// --- Case 17: a long user agent with umlauts survives the column and the signature ----
// substr() would cut inside a multi-byte character; MySQL refuses the value and the decision would be lost.
$engine = wm_c_fixture();
$engine->append_record( 'probe-umlaut', '203.0.113.8', true, false, false, str_repeat( 'Brüller ', 60 ), array(), '1' );
$agent = (string) $wpdb->get_var( "SELECT user_agent FROM {$records} WHERE consent_uuid = 'probe-umlaut'" );
wm_c_assert( 255 === mb_strlen( $agent, 'UTF-8' ) && true === $engine->verify_chain_integrity()['valid'], 'a 480-character user agent is stored as 255 characters and still verifies' );

// --- Teardown -------------------------------------------------------------
$wpdb->query( "TRUNCATE TABLE {$records}" );
$wpdb->query( "TRUNCATE TABLE {$merkle}" );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
delete_option( MerkleTreeEngine::TIP_OPTION );

echo "\n=== RESULT: {$GLOBALS['wm_c_passed']} passed, {$GLOBALS['wm_c_failed']} failed ===\n\n";
