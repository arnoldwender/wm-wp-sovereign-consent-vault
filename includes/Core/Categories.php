<?php
/**
 * The four consent categories: slugs, aliases, canonical lists and their default wording.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed set of categories. The slugs are a storage contract: they are written into the consent log
 * (`categories` column, signed from signature version 2 on), into the visitor's browser and into the
 * `wm_consent` cookie, so renaming one would make every stored decision unreadable. Labels and
 * descriptions are wording and can change; the descriptions are editable under Einstellungen.
 */
final class Categories {
	public const NECESSARY  = 'necessary';
	public const STATISTICS = 'statistics';
	public const MARKETING  = 'marketing';
	public const MEDIA      = 'media';

	/** Canonical order. `necessary` is always part of a decision and cannot be switched off. */
	public const SLUGS = [ self::NECESSARY, self::STATISTICS, self::MARKETING, self::MEDIA ];

	/**
	 * Other spellings accepted where a category is named by hand (`data-wm-consent="analytics"`,
	 * `wm_consent_has( 'external_media' )`). `analytics` is the name of the column and the localStorage
	 * key this plugin wrote before the categories existed; `essential` the name of the first button.
	 */
	public const ALIASES = [
		'analytics'      => self::STATISTICS,
		'essential'      => self::NECESSARY,
		'external_media' => self::MEDIA,
		'external-media' => self::MEDIA,
	];

	/** The slug for a name or alias, null for anything that is not a category. */
	public static function normalize( string $name ): ?string {
		$name = strtolower( trim( $name ) );
		if ( in_array( $name, self::SLUGS, true ) ) {
			return $name;
		}
		return self::ALIASES[ $name ] ?? null;
	}

	/**
	 * A decision as a canonical list: known slugs only, no duplicates, canonical order, `necessary` always first.
	 *
	 * @param array<int, mixed> $names Category names as sent by a browser or a caller.
	 * @return list<string>
	 */
	public static function canonical( array $names ): array {
		$chosen = [ self::NECESSARY => true ];
		foreach ( $names as $name ) {
			if ( is_string( $name ) ) {
				$slug = self::normalize( $name );
				if ( null !== $slug ) {
					$chosen[ $slug ] = true;
				}
			}
		}
		return array_values( array_filter( self::SLUGS, static fn( string $slug ): bool => isset( $chosen[ $slug ] ) ) );
	}

	/**
	 * True when every entry names a category; used to refuse a request instead of silently dropping a typo.
	 *
	 * @param array<int, mixed> $names Category names.
	 */
	public static function all_known( array $names ): bool {
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || null === self::normalize( $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The list stored in the `categories` column and the cookie: comma-separated canonical slugs.
	 *
	 * @param array<int, mixed> $names Category names.
	 */
	public static function to_string( array $names ): string {
		return implode( ',', self::canonical( $names ) );
	}

	/**
	 * A stored list back into slugs.
	 *
	 * @return list<string>
	 */
	public static function from_string( string $stored ): array {
		return self::canonical( '' === trim( $stored ) ? [] : explode( ',', $stored ) );
	}

	/** Categories the visitor can switch; everything except `necessary`. */
	public static function optional(): array {
		return [ self::STATISTICS, self::MARKETING, self::MEDIA ];
	}

	/**
	 * Label and default description per category.
	 *
	 * The descriptions say what a category groups, not what this site loads: the plugin cannot know which
	 * services an operator registers, so a sentence like "we use Google Analytics" would be a statement it
	 * cannot back (rule 1 of CLAUDE.md).
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function defaults(): array {
		return [
			self::NECESSARY  => [
				'label'       => __( 'Notwendig', 'wm-sovereign-consent-vault' ),
				'description' => __( 'Für den Betrieb der Website erforderlich, zum Beispiel das Speichern dieser Auswahl. Diese Kategorie lässt sich nicht abschalten.', 'wm-sovereign-consent-vault' ),
			],
			self::STATISTICS => [
				'label'       => __( 'Statistik', 'wm-sovereign-consent-vault' ),
				'description' => __( 'Dienste, die erfassen, wie die Website genutzt wird, um sie auszuwerten.', 'wm-sovereign-consent-vault' ),
			],
			self::MARKETING  => [
				'label'       => __( 'Marketing', 'wm-sovereign-consent-vault' ),
				'description' => __( 'Dienste, die Besuche für Werbung und deren Erfolgsmessung auswerten, auch über diese Website hinaus.', 'wm-sovereign-consent-vault' ),
			],
			self::MEDIA      => [
				'label'       => __( 'Externe Medien', 'wm-sovereign-consent-vault' ),
				'description' => __( 'Eingebettete Inhalte anderer Anbieter wie Videos und Karten. Beim Laden baut Ihr Browser eine Verbindung zu diesem Anbieter auf.', 'wm-sovereign-consent-vault' ),
			],
		];
	}
}
