<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Database\Schema;

final class MerkleTreeEngine {
	/** Predecessor of the first node. Public: any external verifier needs it. */
	public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Signature format of the rows append_record() writes now.
	 *
	 * 1 — record_signature(): uuid, blinded IP, the three flags and the time, joined with "|". Every row written
	 *     before 2026-09-17 carries it, and schema version 3 stores 1 in their `signature_version` column.
	 * 2 — record_signature_v2(): the same values plus the chosen categories, the policy version and the user agent,
	 *     encoded as a JSON list so that a "|" inside the user agent cannot move a field boundary.
	 *
	 * Old rows are verified with the format they were written in and are never re-signed: re-signing would need the
	 * key and would erase the evidence that a row was not changed since it was written. Flipping the column does not
	 * help an attacker either — both formats are keyed, so a row verified with the other format fails.
	 */
	public const SIGNATURE_VERSION        = 2;
	public const LEGACY_SIGNATURE_VERSION = 1;

	/** What a policy version stored in the log may look like (same rule as the settings screen). */
	private const POLICY_VERSION_PATTERN = '/^[A-Za-z0-9._-]{0,32}$/';

	/** Mirrors the chain tip outside the chain tables so node deletion is detectable. */
	public const TIP_OPTION = 'wm_consent_chain_tip';

	/** Named database lock held while a node is appended, so two requests cannot read the same tip. */
	private const LOCK_NAME           = 'wm_consent_chain';
	private const LOCK_TIMEOUT        = 0;
	private const LOCK_RETRY_ATTEMPTS = 8;

	/** An anchor hash from another plugin is a hex SHA-256; the column holds 64 characters. */
	private const HASH_PATTERN = '/^[a-f0-9]{64}$/i';

	/** The phrase wp-config-sample.php ships with; an install that kept it has a publicly known key. */
	private const SAMPLE_KEY = 'put your unique phrase here';

	/** Where the signing key comes from on this install. */
	public static function key_source(): string {
		return defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ? 'wp-config' : 'database';
	}

	/**
	 * True when the key is the one from wp-config-sample.php.
	 *
	 * Then every signature in this chain can be recomputed by anyone, so the verification proves
	 * nothing about who wrote the rows: it is reported, not silently treated as a configured site.
	 */
	public static function key_is_sample(): bool {
		return 'wp-config' === self::key_source() && self::SAMPLE_KEY === (string) AUTH_KEY;
	}

	/**
	 * The HMAC key for records, chain links and the recorded tip.
	 *
	 * Both hashes used to be plain hash( 'sha256', ... ) over values that are all stored in the
	 * row, so anyone with database write access could rewrite a consent and recompute every hash
	 * after it without holding any secret.
	 *
	 * @return string
	 */
	private static function key(): string {
		return 'wp-config' === self::key_source() ? (string) AUTH_KEY : wp_salt( 'secure_auth' );
	}

	/**
	 * Signature over what the visitor actually consented to.
	 *
	 * One function, used by the writer and by verification. Until this release nothing ever
	 * recomputed it: verify_chain_integrity() only checked that merkle_root equalled
	 * sha256( prev_hash . record_hash ) using the STORED record_hash, so the chain never bound
	 * the consent it exists to protect. Measured on a clean install: flipping `marketing` from 0
	 * to 1 on a stored record — fabricating consent to marketing — left the audit reporting
	 * valid=true.
	 *
	 * @param string $uuid      Consent record UUID.
	 * @param string $ip_hmac   Blinded client address.
	 * @param bool   $essential Essential consent.
	 * @param bool   $analytics Analytics consent.
	 * @param bool   $marketing Marketing consent.
	 * @param string $timestamp Timestamp as stored.
	 * @return string Hex HMAC.
	 */
	public static function record_signature( string $uuid, string $ip_hmac, bool $essential, bool $analytics, bool $marketing, string $timestamp ): string {
		$payload = "{$uuid}|{$ip_hmac}|" . (int) $essential . '|' . (int) $analytics . '|' . (int) $marketing . "|{$timestamp}";

		return hash_hmac( 'sha256', $payload, self::key() );
	}

