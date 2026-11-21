# WM Sovereign Consent Vault — Developer Manual

> Everything below is measured against the code in this repository (2026-09-17). The edition before 2026-09-14 documented REST routes `/status` `/submit` `/export`, the tables `wp_wm_consent_vault_proofs` and `wp_wm_consent_categories`, the hooks `wm_consent_cookie_categories`, `wm_consent_recorded` and `wm_consent_banner_config`, a Gutenberg block and a `wm_suite_hub_register_spoke` registration; none of those exist here. The filters and the action listed in section 9 are the ones the code applies today.

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-sovereign-consent-vault.php` | Header, constants (`WM_CONSENT_VAULT_VERSION` / `_FILE` / `_PATH` / `_URL`), PSR-4 autoloader for `WMCyber\Consent\Vault\` → `includes/`, `includes/functions.php`, activation hook (single-site and network-wide tables + schema version), `wp_initialize_site` Multisite table provisioning when network-activated, hub spoke registration, `plugins_loaded` boot. |
| `includes/functions.php` | `wm_consent_has( string $category ): bool` for themes and other plugins. |
| `includes/Core/Plugin.php` | Text domain and `Schema::maybe_upgrade()` on `init`, banner, `the_content` placeholder filter (20), page-wide `OutputBlocker`, `AdminDashboard` and `SettingsScreen` in the admin, REST controller on `rest_api_init`, WP-CLI command `consent`. |
| `includes/Core/Categories.php` | The four category slugs (`necessary`, `statistics`, `marketing`, `media`), their aliases (`analytics`, `essential`, `external_media`), canonical lists, labels and default descriptions. |
| `includes/Core/Settings.php` | Option `wm_consent_settings`: text overrides, privacy and legal-notice URL, category descriptions, policy version, consent duration, blocking switch; `sanitize()`, `save()`, `bump_policy_version()`. |
| `includes/Core/ConsentCookie.php` | Reads and validates the `wm_consent` cookie; behind `wm_consent_has()`. |
| `includes/Services/ServiceRegistry.php` | Option `wm_consent_services`: validation, CRUD, the ten presets with their documented hosts and cookies, pattern matching. |
| `includes/Database/Schema.php` | `VERSION` (3), `VERSION_OPTION`, `maybe_upgrade()` (true when it upgraded), `create_tables()`, table names. |
| `includes/Crypto/MerkleTreeEngine.php` | `record_signature()` (format 1), `record_signature_v2()`, `expected_signature()`, `chain_link()`, `tip_signature()`, `migrate_tip_signature()`, `append_record()`, `append_anchor()`, `verify_chain_integrity()`, `key_source()`, `GENESIS_HASH`, `TIP_OPTION`. A linear HMAC chain; the class name predates that description. |
| `includes/API/RestController.php` | `/nonce`, `/record`, `/verify-merkle` under `wm-consent/v1`. |
| `includes/Frontend/ConsentBanner.php` | The dialog, its configuration block, the front-end assets, `[wm_consent_settings]`, the default texts. |
| `includes/Frontend/EcosystemIntegration.php` | WordPress 6.5+ Block Bindings (`wm-consent/status`), GenerateBlocks 2.x Dynamic Tag (`{{wm_consent_status}}`), and the `wm_consent_status_label` filter. |
| `includes/Blocker/OutputBlocker.php` | Output buffer on `template_redirect` (99) and the filters `wp_script_attributes`, `wp_inline_script_attributes`, `script_loader_tag`. |
| `includes/Blocker/ScriptIframeBlocker.php` | `filter_content_iframes()` and `render_placeholder()` — click-to-load placeholder for iframes. |
| `includes/CLI/ConsentCommand.php` | `wp consent audit_merkle`. |
| `includes/Admin/AdminDashboard.php` | Menu, family sheet enqueue, seven-tab screen, dashboard widget, `record_stats()`. |
| `includes/Admin/SettingsScreen.php` | The tabs Einstellungen and Dienste and the five `admin_post_` handlers behind their forms. |
| `includes/Adapters/Consent_Vault_Spoke_Adapter.php` | WM Suite Hub contract; returns early when the hub's interface does not exist. |
| `assets/css/` | `wm-admin-tokens.css`, `wm-admin-ui.css` (copies of the hub's canonical sheets, pinned by `tests/test-ui-family.php`) and `wm-consent-vault.css` (front end, every value a custom property). |
| `assets/js/wm-consent-vault.js` | Dialog, stored decision, activation of held-back content, cookie deletion on revocation, `window.wmConsent`. |
| `languages/` | 28 compiled `.po` / `.mo` pairs generated from `languages/wm-sovereign-consent-vault.pot`. |
| `uninstall.php` | Preserves tables and options by default; when `wm_consent_delete_data_on_uninstall` is enabled, deletes `wm_consent_chain_tip`, `wm_consent_vault_db_version`, `wm_consent_banner_config`, `wm_consent_settings`, `wm_consent_services`, `wm_consent_delete_data_on_uninstall`, rate-limit transients `wm_cv_rl_*`, service-form drafts, and drops both tables. |
| `tests/wp-runtime-consent.php` | Read-only check inside a real WordPress: tables and columns, routes and their argument types, the 403 without a token, the chain as this install holds it, dialog, blocking, placeholder, `wm_consent_has()`, hub agreement, deployed files, catalog, and that it wrote nothing. |

---

## 2. Storage

`wp_wm_consent_records`: `id`, `consent_uuid` (UNIQUE), `ip_hmac`, `essential`, `analytics`, `marketing`, `categories` (64, canonical comma list), `policy_version` (32), `signature_version` (1 or 2, default 1), `user_agent` (255), `signature_hash`, `created_at`.

`wp_wm_consent_merkle_nodes`: `node_index`, `record_id` (0 for anchors), `foreign_id`, `prev_hash`, `record_hash`, `merkle_root` (the link), `timestamp`.

Options: `wm_consent_chain_tip` (`{hash, count, sig}`), `wm_consent_vault_db_version` (schema `3`), `wm_consent_settings`, `wm_consent_services`. Transients `wm_cv_rl_<hmac>` hold the rate-limit window and `wm_consent_service_draft_<user id>` a service form that failed validation; `uninstall.php` deletes them with the options.

Browser: localStorage `wm_consent_uuid`, `wm_consent_analytics`, `wm_consent_marketing`, `wm_consent_recorded`, `wm_consent_categories`, `wm_consent_policy_version`, `wm_consent_decided_at`; cookie `wm_consent` = `<policy version>|<categories>|<Unix seconds>`, path `/`, `SameSite=Lax`, `Secure` on HTTPS, `max-age` the configured duration.

`Schema::maybe_upgrade()` runs `dbDelta` only when the stored version is behind and returns true when it did; `Core\Plugin` then calls `MerkleTreeEngine::migrate_tip_signature()`. Version 3 adds `categories`, `policy_version` and `signature_version`; `dbDelta` adds them with their defaults, so every existing row is a format-1 row, which is what format 1 verifies.

---

## 3. Chain and signature formats

- **Key:** `AUTH_KEY` when defined and non-empty (`key_source()` = `wp-config`), else `wp_salt( 'secure_auth' )` (`database`).
- **Format 1** (`record_signature()`): `HMAC-SHA256( "uuid|ip_hmac|essential|analytics|marketing|created_at", key )`. Every row written before 2026-09-17.
- **Format 2** (`record_signature_v2()`): `HMAC-SHA256( json( [ 'v2', uuid, ip_hmac, essential, analytics, marketing, categories, policy_version, user_agent, created_at ] ), key )`. A JSON list, so a `|` inside the user agent cannot move a field boundary. Returns null when the values cannot be encoded; the caller refuses to write.
- **`expected_signature( array $record )`** picks the format from `signature_version` (missing column = format 1). A format-1 row carrying `categories` or `policy_version` returns null: its signature does not cover those columns, so someone wrote them afterwards.
- **Link:** `HMAC-SHA256( prev_hash . '|' . record_hash, key )`; the first node's predecessor is `GENESIS_HASH` (64 zeros).
- **Tip signature:** `HMAC-SHA256( hash . '|' . count, key )`, stored in the option beside the two values.
- **Lock:** every append holds the MySQL named lock `wm_consent_chain` using non-blocking `GET_LOCK( 'wm_consent_chain', 0 )` with up to 8 jittered backoff retries (`15–45 ms`, filterable via `wm_consent_lock_backoff_enabled`) instead of a 5 s blocking wait. `1` is the lock; `0` (busy after retries) and `null` (a database that does not answer) both refuse the write with `['error' => 'chain_busy']`, which the REST route turns into 503 with `Retry-After: 2`.
- **`append_record( $uuid, $ip, $essential, $analytics, $marketing, $user_agent, array $categories = [], string $policy_version = '' )`:** derives the categories from the two flags when the list is empty, and refuses (`inconsistent_categories`) when a given list disagrees with them or names an unknown category, and `invalid_policy_version` for a version outside `[A-Za-z0-9._-]{0,32}`; cuts the user agent to 255 characters with `mb_substr`; takes the lock; looks up the uuid and returns `duplicate_or_failed_insert` without inserting; writes the consent row and its node inside one transaction; links to the **recorded tip** (not to the last surviving row), reading `insert_id` before the option write moves it; deletes the consent row again and returns `record_not_written` when the node was refused; writes the signed tip with `count + 1`. The result carries `record_id`, `node_index`, `consent_uuid`, `signature`, `merkle_root`, `categories`, `policy_version`, `signature_version`.
- **`append_anchor( $foreign_id, $foreign_hash )`:** same lock and tip handling; refuses a `$foreign_hash` that is not 64 hex characters (`invalid_anchor_hash`). Caller in the fleet: `wm-dsgvo-privacy-eraser` (`ErasureCertificateGenerator`).
- **`verify_chain_integrity( bool $tolerate_unsigned_tip = false )`:** returns `valid`, `status` (`INTACT`, `EMPTY_CHAIN`, `TAMPERED`), `key_source`, and `total_nodes` / `merkle_root` / `key_is_sample` / `records_by_signature_version` or `error` / `broken_node`. Errors: `PREV_HASH_MISMATCH`, `MERKLE_ROOT_CORRUPTED`, `CONSENT_RECORD_MISSING`, `CONSENT_RECORD_ALTERED`, `UNCHAINED_RECORD`, `TIP_UNSIGNED` / `TIP_SIGNATURE_INVALID`, `CHAIN_TRUNCATED`. One query per node with a record, plus two counts. The parameter is for the version-2 migration only.

---

## 4. REST

| Method | Route | Permission | Behaviour |
| --- | --- | --- | --- |
| `GET` | `/wp-json/wm-consent/v1/nonce` | `check_public_nonce_access` (120 requests / 60 s per client via HMAC-SHA256 transient, bypassed for `manage_options`) | `{ nonce }` for `wp_rest`, `Cache-Control: no-store` |
| `POST` | `/wp-json/wm-consent/v1/record` | REST nonce (403) + 10 requests / 60 s per client (429; IPv6 counted per /64) | Arguments: `uuid` string `[A-Za-z0-9_-]{8,64}`, `essential` / `analytics` / `marketing` boolean, all required; `categories` an optional array of `necessary`, `statistics`, `marketing`, `media`; `policy_version` an optional string `[A-Za-z0-9._-]{1,32}`. The handler checks the same values again for direct calls. `200 { success, data: {record_id, node_index, consent_uuid, signature, merkle_root, categories, policy_version, signature_version} }`, `400` for an unknown category, flags that contradict the categories or a malformed version, `409` for a stored uuid, `503` (`Retry-After: 2`) while the chain lock is held, `500` when the decision was not written. Fires `wm_consent_decision_recorded` after a stored decision. |
| `GET` | `/wp-json/wm-consent/v1/verify-merkle` | `manage_options` | `200 { success: true, data: <verify_chain_integrity> }` |

---

## 5. Front end

- **Dialog** (`wp_footer`, not in the admin): `div#wm-consent-vault-banner` with a backdrop and `div[role="dialog"][aria-modal="true"]`, labelled by `#wm-consent-vault-title` and described by `#wm-consent-vault-description`, two views (`data-wm-consent-view="main|settings"`), one `input[role="switch"]` per category (necessary checked and disabled), the services of each category in a `<details>`, links to the privacy policy and the legal notice, a close button that only a reopened dialog shows. Actions carry `data-wm-consent-action="accept-all|necessary|settings|save|back|close"`.
- **Configuration:** `script[type="application/json"]#wm-consent-config` from `ConsentBanner::config()` — `policyVersion`, `durationDays`, `nonceUrl`, `recordUrl`, `categories`, `aliases`, `services` (id, category, cookies), `suppressOn` (the paths of the privacy policy and the legal notice), `cookieName`, `secure`.
- **Script** (`assets/js/wm-consent-vault.js`, handle `wm-consent-vault`, in the footer): reads the stored decision, checks it against the policy version and the duration, resends a decision the server never confirmed, opens the dialog when there is none, activates what a decision allows, deletes cookies and reloads on revocation, and exposes `window.wmConsent` plus the event `wm-consent:decision`. `wmSubmitConsent( analytics, marketing, media )` is kept for code written against the two-button banner.
- **Sheet** (`assets/css/wm-consent-vault.css`, handle `wm-consent-vault`): dialog, placeholder and settings button; every colour and size is a custom property on the component root, so a theme restyles it by overriding `--wm-consent-surface` and `--wm-consent-text` on `.wm-consent`. On the front end it comes with the script through `wp_enqueue_scripts`; in the block editor `enqueue_block_assets` loads the sheet alone (`ConsentBanner::enqueue_editor_style()`), so a block that prints `[wm_consent_settings]` shows the styled button in the canvas (since 1.2.1).
- **`[wm_consent_settings]`:** a button carrying `data-wm-consent-open`. No attributes.
- **Block Bindings & Dynamic Tags** (`includes/Frontend/EcosystemIntegration.php`): WordPress 6.5+ source `wm-consent/status` (`field`: `policy_version`, `services_count`, or `category`) and GenerateBlocks 2.x dynamic tag `{{wm_consent_status}}`.
- **Placeholder** (`the_content` 20, and the page buffer): regex over `<iframe … src="…">…</iframe>` (case-insensitive, dot matches newlines, scheme optional, quotes optional) for `youtube.com`, `youtube-nocookie.com`, `youtu.be`, `vimeo.com`, `player.vimeo.com`, `m.youtube.com`, `maps.google.com`, `google.com/maps`; replaced by `div.wm-2click-wrapper` carrying `data-wm-embed-src`, `data-wm-embed-title`, `data-wm-embed-height`, `data-wm-consent-category="media"` and `data-wm-consent-service`.

