# Changelog — WM Sovereign Consent Vault

All notable changes to **WM Sovereign Consent Vault** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- Renamed `RestController::NAMESPACE` to `REST_NAMESPACE` for PHP 8.5/8.6 compatibility.

## [1.2.2] - 2026-11-21

### Fixed

- Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»); it is retaken with the current menu. The service count in the consent dialog («%d Dienst in dieser Kategorie») repeated one form for several numbers in Arabic, Croatian, Czech, Irish, Latvian, Lithuanian, Maltese, Polish, Romanian, Slovak and Slovenian, for example Czech «2 služeb» and Polish «5 usługi»; each number now gets the form its language uses (Czech «2 služby», Polish «5 usług»). The plugin's code is unchanged.

## [1.2.1] - 2026-11-21

### Fixed

- In the block editor, a block that prints `[wm_consent_settings]` (WM Suite Hub's privacy-center) showed a bare browser button: the front-end sheet came only through `wp_enqueue_scripts`, which the editor's iframed canvas never runs (measured: background `rgb(239, 239, 239)`, `min-height` 0). The sheet now also loads there through `enqueue_block_assets`, without the banner script; on the front end nothing changes.

### Changed

- The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.5] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-sovereign-consent-vault/`, `https://github.com/arnoldwender/wm-wp-sovereign-consent-vault`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [Unreleased]

## [1.1.4] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-3.png`) with `== Screenshots ==` in `readme.txt`.
- Visual previews and direct link to `https://arnoldwender.com/wordpress-plugins/wm-sovereign-consent-vault/` in `README.md`, `README.de.md`, `README.es.md`, `docs/translations/README.*.md`, and `llms.txt`.

### Changed
- Archived superseded early banners and screenshots non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata and SBOM (`bom.json`) to `1.1.4` and aligned current-version references in `README.md`, `README.de.md`, `README.es.md`, and `Consent_Vault_Spoke_Adapter.php`.

---

## [1.1.3] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-sovereign-consent-vault/` and align release metadata at `v1.1.3`.

---

## [1.1.2] - 2026-11-18

### Performance, Resilience & Multisite
- **Non-Blocking Lock Backoff (`MerkleTreeEngine::acquire_lock_with_jitter()`, `RestController::handle_record_consent()`):** Replaced the 5-second blocking `GET_LOCK('wm_consent_chain', 5)` wait with non-blocking `GET_LOCK('wm_consent_chain', 0)` and up to 8 jittered retries (`15–45 ms`, filterable via `wm_consent_lock_backoff_enabled`) across `append_record()` and `append_anchor()`, preventing PHP-FPM worker stalls under contention. Contended `/record` writes now return HTTP `503` with a `Retry-After: 2` header.
- **Multisite Site Provisioning (`wm-sovereign-consent-vault.php`):** Hooked `wp_initialize_site` when network-activated (and `$network_wide` activation across existing sites via `provision_site_schema()`) to call `switch_to_blog()`, run `Database\Schema::create_tables()` and `update_option( Database\Schema::VERSION_OPTION, Database\Schema::VERSION, false )`, and `restore_current_blog()`.

---

## [1.1.1] - 2026-11-18

### Security & Concurrency
- **Concurrent Tip Cache Eviction (`MerkleTreeEngine::next_link()`, `verify_chain_integrity()`):** Evicts `wm_consent_chain_tip`, `notoptions` and `alloptions` from `wp_cache` inside `GET_LOCK('wm_consent_chain', 5)` before reading `get_option()`, preventing concurrent Apache/PHP-FPM workers from linking against a pre-lock cached chain tip.
- **Activation Prerequisite Check & MySQL 8.0 CI Job:** Added `Database\Schema::enforce_prerequisites()` (`ext-mbstring`, `ext-json`) and a `mysql:8.0` integration job in `.github/workflows/ci.yml`.

---

## [1.1.0] - 2026-11-16

