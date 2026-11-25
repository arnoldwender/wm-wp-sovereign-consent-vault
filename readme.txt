=== WM Sovereign Consent Vault (TDDDG § 25 & DSGVO) ===
Contributors: arnoldwender
Tags: consent, dsgvo, gdpr, merkle, audit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent dialog with categories that holds back the scripts and embeds of the services you register.

== Description ==

Shows visitors a modal consent dialog with "Alle akzeptieren", "Nur notwendige" and a switch per category (necessary, statistics, marketing, external media), stores the decision in the browser and logs it in two tables of the site's own database. Every logged decision carries its categories and the policy version, is signed with an HMAC and chained to the one before it, so changes made afterwards without the site key are detected by the verification.

Until the visitor accepts a category, the scripts and iframes of the services registered for it are held back on every front-end page: an external or inline script becomes `type="text/plain"` with its source in a data attribute, an embed becomes a click-to-load placeholder. After a decision the accepted content loads without reloading the page; taking a category back deletes the cookies registered for it and reloads. Iframes of YouTube (also youtube-nocookie), Vimeo and Google Maps are replaced by the placeholder in any case.

The plugin makes no legal assessment of the dialog, the blocking or the log, and its default texts claim conformity with nothing.

* **Settings:** banner texts, privacy and legal-notice URL, category descriptions, policy version (raising it asks every visitor again), consent duration in days, blocking switch, and opt-in data removal on uninstall (data preserved by default).
* **Services:** name, provider, category, purpose, privacy policy, cookie names and host or file patterns, with ten optional presets whose hosts and cookies were read from the vendors' own documentation.
* **Shortcode:** `[wm_consent_settings]` opens the dialog again; a link to `#wm-consent-settings` does the same.
* **Developers:** `<script type="text/plain" data-wm-consent="marketing">`, `wm_consent_has( 'statistics' )` in PHP, `window.wmConsent` and the event `wm-consent:decision` in JavaScript, WordPress 6.5+ Block Bindings (`wm-consent/status`), GenerateBlocks 2.x Dynamic Tag (`{{wm_consent_status}}`), the filters `wm_consent_services`, `wm_consent_script_handles`, `wm_consent_script_category`, `wm_consent_blocking_enabled`, `wm_consent_has`, `wm_consent_has_nocache`, `wm_consent_rest_rate_limit`, `wm_consent_status_label` and the actions `wm_consent_ready` and `wm_consent_decision_recorded`.
* **Admin screen:** menu entry "Consent Vault" with an overview, settings, services, the banner as visitors see it, the placeholder hosts, the chain with a verify button, and a dashboard widget.
* **WP-CLI:** `wp consent audit_merkle` verifies the chain.
* **REST:** `wm-consent/v1` with `/nonce` (rate-limited), `/record` (nonce and rate limit) and `/verify-merkle` (administrators).
* **Suite Hub:** registers as a spoke of WM Suite Hub when the hub is active.

No outbound requests; every script and style ships inside the plugin, with 28 compiled `.po`/`.mo` interface catalogs.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-sovereign-consent-vault/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Consent Vault admin screen managing service categories, script blocker rules and audit ledger
2. First-visit modal consent dialog (role=dialog, aria-modal=true) rendered before visitor choice
3. Dashboard widget reporting recorded visitor consent decisions

== Changelog ==


= 1.2.2 =
* Fixed: Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»); it is retaken with the current menu. The service count in the consent dialog («%d Dienst in dieser Kategorie») repeated one form for several numbers in Arabic, Croatian, Czech, Irish, Latvian, Lithuanian, Maltese, Polish, Romanian, Slovak and Slovenian, for example Czech «2 služeb» and Polish «5 usługi»; each number now gets the form its language uses (Czech «2 služby», Polish «5 usług»). The plugin's code is unchanged.

