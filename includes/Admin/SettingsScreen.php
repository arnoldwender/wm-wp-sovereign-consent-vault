<?php
/**
 * The "Einstellungen" and "Dienste" tabs of the Consent Vault screen and the admin-post handlers behind their forms.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Frontend\ConsentBanner;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

/**
 * Every form posts to admin-post.php. Each handler checks `manage_options` and its own nonce before it reads a single
 * field, sanitises each field it reads, and redirects back with a notice code; nothing is written on a GET.
 */
final class SettingsScreen {
	public const ACTION_SETTINGS       = 'wm_consent_save_settings';
	public const ACTION_BUMP           = 'wm_consent_bump_policy';
	public const ACTION_SAVE_SERVICE   = 'wm_consent_save_service';
	public const ACTION_DELETE_SERVICE = 'wm_consent_delete_service';
	public const ACTION_ADD_PRESET     = 'wm_consent_add_preset';

	/** Submitted service values kept for one minute, so a form with an error comes back filled in. */
	public const DRAFT_TRANSIENT = 'wm_consent_service_draft_';

	private const PAGE = 'wm-consent-vault';

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_' . self::ACTION_SETTINGS, [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_' . self::ACTION_BUMP, [ $this, 'handle_bump_policy' ] );
		add_action( 'admin_post_' . self::ACTION_SAVE_SERVICE, [ $this, 'handle_save_service' ] );
		add_action( 'admin_post_' . self::ACTION_DELETE_SERVICE, [ $this, 'handle_delete_service' ] );
		add_action( 'admin_post_' . self::ACTION_ADD_PRESET, [ $this, 'handle_add_preset' ] );
	}

	/** Register the explicit opt-in option controlling data removal on uninstall. */
	public function register_settings(): void {
		if ( function_exists( 'register_setting' ) ) {
			register_setting(
				'wm_consent_vault_settings',
				Settings::DELETE_DATA_OPTION,
				[
					'type'              => 'boolean',
					'sanitize_callback' => static fn( $value ): string => ( true === $value || 1 === $value || '1' === $value ) ? '1' : '0',
					'default'           => '0',
				]
			);
		}
	}

	// ------------------------------------------------------------------------------------------ handlers

	/** Save texts, links, category descriptions, policy version, duration and the blocking switch. */
	public function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-sovereign-consent-vault' ), 403 );
		}
		check_admin_referer( self::ACTION_SETTINGS );

		$texts = [];
		foreach ( Settings::TEXT_KEYS as $key ) {
			$raw            = isset( $_POST['texts'][ $key ] ) && is_string( $_POST['texts'][ $key ] ) ? wp_unslash( $_POST['texts'][ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised on the next line, per field type.
			$texts[ $key ] = 'description' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}
		$descriptions = [];
		foreach ( Categories::SLUGS as $slug ) {
			$descriptions[ $slug ] = isset( $_POST['category_descriptions'][ $slug ] ) && is_string( $_POST['category_descriptions'][ $slug ] ) ? sanitize_textarea_field( wp_unslash( $_POST['category_descriptions'][ $slug ] ) ) : '';
		}

		$errors = Settings::save(
			[
				'texts'                 => $texts,
				'privacy_url'           => isset( $_POST['privacy_url'] ) && is_string( $_POST['privacy_url'] ) ? esc_url_raw( wp_unslash( $_POST['privacy_url'] ) ) : '',
				'imprint_url'           => isset( $_POST['imprint_url'] ) && is_string( $_POST['imprint_url'] ) ? esc_url_raw( wp_unslash( $_POST['imprint_url'] ) ) : '',
				'category_descriptions' => $descriptions,
				'policy_version'        => isset( $_POST['policy_version'] ) && is_string( $_POST['policy_version'] ) ? sanitize_text_field( wp_unslash( $_POST['policy_version'] ) ) : '',
				'duration_days'         => isset( $_POST['duration_days'] ) && is_string( $_POST['duration_days'] ) ? sanitize_text_field( wp_unslash( $_POST['duration_days'] ) ) : '',
				// An unchecked checkbox sends nothing, so its absence is the "off" value.
				'blocking_enabled'      => isset( $_POST['blocking_enabled'] ) ? '1' : '0',
			]
		);
		update_option( Settings::DELETE_DATA_OPTION, isset( $_POST['delete_data_on_uninstall'] ) ? '1' : '0', false );

		$this->redirect( 'settings', [] === $errors ? 'settings_saved' : 'settings_partial', [ 'fields' => implode( ',', $errors ) ] );
	}

	/** Raise the policy version by one step, so every visitor is asked again. */
	public function handle_bump_policy(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-sovereign-consent-vault' ), 403 );
		}
		check_admin_referer( self::ACTION_BUMP );

		$version = Settings::bump_policy_version();
		$this->redirect( 'settings', 'policy_bumped', [ 'version' => $version ] );
	}

	/** Add a service or replace the one being edited. */
	public function handle_save_service(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-sovereign-consent-vault' ), 403 );
		}
		check_admin_referer( self::ACTION_SAVE_SERVICE );

		$input = [];
		foreach ( [ 'id', 'name', 'provider', 'category', 'preset' ] as $key ) {
			$input[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		foreach ( [ 'purpose', 'cookies', 'hosts' ] as $key ) {
			$input[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		$input['privacy_url'] = isset( $_POST['privacy_url'] ) && is_string( $_POST['privacy_url'] ) ? esc_url_raw( wp_unslash( $_POST['privacy_url'] ) ) : '';
		$original_id          = isset( $_POST['original_id'] ) && is_string( $_POST['original_id'] ) ? sanitize_key( wp_unslash( $_POST['original_id'] ) ) : '';

		$errors = ServiceRegistry::save_service( $input, $original_id );
		if ( [] === $errors ) {
			delete_transient( self::DRAFT_TRANSIENT . get_current_user_id() );
			$this->redirect( 'services', '' === $original_id ? 'service_added' : 'service_updated' );
		}

		set_transient( self::DRAFT_TRANSIENT . get_current_user_id(), $input + [ 'original_id' => $original_id ], MINUTE_IN_SECONDS );
		$this->redirect( 'services', 'service_invalid', [ 'fields' => implode( ',', $errors ), 'edit' => '' !== $original_id ? $original_id : 'draft' ] );
	}

	public function handle_delete_service(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-sovereign-consent-vault' ), 403 );
		}
		check_admin_referer( self::ACTION_DELETE_SERVICE );

		$id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$this->redirect( 'services', ServiceRegistry::delete_service( $id ) ? 'service_deleted' : 'service_missing' );
	}

	public function handle_add_preset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-sovereign-consent-vault' ), 403 );
		}
		check_admin_referer( self::ACTION_ADD_PRESET );

		$key    = isset( $_POST['preset'] ) && is_string( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		$result = ServiceRegistry::add_preset( $key );
		$this->redirect( 'services', 'added' === $result ? 'preset_added' : ( 'exists' === $result ? 'preset_exists' : 'preset_unknown' ) );
	}

	/**
	 * Back to a tab with a notice code and ends the request.
	 *
	 * @param array<string, string> $extra Additional query arguments.
	 */
	private function redirect( string $tab, string $notice, array $extra = [] ): void {
		$args = array_merge( [ 'page' => self::PAGE, 'tab' => $tab, 'wm_notice' => $notice ], array_filter( $extra, static fn( string $v ): bool => '' !== $v ) );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------------------------------------ notices

	/**
	 * Text and type of a notice after a redirect; null for an unknown code.
	 *
	 * @param string $code   Notice code from the query string.
	 * @param string $fields Comma-separated field names that were not accepted.
	 * @param string $extra  Version after a bump.
	 * @return array{type: string, text: string}|null
	 */
	public static function notice( string $code, string $fields = '', string $extra = '' ): ?array {
		$field_labels = [
			'privacy_url'    => __( 'Datenschutzerklärung (URL)', 'wm-sovereign-consent-vault' ),
			'imprint_url'    => __( 'Impressum (URL)', 'wm-sovereign-consent-vault' ),
			'policy_version' => __( 'Version der Richtlinie', 'wm-sovereign-consent-vault' ),
			'duration_days'  => __( 'Gültigkeit in Tagen', 'wm-sovereign-consent-vault' ),
			'name'           => __( 'Name', 'wm-sovereign-consent-vault' ),
			'category'       => __( 'Kategorie', 'wm-sovereign-consent-vault' ),
			'hosts'          => __( 'Host- oder Dateimuster', 'wm-sovereign-consent-vault' ),
			'cookies'        => __( 'Cookie-Namen', 'wm-sovereign-consent-vault' ),
			'id'             => __( 'Kennung', 'wm-sovereign-consent-vault' ),
			'duplicate_id'   => __( 'Kennung (schon vergeben)', 'wm-sovereign-consent-vault' ),
			'not_found'      => __( 'Dienst (nicht mehr vorhanden)', 'wm-sovereign-consent-vault' ),
		];
		$named = [];
		foreach ( array_filter( explode( ',', $fields ) ) as $field ) {
			$named[] = $field_labels[ $field ] ?? $field;
		}
		$list = implode( ', ', $named );

		switch ( $code ) {
			case 'settings_saved':
				return [ 'type' => 'success', 'text' => __( 'Einstellungen gespeichert.', 'wm-sovereign-consent-vault' ) ];
			case 'settings_partial':
				/* translators: %s: list of fields */
				return [ 'type' => 'warning', 'text' => sprintf( __( 'Gespeichert, bis auf diese Felder, die ihren bisherigen Wert behalten: %s', 'wm-sovereign-consent-vault' ), $list ) ];
			case 'policy_bumped':
				/* translators: %s: new policy version */
				return [ 'type' => 'success', 'text' => sprintf( __( 'Version der Richtlinie ist jetzt „%s“. Besucher sehen das Banner beim nächsten Seitenaufruf erneut.', 'wm-sovereign-consent-vault' ), $extra ) ];
			case 'service_added':
				return [ 'type' => 'success', 'text' => __( 'Dienst hinzugefügt.', 'wm-sovereign-consent-vault' ) ];
			case 'service_updated':
				return [ 'type' => 'success', 'text' => __( 'Dienst gespeichert.', 'wm-sovereign-consent-vault' ) ];
			case 'service_invalid':
				/* translators: %s: list of fields */
				return [ 'type' => 'error', 'text' => sprintf( __( 'Der Dienst wurde nicht gespeichert. Bitte prüfen: %s', 'wm-sovereign-consent-vault' ), $list ) ];
			case 'service_deleted':
				return [ 'type' => 'success', 'text' => __( 'Dienst entfernt.', 'wm-sovereign-consent-vault' ) ];
			case 'service_missing':
				return [ 'type' => 'error', 'text' => __( 'Diesen Dienst gibt es nicht mehr.', 'wm-sovereign-consent-vault' ) ];
			case 'preset_added':
				return [ 'type' => 'success', 'text' => __( 'Vorlage als Dienst hinzugefügt. Bitte Anbieter und Angaben für Ihre Website prüfen.', 'wm-sovereign-consent-vault' ) ];
			case 'preset_exists':
				return [ 'type' => 'warning', 'text' => __( 'Ein Dienst mit dieser Kennung ist schon eingetragen; er wurde nicht überschrieben.', 'wm-sovereign-consent-vault' ) ];
			case 'preset_unknown':
				return [ 'type' => 'error', 'text' => __( 'Unbekannte Vorlage.', 'wm-sovereign-consent-vault' ) ];
		}
		return null;
	}

	/** Print the notice named in the query string, if any. */
	public function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags after a redirect.
		$code    = isset( $_GET['wm_notice'] ) ? sanitize_key( wp_unslash( $_GET['wm_notice'] ) ) : '';
		$fields  = isset( $_GET['fields'] ) ? sanitize_text_field( wp_unslash( $_GET['fields'] ) ) : '';
		$version = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$notice = '' === $code ? null : self::notice( $code, $fields, $version );
		if ( null === $notice ) {
			return;
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['text'] ) );
	}

	// ------------------------------------------------------------------------------------------ settings tab

	public function render_settings_tab(): void {
		$settings = Settings::get();
		$defaults = ConsentBanner::default_texts();
		$labels   = [
			'heading'        => __( 'Überschrift', 'wm-sovereign-consent-vault' ),
			'description'    => __( 'Text', 'wm-sovereign-consent-vault' ),
			'accept_all'     => __( 'Schaltfläche „alle akzeptieren“', 'wm-sovereign-consent-vault' ),
			'necessary_only' => __( 'Schaltfläche „nur notwendige“', 'wm-sovereign-consent-vault' ),
			'settings'       => __( 'Schaltfläche „Einstellungen“', 'wm-sovereign-consent-vault' ),
			'save'           => __( 'Schaltfläche „Auswahl speichern“', 'wm-sovereign-consent-vault' ),
			'privacy_link'   => __( 'Linktext Datenschutzerklärung', 'wm-sovereign-consent-vault' ),
			'imprint_link'   => __( 'Linktext Impressum', 'wm-sovereign-consent-vault' ),
		];
		$wp_privacy = (string) get_privacy_policy_url();
		$categories = Categories::defaults();
		?>
		<div class="wm-tab-content">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SETTINGS ); ?>" />
				<?php wp_nonce_field( self::ACTION_SETTINGS ); ?>

				<section class="wm-card" aria-labelledby="wm-consent-settings-texts">
					<h2 id="wm-consent-settings-texts"><?php esc_html_e( 'Texte des Banners', 'wm-sovereign-consent-vault' ); ?></h2>
					<p class="wm-field-desc"><?php esc_html_e( 'Ein leeres Feld verwendet den Standardtext, der unter dem Feld steht und mit der Sprache der Website übersetzt wird. Die Standardtexte beschreiben, was das Plugin speichert; ob sie für Ihre Website rechtlich genügen, prüft das Plugin nicht.', 'wm-sovereign-consent-vault' ); ?></p>
					<?php foreach ( Settings::TEXT_KEYS as $key ) : ?>
						<?php $field_id = 'wm-consent-text-' . $key; ?>
					<div class="wm-form-group">
						<label class="wm-label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $labels[ $key ] ); ?></label>
						<?php if ( 'description' === $key ) : ?>
						<textarea class="wm-textarea" id="<?php echo esc_attr( $field_id ); ?>" name="texts[<?php echo esc_attr( $key ); ?>]" rows="4" maxlength="2000" aria-describedby="<?php echo esc_attr( $field_id . '-default' ); ?>"><?php echo esc_textarea( $settings['texts'][ $key ] ); ?></textarea>
						<?php else : ?>
						<input class="wm-input" type="text" id="<?php echo esc_attr( $field_id ); ?>" name="texts[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings['texts'][ $key ] ); ?>" maxlength="2000" aria-describedby="<?php echo esc_attr( $field_id . '-default' ); ?>" />
						<?php endif; ?>
						<p class="wm-field-desc" id="<?php echo esc_attr( $field_id . '-default' ); ?>">
							<?php
							/* translators: %s: default text */
							echo esc_html( sprintf( __( 'Standard: %s', 'wm-sovereign-consent-vault' ), $defaults[ $key ] ?? '' ) );
							?>
						</p>
					</div>
					<?php endforeach; ?>
				</section>

				<section class="wm-card" aria-labelledby="wm-consent-settings-links">
					<h2 id="wm-consent-settings-links"><?php esc_html_e( 'Links im Banner', 'wm-sovereign-consent-vault' ); ?></h2>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-privacy-url"><?php esc_html_e( 'Datenschutzerklärung (URL)', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="url" id="wm-consent-privacy-url" name="privacy_url" value="<?php echo esc_attr( $settings['privacy_url'] ); ?>" placeholder="<?php echo esc_attr( $wp_privacy ); ?>" aria-describedby="wm-consent-privacy-url-help" />
						<p class="wm-field-desc" id="wm-consent-privacy-url-help">
							<?php
							echo esc_html(
								'' !== $wp_privacy
									/* translators: %s: URL of the privacy policy page set in WordPress */
									? sprintf( __( 'Leer: die unter Einstellungen › Datenschutz gewählte Seite (%s).', 'wm-sovereign-consent-vault' ), $wp_privacy )
									: __( 'Leer: die unter Einstellungen › Datenschutz gewählte Seite. Dort ist derzeit keine veröffentlichte Seite gewählt, also zeigt das Banner keinen Link.', 'wm-sovereign-consent-vault' )
							);
							?>
						</p>
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-imprint-url"><?php esc_html_e( 'Impressum (URL)', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="url" id="wm-consent-imprint-url" name="imprint_url" value="<?php echo esc_attr( $settings['imprint_url'] ); ?>" aria-describedby="wm-consent-imprint-url-help" />
						<p class="wm-field-desc" id="wm-consent-imprint-url-help"><?php esc_html_e( 'Leer: kein Impressum-Link im Banner.', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
					<p class="wm-field-desc"><?php esc_html_e( 'Auf diesen beiden Seiten öffnet sich das Banner nicht von selbst, damit Besucher sie vor ihrer Entscheidung lesen können.', 'wm-sovereign-consent-vault' ); ?></p>
				</section>

				<section class="wm-card" aria-labelledby="wm-consent-settings-categories">
					<h2 id="wm-consent-settings-categories"><?php esc_html_e( 'Beschreibungen der Kategorien', 'wm-sovereign-consent-vault' ); ?></h2>
					<?php foreach ( Categories::SLUGS as $slug ) : ?>
						<?php $field_id = 'wm-consent-category-' . $slug; ?>
					<div class="wm-form-group">
						<label class="wm-label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $categories[ $slug ]['label'] ); ?></label>
						<textarea class="wm-textarea" id="<?php echo esc_attr( $field_id ); ?>" name="category_descriptions[<?php echo esc_attr( $slug ); ?>]" rows="2" maxlength="2000" aria-describedby="<?php echo esc_attr( $field_id . '-default' ); ?>"><?php echo esc_textarea( $settings['category_descriptions'][ $slug ] ); ?></textarea>
						<p class="wm-field-desc" id="<?php echo esc_attr( $field_id . '-default' ); ?>">
							<?php
							/* translators: %s: default text */
							echo esc_html( sprintf( __( 'Standard: %s', 'wm-sovereign-consent-vault' ), $categories[ $slug ]['description'] ) );
							?>
						</p>
					</div>
					<?php endforeach; ?>
				</section>

				<section class="wm-card" aria-labelledby="wm-consent-settings-consent">
					<h2 id="wm-consent-settings-consent"><?php esc_html_e( 'Einwilligung', 'wm-sovereign-consent-vault' ); ?></h2>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-policy-version"><?php esc_html_e( 'Version der Richtlinie', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="text" id="wm-consent-policy-version" name="policy_version" value="<?php echo esc_attr( $settings['policy_version'] ); ?>" required maxlength="32" pattern="[A-Za-z0-9._\-]{1,32}" aria-describedby="wm-consent-policy-version-help" />
						<p class="wm-field-desc" id="wm-consent-policy-version-help"><?php esc_html_e( 'Jede gespeicherte Entscheidung gilt für diese Version. Ändern Sie sie, sehen alle Besucher das Banner erneut, und das Protokoll hält fest, für welche Version entschieden wurde. Erlaubt: Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich (höchstens 32 Zeichen).', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-duration"><?php esc_html_e( 'Gültigkeit in Tagen', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="number" id="wm-consent-duration" name="duration_days" value="<?php echo esc_attr( (string) $settings['duration_days'] ); ?>" required min="<?php echo esc_attr( (string) Settings::MIN_DAYS ); ?>" max="<?php echo esc_attr( (string) Settings::MAX_DAYS ); ?>" step="1" aria-describedby="wm-consent-duration-help" />
						<p class="wm-field-desc" id="wm-consent-duration-help"><?php esc_html_e( 'Nach so vielen Tagen fragt das Banner erneut; so lange lebt auch das Cookie wm_consent.', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
					<div class="wm-form-group">
						<label class="wm-toggle-label" for="wm-consent-blocking">
							<input type="checkbox" id="wm-consent-blocking" name="blocking_enabled" value="1" <?php checked( $settings['blocking_enabled'] ); ?> aria-describedby="wm-consent-blocking-help" />
							<?php esc_html_e( 'Skripte und iframes eingetragener Dienste bis zur Einwilligung blockieren', 'wm-sovereign-consent-vault' ); ?>
						</label>
						<p class="wm-field-desc" id="wm-consent-blocking-help"><?php esc_html_e( 'Ausgeschaltet schreibt das Plugin die Seite nicht mehr um: eingetragene Dienste laden sofort, auch ohne Entscheidung. Banner, Protokoll und die Platzhalter im Beitragsinhalt bleiben aktiv.', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
					<div class="wm-form-group">
						<label class="wm-toggle-label" for="wm-consent-delete-on-uninstall">
							<input type="checkbox" id="wm-consent-delete-on-uninstall" name="delete_data_on_uninstall" value="1" <?php checked( Settings::delete_data_on_uninstall() ); ?> aria-describedby="wm-consent-delete-on-uninstall-help" />
							<?php esc_html_e( 'Alle Protokolle, Tabellen und Einstellungen beim Löschen des Plugins entfernen', 'wm-sovereign-consent-vault' ); ?>
						</label>
						<p class="wm-field-desc" id="wm-consent-delete-on-uninstall-help"><?php esc_html_e( 'Standardmäßig bleiben das Einwilligungsprotokoll, die HMAC-Kette und alle Einstellungen bei der Deinstallation erhalten, damit dokumentierte Entscheidungen nicht unbeabsichtigt gelöscht werden.', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
				</section>

				<p><button type="submit" class="wm-btn wm-btn-primary"><?php esc_html_e( 'Einstellungen speichern', 'wm-sovereign-consent-vault' ); ?></button></p>
			</form>

			<section class="wm-card" aria-labelledby="wm-consent-settings-bump">
				<h2 id="wm-consent-settings-bump"><?php esc_html_e( 'Alle Besucher erneut fragen', 'wm-sovereign-consent-vault' ); ?></h2>
				<p class="wm-field-desc"><?php esc_html_e( 'Erhöht die Version der Richtlinie um einen Schritt (eine Zahl am Ende wird hochgezählt). Bestehende Entscheidungen bleiben im Protokoll, gelten im Browser aber nicht mehr.', 'wm-sovereign-consent-vault' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_BUMP ); ?>" />
					<?php wp_nonce_field( self::ACTION_BUMP ); ?>
					<button type="submit" class="wm-btn wm-btn-secondary"><?php esc_html_e( 'Version erhöhen', 'wm-sovereign-consent-vault' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}

	// ------------------------------------------------------------------------------------------ services tab

	public function render_services_tab(): void {
		$services   = ServiceRegistry::stored();
		$presets    = ServiceRegistry::presets();
		$categories = Categories::defaults();
		$editing    = $this->editing_values();
		$post_url   = admin_url( 'admin-post.php' );
		?>
		<div class="wm-tab-content">
			<section class="wm-card" aria-labelledby="wm-consent-services-list">
				<h2 id="wm-consent-services-list"><?php esc_html_e( 'Eingetragene Dienste', 'wm-sovereign-consent-vault' ); ?></h2>
				<p class="wm-field-desc"><?php esc_html_e( 'Skripte und iframes, deren Quelle oder Code eines der Muster enthält, warten auf die Einwilligung in die Kategorie des Dienstes. Das Banner listet die Dienste je Kategorie; beim Widerruf löscht es die angegebenen Cookies dieser Website.', 'wm-sovereign-consent-vault' ); ?></p>
				<?php if ( [] === $services ) : ?>
				<p class="wm-empty-state"><?php esc_html_e( 'Noch keine Dienste eingetragen: außer den Platzhaltern für YouTube, Vimeo und Google Maps und dem Markup mit data-wm-consent wird nichts blockiert.', 'wm-sovereign-consent-vault' ); ?></p>
				<?php else : ?>
				<div class="wm-table-container">
					<table class="wm-table">
						<caption class="screen-reader-text"><?php esc_html_e( 'Eingetragene Dienste', 'wm-sovereign-consent-vault' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Dienst', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Kategorie', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Muster', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Cookies', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Aktionen', 'wm-sovereign-consent-vault' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $services as $service ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $service['name'] ); ?></strong><br /><?php echo esc_html( $service['provider'] ); ?></td>
								<td><?php echo esc_html( $categories[ $service['category'] ]['label'] ?? $service['category'] ); ?></td>
								<td><code><?php echo esc_html( implode( ', ', $service['hosts'] ) ); ?></code></td>
								<td><?php echo [] === $service['cookies'] ? '—' : esc_html( implode( ', ', $service['cookies'] ) ); ?></td>
								<td>
									<a class="wm-btn wm-btn-secondary" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'services', 'edit' => $service['id'] ], admin_url( 'admin.php' ) ) ); ?>">
										<?php esc_html_e( 'Bearbeiten', 'wm-sovereign-consent-vault' ); ?><span class="screen-reader-text">: <?php echo esc_html( $service['name'] ); ?></span>
									</a>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="wm-consent-inline-form">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE_SERVICE ); ?>" />
										<input type="hidden" name="id" value="<?php echo esc_attr( $service['id'] ); ?>" />
										<?php wp_nonce_field( self::ACTION_DELETE_SERVICE ); ?>
										<button type="submit" class="wm-btn wm-btn-danger"><?php esc_html_e( 'Entfernen', 'wm-sovereign-consent-vault' ); ?><span class="screen-reader-text">: <?php echo esc_html( $service['name'] ); ?></span></button>
									</form>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

			<section class="wm-card" aria-labelledby="wm-consent-service-form">
				<h2 id="wm-consent-service-form"><?php echo '' !== $editing['original_id'] ? esc_html__( 'Dienst bearbeiten', 'wm-sovereign-consent-vault' ) : esc_html__( 'Dienst hinzufügen', 'wm-sovereign-consent-vault' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SERVICE ); ?>" />
					<input type="hidden" name="original_id" value="<?php echo esc_attr( $editing['original_id'] ); ?>" />
					<input type="hidden" name="preset" value="<?php echo esc_attr( $editing['preset'] ); ?>" />
					<?php wp_nonce_field( self::ACTION_SAVE_SERVICE ); ?>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-name"><?php esc_html_e( 'Name', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="text" id="wm-consent-service-name" name="name" value="<?php echo esc_attr( $editing['name'] ); ?>" required maxlength="500" />
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-id"><?php esc_html_e( 'Kennung', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="text" id="wm-consent-service-id" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>" maxlength="64" pattern="[a-z0-9_\-]*" aria-describedby="wm-consent-service-id-help" />
						<p class="wm-field-desc" id="wm-consent-service-id-help"><?php esc_html_e( 'Kleinbuchstaben, Ziffern, Bindestrich und Unterstrich. Leer: aus dem Namen gebildet.', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-provider"><?php esc_html_e( 'Anbieter', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="text" id="wm-consent-service-provider" name="provider" value="<?php echo esc_attr( $editing['provider'] ); ?>" maxlength="500" aria-describedby="wm-consent-service-provider-help" />
						<p class="wm-field-desc" id="wm-consent-service-provider-help"><?php esc_html_e( 'Wie Besucher den Anbieter im Banner sehen, zum Beispiel mit Firma und Sitz.', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-category"><?php esc_html_e( 'Kategorie', 'wm-sovereign-consent-vault' ); ?></label>
						<select class="wm-select" id="wm-consent-service-category" name="category" required>
							<?php foreach ( Categories::SLUGS as $slug ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $editing['category'], $slug ); ?>><?php echo esc_html( $categories[ $slug ]['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-purpose"><?php esc_html_e( 'Zweck', 'wm-sovereign-consent-vault' ); ?></label>
						<textarea class="wm-textarea" id="wm-consent-service-purpose" name="purpose" rows="2" maxlength="500"><?php echo esc_textarea( $editing['purpose'] ); ?></textarea>
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-privacy"><?php esc_html_e( 'Datenschutzerklärung des Anbieters (URL)', 'wm-sovereign-consent-vault' ); ?></label>
						<input class="wm-input" type="url" id="wm-consent-service-privacy" name="privacy_url" value="<?php echo esc_attr( $editing['privacy_url'] ); ?>" />
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-hosts"><?php esc_html_e( 'Host- oder Dateimuster', 'wm-sovereign-consent-vault' ); ?></label>
						<textarea class="wm-textarea" id="wm-consent-service-hosts" name="hosts" rows="3" aria-describedby="wm-consent-service-hosts-help"><?php echo esc_textarea( $editing['hosts'] ); ?></textarea>
						<p class="wm-field-desc" id="wm-consent-service-hosts-help"><?php esc_html_e( 'Ein Muster je Zeile, zum Beispiel googletagmanager.com oder matomo.js: 4 bis 100 Zeichen aus Kleinbuchstaben, Ziffern und . _ - /, mit mindestens einem Punkt oder Schrägstrich. Ein Muster trifft, wo es als ganzer Host- oder Dateiname in der Quelle eines Skripts oder iframes oder im Code eines Inline-Skripts steht. Pflicht, außer in der Kategorie Notwendig.', 'wm-sovereign-consent-vault' ); ?><?php // blocked host pattern example ?></p>
					</div>
					<div class="wm-form-group">
						<label class="wm-label" for="wm-consent-service-cookies"><?php esc_html_e( 'Cookie-Namen', 'wm-sovereign-consent-vault' ); ?></label>
						<textarea class="wm-textarea" id="wm-consent-service-cookies" name="cookies" rows="2" aria-describedby="wm-consent-service-cookies-help"><?php echo esc_textarea( $editing['cookies'] ); ?></textarea>
						<p class="wm-field-desc" id="wm-consent-service-cookies-help"><?php esc_html_e( 'Ein Name je Zeile; ein * am Ende steht für jede Fortsetzung (_ga_*). Werden beim Widerruf der Kategorie auf dieser Website gelöscht.', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
					<p>
						<button type="submit" class="wm-btn wm-btn-primary"><?php echo '' !== $editing['original_id'] ? esc_html__( 'Dienst speichern', 'wm-sovereign-consent-vault' ) : esc_html__( 'Dienst hinzufügen', 'wm-sovereign-consent-vault' ); ?></button>
						<?php if ( '' !== $editing['original_id'] ) : ?>
						<a class="wm-btn wm-btn-secondary" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'services' ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Abbrechen', 'wm-sovereign-consent-vault' ); ?></a>
						<?php endif; ?>
					</p>
				</form>
			</section>

			<section class="wm-card" aria-labelledby="wm-consent-presets">
				<h2 id="wm-consent-presets"><?php esc_html_e( 'Vorlagen', 'wm-sovereign-consent-vault' ); ?></h2>
				<p class="wm-field-desc"><?php esc_html_e( 'Häufige Dienste mit den Hosts, Dateinamen und Cookies, die ihre Anbieter dokumentieren (geprüft am 17.09.2026). Eine Vorlage wird erst mit „Hinzufügen“ zum Dienst; tragen Sie nur Dienste ein, die Ihre Website tatsächlich verwendet, und ergänzen Sie den Anbieter.', 'wm-sovereign-consent-vault' ); ?></p>
				<div class="wm-table-container">
					<table class="wm-table">
						<caption class="screen-reader-text"><?php esc_html_e( 'Vorlagen', 'wm-sovereign-consent-vault' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Dienst', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Kategorie', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Muster', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Aktion', 'wm-sovereign-consent-vault' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $presets as $key => $preset ) : ?>
							<tr>
								<td><?php echo esc_html( $preset['name'] ); ?></td>
								<td><?php echo esc_html( $categories[ $preset['category'] ]['label'] ); ?></td>
								<td><code><?php echo esc_html( implode( ', ', $preset['hosts'] ) ); ?></code></td>
								<td>
									<?php if ( null !== ServiceRegistry::find_stored( $key ) ) : ?>
									<?php esc_html_e( 'eingetragen', 'wm-sovereign-consent-vault' ); ?>
									<?php else : ?>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="wm-consent-inline-form">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ADD_PRESET ); ?>" />
										<input type="hidden" name="preset" value="<?php echo esc_attr( $key ); ?>" />
										<?php wp_nonce_field( self::ACTION_ADD_PRESET ); ?>
										<button type="submit" class="wm-btn wm-btn-secondary"><?php esc_html_e( 'Hinzufügen', 'wm-sovereign-consent-vault' ); ?><span class="screen-reader-text">: <?php echo esc_html( $preset['name'] ); ?></span></button>
									</form>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

			<section class="wm-card" aria-labelledby="wm-consent-developer">
				<h2 id="wm-consent-developer"><?php esc_html_e( 'Für Entwickler', 'wm-sovereign-consent-vault' ); ?></h2>
				<ul class="wm-consent-dev-list">
					<li><code>&lt;script type="text/plain" data-wm-consent="marketing"&gt;…&lt;/script&gt;</code> — <?php esc_html_e( 'läuft erst nach Einwilligung in die Kategorie (auch mit src).', 'wm-sovereign-consent-vault' ); ?></li>
					<li><code>wm_consent_has( 'statistics' )</code> — <?php esc_html_e( 'PHP: ob der Besucher eingewilligt hat; die Antwort wird nicht zwischengespeichert.', 'wm-sovereign-consent-vault' ); ?></li>
					<li><code>window.wmConsent.has( 'media' )</code>, <code>window.wmConsent.open()</code>, <?php esc_html_e( 'Ereignis', 'wm-sovereign-consent-vault' ); ?> <code>wm-consent:decision</code></li>
					<li><?php esc_html_e( 'Filter', 'wm-sovereign-consent-vault' ); ?>: <code>wm_consent_services</code>, <code>wm_consent_script_handles</code>, <code>wm_consent_script_category</code>, <code>wm_consent_blocking_enabled</code>, <code>wm_consent_has</code>, <code>wm_consent_has_nocache</code>, <code>wm_consent_rest_rate_limit</code>, <code>wm_consent_status_label</code>; <?php esc_html_e( 'Aktionen', 'wm-sovereign-consent-vault' ); ?>: <code>wm_consent_ready</code>, <code>wm_consent_decision_recorded</code></li>
					<li><?php esc_html_e( 'Block Bindings & Dynamic Tags', 'wm-sovereign-consent-vault' ); ?>: <code>wm-consent/status</code> (WordPress 6.5+), <code>{{wm_consent_status}}</code> (GenerateBlocks 2.x)</li>
				</ul>
			</section>
		</div>
		<?php
	}

	/**
	 * Values for the service form: a draft that failed validation, the entry named in `edit`, or empty fields.
	 *
	 * @return array{original_id: string, id: string, name: string, provider: string, category: string, purpose: string, privacy_url: string, hosts: string, cookies: string, preset: string}
	 */
	private function editing_values(): array {
		$empty = [
			'original_id' => '',
			'id'          => '',
			'name'        => '',
			'provider'    => '',
			'category'    => Categories::STATISTICS,
			'purpose'     => '',
			'privacy_url' => '',
			'hosts'       => '',
			'cookies'     => '',
			'preset'      => '',
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection of the entry to edit.
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		if ( '' === $edit ) {
			return $empty;
		}

		$draft = get_transient( self::DRAFT_TRANSIENT . get_current_user_id() );
		if ( is_array( $draft ) ) {
			$values = $empty;
			foreach ( array_keys( $empty ) as $key ) {
				$values[ $key ] = is_string( $draft[ $key ] ?? null ) ? $draft[ $key ] : $values[ $key ];
			}
			return $values;
		}

		$service = ServiceRegistry::find_stored( $edit );
		if ( null === $service ) {
			return $empty;
		}
		return [
			'original_id' => $service['id'],
			'id'          => $service['id'],
			'name'        => $service['name'],
			'provider'    => $service['provider'],
			'category'    => $service['category'],
			'purpose'     => $service['purpose'],
			'privacy_url' => $service['privacy_url'],
			'hosts'       => implode( "\n", $service['hosts'] ),
			'cookies'     => implode( "\n", $service['cookies'] ),
			'preset'      => $service['preset'],
		];
	}
}