### Added
- **WordPress 6.5+ Block Bindings & GenerateBlocks 2.x Dynamic Tag:** Registered Block Bindings source `wm-consent/status` and GenerateBlocks 2.x dynamic tag `{{wm_consent_status}}` (`includes/Frontend/EcosystemIntegration.php`), plus the `wm_consent_status_label` filter and `wm_consent_ready` action.
- **Opt-In Uninstall Data Deletion (`D-UNINSTALL`):** Added `wm_consent_delete_data_on_uninstall` (default `0`, registered via `register_setting` and exposed under **Consent Vault › Einstellungen**). `uninstall.php` now preserves both consent tables and options by default unless deletion is explicitly enabled.
- **28 Complete UI Translation Catalogs:** Compiled human-translated `.po` and `.mo` catalogs across all 28 supported locales in `languages/`.
- **CycloneDX 1.6 SBOM & WordPress Playground Blueprint:** Added `bom.json`, `.wordpress-org/blueprints/blueprint.json`, and `.license-allowlist.yml`.

### Security & Quality
- **Public Nonce Rate-Limiting (`SEC-02`):** Replaced `'__return_true'` on `GET /wp-json/wm-consent/v1/nonce` with `RestController::check_public_nonce_access()`, enforcing transient-based HMAC-SHA256 rate limiting (`wm_cv_rl_nonce_*`, default 120 requests/min, filterable via `wm_consent_rest_rate_limit`, bypassed for `manage_options`, returning HTTP 429 when exceeded).
- **Prepared SQL Identifiers (`SEC-01`):** Wrapped all table-level `$wpdb` queries in `MerkleTreeEngine`, `AdminDashboard` and `Consent_Vault_Spoke_Adapter` with `$wpdb->prepare()` and `%i` identifier placeholders.
- **CSS Token Hygiene (`CSS-01`):** Removed raw hex colour examples outside token declarations in `assets/css/wm-consent-vault.css`.

### Fixed — the badge claimed a translation the catalogs do not hold (2026-09-20)

- The badge row carried **`Translations — 24 EU Languages` in green**, which reads as a finished translation of the plugin. Measured: **17 real translations across 5,852 catalog entries** (`ar` 4, `bg_BG` 4, `cs_CZ` 4, `da_DK` 4 and `el` 1). Every other filled entry repeats the German source string word for word — `msgfmt` counts those copies as translated and a user does not.
- The badge now reads `Translated READMEs — 24 (headings only)` in grey, the label three other plugins of the fleet already use, and the section it links to says plainly that the plugin's own interface is not translated and that the translated READMEs are a translated table of contents over an English document.
- A badge is read before the prose, so an honest paragraph does not cancel a misleading badge. The fleet now carries a check that fires only when all three hold: the label claims the *plugin* is translated, it wears a success colour, and the catalogs hold under 50 % real translations.

### Added — the catalog freshness gate (2026-09-20)

- `tests/test-pot-freshness.php` and two CI steps on PHP 8.3. **This catalog was already current** — 210 translatable strings, 210 entries, nothing missing and nothing stale — and that is the reason to add the gate rather than a reason to skip it: fifteen of the fleet's twenty-four plugins had drifted, one down to a single shared entry, and not one of them had a check that could notice. Being correct today is not a mechanism.
- The gate takes its definition of "fresh" from `wp i18n make-pot` rather than a regex of its own, reads the text domain from the plugin header instead of holding a second copy, treats 0 extracted strings as a failure rather than a pass, ignores the plugin-header fields `make-pot` adds, requires wp-cli with no escape hatch, and runs its own self-test before judging the plugin.
### The decision finally acts (2026-09-17)

Until this release the banner stored a decision **nothing read**: no script was loaded or blocked because of
it, there was no choice per category, no settings screen, no link to a privacy policy, and the banner was not
a dialog. All of that is in the plugin now, and none of what worked before was removed.

- **Categories.** `necessary` (always on), `statistics`, `marketing`, `media`, with the aliases `analytics`,
  `essential` and `external_media` for code that names them by hand. The slugs are a storage contract: they go
  into the log, the browser and the cookie.
- **Blocking before consent.** `includes/Blocker/OutputBlocker.php` rewrites every front-end page through an
  output buffer (`template_redirect`, priority 99 — inside the buffer of a caching plugin, so the cache stores
  the blocked page) and through `wp_script_attributes`, `wp_inline_script_attributes` and `script_loader_tag`
  for the scripts WordPress prints itself. A script of a registered service, or one marked
  `data-wm-consent="marketing"`, becomes `type="text/plain"` with its source in `data-wm-consent-src`; an
  embed becomes the click-to-load placeholder, which now applies to the whole page and not only to
  `the_content`. **The server always writes the blocked form**, whatever the visitor decided: a page rewritten
  per visitor and kept by a full-page cache would reach the next visitor unblocked. The HTML is scanned into
  segments instead of matched with one lazy regular expression, which runs into PCRE's backtrack limit on a
  megabyte of inline code and would have let a page out unblocked.
