<?php
/**
 * Plugin settings: banner texts, legal links, category descriptions, policy version, consent duration and the
 * blocking switch, stored in one option.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Frontend\ConsentBanner;

/**
 * Reads and validates `wm_consent_settings`.
 *
 * Texts are stored as overrides only: an empty field means "use the default wording", which is translatable
 * and lives in ConsentBanner::default_texts(). Storing the German default on first save would freeze it and
 * hide every later translation from sites in other languages.
 */
final class Settings {
	public const OPTION             = 'wm_consent_settings';
	public const DELETE_DATA_OPTION = 'wm_consent_delete_data_on_uninstall';

	/** Editable banner texts; the keys are the ones ConsentBanner::default_texts() provides. */
	public const TEXT_KEYS = [ 'heading', 'description', 'accept_all', 'necessary_only', 'settings', 'save', 'privacy_link', 'imprint_link' ];

	/** What a policy version may look like: it travels in a cookie and a log column of 32 characters. */
	public const POLICY_VERSION_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

	/** Bounds of the consent duration in days; outside them the stored value is not accepted. */
	public const MIN_DAYS = 1;
	public const MAX_DAYS = 3650;

	/** Maximum stored length of a text override (the description is the long one). */
	private const MAX_TEXT_LENGTH = 2000;

	/**
	 * Values a fresh install uses.
	 *
	 * @return array{texts: array<string, string>, privacy_url: string, imprint_url: string, category_descriptions: array<string, string>, policy_version: string, duration_days: int, blocking_enabled: bool}
	 */
	public static function defaults(): array {
		return [
			'texts'                 => array_fill_keys( self::TEXT_KEYS, '' ),
			'privacy_url'           => '',
			'imprint_url'           => '',
			'category_descriptions' => array_fill_keys( Categories::SLUGS, '' ),
			'policy_version'        => '1',
			'duration_days'         => 365,
			'blocking_enabled'      => true,
		];
	}

	/**
	 * The stored settings, completed with the defaults and validated again on read: the option can be written by
	 * anything with database access, and every consumer relies on the types below.
	 *
	 * @return array{texts: array<string, string>, privacy_url: string, imprint_url: string, category_descriptions: array<string, string>, policy_version: string, duration_days: int, blocking_enabled: bool}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		return self::sanitize( is_array( $stored ) ? $stored : [], self::defaults() )['settings'];
	}

	/**
	 * Validate submitted values against the current settings.
	 *
	 * A value that fails validation keeps the current one and is named in `errors`, so a typo in the policy
	 * version cannot silently reset it (which would ask every visitor again) or be stored in a shape the cookie
	 * cannot carry.
	 *
	 * @param array<string, mixed> $input   Submitted or stored values.
	 * @param array<string, mixed> $current Settings to fall back to.
	 * @return array{settings: array{texts: array<string, string>, privacy_url: string, imprint_url: string, category_descriptions: array<string, string>, policy_version: string, duration_days: int, blocking_enabled: bool}, errors: list<string>}
	 */
	public static function sanitize( array $input, array $current ): array {
		$defaults = self::defaults();
		$current  = array_replace( $defaults, $current );
		$errors   = [];
		$out      = $defaults;

		// --- Texts: plain text, no markup. The description keeps line breaks.
		$texts = is_array( $input['texts'] ?? null ) ? $input['texts'] : [];
		foreach ( self::TEXT_KEYS as $key ) {
			$value               = is_string( $texts[ $key ] ?? null ) ? $texts[ $key ] : (string) ( $current['texts'][ $key ] ?? '' );
			$value               = 'description' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			$out['texts'][ $key ] = mb_substr( $value, 0, self::MAX_TEXT_LENGTH, 'UTF-8' );
		}

		// --- Links: http(s) URLs only; an empty privacy URL means the WordPress privacy page.
		foreach ( [ 'privacy_url', 'imprint_url' ] as $key ) {
			$raw = is_string( $input[ $key ] ?? null ) ? trim( $input[ $key ] ) : (string) $current[ $key ];
			if ( '' === $raw ) {
				$out[ $key ] = '';
				continue;
			}
			$url = esc_url_raw( $raw, [ 'http', 'https' ] );
			if ( '' === $url ) {
				$errors[]    = $key;
				$out[ $key ] = (string) $current[ $key ];
				continue;
			}
			$out[ $key ] = $url;
		}

		// --- Category descriptions: plain text per known category.
		$descriptions = is_array( $input['category_descriptions'] ?? null ) ? $input['category_descriptions'] : [];
		foreach ( Categories::SLUGS as $slug ) {
			$value                                 = is_string( $descriptions[ $slug ] ?? null ) ? $descriptions[ $slug ] : (string) ( $current['category_descriptions'][ $slug ] ?? '' );
			$out['category_descriptions'][ $slug ] = mb_substr( sanitize_textarea_field( $value ), 0, self::MAX_TEXT_LENGTH, 'UTF-8' );
		}

		// --- Policy version: the value a visitor's stored decision is compared with.
		$version = is_scalar( $input['policy_version'] ?? null ) ? trim( (string) $input['policy_version'] ) : (string) $current['policy_version'];
		if ( 1 === preg_match( self::POLICY_VERSION_PATTERN, $version ) ) {
			$out['policy_version'] = $version;
		} else {
			$errors[]              = 'policy_version';
			$out['policy_version'] = 1 === preg_match( self::POLICY_VERSION_PATTERN, (string) $current['policy_version'] ) ? (string) $current['policy_version'] : $defaults['policy_version'];
		}

		// --- Duration in days: whole numbers within the bounds.
		$days = $input['duration_days'] ?? $current['duration_days'];
		if ( ( is_int( $days ) || ( is_string( $days ) && 1 === preg_match( '/^\d+$/', trim( $days ) ) ) ) && (int) $days >= self::MIN_DAYS && (int) $days <= self::MAX_DAYS ) {
			$out['duration_days'] = (int) $days;
		} else {
			$errors[]             = 'duration_days';
			$fallback             = (int) $current['duration_days'];
			$out['duration_days'] = $fallback >= self::MIN_DAYS && $fallback <= self::MAX_DAYS ? $fallback : $defaults['duration_days'];
		}

		// --- Blocking switch: an explicit boolean; a form sends "1" or nothing.
		$blocking                = $input['blocking_enabled'] ?? $current['blocking_enabled'];
		$out['blocking_enabled'] = true === $blocking || 1 === $blocking || '1' === $blocking;

		return [
			'settings' => $out,
			'errors'   => $errors,
		];
	}