---

## 6. Blocking

`OutputBlocker::should_block()` is true on a front-end request while the settings switch is on and `wm_consent_blocking_enabled` does not say otherwise; false in the admin, in AJAX, cron and REST requests, and for feeds, robots and trackbacks.

- **Buffer:** `ob_start()` on `template_redirect` priority 99 — inside the buffer a caching plugin opens earlier, so the cache stores the blocked page. Chunks flushed early are held back and rewritten with the rest; `ob_clean()` discards them. A response whose `Content-Type` is not HTML is passed through.
- **Segments:** the HTML is scanned (not matched with one regular expression, which runs into PCRE's backtrack limit on a megabyte of inline code) into `script` elements, `raw` parts (HTML comments, `<style>`, `<textarea>`) and the `markup` between them. Only script segments are examined as scripts, only markup segments for iframes — so an iframe inside script text stays untouched.
- **A script is held back** when its type is JavaScript (or empty, or `module`) and it carries `data-wm-consent="<category>"`, or its `src` or inline code matches a registered service, or its handle is mapped through `wm_consent_script_handles`. Never: `data-wm-consent-ignore`, an id starting with `wm-consent-`, another type, the category `necessary`. The result: `type="text/plain"`, `data-wm-consent-category`, `data-wm-consent-service`, `data-wm-consent-src` (the `src` attribute is removed), `data-wm-consent-type` for a non-classic type; every other attribute is kept as written.
- **An iframe is held back** the same way: `media` becomes the placeholder (for `https:` and protocol-relative sources), any other category keeps its source in `data-wm-consent-src`.
- **The tag filters** `wp_script_attributes` and `wp_inline_script_attributes` (WordPress 5.7+, also used for enqueued scripts since 6.3) and `script_loader_tag` (4.1+, the whole output of a handle including its inline parts) apply the same rules before WordPress prints a tag. The rewrite is idempotent: a `text/plain` tag is not touched again.

---

## 7. Settings and services

`Settings::get()` returns `texts` (the eight keys of `Settings::TEXT_KEYS`, empty meaning "use the translatable default from `ConsentBanner::default_texts()`"), `privacy_url`, `imprint_url`, `category_descriptions`, `policy_version`, `duration_days` (1–3650), `blocking_enabled`. `Settings::sanitize()` keeps the current value of a field it cannot accept and names it in `errors`; `Settings::bump_policy_version()` increments a trailing number.

A service is `id`, `name`, `provider`, `category`, `purpose`, `privacy_url`, `cookies` (names, `*` at the end covers a family), `hosts` (4–100 characters of `a-z0-9._/-` with at least one `.` or `/`), `preset`. `ServiceRegistry::match()` compiles one case-insensitive regex per service in which each pattern must stand between characters that cannot continue a host or file name, so `youtube.com` matches `www.youtube.com/embed` but not `notyoutube.com`. `presets()` carries the ten templates with the source of every host and cookie in its docblock.

---

## 8. WP-CLI

```bash
wp consent audit_merkle   # error exit on a broken chain; warning on an empty one; nodes, tip, key source, the count per signature format and the policy version otherwise
```

---

## 9. Filters and actions

| Hook | Signature | Purpose |
| --- | --- | --- |
| `wm_consent_services` | `array $services` | Add or change services at runtime; what it returns is validated again. |
| `wm_consent_script_handles` | `array $handles` | `[ 'my-analytics' => 'statistics' ]`: a script handle waits for a category, inline parts included. |
| `wm_consent_script_category` | `?string $category, array $context` | Decide per script (`src`, `code`, `attributes`, `service`); null leaves it alone. |
| `wm_consent_blocking_enabled` | `bool $enabled` | Switch the page-wide rewrite off for a request. |
| `wm_consent_has` | `bool $result, string $category` | The answer of `wm_consent_has()`. |
| `wm_consent_has_nocache` | `bool $nocache, string $category` | Whether reading the cookie marks the response as not cacheable. |
| `wm_consent_rest_rate_limit` | `int $limit, string $route, ?WP_REST_Request $request` | Per-minute rate limit for `nonce` (120) and `record` (10). |
| `wm_consent_lock_backoff_enabled` | `bool $enabled` | Whether `MerkleTreeEngine` retries contended `GET_LOCK(..., 0)` calls with jittered backoff (default `true`). |
| `wm_consent_status_label` | `string $label, string $field, string $category` | Customize output of `wm-consent/status` Block Bindings and `{{wm_consent_status}}`. |
| `wm_consent_ready` | `Plugin $plugin, ServiceRegistry $registry` (action) | Fired after the plugin registers its hooks and services. |
| `wm_consent_decision_recorded` | `array $result` (action) | After a decision was written and chained. |

JavaScript: `window.wmConsent.open()`, `.has( category )`, `.categories()`; the event `wm-consent:decision` on `document` with `detail.categories` and `detail.source` (`banner` or `stored`).

---

## 10. Admin

- **Menu:** `add_menu_page( 'wm-consent-vault', 'Consent Vault', manage_options, family icon, 84 )`.
- **Assets:** family sheets on hooks containing `wm-consent-vault` (standalone and under the hub) and on `index.php` (widget).
- **Screen:** `Schema::maybe_upgrade()`, then the tabs `overview`, `settings`, `services`, `banner`, `embeds`, `chain`, `glossary`. Unknown tabs fall back to `overview`; the active tab carries `aria-current`. The forms of `settings` and `services` post to `admin-post.php`; each handler checks `manage_options` and its own nonce before reading a field, and redirects with a notice code (`SettingsScreen::notice()`).
- **Widget:** `wm_consent_vault_widget` for `manage_options` — decision counts and the newest decision; the chain is not verified there.

---

## 11. WM Suite Hub spoke

`Consent_Vault_Spoke_Adapter`: slug `wm-sovereign-consent-vault`, version from the constant, health `ERROR` when verification fails, `NEEDS_CONFIG` for `EMPTY_CHAIN`, `HEALTHY` otherwise; SBOM component with the SHA-256 of the main file, the licence `GPL-2.0-or-later` and four feature properties; settings URL `admin.php?page=wm-consent-vault`; telemetry `total_consents_logged` (`null` without the table), `chain_integrity`, `chain_error`, `ledger_nodes_count`, `key_source`, `chain_tip`, `consents_by_category`, `policy_version`, `consents_current_policy`, `records_by_signature_version`, `blocking_enabled`, `services_registered`; actions `self_test` / `audit_merkle`, anything else `success: false`.

---

## 12. Known drift (documented, not fixed)

1. Blocking reaches the registered services, the marked markup and the built-in embed providers — nothing else, and nothing a script inserts after the page has loaded.
2. `/record` accepts decisions from anyone who fetched the public nonce, within the rate limit.
3. User agent stored in clear (signed from format 2 on); IP as a keyed hash.
4. Verification cost is linear in the number of nodes, and the hub calls it for health and telemetry.
5. `uninstall.php` preserves both tables and options by default unless `wm_consent_delete_data_on_uninstall` is opted in.
6. Class and column names (`MerkleTreeEngine`, `merkle_root`, `/verify-merkle`) describe a tree that does not exist.
7. `wm_consent_has()` marks the response as not cacheable; that is the price of a per-visitor decision in PHP.
8. Rows written by another installation carry that site's key and verify as `MERKLE_ROOT_CORRUPTED` here.
9. `GET_LOCK` is MySQL/MariaDB; on a database that does not answer it, `/record` refuses with 503 instead of writing unserialised.
10. Format 2 covers the categories, the policy version and the user agent; the node `timestamp` and an anchor's `foreign_id` stay unsigned. Changing a payload needs a new format, never a re-signature — which is also what rotating `AUTH_KEY` would require (no key version is stored).
11. `key_is_sample` in the audit result is true when `AUTH_KEY` is still the phrase from `wp-config-sample.php`.
12. `uninstall.php` cleans the current site only; on a network install the other sites keep their tables, options and transients.
13. The legal wording of the dialog and the retention question wait for Arnold (fleet audit D6).

---

## 13. Tests

```bash
php tests/test-suite.php        # key source, chain append and verification, signature formats 1 and 2, tampering, truncation, unchained rows, the lock, record route (nonce, rate limit, booleans, categories, policy version), categories, settings, service registry and presets, page-wide blocking, the tag filters, wm_consent_has, dialog markup, front-end sheet (contrast and target sizes), banner script, admin screens and their handlers, telemetry, uninstall
bash tests/run-mutations.sh     # each entry of tests/mutations.php must turn the suite red when it is undone
python3 tests/browser/test-consent-dialog.py   # Chromium (Playwright): nothing of a registered service runs before a decision, the dialog is modal and keeps the focus, the accepted script runs and the refused one does not, the cookie and the storage carry the decision, a revocation deletes the cookies and reloads
wp eval-file wp-content/plugins/wm-sovereign-consent-vault/tests/wp-runtime-consent.php --user=1       # read-only, safe on a live site
wp eval-file wp-content/plugins/wm-sovereign-consent-vault/tests/test-consent-chain.php --allow-root   # TRUNCATES the consent tables: disposable installs only
```

`wp-plugin-fleet-runtime.sh` skips `test-consent-chain.php` unless it is run with `--destructive`. What needs a real database and runs nowhere else: a second connection holding `wm_consent_chain` while an append is attempted, a truncation followed by a new decision, the columns and defaults of schema 3, a format-1 row beside a format-2 row, and a user agent with umlauts cut to 255 characters.

---

## 14. Security disclosure

See [SECURITY.md](SECURITY.md).
