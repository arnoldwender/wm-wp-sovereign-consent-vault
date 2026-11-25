<?php
/**
 * Registry of the services a site uses: which category each belongs to, which host or file names identify its
 * scripts and embeds, and which cookies it sets. Includes optional presets for common trackers and embeds.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;

/**
 * Stored in the option `wm_consent_services` as a list of services:
 *
 *     id, name, provider, category, purpose, privacy_url, cookies (list), hosts (list), preset (key or '')
 *
 * The registry starts empty. Presets are templates an administrator adds explicitly: the plugin cannot know which
 * of these services a site embeds, and a preset that blocks a script the site does not load does nothing, while
 * one that names a service the site does not use would put a false statement into the banner.
 */
final class ServiceRegistry {
	public const OPTION = 'wm_consent_services';

	/**
	 * A host or file pattern: lower-case letters, digits and `. _ - /`, at least one `.` or `/`, 4 to 100
	 * characters. Wildcards are not needed: a pattern matches wherever it stands between non-name characters
	 * (see pattern_regex()), so `hotjar.com` covers `static.hotjar.com` and `script.hotjar.com`.
	 */
	private const HOST_PATTERN = '/^(?=.*[.\/])[a-z0-9._\/-]{4,100}$/';

	/** A cookie name, optionally ending in `*` to cover a family (`_ga_*`, `_pk_id*`). */
	private const COOKIE_PATTERN = '/^[A-Za-z0-9_.-]{1,100}\*?$/';

	private const MAX_TEXT_LENGTH = 500;

	/** @var array<string, string>|null Compiled pattern per service id, built once per request. */
	private ?array $compiled = null;

	/** @var list<array<string, mixed>>|null Services in effect for this request. */
	private ?array $services = null;

