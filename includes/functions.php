<?php
/**
 * Global functions for themes and other plugins.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wm_consent_has' ) ) {
	/**
	 * Whether the current visitor accepted a consent category.
	 *
	 * Reads the `wm_consent` cookie the banner writes and checks it against the current policy version and the
	 * consent duration. `necessary` is always true; `statistics`, `marketing` and `media` (aliases: `analytics`,
	 * `external_media`) are true only for a valid decision that includes them.
	 *
	 * Use it to decide what PHP renders, e.g. `if ( wm_consent_has( 'marketing' ) ) { … }`. Output that depends on
	 * it must not be cached per URL, so by default the response is marked as not cacheable (filter
	 * `wm_consent_has_nocache`). Markup that should wait for consent without that cost belongs in
	 * `<script type="text/plain" data-wm-consent="marketing">`, which the banner script activates in the browser.
	 *
	 * @param string $category Category slug or alias.
	 * @return bool
	 */
	function wm_consent_has( string $category ): bool {
		return \WMCyber\Consent\Vault\Core\ConsentCookie::has( $category );
	}
}