- **The dialog.** `role="dialog"`, `aria-modal`, a name and a description, focus moved in, kept inside with
  Tab and returned on close, Escape only for a dialog opened on purpose, "Alle akzeptieren" and "Nur
  notwendige" in one and the same style, a switch per category with its services (provider, purpose, cookies,
  the provider's privacy policy), links to the privacy policy and the legal notice, and no automatic opening
  on those two pages. Every colour and size is a custom property in `assets/css/wm-consent-vault.css`; the
  defaults measure at least 4.5:1 for text and 44 px for targets, both checked by the suite.
- **Activation and revocation.** `assets/js/wm-consent-vault.js` (the banner script moved out of the inline
  `<script>` in `ConsentBanner.php`) activates what a decision allows in document order — an external script
  that was not `async` is awaited, so an inline snippet that depends on it finds it loaded — and, when a
  category is taken back, deletes the cookies registered for its services on this site and reloads, because a
  script that already ran cannot be unloaded.
- **Settings and services.** `Consent Vault › Einstellungen` (texts, privacy and legal-notice URL, category
  descriptions, policy version with a one-step raise, consent duration, blocking switch) and
  `Consent Vault › Dienste` (service table, add/edit/delete, ten presets, the developer API). Every form posts
  to `admin-post.php` and checks `manage_options` and its own nonce before it reads a field.
- **Presets.** Google Analytics / Tag Manager, Meta Pixel, Matomo, Hotjar, Microsoft Clarity, LinkedIn Insight
  Tag, TikTok Pixel, YouTube, Vimeo, Google Maps. Every host and cookie was read on 2026-09-17 from the
  vendor's own documentation or code, and the source of each is written in `ServiceRegistry::presets()`
  (Google's CSP guide, Meta's pixel setup, Matomo's tracking guide and the file names in its repository,
  hotjar/hotjar-js, Microsoft's Clarity CSP and cookie pages, LinkedIn's help centre and cookie table,
  TikTok's own WordPress plugin, the YouTube IFrame API and privacy-enhanced mode, vimeo/player.js, the Maps
  Embed API and the Maps JavaScript API). Hotjar's cookie names are **not** preset: help.hotjar.com answered
  403 that day, so they could not be read from Hotjar. A preset becomes a service only when an administrator
  adds it: the plugin cannot know which services a site embeds, and naming one it does not use would put a
  false statement into the banner.
- **Signature format 2.** The log stores `categories`, `policy_version` and `signature_version` (schema 3).
  New rows are signed with format 2, which also covers the user agent that format 1 left unsigned; rows
  written earlier are verified with format 1 and are **never re-signed**. A format-1 row that carries
  categories or a policy version — columns its signature does not cover — counts as altered, so the new
  columns cannot be used to fabricate a decision on an old row. `verify_chain_integrity()` reports the count
  per format, the CLI and the hub print it.
- **The record route** accepts `categories` and `policy_version`, refuses an unknown category, flags that
  contradict the categories and a malformed version with 400, and fires `wm_consent_decision_recorded`.
- **For developers:** `wm_consent_has( 'statistics' )` (reads the `wm_consent` cookie, checks it against the
  policy version and the duration, and marks the response as not cacheable), `window.wmConsent`, the event
  `wm-consent:decision`, the filters `wm_consent_services`, `wm_consent_script_handles`,
  `wm_consent_script_category`, `wm_consent_blocking_enabled`, `wm_consent_has`, `wm_consent_has_nocache`.
- **Boy-scout fixes on the way:** the SBOM named a proprietary licence although the header has been
  `GPLv2 or later` since `e598ac2`; the admin screen carried the last inline styles; the user agent was cut
  by bytes (`substr`), which can split a multi-byte character and make `$wpdb` refuse the row.
- **Gates:** `tests/test-suite.php` 155 passed / 0 failed, `tests/run-mutations.sh` 138 red / 0 survived /
  0 not applied, `tests/test-ui-family.php` 6 passed. Two mutations survived the first run and are fixed:
  operator precedence made one of them a no-op, and the mock aggregate ignored the column aliases of the
  query. The 28 catalogs were regenerated from the code with `--keep-obsolete`, so the strings that are gone
  stay in them as obsolete entries.