	/**
	 * Presets for common trackers and embeds.
	 *
	 * Host and file names were read from each vendor's own documentation or code on 2026-09-17:
	 *
	 * - Google Analytics / Google Tag Manager: https://developers.google.com/tag-platform/security/guides/csp
	 *   (updated 2026-07-30): gtag.js and gtm.js block pattern `www.googletagmanager.com`; hits go to
	 *   blocked host pattern `*.google-analytics.com`. Cookies `_ga`, `_ga_<id>`, `_gid`, `_gat…`, `_gcl_au`:
	 *   https://business.safety.google/adscookies/
	 * - Meta Pixel: https://developers.facebook.com/docs/meta-pixel/get-started (base code
	 *   block pattern `https://connect.facebook.net/en_US/fbevents.js`); cookies `_fbp`, `_fbc`:
	 *   https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc
	 * - Matomo: https://developer.matomo.org/guides/tracking-javascript-guide (the tracking code loads
	 *   `matomo.js` and sends to `matomo.php` on the operator's own Matomo host, so the host cannot be preset;
	 *   the file names are). `piwik.js` / `piwik.php` sit next to them in the root of github.com/matomo-org/matomo.
	 *   Cookies `_pk_id`, `_pk_ses`, `_pk_ref`, `_pk_cvar`, `_pk_hsr`, `_pk_testcookie`:
	 *   https://matomo.org/faq/general/faq_146/
	 * - Hotjar: the official package github.com/hotjar/hotjar-js (src/utils.ts loads
	 *   `https://static.hotjar.com/c/hotjar-…js`; its README allows `*.hotjar.com`). Cookie names are not preset:
	 *   help.hotjar.com answered 403 on 2026-09-17, so they could not be read from Hotjar.
	 * - Microsoft Clarity: https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-csp
	 *   (blocked host pattern `www.clarity.ms`, `[a-z].clarity.ms`); cookies `_clck`, `_clsk`:
	 *   https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-cookies
	 * - LinkedIn Insight Tag: https://www.linkedin.com/help/lms/answer/a425696 (block pattern `snap.licdn.com`); first-party
	 *   cookies from https://www.linkedin.com/legal/l/cookie-table, limited to the names that are LinkedIn's own
	 *   (generic names from that table such as `JSESSIONID` or `lang` would delete the site's own cookies).
	 * - TikTok Pixel: TikTok's own WordPress plugin `tiktok-for-business` 1.4.2 (author TikTok, updated
	 *   2026-09-16), pixel/Tt4b_Pixel_Class.php: block pattern `https://analytics.tiktok.com/i18n/pixel/events.js`; cookies
	 *   `_ttp`, `ttcsid…`, `ttclid`: https://ads.tiktok.com/help/article/using-cookies-with-tiktok-pixel
	 * - YouTube: https://developers.google.com/youtube/iframe_api_reference (updated 2026-09-15:
	 *   block pattern `www.youtube.com/iframe_api`, embeds under `www.youtube.com/embed`) and
	 *   https://support.google.com/youtube/answer/171780 (block pattern `www.youtube-nocookie.com`).
	 * - Vimeo: the official SDK github.com/vimeo/player.js (block pattern `player.vimeo.com/api/player.js`,
	 *   block pattern `player.vimeo.com/video/…`).
	 * - Google Maps: https://developers.google.com/maps/documentation/embed/get-started (updated 2026-09-16:
	 *   block pattern `https://www.google.com/maps/embed/v1/…`) and
	 *   https://developers.google.com/maps/documentation/javascript/load-maps-js-api (block pattern `maps.googleapis.com/maps/api/js`).
	 *
	 * Embeds keep their cookies on the provider's domain, where a script on this site cannot delete them, so the
	 * three media presets name none. Provider names are the brands; the legal entity and address that apply to a
	 * site are for its operator to fill in.
	 *
	 * @return array<string, array{id: string, name: string, provider: string, category: string, purpose: string, privacy_url: string, cookies: list<string>, hosts: list<string>, preset: string}>
	 */
	public static function presets(): array {
		$presets = [
			'google-analytics' => [
				'name'        => 'Google Analytics / Google Tag Manager',
				'provider'    => 'Google',
				'category'    => Categories::STATISTICS,
				'purpose'     => __( 'Auswertung der Nutzung der Website (Reichweitenmessung).', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://policies.google.com/privacy',
				'cookies'     => [ '_ga', '_ga_*', '_gid', '_gat*', '_gcl_au' ],
				'hosts'       => [ 'googletagmanager.com', 'google-analytics.com' ],
			],
			'meta-pixel'       => [
				'name'        => 'Meta Pixel',
				'provider'    => 'Meta',
				'category'    => Categories::MARKETING,
				'purpose'     => __( 'Messung und Ausspielung von Werbung auf Plattformen von Meta.', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://www.facebook.com/privacy/policy/',
				'cookies'     => [ '_fbp', '_fbc' ],
				'hosts'       => [ 'connect.facebook.net' ],
			],
			'matomo'           => [
				'name'        => 'Matomo',
				'provider'    => 'Matomo',
				'category'    => Categories::STATISTICS,
				'purpose'     => __( 'Auswertung der Nutzung der Website (Reichweitenmessung).', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://matomo.org/privacy-policy/',
				'cookies'     => [ '_pk_id*', '_pk_ses*', '_pk_ref*', '_pk_cvar*', '_pk_hsr*', '_pk_testcookie*' ],
				'hosts'       => [ 'matomo.js', 'matomo.php', 'piwik.js', 'piwik.php' ],
			],
			'hotjar'           => [
				'name'        => 'Hotjar',
				'provider'    => 'Hotjar',
				'category'    => Categories::STATISTICS,
				'purpose'     => __( 'Auswertung, wie Besucher die Seiten nutzen (Heatmaps, Sitzungsaufzeichnungen).', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://www.hotjar.com/legal/policies/privacy/',
				'cookies'     => [],
				'hosts'       => [ 'hotjar.com' ],
			],
			'microsoft-clarity' => [
				'name'        => 'Microsoft Clarity',
				'provider'    => 'Microsoft',
				'category'    => Categories::STATISTICS,
				'purpose'     => __( 'Auswertung, wie Besucher die Seiten nutzen (Heatmaps, Sitzungsaufzeichnungen).', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://www.microsoft.com/en-us/privacy/privacystatement',
				'cookies'     => [ '_clck', '_clsk' ],
				'hosts'       => [ 'clarity.ms' ],
			],
			'linkedin-insight' => [
				'name'        => 'LinkedIn Insight Tag',
				'provider'    => 'LinkedIn',
				'category'    => Categories::MARKETING,
				'purpose'     => __( 'Messung von Werbekampagnen auf LinkedIn.', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://www.linkedin.com/legal/privacy-policy',
				'cookies'     => [ 'li_fat_id', 'li_giant', 'ln_or', 'oribili_user_guid' ],
				'hosts'       => [ 'snap.licdn.com' ],
			],
			'tiktok-pixel'     => [
				'name'        => 'TikTok Pixel',
				'provider'    => 'TikTok',
				'category'    => Categories::MARKETING,
				'purpose'     => __( 'Messung und Ausspielung von Werbung auf TikTok.', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://www.tiktok.com/legal/page/eea/privacy-policy/en',
				'cookies'     => [ '_ttp', 'ttcsid*', 'ttclid' ],
				'hosts'       => [ 'analytics.tiktok.com' ],
			],
			'youtube'          => [
				'name'        => 'YouTube',
				'provider'    => 'Google',
				'category'    => Categories::MEDIA,
				'purpose'     => __( 'Einbettung von Videos.', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://policies.google.com/privacy',
				'cookies'     => [],
				'hosts'       => [ 'youtube.com', 'youtube-nocookie.com', 'youtu.be' ],
			],
			'vimeo'            => [
				'name'        => 'Vimeo',
				'provider'    => 'Vimeo',
				'category'    => Categories::MEDIA,
				'purpose'     => __( 'Einbettung von Videos.', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://vimeo.com/legal/privacy/policy',
				'cookies'     => [],
				'hosts'       => [ 'player.vimeo.com', 'vimeo.com' ],
			],
			'google-maps'      => [
				'name'        => 'Google Maps',
				'provider'    => 'Google',
				'category'    => Categories::MEDIA,
				'purpose'     => __( 'Einbettung von Karten.', 'wm-sovereign-consent-vault' ),
				'privacy_url' => 'https://policies.google.com/privacy',
				'cookies'     => [],
				'hosts'       => [ 'google.com/maps', 'maps.google.com', 'maps.googleapis.com' ],
			],
		];

		foreach ( $presets as $key => $preset ) {
			$presets[ $key ] = [ 'id' => $key ] + $preset + [ 'preset' => $key ];
		}
		return $presets;
	}

	/**
	 * Services stored by the administrator, validated again on read, then passed through `wm_consent_services`.
	 * Whatever the filter returns is validated too: an entry without a category or pattern cannot block anything.
	 *
	 * @return list<array{id: string, name: string, provider: string, category: string, purpose: string, privacy_url: string, cookies: list<string>, hosts: list<string>, preset: string}>
	 */
	public function all(): array {
		if ( null !== $this->services ) {
			return $this->services;
		}
		$stored   = get_option( self::OPTION, [] );
		$services = self::clean_list( is_array( $stored ) ? $stored : [] );

		/**
		 * Filters the services the banner lists and the blocker matches.
		 *
		 * @param list<array<string, mixed>> $services Validated services from the settings screen.
		 */
		$filtered       = apply_filters( 'wm_consent_services', $services );
		$this->services = self::clean_list( is_array( $filtered ) ? $filtered : $services );
		return $this->services;
	}

	/**
	 * Services of one category.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function in_category( string $category ): array {
		return array_values( array_filter( $this->all(), static fn( array $service ): bool => $category === $service['category'] ) );
	}

	/** The stored entry with this id, or null. */
	public static function find_stored( string $id ): ?array {
		foreach ( self::stored() as $service ) {
			if ( $id === $service['id'] ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * The first service whose patterns occur in a script source, an iframe source or inline code.
	 *
	 * @param string $haystack URL or inline script text.
	 * @return array<string, mixed>|null
	 */
	public function match( string $haystack ): ?array {
		if ( '' === $haystack ) {
			return null;
		}
		if ( null === $this->compiled ) {
			$this->compiled = [];
			foreach ( $this->all() as $service ) {
				$this->compiled[ $service['id'] ] = self::pattern_regex( $service['hosts'] );
			}
		}
		foreach ( $this->all() as $service ) {
			$regex = $this->compiled[ $service['id'] ] ?? '';
			if ( '' !== $regex && 1 === preg_match( $regex, $haystack ) ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * One case-insensitive regex for a service's patterns.
	 *
	 * Each pattern must stand between characters that cannot continue a host or file name: `(?<![a-z0-9-])`
	 * before it and `(?![a-z0-9-])` after it. `youtube.com` therefore matches `www.youtube.com/embed` (a dot
	 * before it) but not `notyoutube.com`, and `matomo.js` matches `/matomo.js?v=1` but not `matomo.json`.
	 *
	 * @param list<string> $patterns Validated patterns.
	 */
	public static function pattern_regex( array $patterns ): string {
		$parts = [];
		foreach ( $patterns as $pattern ) {
			$parts[] = preg_quote( $pattern, '/' );
		}
		return [] === $parts ? '' : '/(?<![a-z0-9-])(?:' . implode( '|', $parts ) . ')(?![a-z0-9-])/i';
	}

	/**
	 * Validate one service as submitted by the form or stored in the option.
	 *
	 * @param array<string, mixed> $input Raw values; `cookies` and `hosts` may be lists or text with one entry per
	 *                                    line (commas work too).
	 * @return array{service: array{id: string, name: string, provider: string, category: string, purpose: string, privacy_url: string, cookies: list<string>, hosts: list<string>, preset: string}|null, errors: list<string>}
	 */
	public static function sanitize_service( array $input ): array {
		$errors = [];

		$name = self::text( $input['name'] ?? '' );
		if ( '' === $name ) {
			$errors[] = 'name';
		}

		$category = is_string( $input['category'] ?? null ) ? Categories::normalize( $input['category'] ) : null;
		// A service in `necessary` is never blocked, so the only thing it could do is claim a purpose in the banner.
		// It is accepted for listing (e.g. a CDN the operator wants to name), but the blocker skips it.
		if ( null === $category ) {
			$errors[] = 'category';
		}

		$hosts = [];
		foreach ( self::entries( $input['hosts'] ?? [] ) as $entry ) {
			$entry = strtolower( $entry );
			if ( 1 === preg_match( self::HOST_PATTERN, $entry ) ) {
				$hosts[ $entry ] = true;
			} else {
				$errors[] = 'hosts';
			}
		}
		if ( [] === $hosts && Categories::NECESSARY !== $category ) {
			$errors[] = 'hosts';
		}

		$cookies = [];
		foreach ( self::entries( $input['cookies'] ?? [] ) as $entry ) {
			if ( 1 === preg_match( self::COOKIE_PATTERN, $entry ) ) {
				$cookies[ $entry ] = true;
			} else {
				$errors[] = 'cookies';
			}
		}

		$privacy_url = '';
		if ( is_string( $input['privacy_url'] ?? null ) && '' !== trim( $input['privacy_url'] ) ) {
			$privacy_url = esc_url_raw( trim( $input['privacy_url'] ), [ 'http', 'https' ] );
			if ( '' === $privacy_url ) {
				$errors[] = 'privacy_url';
			}
		}

		$id = is_string( $input['id'] ?? null ) ? sanitize_key( $input['id'] ) : '';
		if ( '' === $id ) {
			$id = sanitize_key( sanitize_title( $name ) );
		}
		if ( '' === $id ) {
			$errors[] = 'id';
		}

		$preset = is_string( $input['preset'] ?? null ) ? sanitize_key( $input['preset'] ) : '';

		$errors = array_values( array_unique( $errors ) );
		if ( [] !== $errors ) {
			return [ 'service' => null, 'errors' => $errors ];
		}

		return [
			'service' => [
				'id'          => mb_substr( $id, 0, 64, 'UTF-8' ),
				'name'        => $name,
				'provider'    => self::text( $input['provider'] ?? '' ),
				'category'    => (string) $category,
				'purpose'     => self::text( $input['purpose'] ?? '', true ),
				'privacy_url' => $privacy_url,
				'cookies'     => array_keys( $cookies ),
				'hosts'       => array_keys( $hosts ),
				'preset'      => isset( self::presets()[ $preset ] ) ? $preset : '',
			],
			'errors'  => [],
		];
	}

	/**
	 * Add or replace a stored service.
	 *
	 * @param array<string, mixed> $input       Submitted values.
	 * @param string               $original_id Id of the entry being edited; '' adds a new one.
	 * @return list<string> Fields that were not accepted, `duplicate_id` when another entry already uses the id.
	 */
	public static function save_service( array $input, string $original_id = '' ): array {
		$result = self::sanitize_service( $input );
		if ( null === $result['service'] ) {
			return $result['errors'];
		}
		$service  = $result['service'];
		$services = self::stored();
		$position = null;
		foreach ( $services as $index => $existing ) {
			if ( '' !== $original_id && $original_id === $existing['id'] ) {
				$position = $index;
			} elseif ( $service['id'] === $existing['id'] ) {
				return [ 'duplicate_id' ];
			}
		}
		if ( null === $position ) {
			if ( '' !== $original_id ) {
				return [ 'not_found' ];
			}
			$services[] = $service;
		} else {
			$services[ $position ] = $service;
		}
		update_option( self::OPTION, array_values( $services ), false );
		return [];
	}

	/** Remove a stored service; false when no entry has this id. */
	public static function delete_service( string $id ): bool {
		$services = self::stored();
		$kept     = array_values( array_filter( $services, static fn( array $service ): bool => $id !== $service['id'] ) );
		if ( count( $kept ) === count( $services ) ) {
			return false;
		}
		update_option( self::OPTION, $kept, false );
		return true;
	}

	/**
	 * Store a preset as a service. Returns `added`, `exists` (an entry with that id is already there, possibly
	 * edited, and is not overwritten) or `unknown`.
	 */
	public static function add_preset( string $key ): string {
		$presets = self::presets();
		if ( ! isset( $presets[ $key ] ) ) {
			return 'unknown';
		}
		if ( null !== self::find_stored( $key ) ) {
			return 'exists';
		}
		$errors = self::save_service( $presets[ $key ] );
		return [] === $errors ? 'added' : 'unknown';
	}

	/**
	 * The stored list, validated, without the filter.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, [] );
		return self::clean_list( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Validated services with unique ids; invalid entries are dropped.
	 *
	 * @param array<int|string, mixed> $list Raw list.
	 * @return list<array<string, mixed>>
	 */
	private static function clean_list( array $list ): array {
		$clean = [];
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$service = self::sanitize_service( $entry )['service'];
			if ( null !== $service && ! isset( $clean[ $service['id'] ] ) ) {
				$clean[ $service['id'] ] = $service;
			}
		}
		return array_values( $clean );
	}

	/**
	 * Entries of a list field: an array, or text split on line breaks and commas.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private static function entries( mixed $value ): array {
		$items = is_array( $value ) ? $value : ( is_string( $value ) ? preg_split( '/[\r\n,]+/', $value ) : [] );
		$out   = [];
		foreach ( (array) $items as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$out[] = trim( $item );
			}
		}
		return $out;
	}

	/** Plain text within the stored length. */
	private static function text( mixed $value, bool $multiline = false ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		return mb_substr( $value, 0, self::MAX_TEXT_LENGTH, 'UTF-8' );
	}
}
