<?php
/**
 * Server-side view of the visitor's decision: the `wm_consent` cookie the banner script writes, checked against the
 * current policy version and the consent duration. Backs wm_consent_has().
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cookie format (written by assets/js/wm-consent-vault.js, URL-encoded as a whole):
 *
 *     <policy version>|<categories, comma-separated>|<decision time, Unix seconds>
 *
 * The cookie is whatever the browser sends: it states what the visitor chose in that browser and proves nothing
 * else. It is read only to let PHP code render differently for a visitor who accepted a category; the blocker never
 * reads it, because a page rendered from it and stored by a full-page cache would be served to the next visitor
 * with that visitor's scripts already unblocked.
 */
final class ConsentCookie {
	public const NAME = 'wm_consent';

	/**
	 * The decision the cookie carries, or null when there is none, it is malformed, it was given for another
	 * policy version, or it is older than the consent duration.
	 *
	 * @param string|null $raw Cookie value; null reads $_COOKIE.
	 * @return array{policy_version: string, categories: list<string>, decided_at: int}|null
	 */
	public static function decision( ?string $raw = null ): ?array {
		if ( null === $raw ) {
			$raw = isset( $_COOKIE[ self::NAME ] ) && is_string( $_COOKIE[ self::NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::NAME ] ) ) : '';
		}
		// version | categories | seconds. Groups: 1 the version as Settings::POLICY_VERSION_PATTERN allows it,
		// 2 lower-case slugs separated by commas (may be empty only in theory: the script always sends necessary),
		// 3 a Unix timestamp of 9 to 11 digits.
		if ( 1 !== preg_match( '/^([A-Za-z0-9._-]{1,32})\|([a-z_,-]{0,200})\|(\d{9,11})$/', $raw, $m ) ) {
			return null;
		}
		if ( $m[1] !== Settings::policy_version() ) {
			return null;
		}
		$decided_at = (int) $m[3];
		$now        = time();
		// A decision from the future (a clock set ahead) is not valid either; five minutes of drift are tolerated.
		if ( $decided_at > $now + 300 || $now - $decided_at > Settings::duration_days() * DAY_IN_SECONDS ) {
			return null;
		}
		$names = '' === $m[2] ? [] : explode( ',', $m[2] );
		if ( ! Categories::all_known( $names ) ) {
			return null;
		}
		return [
			'policy_version' => $m[1],
			'categories'     => Categories::canonical( $names ),
			'decided_at'     => $decided_at,
		];
	}

	/**
	 * Whether the visitor accepted a category. `necessary` is always true; an unknown category is false.
	 *
	 * Reading the cookie makes the page depend on the visitor, so by default this marks the response as not
	 * cacheable (`DONOTCACHEPAGE`, which WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache honour, and
	 * the no-cache headers of nocache_headers()); `wm_consent_has_nocache` turns that off for code that knows the
	 * page is not cached.
	 */
	public static function has( string $category ): bool {
		$slug = Categories::normalize( $category );
		if ( null === $slug ) {
			$result = false;
		} elseif ( Categories::NECESSARY === $slug ) {
			$result = true;
		} else {
			/**
			 * Filters whether wm_consent_has() marks the current response as not cacheable when it reads the cookie.
			 *
			 * @param bool   $nocache  Default true.
			 * @param string $category Requested category slug.
			 */
			if ( apply_filters( 'wm_consent_has_nocache', true, $slug ) ) {
				self::mark_uncacheable();
			}
			$decision = self::decision();
			$result   = null !== $decision && in_array( $slug, $decision['categories'], true );
		}

		/**
		 * Filters the answer of wm_consent_has().
		 *
		 * @param bool   $result   Whether the category counts as accepted.
		 * @param string $category The category as requested.
		 */
		return (bool) apply_filters( 'wm_consent_has', $result, $category );
	}

	/** Keep full-page caches from storing a response that depends on this visitor's cookie. */
	private static function mark_uncacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the name every full-page cache reads; a prefixed constant would be read by nothing.
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}
}