### The version this file opens on matches the version the plugin ships (D15, 2026-09-17)

- **The plugin was 1.0.0, and the changelog opened on 1.0.0 too.** This file used to open on an unreleased draft section that `git tag` (0) and `gh release list` (0) both denied. That draft entry moved **verbatim and complete** to an appendix at the foot of this file via the fleet changelog helper, which refuses to write if a single non-blank line would be lost.

### Reader pass (2026-09-16)

- **Every IPv4 address a dual-stack socket reports as `::ffff:x.x.x.x` shared one rate-limit bucket.** `rate_limit_subject()` groups IPv6 clients by their /64 because a residential line gets a whole /64 — but an IPv4-mapped address packs to ten zero bytes, `ff ff` and the four IPv4 bytes, so its first eight bytes are zero for *every* such address. Measured on a local WordPress install before the fix: `::ffff:203.0.113.5` and `::ffff:198.51.100.9` both returned `0000000000000000::/64`. On a site behind such a socket the eleventh visitor of the minute was refused with 429 and their decision went unrecorded. A mapped address is now counted as the IPv4 address it carries; the /64 grouping for real IPv6 is unchanged.
- **The consent row and its chain node are written inside one transaction.** They were two separate inserts under the same lock: a worker killed between them (an OOM on the 128 MB shared hosting the fleet runs on) left a node the recorded tip did not know about, and from then on every verification reported the chain as `TAMPERED` for an incident nobody caused. Rolled back, neither row exists and the caller is told the decision was not recorded. **The window is narrowed, not closed:** `store_tip()` writes an option after the commit, so a kill in that gap still leaves the tip one node behind, and that state is still reported as a break — nothing is repaired to make a chain verify (rule 6 of `CLAUDE.md`). Whether such a state should be told apart from tampering is a decision about what "tamper-evident" means here, and it waits for Arnold.

### Family sheets (2026-09-16)
- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Fixed (2026-09-16, the chain keeps what it detected)

- **The next consent erased the evidence of a deleted node.** `append_record()` read the last surviving row as predecessor and rewrote the tip from `COUNT(*)`, so a chain whose newest node had been cut verified again as soon as one more decision arrived. Measured on a local WordPress install: node 3 gone, node 4 linked to node 2, status `INTACT`. The predecessor and the count now come from the recorded tip; the cut stays visible as `PREV_HASH_MISMATCH`.
- **The recorded tip was not signed.** It lives in `wp_options`, where whoever can delete a chain row can also write: setting it to the surviving link hid a truncation without holding the key, although the documentation promised the opposite. The tip now carries an HMAC over link and count (`TIP_UNSIGNED`, `TIP_SIGNATURE_INVALID`), and schema version 2 signs the tip of an install whose chain still verifies.
- **A consent row written straight into the table was invisible.** Verification walked the nodes only, so an inserted row counted as a logged decision on the admin screen and passed every check; it is now `UNCHAINED_RECORD`.
- **Two visitors at once forked the chain.** Both requests read the same predecessor and wrote two nodes naming it — which verification reports as tampering on a site that did nothing wrong. Appends now hold the named lock `wm_consent_chain`; a busy chain answers 503 and writes nothing.
- **A decision whose chain node failed stayed in the records table** as a row nothing verifies, and the route answered 409 "already recorded" for it. The row is removed again and the answer is 500.
- **The record route read `"false"` as consent.** The three flags went through `(bool)`, where every non-empty string is true. The route declares them as booleans and the handler sanitises them with `rest_sanitize_boolean()`.
- **The answer carried an option row id as `node_index`:** `insert_id` was read after `update_option()` had written the tip.
- **An anchor hash that is not a hex SHA-256** was stored truncated by the 64-character column and broke every later verification; it is refused.
- **A protocol-relative embed loaded without any click:** `//www.youtube.com/embed/…` did not match the placeholder pattern. The pattern covers it, the placeholder keeps the title and height of the iframe it replaced, and the source travels in data attributes instead of an inline `onclick`.
- **The placeholder claimed compliance:** "Gemäß § 25 TDDDG und DSGVO werden externe Inhalte erst nach Ihrer ausdrücklichen Einwilligung geladen" — a statement about the operator's site. It now says which provider the content comes from and what a click transmits.
- **The banner said the browser identifier is stored as a hash;** the user agent is stored as it arrives. The sentence names both values as they are.
- **A decision whose request never arrived was lost:** the banner hid itself and nothing reached the log. Only 200 or 409 sets `wm_consent_recorded`, and a decision without that flag is posted again on the next page view.
- `includes/Database/Schema.php` had no direct-access guard; `uninstall.php` left the rate-limit transients behind.
- CI compiles every shipped PHP file (five of ten were never loaded by a suite) and runs the new mutation check; `tests/mutations.php` holds 19 entries, `tests/wp-runtime-consent.php` is a read-only check for a live install, and the WP-CLI suite covers laundering, unchained rows, the signed tip and the lock held by a second connection.
- `languages/`: the 28 catalogs were rebuilt from the code with `wp-plugin-fleet-catalog-regenerate.sh`; their msgids are the German strings this version ships, and the double-encoded Spanish entries are gone.

