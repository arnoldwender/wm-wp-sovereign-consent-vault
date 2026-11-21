<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\Blocker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;

/**
 * Click-to-load placeholders for YouTube, Vimeo and Google Maps iframes.
 *
 * Runs on `the_content` and, through OutputBlocker, on the whole page. The placeholder carries the category
 * `media`: once a visitor accepts external media the banner script loads every placeholder of that category without
 * a click; until then a click on the button loads that one embed (not recorded).
 */
final class ScriptIframeBlocker {
	/** Service id per built-in provider, so the script and OutputBlocker can tell the placeholders apart. */
	private const PROVIDER_IDS = [
		'YouTube'     => 'youtube',
		'Vimeo'       => 'vimeo',
		'Google Maps' => 'google-maps',
	];

	/**
	 * Filter post content (or a whole page) to replace third-party iframes with a click-to-load placeholder.
	 */
	public function filter_content_iframes( $content ): string {
		// `the_content` hands this filter whatever the filter before it returned; a plugin that passes
		// null made a typed parameter fatal on a live page.
		$content = (string) $content;
		if ( is_admin() || wp_doing_ajax() ) {
			return $content;
		}

		// YouTube (also youtube-nocookie.com, which still connects to Google and was not matched until
		// 2026-09-14), Vimeo (blocked pattern: player.vimeo.com included) and Google Maps iframes. The scheme is optional:
		// a protocol-relative src (`//www.youtube.com/embed/…`), which older themes and pasted embed block patterns
		// still carry, went through untouched and loaded YouTube without any click (measured 2026-09-16).
		// `s`: an iframe whose fallback content spans lines is still one iframe.
		// The quotes around src are optional (pasted embed codes write `src=https://…`) and the mobile
		// host `m.youtube.com` counts like `www.` in the block pattern.
		$pattern = '/<iframe\b([^>]*src=(["\']?)((?:https?:)?\/\/(?:www\.|player\.|m\.)?(?:youtube\.com|youtube-nocookie\.com|youtu\.be|vimeo\.com|maps\.google\.com|google\.com\/maps)[^"\'\s>]*)\2[^>]*)>(.*?)<\/iframe>/is';

		return preg_replace_callback( $pattern, [ $this, 'render_2click_placeholder' ], $content ) ?? $content;
	}

	private function render_2click_placeholder( array $matches ): string {
		$attributes = $matches[1];
		$src        = $matches[3];
		$provider   = str_contains( $src, 'youtube' ) || str_contains( $src, 'youtu.be' ) ? 'YouTube' : ( str_contains( $src, 'vimeo' ) ? 'Vimeo' : 'Google Maps' );

		// The title and height of the original iframe travel with the placeholder: the embed used to come
		// back without its title, which leaves a frame no screen reader can name (WCAG 2.2, 4.1.2).
		$title  = preg_match( '/\btitle=["\']([^"\']*)["\']/i', $attributes, $t ) ? $t[1] : '';
		$height = preg_match( '/\bheight=["\']?(\d{2,4})\b/i', $attributes, $h ) ? (int) $h[1] : 400;

		return $this->render_placeholder( $src, $title, $height, $provider, Categories::MEDIA, self::PROVIDER_IDS[ $provider ] );
	}

	/**
	 * The placeholder markup for any embed.
	 *
	 * @param string $src        Source of the embed as found in the page (a protocol-relative source becomes https).
	 * @param string $title      Title of the original iframe; the provider name when it had none.
	 * @param int    $height     Height in pixels the inserted iframe gets.
	 * @param string $provider   Name shown to the visitor.
	 * @param string $category   Consent category that loads it without a click.
	 * @param string $service_id Registry id or built-in provider id.
	 */
	public function render_placeholder( string $src, string $title, int $height, string $provider, string $category, string $service_id ): string {
		if ( str_starts_with( $src, '//' ) ) {
			$src = 'https:' . $src;
		}

		/* translators: %s: External service provider name like YouTube or Google Maps */
		$heading = sprintf( esc_html__( 'Externer Inhalt blockiert (%s)', 'wm-sovereign-consent-vault' ), esc_html( $provider ) );
		// What happens, not what a statute requires: the placeholder used to tell visitors that "gemäß
		// § 25 TDDDG und DSGVO" external content loads only after their explicit consent — a legal claim
		// about the operator's site, which this plugin neither knows nor enforces.
		$description = sprintf(
			/* translators: 1: provider name, 2: provider name */
			esc_html__( 'Dieser Inhalt liegt bei %1$s. Erst ein Klick auf die Schaltfläche lädt ihn nach; dabei baut Ihr Browser eine Verbindung zu %2$s auf und übermittelt unter anderem Ihre IP-Adresse.', 'wm-sovereign-consent-vault' ),
			esc_html( $provider ),
			esc_html( $provider )
		);
		/* translators: %s: External service provider name like YouTube or Google Maps */
		$button_text   = sprintf( esc_html__( 'Inhalt entsperren & %s laden', 'wm-sovereign-consent-vault' ), esc_html( $provider ) );
		$settings_text = esc_html__( 'Datenschutzeinstellungen anpassen', 'wm-sovereign-consent-vault' );

		// The source travels in a data attribute, read by the footer script. It used to be printed inside
		// an inline onclick handler, where an escaped quote in the URL decodes back to a quote before the
		// JavaScript parser sees it. Styles come from assets/css/wm-consent-vault.css (inline styles with fixed
		// colours until 2026-09-17); the text is a paragraph, not an h4, so it does not break the heading order
		// of the page it lands in.
		return '<div class="wm-2click-wrapper" data-wm-embed-src="' . esc_url( $src ) . '" data-wm-embed-title="' . esc_attr( '' !== $title ? $title : $provider ) . '" data-wm-embed-height="' . esc_attr( (string) $height ) . '"'
			. ' data-wm-consent-category="' . esc_attr( $category ) . '" data-wm-consent-service="' . esc_attr( $service_id ) . '">'
			. '<span class="wm-2click-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>'
			. '<p class="wm-2click-title">' . $heading . '</p>'
			. '<p class="wm-2click-text">' . $description . '</p>'
			. '<span class="wm-2click-actions">'
			. '<button type="button" class="wm-btn wm-btn-primary wm-2click-unlock">'
			. $button_text
			. '</button>'
			. '<button type="button" class="wm-2click-settings" data-wm-consent-open>' . $settings_text . '</button>'
			. '</span>'
			. '</div>';
	}
}
