<?php
declare(strict_types=1);

namespace WMCyber\Consent\Vault\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Consent\Vault\Core\Categories;
use WMCyber\Consent\Vault\Core\Settings;
use WMCyber\Consent\Vault\Database\Schema;
use WMCyber\Consent\Vault\Services\ServiceRegistry;

/**
 * Admin screen and dashboard widget.
 *
 * Until 2026-09-14 (Spanish interface): the widget counted `wm_consent_audit_log`, a table nothing
 * creates, so it always showed 0, beside fixed tiles "100% Lokal / Zero-CDN" and "SHA-256 Integrität"
 * and a green "Nachweis ledger aktiv" banner; the header carried "CERO FUGAS A TERCEROS" and "SIN
 * PATRONES OSCUROS"; the preview tab drew a banner with three buttons ("Personalizar") and Spanish text
 * that is not the banner visitors see; the blocker tab promised "BLOQUEO AUTOMÁTICO ACTIVO" and buttons
 * that do not exist; the stats said "Prueba legal certificada"; every page view ran dbDelta.
 */
final class AdminDashboard {
	private static ?AdminDashboard $instance = null;

	private const SLUG = 'wm-consent-vault';

	public static function instance(): AdminDashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	/**
	 * Counts from the records table; `null` values when the table does not exist.
	 *
	 * `analytics` counts the statistics category (the column kept its first name); `media` counts rows whose
	 * category list contains `media`, which only decisions since the categories can carry.
	 *
	 * @return array{exists: bool, total: ?int, analytics: ?int, marketing: ?int, media: ?int, current_policy: ?int, last: ?string}
	 */
	public static function record_stats(): array {
		global $wpdb;
		$table = Schema::get_records_table();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [ 'exists' => false, 'total' => null, 'analytics' => null, 'marketing' => null, 'media' => null, 'current_policy' => null, 'last' => null ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts for the admin, one query.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(analytics = 1) AS analytics, SUM(marketing = 1) AS marketing, SUM(FIND_IN_SET(%s, categories) > 0) AS media, SUM(policy_version = %s) AS current_policy, MAX(created_at) AS last FROM %i',
				Categories::MEDIA,
				Settings::policy_version(),
				$table
			),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : [];

		return [
			'exists'         => true,
			'total'          => (int) ( $row['total'] ?? 0 ),
			'analytics'      => (int) ( $row['analytics'] ?? 0 ),
			'marketing'      => (int) ( $row['marketing'] ?? 0 ),
			'media'          => (int) ( $row['media'] ?? 0 ),
			'current_policy' => (int) ( $row['current_policy'] ?? 0 ),
			'last'           => isset( $row['last'] ) ? (string) $row['last'] : null,
		];
	}

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_consent_vault_widget',
			__( 'WM Consent Vault — protokollierte Entscheidungen', 'wm-sovereign-consent-vault' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	/** Counts only: verifying the chain costs one query per node and belongs to the chain tab. */
	public function render_dashboard_widget(): void {
		$stats = self::record_stats();
		?>
		<div class="wm-dash-widget-box" data-wm-theme="<?php echo esc_attr( function_exists( 'wm_get_suite_theme' ) ? wm_get_suite_theme() : 'cyber-events' ); ?>">
			<div class="wm-dash-banner">
				<span class="wm-dash-banner-icon"><span class="dashicons dashicons-privacy" aria-hidden="true"></span></span>
				<div>
					<div class="wm-dash-banner-title">
						<?php
						if ( ! $stats['exists'] ) {
							esc_html_e( 'Tabelle für Entscheidungen fehlt', 'wm-sovereign-consent-vault' );
						} elseif ( 0 === $stats['total'] ) {
							esc_html_e( 'Noch keine Entscheidung protokolliert', 'wm-sovereign-consent-vault' );
						} else {
							/* translators: %s: date and time of the newest decision */
							echo esc_html( sprintf( __( 'Letzte Entscheidung: %s', 'wm-sovereign-consent-vault' ), (string) $stats['last'] ) );
						}
						?>
					</div>
					<div class="wm-dash-banner-sub"><?php esc_html_e( 'Kettenprüfung im Tab „Protokoll“', 'wm-sovereign-consent-vault' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<span class="wm-dash-kpi-val"><?php echo null === $stats['total'] ? '—' : esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
					<span class="wm-dash-kpi-lbl"><?php esc_html_e( 'Entscheidungen', 'wm-sovereign-consent-vault' ); ?></span>
				</div>
				<div class="wm-dash-kpi-card">
					<span class="wm-dash-kpi-val"><?php echo null === $stats['analytics'] ? '—' : esc_html( number_format_i18n( $stats['analytics'] ) ); ?></span>
					<span class="wm-dash-kpi-lbl"><?php esc_html_e( 'mit Statistik', 'wm-sovereign-consent-vault' ); ?></span>
				</div>
				<div class="wm-dash-kpi-card">
					<span class="wm-dash-kpi-val"><?php echo null === $stats['marketing'] ? '—' : esc_html( number_format_i18n( $stats['marketing'] ) ); ?></span>
					<span class="wm-dash-kpi-lbl"><?php esc_html_e( 'mit Marketing', 'wm-sovereign-consent-vault' ); ?></span>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="wm-dash-btn">
					<span><?php esc_html_e( 'Consent Vault öffnen', 'wm-sovereign-consent-vault' ); ?></span> <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WM Consent Vault', 'wm-sovereign-consent-vault' ),
			__( 'Consent Vault', 'wm-sovereign-consent-vault' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render_dashboard' ],
			self::family_menu_icon(),
			84
		);
	}

	/**
	 * Family sheets on the plugin screen (standalone `toplevel_page_…`, under the hub `…_page_…`) and on
	 * the dashboard for the widget. Until 2026-09-14 only the standalone hook matched.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) && 'index.php' !== $hook ) {
			return;
		}

		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		wp_enqueue_style(
			'wm-admin-tokens',
			WM_CONSENT_VAULT_URL . 'assets/css/wm-admin-tokens.css',
			[],
			(string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: WM_CONSENT_VAULT_VERSION )
		);
		wp_enqueue_style(
			'wm-admin-ui',
			WM_CONSENT_VAULT_URL . 'assets/css/wm-admin-ui.css',
			[ 'wm-admin-tokens' ],
			(string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: WM_CONSENT_VAULT_VERSION )
		);
	}

	/**
	 * @return array<string, array{label: string, icon: string}>
	 */
	private function tabs(): array {
		return [
			'overview' => [ 'label' => __( 'Übersicht', 'wm-sovereign-consent-vault' ), 'icon' => 'dashicons-chart-bar' ],
			'settings' => [ 'label' => __( 'Einstellungen', 'wm-sovereign-consent-vault' ), 'icon' => 'dashicons-admin-settings' ],
			'services' => [ 'label' => __( 'Dienste', 'wm-sovereign-consent-vault' ), 'icon' => 'dashicons-admin-plugins' ],
			'banner'   => [ 'label' => __( 'Banner', 'wm-sovereign-consent-vault' ), 'icon' => 'dashicons-format-aside' ],
			'embeds'   => [ 'label' => __( 'Eingebettete Inhalte', 'wm-sovereign-consent-vault' ), 'icon' => 'dashicons-video-alt3' ],
			'chain'    => [ 'label' => __( 'Protokoll', 'wm-sovereign-consent-vault' ), 'icon' => 'dashicons-admin-links' ],
			'glossary' => [ 'label' => __( 'Begriffe', 'wm-sovereign-consent-vault' ), 'icon' => 'dashicons-book-alt' ],
		];
	}

	public function render_dashboard(): void {
		global $wpdb;
		// Schema self-heal once per version, not dbDelta on every page view.
		Schema::maybe_upgrade();

		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing, checked against the tab list.
		$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'overview';
		}

		$stats           = self::record_stats();
		$settings_screen = new SettingsScreen();
		?>
		<div class="wrap wm-admin">
			<header class="wm-admin-header">
				<div class="wm-header-brand">
					<div class="wm-header-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					</div>
					<div>
						<h1><?php esc_html_e( 'WM Sovereign Consent Vault', 'wm-sovereign-consent-vault' ); ?></h1>
						<p class="wm-subtitle"><?php esc_html_e( 'Einwilligungsdialog nach Kategorien &bull; Blockieren eingetragener Dienste bis zur Einwilligung &bull; verkettetes Protokoll der Entscheidungen', 'wm-sovereign-consent-vault' ); ?></p>
					</div>
				</div>
				<div class="wm-header-badges">
					<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-sovereign-consent-vault' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
						<?php esc_html_e( 'WM Suite', 'wm-sovereign-consent-vault' ); ?>
						<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-sovereign-consent-vault' ) : esc_html__( 'Standalone', 'wm-sovereign-consent-vault' ); ?></span>
					</span>
				</div>
			</header>

			<?php $settings_screen->render_notice(); ?>

			<div class="wm-quick-guide">
				<div class="wm-quick-guide-title">
					<span><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> <?php esc_html_e( 'Was dieses Plugin tut', 'wm-sovereign-consent-vault' ); ?></span>
				</div>
				<p class="wm-quick-guide-desc">
					<?php esc_html_e( 'Es zeigt Besuchern ohne gültige Entscheidung einen Dialog mit „Alle akzeptieren“, „Nur notwendige“ und einer Auswahl nach Kategorien (Statistik, Marketing, Externe Medien). Bis zur Einwilligung in die jeweilige Kategorie hält es Skripte und iframes der unter „Dienste“ eingetragenen Anbieter, mit data-wm-consent markiertes Markup und Videos und Karten von YouTube, Vimeo und Google Maps zurück; danach lädt der Browser sie ohne neuen Seitenaufruf. Jede Entscheidung wird mit ihren Kategorien und der Version der Richtlinie in zwei eigenen Tabellen protokolliert, deren Einträge per HMAC verkettet sind.', 'wm-sovereign-consent-vault' ); ?>
				</p>
			</div>

			<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Module Tabs', 'wm-sovereign-consent-vault' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
				<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>" class="wm-tab-link <?php echo $active_tab === $slug ? 'active' : ''; ?>"<?php echo $active_tab === $slug ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $tab['label'] ); ?>
				</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'overview' === $active_tab ) : ?>
			<div class="wm-tab-content">
				<?php if ( ! $stats['exists'] ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Die Tabelle für Entscheidungen existiert nicht. Plugin deaktivieren und wieder aktivieren legt sie an.', 'wm-sovereign-consent-vault' ); ?></p></div>
				<?php endif; ?>
				<div class="wm-grid-4">
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Protokollierte Entscheidungen', 'wm-sovereign-consent-vault' ); ?></span>
						<span class="wm-stat-value text-cyan"><?php echo null === $stats['total'] ? '—' : esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
						<span class="wm-stat-delta"><?php esc_html_e( 'je Klick im Banner ein Eintrag', 'wm-sovereign-consent-vault' ); ?></span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Mit Statistik', 'wm-sovereign-consent-vault' ); ?></span>
						<span class="wm-stat-value text-emerald"><?php echo null === $stats['analytics'] ? '—' : esc_html( number_format_i18n( $stats['analytics'] ) ); ?></span>
						<span class="wm-stat-delta"><?php esc_html_e( 'Kategorie Statistik gewählt', 'wm-sovereign-consent-vault' ); ?></span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Mit Marketing', 'wm-sovereign-consent-vault' ); ?></span>
						<span class="wm-stat-value text-amber"><?php echo null === $stats['marketing'] ? '—' : esc_html( number_format_i18n( $stats['marketing'] ) ); ?></span>
						<span class="wm-stat-delta"><?php esc_html_e( 'Kategorie Marketing gewählt', 'wm-sovereign-consent-vault' ); ?></span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Mit externen Medien', 'wm-sovereign-consent-vault' ); ?></span>
						<span class="wm-stat-value text-cyan"><?php echo null === $stats['media'] ? '—' : esc_html( number_format_i18n( $stats['media'] ) ); ?></span>
						<span class="wm-stat-delta"><?php esc_html_e( 'nur Entscheidungen mit Kategorien', 'wm-sovereign-consent-vault' ); ?></span>
					</div>
				</div>
				<div class="wm-grid-4">
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Zur aktuellen Richtlinie', 'wm-sovereign-consent-vault' ); ?></span>
						<span class="wm-stat-value text-purple"><?php echo null === $stats['current_policy'] ? '—' : esc_html( number_format_i18n( $stats['current_policy'] ) ); ?></span>
						<span class="wm-stat-delta">
							<?php
							/* translators: %s: current policy version */
							echo esc_html( sprintf( __( 'Version %s', 'wm-sovereign-consent-vault' ), Settings::policy_version() ) );
							?>
						</span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Eingetragene Dienste', 'wm-sovereign-consent-vault' ); ?></span>
						<span class="wm-stat-value text-cyan"><?php echo esc_html( number_format_i18n( count( ServiceRegistry::stored() ) ) ); ?></span>
						<span class="wm-stat-delta"><?php echo Settings::blocking_enabled() ? esc_html__( 'Blockieren eingeschaltet', 'wm-sovereign-consent-vault' ) : esc_html__( 'Blockieren ausgeschaltet', 'wm-sovereign-consent-vault' ); ?></span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Letzte Entscheidung', 'wm-sovereign-consent-vault' ); ?></span>
						<span class="wm-stat-value text-purple"><?php echo empty( $stats['last'] ) ? '—' : esc_html( (string) $stats['last'] ); ?></span>
						<span class="wm-stat-delta"><?php esc_html_e( 'Serverzeit', 'wm-sovereign-consent-vault' ); ?></span>
					</div>
				</div>

				<div class="wm-card">
					<h2><?php esc_html_e( 'Was nicht enthalten ist', 'wm-sovereign-consent-vault' ); ?></h2>
					<ul class="ul-disc">
						<li><?php esc_html_e( 'Keine automatische Erkennung von Diensten: zurückgehalten wird, was unter „Dienste“ eingetragen oder mit data-wm-consent markiert ist, dazu iframes von YouTube, Vimeo und Google Maps.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Nichts, was ein Skript erst nach dem Laden der Seite einfügt, und keine Bilder oder Pixel in noscript-Bereichen.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Keine Farbeinstellungen im Backend: das Aussehen folgt CSS-Variablen, die ein Theme überschreiben kann.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Kein Export und keine rechtliche Bewertung Ihres Einwilligungsverfahrens.', 'wm-sovereign-consent-vault' ); ?></li>
					</ul>
				</div>
			</div>

			<?php elseif ( 'settings' === $active_tab ) : ?>
				<?php $settings_screen->render_settings_tab(); ?>

			<?php elseif ( 'services' === $active_tab ) : ?>
				<?php $settings_screen->render_services_tab(); ?>

			<?php elseif ( 'banner' === $active_tab ) : ?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><?php esc_html_e( 'Das Banner, wie Besucher es sehen', 'wm-sovereign-consent-vault' ); ?></h2>
					<p><?php esc_html_e( 'Ein Dialog in der Mitte des Fensters (auf schmalen Bildschirmen unten), für jeden Besucher ohne gültige Entscheidung in diesem Browser. Die Texte stammen aus den Einstellungen:', 'wm-sovereign-consent-vault' ); ?></p>
					<dl>
						<dt><strong><?php esc_html_e( 'Überschrift', 'wm-sovereign-consent-vault' ); ?></strong></dt>
						<dd><?php echo esc_html( Settings::text( 'heading' ) ); ?></dd>
						<dt><strong><?php esc_html_e( 'Text', 'wm-sovereign-consent-vault' ); ?></strong></dt>
						<dd><?php echo esc_html( Settings::text( 'description' ) ); ?></dd>
						<dt><strong><?php esc_html_e( 'Schaltflächen', 'wm-sovereign-consent-vault' ); ?></strong></dt>
						<dd><?php echo esc_html( Settings::text( 'accept_all' ) . ' · ' . Settings::text( 'necessary_only' ) . ' · ' . Settings::text( 'settings' ) ); ?></dd>
						<dt><strong><?php esc_html_e( 'Links', 'wm-sovereign-consent-vault' ); ?></strong></dt>
						<dd><?php echo esc_html( ( '' !== Settings::privacy_url() ? Settings::privacy_url() : __( 'keine Datenschutzerklärung', 'wm-sovereign-consent-vault' ) ) . ' · ' . ( '' !== Settings::imprint_url() ? Settings::imprint_url() : __( 'kein Impressum', 'wm-sovereign-consent-vault' ) ) ); ?></dd>
					</dl>

					<h3><?php esc_html_e( 'Was eine Entscheidung bewirkt', 'wm-sovereign-consent-vault' ); ?></h3>
					<ol>
						<li><?php esc_html_e( '„Alle akzeptieren“ wählt alle Kategorien, „Nur notwendige“ keine zusätzliche; unter „Einstellungen“ schaltet der Besucher Statistik, Marketing und Externe Medien einzeln und sieht die eingetragenen Dienste je Kategorie. Beide Hauptschaltflächen sind gleich gestaltet.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Der Browser speichert eine zufällige Kennung, die Kategorien, die Version der Richtlinie und den Zeitpunkt in localStorage (wm_consent_uuid, wm_consent_categories, wm_consent_policy_version, wm_consent_decided_at, weiterhin auch wm_consent_analytics und wm_consent_marketing) und im Cookie wm_consent.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Das Skript holt einen REST-Token und sendet die Entscheidung an wm-consent/v1/record; der Server speichert sie mit einem HMAC der IP-Adresse, dem User-Agent, den Kategorien, der Version der Richtlinie und einer Signatur und hängt sie an die Kette an.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Zurückgehaltene Skripte und iframes der gewählten Kategorien laden sofort, ohne neuen Seitenaufruf. Wird eine Kategorie zurückgenommen, löscht das Skript die eingetragenen Cookies ihrer Dienste auf dieser Website und lädt die Seite neu.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Nach Ablauf der Gültigkeit oder nach einer neuen Version der Richtlinie fragt der Dialog erneut.', 'wm-sovereign-consent-vault' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'Erneut öffnen: Shortcode [wm_consent_settings], ein Link auf #wm-consent-settings (zum Beispiel im Menü) oder die zweite Schaltfläche jedes Platzhalters.', 'wm-sovereign-consent-vault' ); ?></p>
					<p class="description"><?php esc_html_e( 'Der Dialog ist ein modaler Dialog: der Fokus springt hinein, bleibt beim Tabben darin und kehrt beim Schließen zurück. Beim ersten Besuch schließt ihn nur eine Wahl; geöffnet über einen Link schließen ihn auch Escape und die Schließen-Schaltfläche. Ob die Texte für Ihre Website rechtlich genügen, beantwortet das Plugin nicht.', 'wm-sovereign-consent-vault' ); ?></p>
				</div>
			</div>

			<?php elseif ( 'embeds' === $active_tab ) : ?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><?php esc_html_e( '2-Klick-Platzhalter', 'wm-sovereign-consent-vault' ); ?></h2>
					<p><?php esc_html_e( 'Auf jeder Seite der Website (Beitragsinhalt, Widgets, Theme-Vorlagen) wird jedes iframe dieser Anbieter durch einen Platzhalter ersetzt, solange das Blockieren eingeschaltet ist; im Beitragsinhalt auch dann:', 'wm-sovereign-consent-vault' ); ?></p>
					<ul class="ul-disc">
						<li><code>youtube.com</code>, <code>youtube-nocookie.com</code>, <code>youtu.be</code><?php // blocked embed host pattern ?></li>
						<li><code>vimeo.com</code>, <code>player.vimeo.com</code><?php // blocked embed host pattern ?></li>
						<li><code>maps.google.com</code>, <code>google.com/maps</code><?php // blocked embed host pattern ?></li>
					</ul>
					<p><?php esc_html_e( 'Iframes eingetragener Dienste der Kategorie Externe Medien bekommen denselben Platzhalter, iframes anderer Kategorien warten ohne sichtbaren Platzhalter auf die Einwilligung.', 'wm-sovereign-consent-vault' ); ?></p>
					<h3><?php esc_html_e( 'Wie ein Platzhalter lädt', 'wm-sovereign-consent-vault' ); ?></h3>
					<ul class="ul-disc">
						<li><?php esc_html_e( 'Nach einer Einwilligung in Externe Medien laden alle Platzhalter der Seite von selbst.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Ohne diese Einwilligung lädt ein Klick auf „Inhalt entsperren“ nur diesen einen Inhalt; der Klick wird nicht protokolliert. Die zweite Schaltfläche öffnet den Dialog.', 'wm-sovereign-consent-vault' ); ?></li>
					</ul>
					<h3><?php esc_html_e( 'Nicht erfasst', 'wm-sovereign-consent-vault' ); ?></h3>
					<ul class="ul-disc">
						<li><?php esc_html_e( 'Andere Anbieter und Länderdomains (z. B. google.de/maps), solange sie nicht als Dienst eingetragen sind.', 'wm-sovereign-consent-vault' ); ?></li>
						<li><?php esc_html_e( 'Player, die ein Skript erst nach dem Laden der Seite einfügt.', 'wm-sovereign-consent-vault' ); ?></li>
					</ul>
				</div>
			</div>

			<?php elseif ( 'chain' === $active_tab ) : ?>
			<?php
			$recent_nodes = [];
			if ( $stats['exists'] ) {
				$table_merkle = Schema::get_merkle_table();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
				$recent_nodes = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY node_index DESC LIMIT %d', $table_merkle, 10 ), ARRAY_A );
			}
			?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><?php esc_html_e( 'Protokoll der Entscheidungen', 'wm-sovereign-consent-vault' ); ?></h2>
					<p><?php esc_html_e( 'Jede Entscheidung wird mit einem HMAC-SHA256 signiert (Schlüssel: AUTH_KEY aus wp-config.php, sonst ein Salt aus der Datenbank) und mit dem vorherigen Eintrag verkettet; die Spitze der Kette steht zusätzlich signiert in einer Option. Die Prüfung erkennt geänderte, gelöschte, nachträglich eingefügte und abgeschnittene Einträge, solange der Schlüssel nicht bekannt ist. Sie beweist nicht, dass ein Eintrag von einem echten Besucher stammt: jeder kann den Token abrufen und eine Entscheidung senden.', 'wm-sovereign-consent-vault' ); ?></p>
					<p>
						<button type="button" class="button button-secondary" id="wm-consent-verify"><?php esc_html_e( 'Kette jetzt prüfen', 'wm-sovereign-consent-vault' ); ?></button>
					</p>
					<div id="wm-merkle-status" aria-live="polite"></div>

					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Knoten', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Vorgänger', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Signatur des Eintrags', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Verkettung', 'wm-sovereign-consent-vault' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Zeitpunkt', 'wm-sovereign-consent-vault' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $recent_nodes ) ) : ?>
							<tr>
								<td colspan="5"><?php esc_html_e( 'Noch keine Einträge.', 'wm-sovereign-consent-vault' ); ?></td>
							</tr>
							<?php else : ?>
							<?php foreach ( $recent_nodes as $node ) : ?>
							<tr>
								<td>#<?php echo esc_html( (string) $node['node_index'] ); ?></td>
								<td><code><?php echo esc_html( substr( (string) $node['prev_hash'], 0, 16 ) ); ?></code></td>
								<td><code><?php echo esc_html( substr( (string) $node['record_hash'], 0, 16 ) ); ?></code></td>
								<td><code><?php echo esc_html( substr( (string) $node['merkle_root'], 0, 16 ) ); ?></code></td>
								<td><?php echo esc_html( (string) $node['timestamp'] ); ?></td>
							</tr>
							<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<script>
				( function () {
					const box = document.getElementById( 'wm-merkle-status' );
					function note( cls, text ) {
						box.className = 'notice inline ' + cls;
						const p = document.createElement( 'p' );
						p.textContent = text;
						box.replaceChildren( p );
					}
					document.getElementById( 'wm-consent-verify' ).addEventListener( 'click', function () {
						note( 'notice-info', <?php echo wp_json_encode( __( 'Prüfe die Kette …', 'wm-sovereign-consent-vault' ) ); ?> );
						fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'wm-consent/v1/verify-merkle' ) ) ); ?>, {
							credentials: 'same-origin',
							headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> }
						} )
						.then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
						.then( function ( d ) {
							const data = d.data || {};
							if ( ! data.valid ) {
								note( 'notice-error', <?php echo wp_json_encode( __( 'Prüfung fehlgeschlagen:', 'wm-sovereign-consent-vault' ) ); ?> + ' ' + data.error + ( data.broken_node ? ' (#' + data.broken_node + ')' : '' ) );
							} else if ( 'EMPTY_CHAIN' === data.status ) {
								note( 'notice-warning', <?php echo wp_json_encode( __( 'Noch keine Entscheidung protokolliert: nichts zu prüfen.', 'wm-sovereign-consent-vault' ) ); ?> );
							} else {
								note( 'notice-success', <?php echo wp_json_encode( __( 'Kette unverändert. Geprüfte Knoten:', 'wm-sovereign-consent-vault' ) ); ?> + ' ' + data.total_nodes + ' · ' + <?php echo wp_json_encode( __( 'Schlüsselquelle:', 'wm-sovereign-consent-vault' ) ); ?> + ' ' + data.key_source );
							}
						} )
						.catch( function ( err ) { note( 'notice-error', <?php echo wp_json_encode( __( 'Fehler:', 'wm-sovereign-consent-vault' ) ); ?> + ' ' + err.message ); } );
					} );
				} )();
				</script>
			</div>

			<?php elseif ( 'glossary' === $active_tab ) : ?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><?php esc_html_e( 'Begriffe', 'wm-sovereign-consent-vault' ); ?></h2>
					<div class="wm-glossary-grid">
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'localStorage', 'wm-sovereign-consent-vault' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Speicher im Browser des Besuchers. Das Plugin legt dort die Entscheidung ab; dazu setzt es ein einziges Cookie, wm_consent, damit auch PHP die Entscheidung lesen kann (wm_consent_has).', 'wm-sovereign-consent-vault' ); ?></p>
						</div>
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'Kategorie', 'wm-sovereign-consent-vault' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Notwendig (immer aktiv), Statistik, Marketing oder Externe Medien. Jeder eingetragene Dienst gehört zu genau einer; seine Skripte und iframes warten auf die Einwilligung in diese Kategorie.', 'wm-sovereign-consent-vault' ); ?></p>
						</div>
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'Version der Richtlinie', 'wm-sovereign-consent-vault' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Kennzeichen unter „Einstellungen“. Eine Entscheidung gilt nur für die Version, zu der sie gegeben wurde; eine neue Version fragt alle Besucher erneut.', 'wm-sovereign-consent-vault' ); ?></p>
						</div>
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'Signaturformat', 'wm-sovereign-consent-vault' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Einträge vor dem 17.09.2026 tragen Format 1 (Kennung, IP-Hash, drei Werte, Zeitpunkt). Neue Einträge tragen Format 2, das zusätzlich Kategorien, Version der Richtlinie und User-Agent signiert. Jeder Eintrag wird mit seinem Format geprüft.', 'wm-sovereign-consent-vault' ); ?></p>
						</div>
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( '2-Klick-Platzhalter', 'wm-sovereign-consent-vault' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Ersetzt ein eingebettetes Video oder eine Karte, bis der Besucher klickt; vorher baut der Browser keine Verbindung zum Anbieter auf.', 'wm-sovereign-consent-vault' ); ?></p>
						</div>
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'HMAC-Kette', 'wm-sovereign-consent-vault' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Jeder Eintrag trägt eine Signatur mit einem geheimen Schlüssel und die des vorherigen. Wer die Daten ohne den Schlüssel ändert, fällt bei der Prüfung auf; wer den Schlüssel kennt, nicht. Trotz des Klassennamens ist es kein Merkle-Baum.', 'wm-sovereign-consent-vault' ); ?></p>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