	/**
	 * Signature format 2: what the visitor chose, under which policy version, from which browser.
	 *
	 * The payload is a JSON list in a fixed order, prefixed with "v2". Format 1 left the user agent unsigned, so a
	 * changed user agent went unnoticed (README, known issues); format 2 covers it, and the categories and the policy
	 * version the decision was given for.
	 *
	 * @param string $uuid           Consent record UUID.
	 * @param string $ip_hmac        Blinded client address.
	 * @param bool   $essential      Essential consent.
	 * @param bool   $analytics      Analytics (statistics) consent.
	 * @param bool   $marketing      Marketing consent.
	 * @param string $categories     Canonical category list as stored (Categories::to_string()).
	 * @param string $policy_version Policy version as stored.
	 * @param string $user_agent     User agent as stored.
	 * @param string $timestamp      Timestamp as stored.
	 * @return string|null Hex HMAC; null when the values cannot be encoded (invalid UTF-8), which callers treat as
	 *                     "not writable" and "altered".
	 */
	public static function record_signature_v2( string $uuid, string $ip_hmac, bool $essential, bool $analytics, bool $marketing, string $categories, string $policy_version, string $user_agent, string $timestamp ): ?string {
		$payload = wp_json_encode(
			[ 'v2', $uuid, $ip_hmac, (int) $essential, (int) $analytics, (int) $marketing, $categories, $policy_version, $user_agent, $timestamp ],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $payload ) ) {
			return null;
		}
		return hash_hmac( 'sha256', $payload, self::key() );
	}

	/**
	 * The signature a stored record must carry, computed with the format its `signature_version` names.
	 *
	 * A format-1 row has no categories and no policy version: if either column holds a value, someone wrote it
	 * afterwards (format 1 does not sign them), so there is no valid signature and the row counts as altered.
	 * A row without the column at all predates schema version 3 and is format 1.
	 *
	 * @param array<string, mixed> $record Row as read from the records table.
	 * @return string|null Expected signature; null when the row cannot verify under any format.
	 */
	public static function expected_signature( array $record ): ?string {
		$version = (int) ( $record['signature_version'] ?? self::LEGACY_SIGNATURE_VERSION );

		if ( self::LEGACY_SIGNATURE_VERSION === $version ) {
			if ( '' !== (string) ( $record['categories'] ?? '' ) || '' !== (string) ( $record['policy_version'] ?? '' ) ) {
				return null;
			}
			return self::record_signature(
				(string) $record['consent_uuid'],
				(string) $record['ip_hmac'],
				(bool) $record['essential'],
				(bool) $record['analytics'],
				(bool) $record['marketing'],
				(string) $record['created_at']
			);
		}

		if ( self::SIGNATURE_VERSION === $version ) {
			return self::record_signature_v2(
				(string) $record['consent_uuid'],
				(string) $record['ip_hmac'],
				(bool) $record['essential'],
				(bool) $record['analytics'],
				(bool) $record['marketing'],
				(string) ( $record['categories'] ?? '' ),
				(string) ( $record['policy_version'] ?? '' ),
				(string) ( $record['user_agent'] ?? '' ),
				(string) $record['created_at']
			);
		}

		return null;
	}

	/**
	 * Link binding a record to its predecessor.
	 *
	 * This is a linear hash chain, not a Merkle tree: there are no leaves, no internal nodes and
	 * no root — each entry simply binds the one before it. The class and column names say Merkle
	 * for historical reasons and are kept to avoid churn across 27 call sites; the documentation
	 * no longer claims a tree.
	 *
	 * @param string $prev_hash   Predecessor link.
	 * @param string $record_hash Record signature.
	 * @return string Hex HMAC.
	 */
	public static function chain_link( string $prev_hash, string $record_hash ): string {
		return hash_hmac( 'sha256', $prev_hash . '|' . $record_hash, self::key() );
	}

	/**
	 * Signature over the recorded tip.
	 *
	 * The tip lives in wp_options, which is exactly where someone who can delete chain rows can
	 * also write: without this, cutting the newest nodes and setting the option to the surviving
	 * root hid the truncation without holding the key, although the documentation promised the
	 * opposite.
	 *
	 * @param string $hash  Tip link.
	 * @param int    $count Number of nodes written.
	 * @return string Hex HMAC.
	 */
	public static function tip_signature( string $hash, int $count ): string {
		return hash_hmac( 'sha256', $hash . '|' . $count, self::key() );
	}

	/**
	 * Sign the tip of an install that recorded its chain before the tip carried a signature.
	 *
	 * Only signs a chain that verifies with the unsigned tip: signing a broken one would state
	 * that this site vouches for rows it cannot verify. Called once per schema version bump.
	 */
	public static function migrate_tip_signature(): void {
		$tip = get_option( self::TIP_OPTION, null );
		if ( ! is_array( $tip ) || ! isset( $tip['hash'] ) || isset( $tip['sig'] ) ) {
			return;
		}
		if ( true !== ( new self() )->verify_chain_integrity( true )['valid'] ) {
			return;
		}
		self::store_tip( (string) $tip['hash'], (int) ( $tip['count'] ?? 0 ) );
	}

	/** Write the tip with its signature. */
	private static function store_tip( string $hash, int $count ): void {
		update_option(
			self::TIP_OPTION,
			[
				'hash'  => $hash,
				'count' => $count,
				'sig'   => self::tip_signature( $hash, $count ),
			],
			false
		);
	}

	/**
	 * Hold the named lock around one append.
	 *
	 * Two visitors clicking within the same millisecond used to read the same last node and write
	 * two nodes naming the same predecessor — a fork that verification reports, correctly, as
	 * PREV_HASH_MISMATCH: a busy site accused itself of tampering. `1` is the lock; `0` is a
	 * timeout and null a database that does not answer GET_LOCK, and neither may write a node.
	 */
	private function lock(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::LOCK_NAME, self::LOCK_TIMEOUT ) );
	}

	/**
	 * Acquire the named chain lock with bounded non-blocking retries and jittered backoff.
	 *
	 * Uses GET_LOCK(..., 0) instead of a multi-second blocking wait so PHP-FPM workers are not held
	 * stalled under contention, retrying up to LOCK_RETRY_ATTEMPTS times with 15-45 ms jitter
	 * between failed attempts before surfacing chain_busy.
	 */
	private function acquire_lock_with_jitter(): bool {
		/**
		 * Filters whether bounded jittered backoff is enabled when acquiring the chain lock.
		 *
		 * @param bool $enabled Default true. Returning false performs one non-blocking attempt.
		 */
		$backoff_enabled = (bool) apply_filters( 'wm_consent_lock_backoff_enabled', true );
		$max_attempts    = $backoff_enabled ? self::LOCK_RETRY_ATTEMPTS : 1;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			if ( $this->lock() ) {
				return true;
			}
			if ( $backoff_enabled && $attempt < $max_attempts - 1 ) {
				usleep( random_int( 15000, 45000 ) );
			}
		}

		return false;
	}

	private function unlock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK_NAME ) );
	}

	/**
	 * Predecessor and node number for the next node, taken from the recorded tip.
	 *
	 * Reading the last surviving row instead — what this did until 2026-09-16 — let the next
	 * consent launder a truncation: delete the newest node, and the following append linked to
	 * the row before it and rewrote the tip from COUNT(*), leaving a chain that verifies.
	 * Measured on a local WordPress install: node 3 gone, node 4 linked to node 2, status INTACT.
	 *
	 * @return array{0: string, 1: int} Predecessor hash and the count the tip will carry.
	 */
	private function next_link(): array {
		global $wpdb;

		$table_merkle = Schema::get_merkle_table();
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::TIP_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		$tip          = get_option( self::TIP_OPTION, null );

		if ( is_array( $tip ) && isset( $tip['hash'] ) ) {
			return [ (string) $tip['hash'], (int) ( $tip['count'] ?? 0 ) + 1 ];
		}

		// No tip recorded: an install from before the option existed, or a vault that never wrote.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		$last  = $wpdb->get_row( $wpdb->prepare( 'SELECT merkle_root FROM %i ORDER BY node_index DESC LIMIT %d', $table_merkle, 1 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_merkle ) );

		return [ $last ? (string) $last->merkle_root : self::GENESIS_HASH, $count + 1 ];
	}

	/**
	 * Append a consent decision to the chain, signed with format 2; `error` in the result when it was not stored.
	 *
	 * @param string       $uuid           Client-generated decision id.
	 * @param string       $ip             Client address; only its HMAC is stored.
	 * @param bool         $essential      Essential consent (always true from the banner).
	 * @param bool         $analytics      Consent to the statistics category.
	 * @param bool         $marketing      Consent to the marketing category.
	 * @param string       $user_agent     User agent as received; the first 255 characters are stored and signed.
	 * @param list<string> $categories     Accepted categories. Empty: derived from the two flags (callers that
	 *                                     predate the categories). When given, the flags must agree with it.
	 * @param string       $policy_version Policy version the banner showed; '' when the client did not say.
	 * @return array<string, mixed> record_id, node_index, consent_uuid, signature, merkle_root, categories,
	 *                              policy_version, signature_version — or error and consent_uuid.
	 */
	public function append_record( string $uuid, string $ip, bool $essential, bool $analytics, bool $marketing, string $user_agent, array $categories = [], string $policy_version = '' ): array {
		global $wpdb;

		$table_records = Schema::get_records_table();
		$table_merkle  = Schema::get_merkle_table();

		// The flags and the category list describe the same decision twice (the flags are kept for everything that
		// read them before the categories existed). A caller that sends both in disagreement would store a row whose
		// counts on the admin screen contradict its categories, so it is refused.
		if ( [] === $categories ) {
			$categories = array_merge( $analytics ? [ Categories::STATISTICS ] : [], $marketing ? [ Categories::MARKETING ] : [] );
		} elseif ( ! Categories::all_known( $categories )
			|| in_array( Categories::STATISTICS, Categories::canonical( $categories ), true ) !== $analytics
			|| in_array( Categories::MARKETING, Categories::canonical( $categories ), true ) !== $marketing ) {
			return [
				'error'        => 'inconsistent_categories',
				'consent_uuid' => $uuid,
			];
		}
		if ( 1 !== preg_match( self::POLICY_VERSION_PATTERN, $policy_version ) ) {
			return [
				'error'        => 'invalid_policy_version',
				'consent_uuid' => $uuid,
			];
		}
		$categories_stored = Categories::to_string( $categories );

		$ip_hmac   = hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$timestamp = current_time( 'mysql' );
		// Characters, not bytes: the column holds 255 characters, and a user agent cut inside a multi-byte character
		// is rejected by $wpdb as invalid data, so the decision would not be written.
		$user_agent = mb_substr( $user_agent, 0, 255, 'UTF-8' );

		$signature_hash = self::record_signature_v2(
			$uuid,
			$ip_hmac,
			$essential,
			$analytics,
			$marketing,
			$categories_stored,
			$policy_version,
			$user_agent,
			$timestamp
		);
		if ( null === $signature_hash ) {
			return [
				'error'        => 'record_not_written: the values could not be signed',
				'consent_uuid' => $uuid,
			];
		}

		if ( ! $this->acquire_lock_with_jitter() ) {
			return [
				'error'        => 'chain_busy',
				'consent_uuid' => $uuid,
			];
		}

		try {
			// A repeated uuid is answered before touching the table. Relying on UNIQUE KEY alone made
			// $wpdb print "Duplicate entry" on sites with WP_DEBUG_DISPLAY, before the REST headers, so the
			// 409 went out as 200 with a PHP warning (measured on a local WordPress install 2026-09-14).
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_records} WHERE consent_uuid = %s LIMIT 1", $uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			if ( null !== $existing ) {
				return [
					'error'        => 'duplicate_or_failed_insert',
					'consent_uuid' => $uuid,
				];
			}

			// The consent row and its chain node are written inside one transaction: a worker killed
			// between the two inserts (OOM on the 128 MB shared hosting the fleet runs on) used to leave
			// a node the tip did not know about, and from then on every verification reported the chain
			// as TAMPERED for an incident nobody caused. Rolled back, neither row exists and the caller
			// is told the decision was not recorded. The window is not closed entirely: store_tip()
			// writes an option after the commit, so a kill in that gap still leaves the tip one node
			// behind. Narrowing it is what this does; the semantics of that state are unchanged and
			// still reported as a break.
			$wpdb->query( 'START TRANSACTION' );

			// Insert Record. UNIQUE KEY consent_uuid_idx still guards a race between two requests; its
			// error is kept out of the response body. Before the check below a failed insert was ignored
			// and a chain node with record_id 0 — indistinguishable from an external anchor — was appended.
			$suppressed = $wpdb->suppress_errors( true );
			$inserted   = $wpdb->insert(
				$table_records,
				[
					'consent_uuid'      => $uuid,
					'ip_hmac'           => $ip_hmac,
					'essential'         => $essential ? 1 : 0,
					'analytics'         => $analytics ? 1 : 0,
					'marketing'         => $marketing ? 1 : 0,
					'categories'        => $categories_stored,
					'policy_version'    => $policy_version,
					'signature_version' => self::SIGNATURE_VERSION,
					'user_agent'        => $user_agent,
					'signature_hash'    => $signature_hash,
					'created_at'        => $timestamp,
				]
			);
			$wpdb->suppress_errors( $suppressed );
			$record_id = (int) $wpdb->insert_id;
			if ( false === $inserted || $record_id <= 0 ) {
				$wpdb->query( 'ROLLBACK' );
				return [
					'error'        => 'duplicate_or_failed_insert',
					'consent_uuid' => $uuid,
				];
			}

			[ $prev_hash, $count ] = $this->next_link();
			$merkle_root           = self::chain_link( $prev_hash, $signature_hash );

			$node_written = $wpdb->insert(
				$table_merkle,
				[
					'record_id'    => $record_id,
					'prev_hash'    => $prev_hash,
					'record_hash'  => $signature_hash,
					'merkle_root'  => $merkle_root,
					'timestamp'    => $timestamp,
				]
			);
			// The node number is read here: update_option() below writes to wp_options and moves
			// insert_id, so the value returned to the caller used to be an option row id.
			$node_index = (int) $wpdb->insert_id;

			if ( false === $node_written ) {
				// A consent row no node points to is invisible to verification, so it does not stay:
				// the decision was not recorded and the caller is told so.
				// The rollback removes the row on a transactional engine; the explicit delete stays for a
				// table that is not one (MyISAM ignores the transaction silently), so it goes either way.
				$wpdb->query( 'ROLLBACK' );
				$wpdb->delete( $table_records, [ 'id' => $record_id ], [ '%d' ] );
				return [
					'error'        => 'record_not_written: the chain node was refused',
					'consent_uuid' => $uuid,
				];
			}

			// Both rows are in the database from here on, or neither is.
			$wpdb->query( 'COMMIT' );

			// Outside the chain tables on purpose: a chain with its newest nodes cut off verifies
			// perfectly on its own. Measured before this release: deleting the newest node left the
			// audit reporting valid=true. The count comes from the tip, not from COUNT(*), which a
			// deletion would silently correct.
			self::store_tip( $merkle_root, $count );
		} finally {
			$this->unlock();
		}

		return [
			'record_id'         => $record_id,
			'node_index'        => $node_index,
			'consent_uuid'      => $uuid,
			'signature'         => $signature_hash,
			'merkle_root'       => $merkle_root,
			'categories'        => Categories::from_string( $categories_stored ),
			'policy_version'    => $policy_version,
			'signature_version' => self::SIGNATURE_VERSION,
		];
	}

	/**
	 * Append an external anchor to the chain.
	 *
	 * This allows external verifiable logs (like the Privacy Eraser certificate chain) to anchor
	 * their tip into this ledger, sealing them in time relative to the stream of consents.
	 *
	 * @param string $foreign_id   The external identifier (e.g. certificate ID).
	 * @param string $foreign_hash The external hash to anchor: hex SHA-256.
	 * @return array
	 */
	public function append_anchor( string $foreign_id, string $foreign_hash ): array {
		global $wpdb;

		$table_merkle = Schema::get_merkle_table();

		// A hash that is not 64 hex characters is refused instead of stored: record_hash holds 64
		// characters, so a longer value arrived truncated and every later verification reported the
		// node as corrupted.
		if ( 1 !== preg_match( self::HASH_PATTERN, $foreign_hash ) ) {
			return [
				'error'      => 'invalid_anchor_hash',
				'foreign_id' => $foreign_id,
			];
		}

		if ( ! $this->acquire_lock_with_jitter() ) {
			return [
				'error'      => 'chain_busy',
				'foreign_id' => $foreign_id,
			];
		}

		try {
			[ $prev_hash, $count ] = $this->next_link();
			$merkle_root           = self::chain_link( $prev_hash, $foreign_hash );
			$timestamp             = current_time( 'mysql' );

			$node_written = $wpdb->insert(
				$table_merkle,
				[
					'record_id'    => 0,
					'foreign_id'   => substr( $foreign_id, 0, 128 ),
					'prev_hash'    => $prev_hash,
					'record_hash'  => $foreign_hash,
					'merkle_root'  => $merkle_root,
					'timestamp'    => $timestamp,
				]
			);
			$node_index = (int) $wpdb->insert_id;

			if ( false === $node_written ) {
				return [
					'error'      => 'anchor_not_written',
					'foreign_id' => $foreign_id,
				];
			}

			self::store_tip( $merkle_root, $count );
		} finally {
			$this->unlock();
		}

		return [
			'node_index'  => $node_index,
			'foreign_id'  => $foreign_id,
			'merkle_root' => $merkle_root,
		];
	}

	/**
	 * Walk the chain: every link, every consent record behind a node, the records no node covers, and
	 * the recorded tip with its signature.
	 *
	 * Status `INTACT`, `EMPTY_CHAIN` (nothing recorded yet) or `TAMPERED` with an `error` code. What it
	 * proves: stored rows were not changed, removed, added or cut off without the key. What it does not
	 * prove: that a record came from a real visitor — anyone can fetch the REST nonce and post a decision.
	 *
	 * @param bool $tolerate_unsigned_tip Only the tip-signature migration passes true: an install that
	 *                                    recorded its chain before the tip was signed is not tampered with.
	 */
	public function verify_chain_integrity( bool $tolerate_unsigned_tip = false ): array {
		global $wpdb;
		$table_merkle  = Schema::get_merkle_table();
		$table_records = Schema::get_records_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		$nodes = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY node_index ASC', $table_merkle ), ARRAY_A );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::TIP_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		$tip   = get_option( self::TIP_OPTION, null );

		if ( empty( $nodes ) ) {
			// An empty node table is only an empty vault when nothing was ever recorded. The tip
			// option counts the nodes written; until 2026-09-14 this branch returned before looking
			// at it, so emptying the whole table (TRUNCATE or DELETE) verified as valid.
			if ( is_array( $tip ) && (int) ( $tip['count'] ?? 0 ) > 0 ) {
				return [
					'valid'        => false,
					'broken_node'  => null,
					'error'        => 'CHAIN_TRUNCATED',
					'status'       => 'TAMPERED',
					'key_source'   => self::key_source(),
					'total_nodes'  => 0,
				];
			}

			// key_source belongs on every return. Without it the CLI printed "Key source: unknown"
			// and warned that AUTH_KEY was missing on an install where it is perfectly present.
			return [
				'valid'        => true,
				'total_nodes'  => 0,
				'merkle_root'  => self::GENESIS_HASH,
				'key_source'   => self::key_source(),
				'status'       => 'EMPTY_CHAIN',
			];
		}

		$current_expected_prev = self::GENESIS_HASH;
		// Verified consent rows per signature format, reported with an intact chain (CLI, hub telemetry).
		$records_by_version = [
			self::LEGACY_SIGNATURE_VERSION => 0,
			self::SIGNATURE_VERSION        => 0,
		];

		foreach ( $nodes as $node ) {
			if ( ! hash_equals( $current_expected_prev, (string) $node['prev_hash'] ) ) {
				return [
					'valid'        => false,
					'broken_node'  => (int) $node['node_index'],
					'error'        => 'PREV_HASH_MISMATCH',
					'status'       => 'TAMPERED',
					'key_source'   => self::key_source(),
				];
			}

			if ( ! hash_equals( self::chain_link( (string) $node['prev_hash'], (string) $node['record_hash'] ), (string) $node['merkle_root'] ) ) {
				return [
					'valid'        => false,
					'broken_node'  => (int) $node['node_index'],
					'error'        => 'MERKLE_ROOT_CORRUPTED',
					'status'       => 'TAMPERED',
					'key_source'   => self::key_source(),
				];
			}

			if ( 0 !== (int) $node['record_id'] ) {
				// The link above only proves the node is consistent with the record_hash it carries.
				// Nothing used to check that record_hash still describes the consent it refers to, so
				// the consent itself could be rewritten freely. This is that check.
				$record = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$table_records} WHERE id = %d", (int) $node['record_id'] ),
					ARRAY_A
				);

				if ( null === $record ) {
					return [
						'valid'        => false,
						'broken_node'  => (int) $node['node_index'],
						'error'        => 'CONSENT_RECORD_MISSING',
						'status'       => 'TAMPERED',
						'key_source'   => self::key_source(),
					];
				}

				// Each row is checked with the signature format it was written in (see SIGNATURE_VERSION).
				$expected_signature = self::expected_signature( $record );
				$row_version        = (int) ( $record['signature_version'] ?? self::LEGACY_SIGNATURE_VERSION );

				if ( null === $expected_signature || ! hash_equals( $expected_signature, (string) $node['record_hash'] ) ) {
					return [
						'valid'        => false,
						'broken_node'  => (int) $node['node_index'],
						'error'        => 'CONSENT_RECORD_ALTERED',
						'status'       => 'TAMPERED',
						'key_source'   => self::key_source(),
					];
				}
				++$records_by_version[ $row_version ];
			}

			$current_expected_prev = $node['merkle_root'];
		}

		// A consent row that no node points to is counted on the admin screen and never verified: writing
		// one into the table fabricates a decision without touching the chain. Two counts, not a join, so
		// the query stays readable in every supported database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		$records_stored = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_records ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		$records_chained = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT record_id) FROM %i WHERE record_id > %d', $table_merkle, 0 ) );
		if ( $records_stored > $records_chained ) {
			return [
				'valid'        => false,
				'broken_node'  => null,
				'error'        => 'UNCHAINED_RECORD',
				'status'       => 'TAMPERED',
				'key_source'   => self::key_source(),
				'total_nodes'  => count( $nodes ),
			];
		}

		// Everything above only proves the surviving nodes are consistent with each other and with
		// their consent records. A chain with its newest nodes removed is internally perfect.
		if ( is_array( $tip ) && isset( $tip['hash'] ) ) {
			$signed = isset( $tip['sig'] ) && hash_equals( self::tip_signature( (string) $tip['hash'], (int) ( $tip['count'] ?? 0 ) ), (string) $tip['sig'] );
			if ( ! $signed && ! ( $tolerate_unsigned_tip && ! isset( $tip['sig'] ) ) ) {
				// Before 2026-09-16 the tip was stored unsigned, so anyone who could delete a node could
				// also rewrite the option and hide it. An unsigned tip is tolerated once, while the schema
				// upgrade signs it; afterwards it means the option was replaced.
				return [
					'valid'        => false,
					'broken_node'  => null,
					'error'        => isset( $tip['sig'] ) ? 'TIP_SIGNATURE_INVALID' : 'TIP_UNSIGNED',
					'status'       => 'TAMPERED',
					'key_source'   => self::key_source(),
					'total_nodes'  => count( $nodes ),
				];
			}

			if ( ! hash_equals( (string) $tip['hash'], $current_expected_prev ) || (int) ( $tip['count'] ?? 0 ) !== count( $nodes ) ) {
				return [
					'valid'        => false,
					'broken_node'  => null,
					'error'        => 'CHAIN_TRUNCATED',
					'status'       => 'TAMPERED',
					'key_source'   => self::key_source(),
					'total_nodes'  => count( $nodes ),
				];
			}
		}

		return [
			'valid'        => true,
			'total_nodes'  => count( $nodes ),
			'merkle_root'  => $current_expected_prev,
			'key_source'   => self::key_source(),
			'key_is_sample' => self::key_is_sample(),
			'status'       => 'INTACT',
			'records_by_signature_version' => $records_by_version,
		];
	}
}
