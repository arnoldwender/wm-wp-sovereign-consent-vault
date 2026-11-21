<?php
/**
 * Holds back scripts and iframes of registered services until the visitor accepts their category: rewrites the
 * front-end HTML through an output buffer and the script tags WordPress prints through its script filters.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Blocker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

/**
 * What is blocked, on every front-end HTML page:
 *
 * - a `<script>` (external or inline) whose source or code contains a host or file pattern of a registered service
 *   in a category other than `necessary`: `type="text/plain"`, `data-wm-consent-category`, `data-wm-consent-service`,
 *   the source moved to `data-wm-consent-src`, a `type="module"` kept in `data-wm-consent-type`;
 * - a `<script data-wm-consent="…">` of JavaScript type written by a developer: the same, with that category;
 * - an `<iframe>` of YouTube, Vimeo or Google Maps (ScriptIframeBlocker's built-in patterns) and an iframe of a
 *   registered `media` service: the click-to-load placeholder; an iframe of another registered category: its
 *   source moved to `data-wm-consent-src`.
 *
 * The server always writes the blocked form, whatever the visitor decided: the decision lives in the browser, and a
 * page rewritten per visitor and kept by a full-page cache would reach the next visitor unblocked. The banner script
 * (assets/js/wm-consent-vault.js) activates what the stored decision allows.
 *
 * Not touched: other script types (JSON-LD, import maps, `text/plain` already), anything carrying
 * `data-wm-consent-ignore`, this plugin's own tags (ids starting with `wm-consent-`), content inside HTML comments,
 * `<style>`, `<textarea>`, and markup that JavaScript inserts after the page has loaded.
 */
final class OutputBlocker {
	/** JavaScript MIME types from the HTML standard plus the empty type and `module`: what a browser executes. */
	private const JS_TYPES = [ '', 'text/javascript', 'application/javascript', 'application/ecmascript', 'application/x-ecmascript', 'application/x-javascript', 'text/ecmascript', 'text/jscript', 'text/livescript', 'text/x-ecmascript', 'text/x-javascript', 'module' ];

	private ServiceRegistry $registry;
	private ScriptIframeBlocker $embeds;

	/** Output collected while ob_flush() calls arrive, rewritten once the page is complete. */
	private string $pending = '';

	public function __construct( ?ServiceRegistry $registry = null, ?ScriptIframeBlocker $embeds = null ) {
		$this->registry = $registry ?? new ServiceRegistry();
		$this->embeds   = $embeds ?? new ScriptIframeBlocker();
	}

	public function boot(): void {
		// Priority 99: inside the buffer a caching plugin opens earlier on the same hook, so the cache stores the
		// blocked page, never the original one.
		add_action( 'template_redirect', [ $this, 'start_buffer' ], 99 );
		add_filter( 'wp_script_attributes', [ $this, 'filter_script_attributes' ], 20 );
		add_filter( 'wp_inline_script_attributes', [ $this, 'filter_inline_script_attributes' ], 20, 2 );
		add_filter( 'script_loader_tag', [ $this, 'filter_script_loader_tag' ], 20, 3 );
	}

