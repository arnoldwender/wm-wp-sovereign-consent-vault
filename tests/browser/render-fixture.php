<?php
/**
 * Renders the page tests/browser/test-consent-dialog.py drives: the real dialog markup, the real configuration
 * block, the real front-end sheet and script, and a body the real blocker rewrote.
 *
 * Standalone, like tests/test-suite.php: the WordPress functions the three classes touch are mocked here, so the
 * fixture cannot drift from the code it renders — the markup is produced by ConsentBanner and OutputBlocker
 * themselves, not copied.
 *
 * Usage: php tests/browser/render-fixture.php > index.html
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'WM_CONSENT_VAULT_VERSION', '1.2.2' );
define( 'WM_CONSENT_VAULT_URL', './' );

$GLOBALS['fixture_options'] = [
	'wm_consent_settings' => [
		'texts'                 => [],
		'privacy_url'           => 'https://example.test/datenschutz/',
		'imprint_url'           => 'https://example.test/impressum/',
		'category_descriptions' => [],
		'policy_version'        => '4',
		'duration_days'         => 30,
		'blocking_enabled'      => true,
	],
	'wm_consent_services' => [
		[
			'id'          => 'google-analytics',
			'name'        => 'Google Analytics / Google Tag Manager',
			'provider'    => 'Google',
			'category'    => 'statistics',
			'purpose'     => 'Auswertung der Nutzung der Website.',
			'privacy_url' => 'https://policies.google.com/privacy',
			'cookies'     => [ '_ga', '_ga_*' ],
			'hosts'       => [ 'googletagmanager.com' ],
			'preset'      => 'google-analytics',
		],
		[
			'id'          => 'meta-pixel',
			'name'        => 'Meta Pixel',
			'provider'    => 'Meta',
			'category'    => 'marketing',
			'purpose'     => 'Messung von Werbung.',
			'privacy_url' => 'https://www.facebook.com/privacy/policy/',
			'cookies'     => [ '_fbp' ],
			'hosts'       => [ 'connect.facebook.net' ],
			'preset'      => 'meta-pixel',
		],
	],
];

// --- The WordPress functions the rendered classes call -----------------------------------------------------
function get_option( string $option, $default = false ) { return $GLOBALS['fixture_options'][ $option ] ?? $default; }
function update_option( string $option, $value, $autoload = null ): bool { $GLOBALS['fixture_options'][ $option ] = $value; return true; }
function apply_filters( string $hook, $value, ...$args ) { return $value; }
function add_action( ...$args ): bool { return true; }
function add_filter( ...$args ): bool { return true; }
function add_shortcode( ...$args ): bool { return true; }
function did_action( string $hook ): int { return 1; }
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function is_feed(): bool { return false; }
function is_robots(): bool { return false; }
function is_trackback(): bool { return false; }
function is_ssl(): bool { return false; }
function rest_url( string $path = '' ): string { return '/wp-json/' . ltrim( $path, '/' ); }
function get_privacy_policy_url(): string { return ''; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ): string { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
// WordPress keeps a root-relative URL as it is, and the REST routes of this fixture are relative; a mock that
// only accepted absolute URLs emptied nonceUrl and recordUrl, and the fixture measured a decision that was
// never posted.
function esc_url_raw( $url, $protocols = null ): string {
	$url = trim( (string) $url );
	return ( str_starts_with( $url, '/' ) || 1 === preg_match( '#^https?://[^\s"<>]+$#i', $url ) ) ? $url : '';
}
function esc_html__( string $text, string $domain = 'default' ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( string $text, string $domain = 'default' ): void { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( string $text, string $domain = 'default' ): void { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function __( string $text, string $domain = 'default' ): string { return $text; }
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { return 1 === $number ? $single : $plural; }
function sanitize_text_field( $str ): string { return is_string( $str ) ? trim( strip_tags( $str ) ) : ''; }
function sanitize_textarea_field( $str ): string { return is_string( $str ) ? trim( strip_tags( $str ) ) : ''; }
function sanitize_key( $key ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_title( $title ): string { return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' ); }
function wp_enqueue_style( ...$args ): void {}
function wp_enqueue_script( ...$args ): void {}

require_once dirname( __DIR__, 2 ) . '/includes/Core/Categories.php';
require_once dirname( __DIR__, 2 ) . '/includes/Core/Settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/Core/ConsentCookie.php';
require_once dirname( __DIR__, 2 ) . '/includes/Services/ServiceRegistry.php';
require_once dirname( __DIR__, 2 ) . '/includes/Frontend/ConsentBanner.php';
require_once dirname( __DIR__, 2 ) . '/includes/Blocker/ScriptIframeBlocker.php';
require_once dirname( __DIR__, 2 ) . '/includes/Blocker/OutputBlocker.php';

use WMCyber\Consent\Vault\Blocker\OutputBlocker;
use WMCyber\Consent\Vault\Blocker\ScriptIframeBlocker;
use WMCyber\Consent\Vault\Frontend\ConsentBanner;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

$registry = new ServiceRegistry();
$banner   = new ConsentBanner( $registry );

// The body a visitor would get: two scripts of registered services, one unrelated script, an embed, and the
// button of [wm_consent_settings]. Everything below goes through the real blocker.
$body = '<h1>Fixture</h1>'
	. '<script src="https://www.googletagmanager.com/gtag/js?id=G-FIXTURE"></script>'
	// The inline snippet sets a flag instead of appending the element: the browser test asserts whether the code
	// RAN, which is what blocking decides, without a request leaving the machine.
	. '<script>window.__fb = true; var s = document.createElement("script"); s.src = "https://connect.facebook.net/en_US/fbevents.js";</script>'
	. '<script src="./neutral.js"></script>'
	. '<iframe width="560" height="315" src="https://www.youtube-nocookie.com/embed/fixture" title="Fixture-Video"></iframe>'
	. $banner->render_settings_shortcode();

$blocked = ( new OutputBlocker( $registry, new ScriptIframeBlocker() ) )->rewrite_html( $body );

ob_start();
$banner->render_banner();
$dialog = (string) ob_get_clean();

echo "<!DOCTYPE html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n<title>Consent Vault fixture</title>\n"
	. "<link rel=\"stylesheet\" href=\"./wm-consent-vault.css\">\n</head>\n<body>\n"
	. $blocked . "\n" . $dialog . "\n"
	. "<script src=\"./wm-consent-vault.js\"></script>\n</body>\n</html>\n";