### Fixed (2026-09-16, reader residuals)

- **A chain key left at the phrase `wp-config-sample.php` ships with** (`put your unique phrase here`) signs every record with a value anyone knows, so the verification proves nothing about who wrote the rows. The audit reports `key_is_sample`, and the admin screen, the CLI and the hub can say so instead of presenting such an install as configured.
- **The rate limit counted single IPv6 addresses.** A residential line hands out a whole `/64`, so one visitor could spend the budget of thousands of them; the limit now counts the `/64`, and still stores only an HMAC of it.
- **A browser that blocks site data saw no banner at all:** reading `localStorage` threw inside the listener that shows it. Every access is guarded.
- **The placeholder missed `m.youtube.com` and unquoted `src` attributes**, both of which pasted embed codes produce; the iframe it inserts now carries `referrerpolicy="strict-origin-when-cross-origin"` and `loading="lazy"`.
- **`the_content` handed the filter `null`** on sites where another filter returns it, which was fatal with a typed parameter.

### Fixed (2026-09-14, honest vault)
- **Emptying the whole chain verified as intact.** `verify_chain_integrity()` returned `EMPTY_CHAIN`, `valid: true` before looking at the recorded tip, so `TRUNCATE` or `DELETE FROM` on the node table was invisible, although deleting only the newest node was caught. An empty node table while `wm_consent_chain_tip` counts written nodes is now `CHAIN_TRUNCATED` (test group 8, WP-CLI case 9).
- **The hub read an empty vault as healthy.** A banner that never stored a decision, or tables that were emptied, gave `HEALTHY`; `self_test` reported success over zero nodes. Empty is now `NEEDS_CONFIG` and a failed self-test; a missing records table is `null` in telemetry instead of 0 (test group 9). SBOM properties describe features instead of "§ 25 Einwilligungspflicht" and "Art. 7 Nachweispflicht".
- **A repeated uuid answered 200 with a PHP warning** on sites that display errors: MySQL's "Duplicate entry" was printed before the REST headers, so the 409 never went out (measured on a local WordPress install). The uuid is checked before the insert and `$wpdb` errors are suppressed around it.
- **The banner told visitors things the plugin cannot know:** "Wir verwenden Technologien und Cookies ausschließlich lokal auf diesem Server" and "Optionale Analyse- und Medienmodule werden erst nach Ihrer ausdrücklichen Einwilligung geladen" — nothing in the plugin loads or blocks modules by consent. The sentence now says what happens to the choice; the page source no longer carries "DSK Compliant Anti-Dark-Pattern Banner". The legal wording of the banner stays open for Arnold (D6). Emoji became inline SVG; the banner has a region role.
- **`youtube-nocookie.com` and multi-line iframes passed the placeholder**; `player.vimeo.com` is matched explicitly (test group 10).
- **Admin screen and widget showed fixed or false figures:** the widget counted `wm_consent_audit_log`, a table nothing creates (always 0), beside fixed Zero-CDN and SHA-256 tiles; the earlier Spanish screen carried unverified claims, a banner preview with three buttons and text visitors never see, and automatic-blocking labels for buttons that do not exist; every page view ran `dbDelta`; the family sheets loaded only on the standalone hook. German screen with measured counts, the real banner, what is not covered, verification on request (`textContent`, no `innerHTML`), dashicons.
- **WP-CLI:** the docblock named `wp consent audit-merkle`, which WP-CLI does not know (the command is `audit_merkle`); an empty chain printed "intact"; the output label named an unverified accounting standard; the warning lost its umlauts.
- `uninstall.php` left `wm_consent_vault_db_version` behind (test group 11); its variables are prefixed.