	/**
	 * Validate and store submitted settings.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return list<string> Names of the fields that were not accepted.
	 */
	public static function save( array $input ): array {
		$result = self::sanitize( $input, self::get() );
		update_option( self::OPTION, $result['settings'], false );
		return $result['errors'];
	}

	/**
	 * Raise the policy version by one step: a trailing number is incremented ("3" → "4", "2026-1" → "2026-2"),
	 * anything else gets "-2" appended. Every visitor sees the banner again afterwards.
	 *
	 * @return string The new version.
	 */
	public static function bump_policy_version(): string {
		$settings = self::get();
		$current  = $settings['policy_version'];
		if ( 1 === preg_match( '/^(.*?)(\d+)$/', $current, $m ) ) {
			$next = $m[1] . ( (int) $m[2] + 1 );
		} else {
			$next = $current . '-2';
		}
		if ( 1 !== preg_match( self::POLICY_VERSION_PATTERN, $next ) ) {
			// A 32-character version cannot grow; starting over is still a different version.
			$next = '1' === $current ? '2' : '1';
		}
		$settings['policy_version'] = $next;
		update_option( self::OPTION, $settings, false );
		return $next;
	}

	/** A banner text: the stored override, or the translatable default. */
	public static function text( string $key ): string {
		$stored = self::get()['texts'][ $key ] ?? '';
		if ( '' !== $stored ) {
			return $stored;
		}
		return ConsentBanner::default_texts()[ $key ] ?? '';
	}

	/** The description of a category: the stored override, or the default wording. */
	public static function category_description( string $slug ): string {
		$stored = self::get()['category_descriptions'][ $slug ] ?? '';
		if ( '' !== $stored ) {
			return $stored;
		}
		return Categories::defaults()[ $slug ]['description'] ?? '';
	}

	/** Link to the privacy policy: the configured URL, else the page set under Settings › Privacy (may be empty). */
	public static function privacy_url(): string {
		$url = self::get()['privacy_url'];
		return '' !== $url ? $url : (string) get_privacy_policy_url();
	}

	/** Link to the legal notice; empty when none is configured, and then the banner shows no link. */
	public static function imprint_url(): string {
		return self::get()['imprint_url'];
	}

	public static function policy_version(): string {
		return self::get()['policy_version'];
	}

	public static function duration_days(): int {
		return self::get()['duration_days'];
	}

	public static function blocking_enabled(): bool {
		return self::get()['blocking_enabled'];
	}

	/** Whether uninstalling the plugin may delete stored consent logs, tables and options (default false). */
	public static function delete_data_on_uninstall(): bool {
		$value = get_option( self::DELETE_DATA_OPTION, '0' );
		return true === $value || 1 === $value || '1' === $value;
	}
}
