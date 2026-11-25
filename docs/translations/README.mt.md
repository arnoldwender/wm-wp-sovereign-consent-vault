# WM Sovereign Consent Vault (Malti)

<p align="center">
  [🇧🇬 Български](README.bg.md) • [🇨🇿 Čeština](README.cs.md) • [🇩🇰 Dansk](README.da.md) • [🇩🇪 Deutsch](../../README.de.md) • [🇬🇷 Ελληνικά](README.el.md) • [🇬🇧 English](../../README.md) • [🇪🇸 Español](../../README.es.md) • [🇪🇪 Eesti](README.et.md) • [🇫🇮 Suomi](README.fi.md) • [🇫🇷 Français](README.fr.md) • [🇮🇪 Gaeilge](README.ga.md) • [🇭🇷 Hrvatski](README.hr.md)<br/>[🇭🇺 Magyar](README.hu.md) • [🇮🇹 Italiano](README.it.md) • [🇱🇹 Lietuvių](README.lt.md) • [🇱🇻 Latviešu](README.lv.md) • [🇲🇹 Malti](README.mt.md) • [🇳🇱 Nederlands](README.nl.md) • [🇵🇱 Polski](README.pl.md) • [🇵🇹 Português](README.pt.md) • [🇷🇴 Română](README.ro.md) • [🇸🇰 Slovenčina](README.sk.md) • [🇸🇮 Slovenščina](README.sl.md) • [🇸🇪 Svenska](README.sv.md)
</p>

---

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-6.4%20--%207.1+-21759b.svg?logo=wordpress&logoColor=white" alt="WordPress Version" /></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3+-777bb4.svg?logo=php&logoColor=white" alt="PHP Version" /></a>
  <img src="https://img.shields.io/badge/Language-mt_MT-blue.svg" alt="Malti" />
  <img src="https://img.shields.io/badge/Zero--CDN-100%25%20Local-purple.svg" alt="Zero-CDN" />
  <a href="../../LICENSE"><img src="https://img.shields.io/badge/License-GPLv2%20or%20later-brightgreen.svg" alt="License" /></a>
</p>

> **Asks visitors per category, holds back the scripts and embeds of the registered services until they accept, and logs every decision with its categories in the site's own database as an HMAC-chained record**  

---

## 📸 Malti Enterprise Showcase

!WM Sovereign Consent Vault Banner

![WM Sovereign Consent Vault Dashboard UI](../../.wordpress-org/screenshot-1.png)

---

## 🟢 LIVELL 1: GWIDA TA' BIDU MALAJR GĦAL DAWK LI QED JIBDEW (NO-CODE)

### X'jagħmel dan il-plugin għall-websajt tiegħek?
Asks visitors per category, holds back the scripts and embeds of the registered services until they accept, and logs every decision with its categories in the site's own database as an HMAC-chained record

### 🚀 Kif tibda f'2 minuti (Pass pass):
1. Install and activate the plugin (WordPress 6.4+, PHP 8.2+). Activation creates the two tables; an update adds the three columns of schema 3.
2. Open **Consent Vault › Dienste** and add a preset for every service the site really uses, or enter your own with its host or file patterns; complete the provider details.
3. Check **Consent Vault › Einstellungen**: texts, the URLs of the privacy policy and the legal notice, the descriptions, the consent duration and the policy version.
4. Visit the site in a private window: the dialog appears, "Einstellungen" shows the categories with their services, and the accepted scripts and embeds load without a reload. Under **Consent Vault › Übersicht** the decision is counted.
5. Put `[wm_consent_settings]` on the privacy policy page, or a link to `#wm-consent-settings` in a menu, so visitors can change their decision.
6. For code of your own: `<script type="text/plain" data-wm-consent="statistics">` in the markup, `wm_consent_has( 'statistics' )` in PHP.
7. Optionally run **Kette jetzt prüfen** under **Protokoll**, or `wp consent audit_merkle`.

### 💡 Mistoqsijiet Frekwenti għal dawk li qed jibdew:
#### ❓ What is not included?
No detection of the services a site uses: what is held back are the registered services, the markup marked with `data-wm-consent` and the built-in embed providers. Nothing a script inserts after the page has loaded, and no images or pixels inside `<noscript>`. No signals to a vendor's own consent API (Google Consent Mode and its like). No colour settings in the admin (the appearance follows CSS custom properties a theme can override). No export, report or certificate. No Merkle tree (the chain is linear despite the class name). No assessment of whether the dialog, the blocking or the log meets any legal requirement.

---

## 🟡 LIVELL 2: GWIDA GĦALL-WEBMASTERS U AMMINISTRATURI

