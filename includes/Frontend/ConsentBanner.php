<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Core\ConsentCookie;
use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

/**
 * Consent dialog in the footer, its configuration, its assets and the shortcode that reopens it.
 *
 * What the decision does since the categories exist: the browser keeps it (localStorage and the `wm_consent`
 * cookie), it is posted to `wm-consent/v1/record`, and assets/js/wm-consent-vault.js activates the scripts and
 * embeds the blocker held back for the accepted categories. Until 2026-09-17 nothing read it.
 *
 * Until 2026-09-14 the banner told visitors the site used "Technologien und Cookies ausschließlich lokal auf diesem
 * Server" and that optional analytics and media modules load only after consent — statements about the operator's
 * site the plugin could neither know nor enforce — and its source carried "DSK Compliant Anti-Dark-Pattern Banner".
 * The default texts below still make no statement about the site and claim no conformity with a law; the operator
 * can replace them under Consent Vault › Einstellungen. The shipped defaults are neutral, factual templates for
 * each site operator to review and adapt.
 */
final class ConsentBanner {
	/** Handle of the front-end script and style; the script tag carries the id `wm-consent-vault-js`. */
	public const HANDLE = 'wm-consent-vault';

	private ServiceRegistry $registry;

	public function __construct( ?ServiceRegistry $registry = null ) {
		$this->registry = $registry ?? new ServiceRegistry();
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_editor_style' ] );
		add_action( 'wp_footer', [ $this, 'render_banner' ] );
		add_shortcode( 'wm_consent_settings', [ $this, 'render_settings_shortcode' ] );
	}

	/**
	 * Default wording of the editable banner texts (Settings::TEXT_KEYS). Translatable; an administrator's text
	 * replaces a default only when the field is not empty.
	 *
	 * @return array<string, string>
	 */
	public static function default_texts(): array {
		return [
			'heading'        => __( 'Datenschutz-Einstellungen', 'wm-sovereign-consent-vault' ),
			// The user agent is stored as it arrives, not hashed; an earlier sentence read as if both values were
			// hashed, which was a false statement about this site's data processing.
			'description'    => __( 'Sie entscheiden, welche Kategorien von Diensten diese Website verwenden darf. Ihre Auswahl wird in Ihrem Browser gespeichert und auf diesem Server protokolliert: mit einem Hash Ihrer IP-Adresse, der Kennung Ihres Browsers (User-Agent) im Klartext und dem Zeitpunkt.', 'wm-sovereign-consent-vault' ),
			'accept_all'     => __( 'Alle akzeptieren', 'wm-sovereign-consent-vault' ),
			'necessary_only' => __( 'Nur notwendige', 'wm-sovereign-consent-vault' ),
			'settings'       => __( 'Einstellungen', 'wm-sovereign-consent-vault' ),
			'save'           => __( 'Auswahl speichern', 'wm-sovereign-consent-vault' ),
			'privacy_link'   => __( 'Datenschutzerklärung', 'wm-sovereign-consent-vault' ),
			'imprint_link'   => __( 'Impressum', 'wm-sovereign-consent-vault' ),
		];
	}

