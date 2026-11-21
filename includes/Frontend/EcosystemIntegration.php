<?php
/**
 * WordPress 6.5+ Block Bindings (`wm-consent/status`) and GenerateBlocks 2.x Dynamic Tag (`{{wm_consent_status}}`).
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

final class EcosystemIntegration {
	public const BINDING_SOURCE = 'wm-consent/status';
	public const DYNAMIC_TAG    = 'wm_consent_status';

	private ServiceRegistry $registry;

	public function __construct( ?ServiceRegistry $registry = null ) {
		$this->registry = $registry ?? new ServiceRegistry();
	}

	public function boot(): void {
		add_action( 'init', [ $this, 'register_block_bindings' ], 20 );
		add_action( 'init', [ $this, 'register_generateblocks_tags' ], 20 );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ $this, 'filter_generateblocks_tag' ], 10, 3 );
	}

	/**
	 * Register the WP 6.5+ Block Bindings source `wm-consent/status`.
	 */
	public function register_block_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'wm-consent/status',
			[
				'label'              => __( 'WM Consent Status', 'wm-sovereign-consent-vault' ),
				'get_value_callback' => [ $this, 'resolve_binding_value' ],
				'uses_context'       => [],
			]
		);
	}

	/**
	 * Register the GenerateBlocks 2.x Dynamic Tag `{{wm_consent_status}}`.
	 */
	public function register_generateblocks_tags(): void {
		if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return;
		}

		new \GenerateBlocks_Register_Dynamic_Tag(
			[
				'title'       => __( 'WM Consent Status', 'wm-sovereign-consent-vault' ),
				'tag'         => 'wm_consent_status',
				'type'        => 'wm-consent-vault',
				'supports'    => [],
				'description' => __( 'Gibt die aktuelle Richtlinien-Version oder den Kategorien-Status aus.', 'wm-sovereign-consent-vault' ),
				'return'      => [ $this, 'render_generateblocks_tag' ],
			]
		);
	}

	/**
	 * Callback for WP 6.5+ Block Bindings (`wm-consent/status`).
	 *
	 * @param array<string, mixed> $source_args Binding arguments (`field`, `category`).
	 * @return string
	 */
	public function resolve_binding_value( array $source_args = [] ): string {
		$field    = isset( $source_args['field'] ) && is_string( $source_args['field'] ) ? sanitize_key( $source_args['field'] ) : '';
		$category = isset( $source_args['category'] ) && is_string( $source_args['category'] ) ? sanitize_key( $source_args['category'] ) : '';

		return $this->status_text( $field, $category );
	}

	/**
	 * Callback for `GenerateBlocks_Register_Dynamic_Tag` (`{{wm_consent_status}}`).
	 *
	 * @param array<string, mixed> $options Tag options (`field`, `category`).
	 * @return string
	 */
	public function render_generateblocks_tag( array $options = [] ): string {
		$field    = isset( $options['field'] ) && is_string( $options['field'] ) ? sanitize_key( $options['field'] ) : '';
		$category = isset( $options['category'] ) && is_string( $options['category'] ) ? sanitize_key( $options['category'] ) : '';

		return esc_html( $this->status_text( $field, $category ) );
	}

	/**
	 * Fallback filter for `generateblocks_dynamic_tag_replacement` when `{{wm_consent_status}}` appears in content.
	 *
	 * @param mixed                $replacement Current replacement.
	 * @param mixed                $tag_details Parsed tag details or tag string.
	 * @param array<string, mixed> $options     Tag options.
	 * @return mixed
	 */
	public function filter_generateblocks_tag( mixed $replacement, mixed $tag_details = null, array $options = [] ): mixed {
		$tag_name = '';
		if ( is_array( $tag_details ) && isset( $tag_details['tag'] ) && is_string( $tag_details['tag'] ) ) {
			$tag_name = $tag_details['tag'];
		} elseif ( is_string( $tag_details ) ) {
			$tag_name = $tag_details;
		}

		if ( self::DYNAMIC_TAG !== $tag_name ) {
			return $replacement;
		}

		return $this->render_generateblocks_tag( $options );
	}

	/**
	 * Resolve a cache-safe summary or per-category consent state.
	 */
	public function status_text( string $field = '', string $category = '' ): string {
		if ( '' !== $category ) {
			$granted = wm_consent_has( $category );
			$label   = $granted
				? __( 'Freigegeben', 'wm-sovereign-consent-vault' )
				: __( 'Blockiert', 'wm-sovereign-consent-vault' );
		} elseif ( 'policy_version' === $field ) {
			$label = Settings::policy_version();
		} elseif ( 'services_count' === $field ) {
			$label = (string) count( $this->registry->all() );
		} else {
			$label = sprintf(
				/* translators: %s: active consent policy version */
				__( 'Richtlinie v%s', 'wm-sovereign-consent-vault' ),
				Settings::policy_version()
			);
		}

		/**
		 * Filters the rendered text for `wm-consent/status` Block Bindings and `{{wm_consent_status}}`.
		 *
		 * @param string $label    Computed status text.
		 * @param string $field    Requested field ('', 'policy_version', 'services_count').
		 * @param string $category Requested category slug (or '' when site-wide).
		 */
		return (string) apply_filters( 'wm_consent_status_label', $label, $field, $category );
	}
}