	/**
	 * Whether this request's HTML is rewritten: a front-end page, blocking switched on, and not an admin, AJAX, cron,
	 * REST, feed, robots or trackback request.
	 */
	public function should_block(): bool {
		if ( ! Settings::blocking_enabled() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		// The query tags only answer once the main query ran; a script tag printed earlier is not a feed.
		if ( did_action( 'wp' ) && ( is_feed() || is_robots() || is_trackback() ) ) {
			return false;
		}
		/**
		 * Filters whether scripts and iframes of registered services are blocked on this request.
		 *
		 * @param bool $enabled True on front-end pages while blocking is switched on in the settings.
		 */
		return (bool) apply_filters( 'wm_consent_blocking_enabled', true );
	}

	public function start_buffer(): void {
		if ( $this->should_block() ) {
			$this->pending = '';
			ob_start( [ $this, 'buffer_callback' ] );
		}
	}

	/**
	 * Output-buffer handler. Chunks that arrive through ob_flush() are held back and rewritten with the rest: a tag
	 * split across two chunks would otherwise pass unblocked. ob_clean() discards what was held.
	 *
	 * @param string $buffer Output of this phase.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* flags.
	 */
	public function buffer_callback( string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL ): string {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			$this->pending = '';
			return '';
		}
		$this->pending .= $buffer;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}
		$html          = $this->pending;
		$this->pending = '';
		return $this->is_html_response() ? $this->rewrite_html( $html ) : $html;
	}

	/** False when a Content-Type other than HTML was sent (a JSON or XML response that passed template_redirect). */
	private function is_html_response(): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'html' );
			}
		}
		return true;
	}

	/**
	 * Rewrite a page or a fragment.
	 *
	 * @param string      $html           HTML.
	 * @param string|null $force_category Category every script of the fragment gets (a handle mapped through
	 *                                    `wm_consent_script_handles`); null decides per tag.
	 */
	public function rewrite_html( string $html, ?string $force_category = null ): string {
		if ( false === stripos( $html, '<script' ) && false === stripos( $html, '<iframe' ) ) {
			return $html;
		}
		$out = '';
		foreach ( $this->segments( $html ) as [ $kind, $text ] ) {
			if ( 'script' === $kind ) {
				$out .= $this->rewrite_script_element( $text, $force_category );
			} elseif ( 'markup' === $kind ) {
				$out .= $this->rewrite_iframes( $text );
			} else {
				$out .= $text;
			}
		}
		return $out;
	}

	/**
	 * Split HTML into `script` elements, `raw` parts (comments, `<style>`, `<textarea>`) and the `markup` between
	 * them, by scanning instead of one regular expression: a lazy `.*?</script>` over a megabyte of inline code runs
	 * into PCRE's backtrack limit, returns null, and the page would go out unblocked.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function segments( string $html ): array {
		$segments = [];
		$offset   = 0;
		$length   = strlen( $html );

		while ( $offset < $length ) {
			// The next opening of a script, style or textarea element, or of a comment.
			if ( 1 !== preg_match( '/<(script|style|textarea)\b|<!--/i', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				break;
			}
			$start = (int) $m[0][1];
			if ( $start > $offset ) {
				$segments[] = [ 'markup', substr( $html, $offset, $start - $offset ) ];
			}

			if ( '<!--' === $m[0][0] ) {
				$close      = strpos( $html, '-->', $start + 4 );
				$end        = false === $close ? $length : $close + 3;
				$segments[] = [ 'raw', substr( $html, $start, $end - $start ) ];
				$offset     = $end;
				continue;
			}

			$tag      = strtolower( $m[1][0] );
			$open_end = $this->open_tag_end( $html, $start );
			$close    = false === $open_end ? false : stripos( $html, '</' . $tag, $open_end );
			if ( false === $close ) {
				// An element that never closes runs to the end of the document, as the browser treats it.
				$segments[] = [ 'script' === $tag ? 'script' : 'raw', substr( $html, $start ) ];
				$offset     = $length;
				break;
			}
			$gt         = strpos( $html, '>', $close );
			$end        = false === $gt ? $length : $gt + 1;
			$segments[] = [ 'script' === $tag ? 'script' : 'raw', substr( $html, $start, $end - $start ) ];
			$offset     = $end;
		}

		if ( $offset < $length ) {
			$segments[] = [ 'markup', substr( $html, $offset ) ];
		}
		return $segments;
	}

	/** Position after the `>` that ends the opening tag at $start, skipping `>` inside quoted attribute values. */
	private function open_tag_end( string $html, int $start ): int|false {
		$length = strlen( $html );
		$quote  = '';
		for ( $i = $start + 1; $i < $length; $i++ ) {
			$char = $html[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '>' === $char ) {
				return $i + 1;
			}
		}
		return false;
	}

	/**
	 * Attributes of an opening tag in source order: lower-case name => raw value as written (entities not decoded),
	 * null for a boolean attribute.
	 *
	 * @return array<string, string|null>
	 */
	private function attributes( string $open_tag ): array {
		$inner = (string) preg_replace( '/^<[a-z0-9]+|\/?>$/i', '', $open_tag );
		// Group 1 the name; groups 2, 3, 4 a double-quoted, single-quoted or unquoted value.
		preg_match_all( '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $inner, $found, PREG_SET_ORDER );
		$attributes = [];
		foreach ( $found as $attribute ) {
			$name = strtolower( $attribute[1] );
			if ( array_key_exists( $name, $attributes ) ) {
				continue; // The first occurrence wins, as in the browser.
			}
			if ( isset( $attribute[4] ) && '' !== $attribute[4] ) {
				$attributes[ $name ] = $attribute[4];
			} elseif ( isset( $attribute[3] ) && '' !== $attribute[3] ) {
				$attributes[ $name ] = $attribute[3];
			} elseif ( isset( $attribute[2] ) ) {
				$attributes[ $name ] = $attribute[2];
			} else {
				$attributes[ $name ] = null;
			}
		}
		return $attributes;
	}

	/** An opening tag built from attributes; values are written back as they were read, in double quotes. */
	private function build_tag( string $name, array $attributes ): string {
		$tag = '<' . $name;
		foreach ( $attributes as $attribute => $value ) {
			$tag .= null === $value ? ' ' . $attribute : ' ' . $attribute . '="' . str_replace( '"', '&quot;', $value ) . '"';
		}
		return $tag . '>';
	}

	/**
	 * Decide for one script element and rewrite it when it belongs to a blocked category.
	 *
	 * @param string      $element        `<script …>…</script>`.
	 * @param string|null $force_category Category imposed by a handle mapping.
	 */
	private function rewrite_script_element( string $element, ?string $force_category ): string {
		$open_end = $this->open_tag_end( $element, 0 );
		if ( false === $open_end ) {
			return $element;
		}
		$open       = substr( $element, 0, $open_end );
		$rest       = substr( $element, $open_end );
		$attributes = $this->attributes( $open );

		$category = $this->script_category( $attributes, (string) preg_replace( '/<\/script\s*>\s*$/i', '', $rest ), $force_category, $service_id );
		if ( null === $category ) {
			return $element;
		}
		return $this->build_tag( 'script', $this->blocked_script_attributes( $attributes, $category, $service_id ) ) . $rest;
	}

	/**
	 * The category a script is held back for, or null to leave it alone.
	 *
	 * @param array<string, string|null> $attributes Attributes as read (raw values).
	 * @param string                     $code       Inline code.
	 * @param string|null                $forced     Category imposed by a handle mapping.
	 * @param string|null                $service_id Set to the matching service id.
	 */
	private function script_category( array $attributes, string $code, ?string $forced, ?string &$service_id = null ): ?string {
		$service_id = '';
		if ( array_key_exists( 'data-wm-consent-ignore', $attributes ) ) {
			return null;
		}
		if ( str_starts_with( (string) ( $attributes['id'] ?? '' ), 'wm-consent-' ) ) {
			return null;
		}
		$type = strtolower( trim( html_entity_decode( (string) ( $attributes['type'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( ! in_array( $type, self::JS_TYPES, true ) ) {
			return null; // JSON-LD, import maps, text/plain markup a developer already blocked.
		}

		$category = null;
		$src      = html_entity_decode( (string) ( $attributes['src'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		if ( null !== $forced ) {
			$category = $forced;
		} elseif ( array_key_exists( 'data-wm-consent', $attributes ) ) {
			$category = Categories::normalize( (string) $attributes['data-wm-consent'] );
		} else {
			$service = $this->registry->match( '' !== $src ? $src : $code );
			if ( null !== $service ) {
				$category   = $service['category'];
				$service_id = $service['id'];
			}
		}

		/**
		 * Filters the category a script is blocked for; return null to leave it as it is.
		 *
		 * @param string|null          $category Category slug, or null when nothing matched.
		 * @param array<string, mixed> $context  `src` (decoded), `code` (inline code), `attributes` (raw), `service`.
		 */
		$category = apply_filters( 'wm_consent_script_category', $category, [ 'src' => $src, 'code' => $code, 'attributes' => $attributes, 'service' => $service_id ] );
		$category = is_string( $category ) ? Categories::normalize( $category ) : null;

		return null === $category || Categories::NECESSARY === $category ? null : $category;
	}

	/**
	 * Attributes of the held-back script: `type="text/plain"` so the browser neither fetches nor runs it, the source in
	 * `data-wm-consent-src` (moved, so nothing reads it as a request), the original type when it was not classic.
	 *
	 * @param array<string, string|null> $attributes Attributes as read.
	 * @return array<string, string|null>
	 */
	private function blocked_script_attributes( array $attributes, string $category, ?string $service_id ): array {
		$type = strtolower( trim( (string) ( $attributes['type'] ?? '' ) ) );
		$out  = [ 'type' => 'text/plain' ];
		foreach ( $attributes as $name => $value ) {
			if ( 'type' === $name || 'data-wm-consent' === $name ) {
				continue;
			}
			if ( 'src' === $name ) {
				$out['data-wm-consent-src'] = (string) $value;
				continue;
			}
			$out[ $name ] = $value;
		}
		$out['data-wm-consent-category'] = $category;
		if ( '' !== (string) $service_id ) {
			$out['data-wm-consent-service'] = (string) $service_id;
		}
		if ( '' !== $type && 'text/javascript' !== $type ) {
			$out['data-wm-consent-type'] = $type;
		}
		return $out;
	}

	/**
	 * Iframes in markup: the built-in provider patterns first (placeholders), then registered services.
	 */
	private function rewrite_iframes( string $markup ): string {
		if ( false === stripos( $markup, '<iframe' ) ) {
			return $markup;
		}
		$markup = $this->embeds->filter_content_iframes( $markup );

		// Group 1 the opening tag's attributes, group 2 the fallback content; `s` because it may span lines.
		$result = preg_replace_callback(
			'/<iframe\b([^>]*)>(.*?)<\/iframe\s*>/is',
			function ( array $m ): string {
				$attributes = $this->attributes( '<iframe' . $m[1] . '>' );
				if ( array_key_exists( 'data-wm-consent-ignore', $attributes ) || ! isset( $attributes['src'] ) ) {
					return $m[0];
				}
				$src      = html_entity_decode( (string) $attributes['src'], ENT_QUOTES, 'UTF-8' );
				$service  = null;
				$category = array_key_exists( 'data-wm-consent', $attributes ) ? Categories::normalize( (string) $attributes['data-wm-consent'] ) : null;
				if ( null === $category ) {
					$service  = $this->registry->match( $src );
					$category = null === $service ? null : $service['category'];
				}
				if ( null === $category || Categories::NECESSARY === $category ) {
					return $m[0];
				}

				// The placeholder script only loads https sources (a plain http frame is mixed content on an https
				// page anyway), so anything else keeps the iframe with its source moved aside.
				if ( Categories::MEDIA === $category && ( str_starts_with( $src, 'https://' ) || str_starts_with( $src, '//' ) ) ) {
					$height = isset( $attributes['height'] ) && 1 === preg_match( '/^\d{2,4}/', (string) $attributes['height'], $h ) ? (int) $h[0] : 400;
					return $this->embeds->render_placeholder(
						$src,
						html_entity_decode( (string) ( $attributes['title'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
						$height,
						null === $service ? __( 'externer Anbieter', 'wm-sovereign-consent-vault' ) : (string) $service['name'],
						$category,
						null === $service ? '' : (string) $service['id']
					);
				}

				$blocked = [];
				foreach ( $attributes as $name => $value ) {
					if ( 'src' === $name ) {
						$blocked['data-wm-consent-src'] = (string) $value;
					} elseif ( 'data-wm-consent' !== $name ) {
						$blocked[ $name ] = $value;
					}
				}
				$blocked['data-wm-consent-category'] = $category;
				if ( null !== $service ) {
					$blocked['data-wm-consent-service'] = (string) $service['id'];
				}
				return $this->build_tag( 'iframe', $blocked ) . $m[2] . '</iframe>';
			},
			$markup
		);
		return $result ?? $markup;
	}

	/**
	 * `wp_script_attributes` (WordPress 5.7+, applied by wp_get_script_tag(), which also builds the tags of enqueued
	 * scripts since 6.3): block a registered script before it is printed.
	 *
	 * @param array<string, string|bool> $attributes Script attributes.
	 * @return array<string, string|bool>
	 */
	public function filter_script_attributes( $attributes ): array {
		$attributes = is_array( $attributes ) ? $attributes : [];
		if ( ! $this->should_filter_tags() ) {
			return $attributes;
		}
		$category = $this->script_category( $this->string_attributes( $attributes ), '', $this->handle_category( (string) ( $attributes['id'] ?? '' ) ), $service_id );
		return null === $category ? $attributes : $this->wordpress_attributes( $this->blocked_script_attributes( $this->string_attributes( $attributes ), $category, $service_id ) );
	}

	/**
	 * `wp_inline_script_attributes` (WordPress 5.7+, applied by wp_get_inline_script_tag()): block inline code that
	 * contains a registered pattern, or that belongs to a mapped handle (`<handle>-js-before|after|extra`).
	 *
	 * @param array<string, string|bool> $attributes Script attributes.
	 * @param string                     $data       Inline code.
	 * @return array<string, string|bool>
	 */
	public function filter_inline_script_attributes( $attributes, $data = '' ): array {
		$attributes = is_array( $attributes ) ? $attributes : [];
		if ( ! $this->should_filter_tags() ) {
			return $attributes;
		}
		$category = $this->script_category( $this->string_attributes( $attributes ), (string) $data, $this->handle_category( (string) ( $attributes['id'] ?? '' ) ), $service_id );
		return null === $category ? $attributes : $this->wordpress_attributes( $this->blocked_script_attributes( $this->string_attributes( $attributes ), $category, $service_id ) );
	}

	/**
	 * `script_loader_tag` (WordPress 4.1+, applied in WP_Scripts::do_item() to the whole output of a handle:
	 * translations, inline before, the script, inline after): the tags the two filters above did not reach, and the
	 * handles mapped to a category through `wm_consent_script_handles`, all parts included so their order holds.
	 *
	 * @param string $tag    Tags of the handle.
	 * @param string $handle Script handle.
	 * @param string $src    Script source.
	 */
	public function filter_script_loader_tag( $tag, $handle = '', $src = '' ): string {
		$tag = (string) $tag;
		if ( ! $this->should_filter_tags() ) {
			return $tag;
		}
		return $this->rewrite_html( $tag, $this->handle_category( (string) $handle . '-js' ) );
	}

	/**
	 * Category a script handle is mapped to by `wm_consent_script_handles`, from a tag id such as `ga-js`,
	 * `ga-js-before` or `ga-js-extra`.
	 */
	private function handle_category( string $id ): ?string {
		if ( 1 !== preg_match( '/^(.+)-js(?:-(?:before|after|extra|translations))?$/', $id, $m ) ) {
			return null;
		}
		/**
		 * Filters the script handles that wait for a category, e.g. `[ 'my-analytics' => 'statistics' ]`.
		 *
		 * @param array<string, string> $handles Handle => category slug.
		 */
		$handles  = apply_filters( 'wm_consent_script_handles', [] );
		$category = is_array( $handles ) && is_string( $handles[ $m[1] ] ?? null ) ? Categories::normalize( $handles[ $m[1] ] ) : null;
		return null === $category || Categories::NECESSARY === $category ? null : $category;
	}

	/** The tag filters act where the buffer acts; outside it (admin, REST, feeds) they leave WordPress's tags alone. */
	private function should_filter_tags(): bool {
		return $this->should_block();
	}

	/**
	 * Attributes as WordPress passes them (values may be booleans) in the raw shape script_category() reads.
	 *
	 * @param array<string, string|bool> $attributes Attributes.
	 * @return array<string, string|null>
	 */
	private function string_attributes( array $attributes ): array {
		$out = [];
		foreach ( $attributes as $name => $value ) {
			if ( false === $value ) {
				continue;
			}
			$out[ strtolower( (string) $name ) ] = true === $value ? null : (string) $value;
		}
		return $out;
	}

	/**
	 * Back to WordPress's shape: a boolean attribute is `true` again, so wp_sanitize_script_attributes() prints
	 * `async` instead of `async=""`.
	 *
	 * @param array<string, string|null> $attributes Attributes.
	 * @return array<string, string|bool>
	 */
	private function wordpress_attributes( array $attributes ): array {
		return array_map( static fn( ?string $value ): string|bool => null === $value ? true : $value, $attributes );
	}
}
