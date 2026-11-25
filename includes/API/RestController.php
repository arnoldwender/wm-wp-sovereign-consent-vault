<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Crypto\MerkleTreeEngine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController {
	private const REST_NAMESPACE = 'wm-consent/v1';

	/**
	 * Per-client budget for the record and public nonce routes. A banner records one decision per visit;
	 * 10/min leaves headroom on /record, while /nonce allows 120/min per client (filterable via wm_consent_rest_rate_limit).
	 */
	private const RATE_LIMIT_MAX       = 10;
	private const NONCE_RATE_LIMIT_MAX = 120;
	private const RATE_LIMIT_WINDOW    = 60;

	/** Client-generated consent id: what the banner produces, nothing else. */
	private const UUID_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

	public function register_routes(): void {
		// Uncached source of the REST nonce for the banner: a nonce baked into a full-page
		// cache would expire after 24 h and every later consent would answer 403.
		register_rest_route( self::REST_NAMESPACE, '/nonce', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_nonce' ],
			'permission_callback' => [ $this, 'check_public_nonce_access' ],
		] );

		register_rest_route( self::REST_NAMESPACE, '/record', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_record_consent' ],
			// Anonymous visitors post here, so the gate is the REST nonce plus a rate limit,
			// not a capability (fleet audit 2026-09-13 §6.2 #20).
			'permission_callback' => [ $this, 'check_record_permission' ],
			// Without this schema the three flags went through (bool) $request->get_param(), and every
			// non-empty string is true there: a body with "analytics": "false" stored consent to
			// analytics. WordPress now rejects anything that is not a boolean value with 400.
			'args'                => [
				'uuid'      => [
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^[A-Za-z0-9_-]{8,64}$',
				],
				'essential' => [
					'type'     => 'boolean',
					'required' => true,
				],
				'analytics' => [
					'type'     => 'boolean',
					'required' => true,
				],
				'marketing' => [
					'type'     => 'boolean',
					'required' => true,
				],
				// Optional, so a page still cached with the two-button banner can record its decision: without the
				// list the categories are derived from the flags. With it, the flags must agree (400 otherwise).
				'categories'     => [
					'type'     => 'array',
					'required' => false,
					'items'    => [
						'type' => 'string',
						'enum' => Categories::SLUGS,
					],
				],
				'policy_version' => [
					'type'     => 'string',
					'required' => false,
					'pattern'  => '^[A-Za-z0-9._-]{1,32}$',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/verify-merkle', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_verify_merkle' ],
			'permission_callback' => [ $this, 'check_admin_permissions' ],
		] );
	}

	public function check_admin_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Gate for /nonce: public visitors need a fresh wp_rest nonce on cached pages, protected by a
	 * transient-based HMAC-SHA256 rate limit (default 120/min, bypassed for manage_options).
	 *
	 * @param WP_REST_Request|null $request Current REST request.
	 * @return true|WP_Error
	 */
	public function check_public_nonce_access( ?WP_REST_Request $request = null ): true|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		/**
		 * Filters the per-minute rate limit for public REST consent endpoints.
		 *
		 * @param int                  $limit   Maximum requests per minute (120 for nonce, 10 for record).
		 * @param string               $route   Route identifier ('nonce' or 'record').
		 * @param WP_REST_Request|null $request Current REST request.
		 */
		$limit = (int) apply_filters( 'wm_consent_rest_rate_limit', self::NONCE_RATE_LIMIT_MAX, 'nonce', $request );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( $limit <= 0 || $this->is_rate_limited( $ip, $limit, 'nonce_' ) ) {
			return new WP_Error(
				'wm_consent_rate_limited',
				__( 'Zu viele Anfragen. Bitte in einer Minute erneut versuchen.', 'wm-sovereign-consent-vault' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	public function handle_nonce(): WP_REST_Response {
		$response = new WP_REST_Response( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ], 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		return $response;
	}

	/**
	 * Gate for /record: valid REST nonce (403) and a fixed-window rate limit (429).
	 *
	 * @return true|WP_Error
	 */
	public function check_record_permission( WP_REST_Request $request ): true|WP_Error {
		$nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?? '' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'wm_consent_nonce',
				__( 'Einwilligung nicht gespeichert: fehlender oder abgelaufener Sicherheits-Token.', 'wm-sovereign-consent-vault' ),
				[ 'status' => 403 ]
			);
		}

		$limit = (int) apply_filters( 'wm_consent_rest_rate_limit', self::RATE_LIMIT_MAX, 'record', $request );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( $limit <= 0 || $this->is_rate_limited( $ip, $limit ) ) {
			return new WP_Error(
				'wm_consent_rate_limited',
				__( 'Zu viele Anfragen. Bitte in einer Minute erneut versuchen.', 'wm-sovereign-consent-vault' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Fixed window: the window end lives inside the transient value and the TTL is derived
	 * from it, so rewriting the transient never slides the window (a sliding window blocks a
	 * client for as long as it keeps retrying). The raw IP is never stored, only its HMAC.
	 */
	private function is_rate_limited( string $ip, int $max = self::RATE_LIMIT_MAX, string $scope = '' ): bool {
		$hash_len = '' === $scope ? 32 : 26;
		$key      = 'wm_cv_rl_' . $scope . substr( hash_hmac( 'sha256', self::rate_limit_subject( $ip ), wp_salt( 'auth' ) ), 0, $hash_len );
		$now      = time();
		$bucket   = get_transient( $key );

		if ( ! is_array( $bucket ) || (int) ( $bucket['reset'] ?? 0 ) <= $now ) {
			$bucket = [ 'count' => 0, 'reset' => $now + self::RATE_LIMIT_WINDOW ];
		}

		$bucket['count'] = (int) $bucket['count'] + 1;
		set_transient( $key, $bucket, max( 1, (int) $bucket['reset'] - $now ) );

		return $bucket['count'] > $max;
	}

	/**
	 * What the rate limit counts: an IPv4 address, or the /64 an IPv6 client sits in.
	 *
	 * A residential IPv6 line hands out a whole /64, so counting full addresses let one visitor spend
	 * the budget of thousands; the full address is never stored either way, only its HMAC.
	 */
	private static function rate_limit_subject( string $ip ): string {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return $ip;
		}
		// An IPv4 address written in IPv6 notation (::ffff:203.0.113.5) packs to ten zero bytes, ff ff
		// and the four IPv4 bytes — so its first eight bytes are zero for EVERY such address. Grouping
		// those by /64 put every visitor a dual-stack socket reports this way into one bucket of ten
		// requests a minute for the whole site, and the eleventh visitor's decision was refused with
		// 429. Measured on a local WordPress install before the fix: ::ffff:203.0.113.5 and ::ffff:198.51.100.9 both
		// returned 0000000000000000::/64. Such an address is counted as the IPv4 address it carries.
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\x00", 10 ) . "\xff\xff" ) ) {
			$mapped = @inet_ntop( substr( $packed, 12 ) );
			return false === $mapped ? $ip : $mapped;
		}
		return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
	}

	public function handle_record_consent( WP_REST_Request $request ): WP_REST_Response {
		$uuid = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		// rest_sanitize_boolean() again, not only through the route schema: a direct call (WP-CLI, a
		// test, another plugin) does not pass through argument validation, and "false" must not be true.
		$essential  = rest_sanitize_boolean( $request->get_param( 'essential' ) );
		$analytics  = rest_sanitize_boolean( $request->get_param( 'analytics' ) );
		$marketing  = rest_sanitize_boolean( $request->get_param( 'marketing' ) );
		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : 'Unknown';

		if ( '' === $uuid || 1 !== preg_match( self::UUID_PATTERN, $uuid ) ) {
			return new WP_REST_Response( [ 'error' => 'uuid required: 8-64 characters of A-Z a-z 0-9 _ -' ], 400 );
		}

		// Categories and policy version are checked here again for the same reason as the flags: a direct call does
		// not pass the route schema. An unknown category is refused, not dropped — dropping it would store a
		// narrower decision than the one sent without telling anyone.
		$raw_categories = $request->get_param( 'categories' );
		$categories     = [];
		if ( null !== $raw_categories ) {
			if ( ! is_array( $raw_categories ) || ! Categories::all_known( $raw_categories ) ) {
				return new WP_REST_Response( [ 'error' => 'categories must be a list of: ' . implode( ', ', Categories::SLUGS ) ], 400 );
			}
			$categories = Categories::canonical( $raw_categories );
			if ( in_array( Categories::STATISTICS, $categories, true ) !== $analytics || in_array( Categories::MARKETING, $categories, true ) !== $marketing ) {
				return new WP_REST_Response( [ 'error' => 'analytics and marketing must match the categories' ], 400 );
			}
		}
		$raw_version    = $request->get_param( 'policy_version' );
		$policy_version = null === $raw_version ? '' : ( is_string( $raw_version ) ? $raw_version : '' );
		if ( null !== $raw_version && 1 !== preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $policy_version ) ) {
			return new WP_REST_Response( [ 'error' => 'policy_version: 1-32 characters of A-Z a-z 0-9 . _ -' ], 400 );
		}

		$engine = new MerkleTreeEngine();
		$result = $engine->append_record( $uuid, $ip, $essential, $analytics, $marketing, $user_agent, $categories, $policy_version );

		if ( isset( $result['error'] ) ) {
			// Every failure used to answer 409 "already recorded", including a decision that was not
			// written at all. The client stops retrying on 409, so a lost decision looked like a stored one.
			if ( 'duplicate_or_failed_insert' === $result['error'] ) {
				return new WP_REST_Response( [ 'error' => 'consent uuid already recorded' ], 409 );
			}
			if ( 'chain_busy' === $result['error'] ) {
				return new WP_REST_Response( [ 'error' => 'consent chain busy, retry' ], 503, [ 'Retry-After' => '2' ] );
			}
			return new WP_REST_Response( [ 'error' => 'consent not recorded' ], 500 );
		}

		/**
		 * Fires after a visitor's decision was written to the log and chained.
		 *
		 * @param array<string, mixed> $result record_id, node_index, consent_uuid, signature, merkle_root, categories,
		 *                                     policy_version, signature_version.
		 */
		do_action( 'wm_consent_decision_recorded', $result );

		return new WP_REST_Response( [
			'success' => true,
			'data'    => $result,
		], 200 );
	}

	public function handle_verify_merkle(): WP_REST_Response {
		$engine = new MerkleTreeEngine();
		$result = $engine->verify_chain_integrity();

		return new WP_REST_Response( [
			'success' => true,
			'data'    => $result,
		], 200 );
	}
}