	/** Front-end sheet and script, versioned by file time so a deploy is not served from a stale cache. */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, WM_CONSENT_VAULT_URL . 'assets/css/wm-consent-vault.css', [], self::asset_version( 'css/wm-consent-vault.css' ) );
		wp_enqueue_script( self::HANDLE, WM_CONSENT_VAULT_URL . 'assets/js/wm-consent-vault.js', [], self::asset_version( 'js/wm-consent-vault.js' ), [ 'in_footer' => true ] );
	}

	/**
	 * The sheet, without the script, inside the block editor's canvas. A block that prints [wm_consent_settings]
	 * (WM Suite Hub's privacy-center) showed a bare browser button there until 1.2.1: the sheet came only through
	 * wp_enqueue_scripts, which the iframed canvas never runs (measured 2026-11-21 with
	 * wp-plugin-fleet-editor-canvas-probe.py: background rgb(239, 239, 239), min-height 0px). The script stays out of
	 * the editor: it would bind the button to a dialog that is not rendered there and post to the REST routes.
	 * On the front end enqueue_assets() loads both, so this returns there.
	 */
	public function enqueue_editor_style(): void {
		if ( ! is_admin() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, WM_CONSENT_VAULT_URL . 'assets/css/wm-consent-vault.css', [], self::asset_version( 'css/wm-consent-vault.css' ) );
	}

	/** File time of an asset, or the plugin version when the file is missing. */
	private static function asset_version( string $file ): string {
		$path = dirname( __DIR__, 2 ) . '/assets/' . $file;
		return is_file( $path ) ? (string) filemtime( $path ) : WM_CONSENT_VAULT_VERSION;
	}

	/**
	 * What the script needs: the policy version and duration a stored decision is checked against, the REST
	 * routes, the categories, and per service the category and the cookies to delete on revocation.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$services = [];
		foreach ( $this->registry->all() as $service ) {
			$services[] = [
				'id'       => $service['id'],
				'category' => $service['category'],
				'cookies'  => $service['cookies'],
			];
		}

		// The dialog does not open by itself on the privacy policy and the legal notice: a modal over the page that
		// explains it would stop visitors from reading it before they decide.
		$suppress = [];
		foreach ( [ Settings::privacy_url(), Settings::imprint_url() ] as $url ) {
			$path = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
			if ( '' !== $path ) {
				$suppress[] = $path;
			}
		}

		return [
			'policyVersion' => Settings::policy_version(),
			'durationDays'  => Settings::duration_days(),
			'nonceUrl'      => esc_url_raw( rest_url( 'wm-consent/v1/nonce' ) ),
			'recordUrl'     => esc_url_raw( rest_url( 'wm-consent/v1/record' ) ),
			'categories'    => Categories::SLUGS,
			'aliases'       => Categories::ALIASES,
			'services'      => $services,
			'suppressOn'    => $suppress,
			'cookieName'    => ConsentCookie::NAME,
			'secure'        => is_ssl(),
		];
	}

	/**
	 * The dialog, hidden until the script decides it is needed, and its configuration as a JSON data block.
	 *
	 * role="dialog" with aria-modal, a name (the heading) and a description (the text). The script moves focus into
	 * it, keeps Tab inside, and returns focus to where it was when it closes. "Alle akzeptieren" and "Nur notwendige"
	 * share one class and therefore one visual weight. No inline style: every colour and size comes from the custom
	 * properties in assets/css/wm-consent-vault.css.
	 */
	public function render_banner(): void {
		if ( is_admin() ) {
			return;
		}
		$texts        = [];
		foreach ( Settings::TEXT_KEYS as $key ) {
			$texts[ $key ] = Settings::text( $key );
		}
		$labels       = Categories::defaults();
		$privacy_url  = Settings::privacy_url();
		$imprint_url  = Settings::imprint_url();
		$new_tab_note = __( '(öffnet in einem neuen Tab)', 'wm-sovereign-consent-vault' );
		?>
		<div id="wm-consent-vault-banner" class="wm-consent" data-wm-consent-root hidden>
			<div class="wm-consent__backdrop"></div>
			<div class="wm-consent__dialog" role="dialog" aria-modal="true" aria-labelledby="wm-consent-vault-title" aria-describedby="wm-consent-vault-description" tabindex="-1">
				<div class="wm-consent__view" data-wm-consent-view="main">
					<h2 id="wm-consent-vault-title" class="wm-consent__title">
						<svg class="wm-consent__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
						<?php echo esc_html( $texts['heading'] ); ?>
					</h2>
					<p id="wm-consent-vault-description" class="wm-consent__text"><?php echo nl2br( esc_html( $texts['description'] ) ); ?></p>
					<div class="wm-consent__actions">
						<button type="button" class="wm-consent__button wm-consent__button--choice" data-wm-consent-action="accept-all"><?php echo esc_html( $texts['accept_all'] ); ?></button>
						<button type="button" class="wm-consent__button wm-consent__button--choice" data-wm-consent-action="necessary"><?php echo esc_html( $texts['necessary_only'] ); ?></button>
						<button type="button" class="wm-consent__button wm-consent__button--secondary" data-wm-consent-action="settings" aria-expanded="false" aria-controls="wm-consent-vault-settings"><?php echo esc_html( $texts['settings'] ); ?></button>
					</div>
				</div>

				<div id="wm-consent-vault-settings" class="wm-consent__view" data-wm-consent-view="settings" hidden>
					<h2 id="wm-consent-vault-settings-title" class="wm-consent__title" tabindex="-1"><?php esc_html_e( 'Auswahl nach Kategorien', 'wm-sovereign-consent-vault' ); ?></h2>
					<ul class="wm-consent__categories">
						<?php foreach ( Categories::SLUGS as $slug ) : ?>
							<?php
							$services  = $this->registry->in_category( $slug );
							$input_id  = 'wm-consent-category-' . $slug;
							$is_locked = Categories::NECESSARY === $slug;
							?>
						<li class="wm-consent__category">
							<div class="wm-consent__category-head">
								<span class="wm-consent__switch-wrap">
									<input type="checkbox" role="switch" class="wm-consent__switch" id="<?php echo esc_attr( $input_id ); ?>" value="<?php echo esc_attr( $slug ); ?>" data-wm-consent-category-input aria-describedby="<?php echo esc_attr( $input_id . '-description' ); ?>"<?php echo $is_locked ? ' checked disabled' : ''; ?> />
									<span class="wm-consent__switch-track" aria-hidden="true"></span>
								</span>
								<label class="wm-consent__category-label" for="<?php echo esc_attr( $input_id ); ?>">
									<?php echo esc_html( $labels[ $slug ]['label'] ); ?>
									<?php if ( $is_locked ) : ?>
									<span class="wm-consent__badge"><?php esc_html_e( 'Immer aktiv', 'wm-sovereign-consent-vault' ); ?></span>
									<?php endif; ?>
								</label>
							</div>
							<p id="<?php echo esc_attr( $input_id . '-description' ); ?>" class="wm-consent__text"><?php echo esc_html( Settings::category_description( $slug ) ); ?></p>
							<?php if ( [] !== $services ) : ?>
							<details class="wm-consent__services">
								<summary>
									<?php
									/* translators: %d: number of services in this category */
									echo esc_html( sprintf( _n( '%d Dienst in dieser Kategorie', '%d Dienste in dieser Kategorie', count( $services ), 'wm-sovereign-consent-vault' ), count( $services ) ) );
									?>
								</summary>
								<ul class="wm-consent__service-list">
									<?php foreach ( $services as $service ) : ?>
									<li class="wm-consent__service">
										<p class="wm-consent__service-name"><?php echo esc_html( $service['name'] ); ?></p>
										<dl class="wm-consent__service-facts">
											<?php if ( '' !== $service['provider'] ) : ?>
											<dt><?php esc_html_e( 'Anbieter', 'wm-sovereign-consent-vault' ); ?></dt>
											<dd><?php echo esc_html( $service['provider'] ); ?></dd>
											<?php endif; ?>
											<?php if ( '' !== $service['purpose'] ) : ?>
											<dt><?php esc_html_e( 'Zweck', 'wm-sovereign-consent-vault' ); ?></dt>
											<dd><?php echo esc_html( $service['purpose'] ); ?></dd>
											<?php endif; ?>
											<?php if ( [] !== $service['cookies'] ) : ?>
											<dt><?php esc_html_e( 'Cookies', 'wm-sovereign-consent-vault' ); ?></dt>
											<dd><?php echo esc_html( implode( ', ', $service['cookies'] ) ); ?></dd>
											<?php endif; ?>
											<?php if ( '' !== $service['privacy_url'] ) : ?>
											<dt><?php esc_html_e( 'Datenschutz', 'wm-sovereign-consent-vault' ); ?></dt>
											<dd><a href="<?php echo esc_url( $service['privacy_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Datenschutzerklärung des Anbieters', 'wm-sovereign-consent-vault' ); ?> <span class="wm-consent__sr-only"><?php echo esc_html( $new_tab_note ); ?></span></a></dd>
											<?php endif; ?>
										</dl>
									</li>
									<?php endforeach; ?>
								</ul>
							</details>
							<?php endif; ?>
						</li>
						<?php endforeach; ?>
					</ul>
					<div class="wm-consent__actions">
						<button type="button" class="wm-consent__button wm-consent__button--choice" data-wm-consent-action="save"><?php echo esc_html( $texts['save'] ); ?></button>
						<button type="button" class="wm-consent__button wm-consent__button--choice" data-wm-consent-action="accept-all"><?php echo esc_html( $texts['accept_all'] ); ?></button>
						<button type="button" class="wm-consent__button wm-consent__button--secondary" data-wm-consent-action="back"><?php esc_html_e( 'Zurück', 'wm-sovereign-consent-vault' ); ?></button>
					</div>
				</div>

				<?php if ( '' !== $privacy_url || '' !== $imprint_url ) : ?>
				<p class="wm-consent__links">
					<?php if ( '' !== $privacy_url ) : ?>
					<a class="wm-consent__link" href="<?php echo esc_url( $privacy_url ); ?>"><?php echo esc_html( $texts['privacy_link'] ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $imprint_url ) : ?>
					<a class="wm-consent__link" href="<?php echo esc_url( $imprint_url ); ?>"><?php echo esc_html( $texts['imprint_link'] ); ?></a>
					<?php endif; ?>
				</p>
				<?php endif; ?>

				<button type="button" class="wm-consent__close" data-wm-consent-action="close" hidden>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
					<span class="wm-consent__sr-only"><?php esc_html_e( 'Schließen, ohne die Auswahl zu ändern', 'wm-sovereign-consent-vault' ); ?></span>
				</button>
			</div>
		</div>
		<?php
		// A data block, not a script: the browser does not execute it, so a Content-Security-Policy does not need to
		// allow it, and the blocker leaves it alone (its type is not JavaScript). JSON_HEX_TAG keeps a `</script>`
		// inside a text from closing the block.
		?>
		<script type="application/json" id="wm-consent-config"><?php echo wp_json_encode( $this->config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
		<?php
	}

	/**
	 * A button that opens the dialog again. The script binds every element carrying `data-wm-consent-open`; until
	 * 2026-09-17 the button set the banner visible through an inline onclick and inline styles.
	 */
	public function render_settings_shortcode(): string {
		$label = esc_html__( 'Datenschutzeinstellungen anpassen', 'wm-sovereign-consent-vault' );
		return '<div class="wm-consent-settings-trigger"><button type="button" class="wm-btn wm-consent-settings-button" data-wm-consent-open>' . $label . '</button></div>';
	}
}