### What the plugin does
1. **Consent dialog with categories.** In `wp_footer`, for every visitor whose browser holds no decision for the current policy version, a modal dialog (`role="dialog"`, `aria-modal`, named by its heading and described by its text) with "Alle akzeptieren" and "Nur notwendige" in one and the same style, and "Einstellungen" for a switch per category: **notwendig** (always on), **Statistik**, **Marketing** and **Externe Medien**, each with its description and the registered services of that category (provider, purpose, cookies, the provider's privacy policy). The script moves focus into the dialog, keeps Tab inside it and returns focus when it closes; the first-visit dialog closes only on a choice, a dialog opened on purpose also on Escape or its close button. Colours and sizes come from custom properties in `assets/css/wm-consent-vault.css` (no inline styles, text contrast at least 4.5:1, targets at least 44 px). It links the privacy policy (the page from Settings › Privacy unless another URL is configured) and the legal notice; on those two pages the dialog does not open by itself. Reopen it with `[wm_consent_settings]`, a link to `#wm-consent-settings`, any element with `data-wm-consent-open`, or `window.wmConsent.open()`.
2. **Blocking before consent.** On every front-end page, `includes/Blocker/OutputBlocker.php` rewrites the HTML through an output buffer (opened late on `template_redirect`, so a caching plugin stores the blocked page) and through `wp_script_attributes`, `wp_inline_script_attributes` and `script_loader_tag` for the scripts WordPress prints itself. A `<script>` whose source or inline code carries a pattern of a registered service, and a script marked `data-wm-consent="marketing"`, become `type="text/plain"` with `data-wm-consent-category`, `data-wm-consent-service`, the source in `data-wm-consent-src` and a `module` type in `data-wm-consent-type`. An `<iframe>` of YouTube, Vimeo or Google Maps, and one of a registered service in Externe Medien, become the click-to-load placeholder; an iframe of another registered category keeps its source in `data-wm-consent-src`. The server always writes the blocked form, whatever the visitor decided, so a full-page cache cannot serve an unblocked page to the next visitor. After a decision the script activates what was accepted in document order — an external script that was not `async` is awaited before the next one runs — without reloading the page. Taking a category back deletes the cookies registered for its services on this site and reloads. Untouched: other script types (JSON-LD, import maps), anything carrying `data-wm-consent-ignore`, this plugin's own tags, HTML comments, `<style>`, `<textarea>`.
3. **Services and presets.** Under **Consent Vault › Dienste** a service carries name, id, provider, category, purpose, the provider's privacy policy, cookie names (`_ga_*` covers a family) and host or file patterns (`googletagmanager.com`, `matomo.js`), which match as whole host or file names. Ten presets are offered for Google Analytics / Tag Manager, Meta Pixel, Matomo, Hotjar, Microsoft Clarity, LinkedIn Insight Tag, TikTok Pixel, YouTube, Vimeo and Google Maps; their hosts and cookies were read from each vendor's own documentation or code on 2026-09-17 (the sources are named in `ServiceRegistry::presets()`). A preset becomes a service only when an administrator adds it.
4. **Settings.** **Consent Vault › Einstellungen**: the banner texts as overrides of the translatable defaults, the URLs of the privacy policy and the legal notice, the description of each category, the **policy version** (raising it asks every visitor again and is recorded with each decision), the **consent duration in days**, a switch for the blocking, and an explicit opt-in checkbox (`wm_consent_delete_data_on_uninstall`) to remove logs, tables and settings on uninstall (preserved by default). The default texts describe what the plugin stores and claim conformity with nothing.
5. **Decision log.** `/nonce` allows 120 requests per minute per client (keyed by an HMAC of the IP address, bypassed for administrators, filterable via `wm_consent_rest_rate_limit`); `/record` requires the REST nonce (403), allows 10 requests per minute per client (keyed by an HMAC of the IP address, counted per IPv6 /64, 429) and declares its arguments: `uuid` as a string of 8–64 characters `[A-Za-z0-9_-]`, the three flags as booleans, optionally `categories` (the four slugs) and `policy_version`; flags that contradict the categories, an unknown category or a malformed policy version answer 400. A stored uuid answers 409 before any insert, a chain busy with another request 503, a decision that could not be written 500. A stored decision holds the uuid, an HMAC-SHA256 of the IP address (key `wp_salt( 'auth' )`), the three flags, the accepted categories, the policy version, the first 255 characters of the user agent, a signature over those values and the time. Each decision appends a node to a linear chain while holding the named database lock `wm_consent_chain`: predecessor, record signature and an HMAC linking both, the consent row and its node in one transaction. The predecessor comes from the recorded tip, and the option `wm_consent_chain_tip` holds that link, the node count and a signature over both. `wm-dsgvo-privacy-eraser` appends its certificate seals (hex SHA-256) to the same chain as anchor nodes.
6. **Two signature formats.** Rows written before 2026-09-17 carry format 1 (uuid, hashed IP address, the three flags, the time); new rows carry format 2, which also covers the categories, the policy version and the user agent. Each row is verified with the format it was written in, and none is ever re-signed; a format-1 row that carries categories or a policy version — columns its signature does not cover — counts as altered.
7. **Verification.** `MerkleTreeEngine::verify_chain_integrity()` walks every node: predecessor, link HMAC, the recomputed signature of the decision behind it, the consent rows no node covers, and the recorded tip with its signature and count. Result `INTACT` (with the count per signature format), `EMPTY_CHAIN` (nothing recorded yet) or `TAMPERED` with `PREV_HASH_MISMATCH`, `MERKLE_ROOT_CORRUPTED`, `CONSENT_RECORD_MISSING`, `CONSENT_RECORD_ALTERED`, `UNCHAINED_RECORD`, `TIP_UNSIGNED` / `TIP_SIGNATURE_INVALID` or `CHAIN_TRUNCATED`. Available as `GET /wp-json/wm-consent/v1/verify-merkle` (`manage_options`), `wp consent audit_merkle`, a button on the admin screen and the hub adapter. It detects changes made without the key; it does not show that a decision came from a real visitor.
8. **Click-to-load placeholders.** Every iframe whose source is on `youtube.com`, `youtube-nocookie.com`, `youtu.be`, `vimeo.com` / `player.vimeo.com`, `maps.google.com` or `google.com/maps` — in post content through `the_content` and, while blocking is on, anywhere on the page — is replaced by a box that names the provider, says that a click connects to it and transmits the visitor's IP address, and carries the source, title and height of the original iframe in data attributes. A protocol-relative source (`//www.youtube.com/…`) is covered as well. A click loads that one embed and is not recorded; consent to Externe Medien loads every placeholder of the page without a click.
9. **For developers.** Markup `<script type="text/plain" data-wm-consent="marketing">…</script>` (with or without `src`) and `<iframe data-wm-consent="media" data-wm-consent-src="…">`. PHP: `wm_consent_has( 'statistics' )` reads the `wm_consent` cookie, checks it against the policy version and the duration, and marks the response as not cacheable. JavaScript: `window.wmConsent.has( 'media' )`, `window.wmConsent.open()`, `window.wmConsent.categories()` and the event `wm-consent:decision` on `document`. Block Bindings & Dynamic Tags: WordPress 6.5+ source `wm-consent/status` and GenerateBlocks 2.x tag `{{wm_consent_status}}`. Filters: `wm_consent_services`, `wm_consent_script_handles`, `wm_consent_script_category`, `wm_consent_blocking_enabled`, `wm_consent_has`, `wm_consent_has_nocache`, `wm_consent_rest_rate_limit`, `wm_consent_status_label`. Actions: `wm_consent_ready`, `wm_consent_decision_recorded`.
10. **Admin.** Menu *Consent Vault* (position 84), page `wm-consent-vault`, German interface with seven tabs: overview (logged decisions, per category, for the current policy version, registered services, what is not included), Einstellungen, Dienste, the banner as visitors see it, the placeholder hosts, the chain (explanation, verify button, last ten nodes) and a short list of terms. A dashboard widget shows the counts without verifying the chain. Family sheets of the WM Suite admin.
11. **WM Suite Hub spoke.** `includes/Adapters/Consent_Vault_Spoke_Adapter.php` registers through `wm_register_suite_module`: health `HEALTHY` for a verified chain with at least one node, `NEEDS_CONFIG` while nothing was recorded, `ERROR` when verification fails; telemetry with the decision count (`null` without the table), the decisions per category, the policy version and how many decisions were given for it, chain state and error, node count, the count per signature format, key source, tip, the blocking switch and the number of registered services; action `self_test` (fails on an empty or broken chain).

### Not shipped
No detection of the services a site uses: what is held back are the registered services, the markup marked with `data-wm-consent` and the built-in embed providers. Nothing a script inserts after the page has loaded, and no images or pixels inside `<noscript>`. No signals to a vendor's own consent API (Google Consent Mode and its like). No colour settings in the admin (the appearance follows CSS custom properties a theme can override). No export, report or certificate. No Merkle tree (the chain is linear despite the class name). No assessment of whether the dialog, the blocking or the log meets any legal requirement.

---

## 🔴 LIVELL 3: GWIDA GĦALL-IŻVILUPPATURI U ARKITETTI TA' SOFTWARE

Classes, hooks, options, the admin screen and the WM Suite Hub adapter are documented in the English manual: **[DEVELOPERS.md](../../DEVELOPERS.md)**.

---

## ⚖️ Konformità Legali u Regolatorja Ewropea

- **What the plugin establishes:** that a browser accepted a set of categories at a given time, under a given policy version, stored with a signature and a chain link that reveal later changes, insertions and deletions made without the key; and that the scripts and embeds registered for a category are not loaded before that category is accepted. It does not assess whether the dialog, the blocking or the log satisfies DSGVO, TDDDG or any judgment, and it does not make a site compliant.
- **Outbound requests:** none. Registered services and embedded providers are contacted by the visitor's browser only after their category is accepted or the placeholder is clicked.
- **Personal data:** per decision a keyed hash of the IP address, the user agent, the categories, the policy version and the time, in two tables of the site's database; nothing is sent elsewhere. In the visitor's browser: the localStorage keys of the decision and the cookie `wm_consent`, which lives as long as the configured duration. Uninstalling preserves the tables and options unless deletion on uninstall is opted in.

---

## 🔒 Liċenzja u Drittijiet Proprjetarji

Distributed under the **GNU General Public License v2 or later**.  
Copyright (C) 2026 Arnold Wender.  