= 1.2.1 =
* Fixed: in the block editor, the button of `[wm_consent_settings]` (for example inside WM Suite Hub's privacy-center block) appeared as a bare browser button, because the front-end stylesheet never reached the editor canvas. It now loads there, without the banner script.
* The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.5 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-sovereign-consent-vault` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.1.4 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..3.png`) and `== Screenshots ==` descriptions.
* Update trilingual READMEs, translated README previews, and `llms.txt` with live previews and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-sovereign-consent-vault/`).

= 1.1.3 =
* Point Plugin URI to the official plugin page.

= 1.1.2 =
* Replaced the 5-second blocking `GET_LOCK('wm_consent_chain', 5)` wait with non-blocking `GET_LOCK('wm_consent_chain', 0)` and bounded jittered retries (`15-45 ms`, up to 8 attempts, filterable via `wm_consent_lock_backoff_enabled`), returning `503` with `Retry-After: 2` under sustained contention without stalling PHP-FPM workers.
* Provisioned consent tables and schema version on newly initialized Multisite blogs via `wp_initialize_site` when network-activated (and on network-wide activation).

= 1.1.1 =
* Evict object-cached `wm_consent_chain_tip` (`notoptions` / `alloptions`) inside `GET_LOCK('wm_consent_chain', 5)` in `MerkleTreeEngine::next_link()` and `verify_chain_integrity()` so concurrent requests cannot fork the chain over a pre-lock cached tip.
* Added activation prerequisite check for `ext-mbstring` and `ext-json` (`Database\Schema::enforce_prerequisites()`) and a MySQL 8.0 CI integration job.

= 1.1.0 =
* Opt-in data retention on uninstall (`wm_consent_delete_data_on_uninstall`): consent tables and options are preserved by default unless deletion is explicitly enabled in settings.
* Public `/nonce` route gated with transient-based HMAC-SHA256 rate limiting (120/min default, filterable via `wm_consent_rest_rate_limit`, bypassed for administrators).
* Parameterized all `$wpdb` table identifier queries via `%i` placeholders.
* Added WordPress 6.5+ Block Bindings source `wm-consent/status`, GenerateBlocks 2.x Dynamic Tag `{{wm_consent_status}}`, `wm_consent_status_label` filter, and `wm_consent_ready` action.
* Completed 28 UI `.po`/`.mo` translation catalogs, CycloneDX 1.6 `bom.json`, and WordPress Playground blueprint.
* The stored decision now acts: scripts and iframes of the registered services wait for their category on every front-end page, and load without a reload once it is accepted.
* Four categories (necessary, statistics, marketing, external media) with a switch each, the services of a category listed with provider, purpose, cookies and privacy policy.
* The dialog is a modal dialog: name, description, focus moved in, kept inside and returned, "Alle akzeptieren" and "Nur notwendige" in the same style, links to the privacy policy and the legal notice, colours and sizes from CSS custom properties.
* Settings for the banner texts, the two legal URLs, the category descriptions, the policy version, the consent duration and the blocking switch; a service registry with ten optional presets.
* The log stores the chosen categories and the policy version, signed with format 2; rows written earlier keep verifying with format 1 and are never re-signed.
* `wm_consent_has()` for PHP, `window.wmConsent` and `wm-consent:decision` for JavaScript, six filters and one action for developers.
* A decision recorded after a node was deleted no longer repairs the chain over the gap: the link and the count come from the recorded tip, which is now signed.
* A consent row written straight into the table is reported instead of counted as a logged decision.
* Decisions are appended under a database lock, so two visitors at once cannot fork the chain; a busy chain answers 503 and writes nothing.
* The record route reads its flags as booleans ("false" was stored as consent) and answers 500 for a decision that was not written.
* Embeds without a protocol (//www.youtube.com/...) are replaced by the placeholder too; it keeps the title of the iframe, says what a click transmits and no longer claims compliance with a statute.
* The banner describes the stored data as it is and sends a decision again when the server did not confirm it.
* Deleting every node of the chain is detected; an empty vault is reported as not configured to the hub.
* A repeated consent id answers 409 instead of a PHP warning with status 200.
* The banner no longer tells visitors what the site loads; placeholders also cover youtube-nocookie and multi-line iframes.
* German admin screen with measured counts and the real banner; the widget counts the real table.
* Documentation rewritten from the code.
* Admin screen on the WM Suite family sheets (shared tokens and components, visible focus rings, native wrap, family badge); the stylesheet and banner image are now part of the distributed package.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.2 =
Screenshot 1 retaken and plural forms fixed in 11 languages. No code, data or settings change.

= 1.2.1 =
Stylesheets only: the settings button is styled in the block editor too, and the admin header follows the family sheet of WM Suite Hub 1.2.5. No data or settings change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