### Documentation (2026-09-14)
- `README.md`, `README.de.md`, `README.es.md`, the 24 translated READMEs, `DEVELOPERS.md`, `CLAUDE.md`, `AGENTS.md`, `llms.txt`, `CONTRIBUTING.md`, `SECURITY.md`, `readme.txt`, `public/humans.txt` and the header description describe what the plugin ships. Until now they promised script blocking before consent, editable designs and legal texts, an unshipped `wm_consent_banner` shortcode with `theme`, `wp consent status|verify|export`, REST `/status` `/submit` `/export`, PDF/CSV audit reports, two other tables, three hooks, a Merkle tree, and unverified legal conformity claims; none of those existed. The translated READMEs are generated from `README.md` by the fleet translations sync helper.
- The historical entries below list features that never shipped as described; they are kept as the record of what was written at the time, each such line marked.
- CI tested PHP 8.1 against a header requiring 8.2; it now runs both standalone suites on 8.2 and 8.3.

### Security
- **`POST wm-consent/v1/record` is gated (HIGH, fleet audit 2026-09-13 §6.2 #20):** the route was `__return_true`, ran two `dbDelta()` per request and inserted whatever uuid the client sent. It now requires the REST nonce the banner fetches from the new uncached `GET wm-consent/v1/nonce` route (403 otherwise), applies a fixed-window rate limit of 10 requests per minute per client keyed by a blinded IP (429), validates the uuid (`[A-Za-z0-9_-]{8,64}`, 400) and answers 409 on a duplicate uuid instead of writing an orphan chain node. `Schema::create_tables()` moved out of the hot path: activation and a schema-version check on boot own it. Tests: `tests/test-suite.php` test 7.

### Design family (hackaton phase 3, 2026-09-14)
- The admin screen renders from the two WM Suite family sheets (`assets/css/wm-admin-tokens.css`, `assets/css/wm-admin-ui.css`, byte-identical copies of the hub's canonical files, pinned by `tests/test-ui-family.php`) instead of a private copy of `admin-obsidian.css`; same handles as every WM plugin, versioned by file time. Native `.wrap`, family badge with the hub/standalone state, gettext menu titles, family menu icon, visible focus rings on every control. Applied with the fleet UI migration tool.
- **The repository never contained `assets/`:** the workspace's global ignore rule for `/Assets/` matched this plugin's root `assets/` on a case-insensitive filesystem. `.gitignore` now negates the rule (as the hub does) and the stylesheets and banner are committed.

---

## [1.0.0] — 2026-08-25

### Initial Release
- Core architectural implementation for Privacy & Consent.
- WP-CLI management commands under `wp consent` (`wp consent audit_merkle`).
- HMAC-SHA256 chained consent ledger (`MerkleTreeEngine`).

---

## Appendix — historical draft written on 2026-08-29 (superseded by 1.1.0)

> Historical draft notes from 2026-08-29 preserved for repository context; see `## [1.1.0] - 2026-11-16` at the top of this file for the actual 1.1.0 release.

### Added
- **24 Official European Union Languages:** Binary `.mo` and source `.po` translation packages in `languages/`.
- **Developer Documentation Suite:** Created `DEVELOPERS.md` with architecture diagrams, hook references, database schema, and REST API guides (rewritten from the code on 2026-09-14).
- **Decoupled REST API Controller:** Registered namespace `/wp-json/wm-consent/v1` with nonce and permission validation (`/nonce`, `/record`, `/verify-merkle`).
- **Zero-CDN Frontend Shortcode:** Settings trigger shortcode `[wm_consent_settings]` with inline SVG icons and CSS design tokens.
- **CycloneDX v1.6 SBOM Engine:** Dynamic SHA-256 entry file integrity hashing and spoke adapter integration for **WM Suite Hub**.
- **Showcase Assets:** 16:9 banner and admin screen assets.
- **Uninstallation Lifecycle:** `uninstall.php` handler with opt-in table and option cleanup.

### Security
- **Input Sanitization:** Sanitization on dynamic text inputs.
- **Constant-Time Cryptography:** Enforced `hash_equals()` verification on all HMAC-SHA256 signature chains.
- **SQL Parameterization:** Parameterized `$wpdb->prepare()` queries.

### Quality & Tests
- Standalone deterministic test suite (`tests/test-suite.php`) with zero external dependencies.
