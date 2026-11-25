# Architecture — WM Sovereign Consent Vault (TDDDG § 25 & DSGVO)
`wm-sovereign-consent-vault` · version `1.1.2` from the plugin header · WordPress 6.4+ · PHP 8.2+ · text domain `wm-sovereign-consent-vault`

Measured against the codebase in this repository. Every figure below comes from the code, not from any document.

---

## How a decision flows

```
every front-end page        OutputBlocker rewrites the HTML (buffer on template_redirect 99, plus
                              wp_script_attributes / wp_inline_script_attributes / script_loader_tag)
                            ├─ script of a registered service or marked data-wm-consent
                            │    → type="text/plain" + category + source in data-wm-consent-src
                            └─ iframe of YouTube / Vimeo / Maps or a registered media service
                                 → click-to-load placeholder (category media)

visitor decides             dialog writes categories, policy version and time to localStorage and the
                              wm_consent cookie
                            ├─ POST /wp-json/wm-consent/v1/record  (public, REST nonce + rate limit)
                            │    └─ take named lock wm_consent_chain
                            │       read the SIGNED tip option {hash, count, sig}
                            │       write the consent row and its node in ONE transaction, linked to
                            │         THAT tip, signed with format 2
                            │       write the new signed tip, count carried forward
                            │       release, then do_action( 'wm_consent_decision_recorded' )
                            └─ script activates the accepted scripts and embeds in document order,
                                 or deletes the cookies of a revoked category and reloads

PHP that must branch        wm_consent_has( 'statistics' ) reads the cookie and marks the response
                              as not cacheable

GET /verify-merkle          manage_options; walks every row against the recorded tip, each row with
wp consent audit_merkle       the signature format it was written in
```

**The server never reads the decision when it rewrites a page.** It always writes the blocked form; the browser
applies the decision. A page rewritten per visitor and kept by a full-page cache would reach the next visitor
unblocked, which is the failure mode this design exists to avoid.

---

## Wiring — what to touch for a given change

| Change | Files, in order | Precedent |
|---|---|---|
| Block another embed provider | `includes/Blocker/` (the pattern and the placeholder) → assertion → mutation | the YouTube pattern; placeholders must stay keyboard-reachable |
| Add a preset | `includes/Services/ServiceRegistry.php` `presets()` — the source and the date of every host and cookie go in the docblock → assertion → mutation | the ten presets of 2026-09-17; Hotjar ships without cookie names because its help centre answered 403 |
| Change what is chained | `includes/Crypto/MerkleTreeEngine.php` — **this is a contract**: a new payload needs a new `SIGNATURE_VERSION` and the old format has to keep verifying; nothing is ever re-signed | format 2 beside format 1, and the invariant that a format-1 row carrying categories is altered |
| Add a category | `includes/Core/Categories.php` (the slugs are a storage contract), then the dialog, the settings and the log's `categories` column width | `media`, added with the categories |
| Add a REST route | `includes/API/` → decide the gate: public needs the REST nonce **and** the rate limit, administrative needs `manage_options` | `/record` (public) vs `/verify-merkle` (`manage_options`) |
| Add a form to the admin | `includes/Admin/SettingsScreen.php` — a handler checks `manage_options` and its own nonce before it reads a field, sanitises per field and redirects with a notice code | the five `admin_post_` handlers |
| Change the hub's view | `includes/Adapters/` `get_health_status()` | `EMPTY_CHAIN → NEEDS_CONFIG`, `CHAIN_TRUNCATED` |

---

## Fragile spots

1. **Every append holds the lock `wm_consent_chain` and links to the RECORDED TIP**, never to the last row in the table. Reading the table is what let a later consent launder a deleted node; writing without the lock is what forked the chain for two simultaneous visitors. A lock that cannot be taken refuses the write with 503 — it never writes unserialised.
2. **The tip option is signed** (`{hash, count, sig}`) and its count is carried forward, never recounted with `COUNT(*)`. A consent row no node covers is `UNCHAINED_RECORD`, not a logged decision.
3. **Two signature formats coexist.** Each row is verified with the format its `signature_version` names; a format-1 row carrying `categories` or `policy_version` is altered, because format 1 does not sign those columns. Re-signing an old row would need the key and would destroy the evidence it carries.
4. **The blocker must not depend on the visitor.** `should_block()` looks at the request and the settings, never at the cookie. `wm_consent_has()` is the only server-side reader, and it marks the response as not cacheable.
5. **The page is scanned, not matched with one regular expression.** A lazy `.*?</script>` over a megabyte of inline code runs into PCRE's backtrack limit, returns null, and the page goes out unblocked. `OutputBlocker::segments()` scans; script text and HTML comments are never searched for iframes.
6. **An empty or unreadable chain is not "intact".** `EMPTY_CHAIN` is `NEEDS_CONFIG` for the hub; a whole-chain deletion is `CHAIN_TRUNCATED`. Missing data never becomes a good result.
7. **The chain proves integrity after write, not authenticity.** `/record` is public. A record is not proof that a particular person consented, and nothing in the UI or docs may say it is.
8. **Nothing is repaired to make the chain verify** — including rows a database copy brought from another install, which carry that site's key and cannot verify here.
9. **`MerkleTreeEngine` is a linear hash chain despite the name.** It is not a Merkle tree; no inclusion proof can be produced from it.
10. **`tests/test-consent-chain.php` truncates both tables.** Disposable WordPress only.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-sovereign-consent-vault --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**19 PHP files, 4748 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 174 |
| `includes` | 1 | 33 |
| `includes/API` | 1 | 283 |
| `includes/Adapters` | 1 | 179 |
| `includes/Admin` | 2 | 1077 |
| `includes/Blocker` | 2 | 628 |
| `includes/CLI` | 1 | 56 |
| `includes/Core` | 4 | 563 |
| `includes/Crypto` | 1 | 744 |
| `includes/Database` | 1 | 126 |
| `includes/Frontend` | 2 | 409 |
| `includes/Services` | 1 | 476 |

---

## Classes

| Class | File |
|---|---|
| `AdminDashboard` | [`includes/Admin/AdminDashboard.php`](../includes/Admin/AdminDashboard.php) |
| `Categories` | [`includes/Core/Categories.php`](../includes/Core/Categories.php) |
| `ConsentBanner` | [`includes/Frontend/ConsentBanner.php`](../includes/Frontend/ConsentBanner.php) |
| `ConsentCommand` | [`includes/CLI/ConsentCommand.php`](../includes/CLI/ConsentCommand.php) |
| `ConsentCookie` | [`includes/Core/ConsentCookie.php`](../includes/Core/ConsentCookie.php) |
| `Consent_Vault_Spoke_Adapter` | [`includes/Adapters/Consent_Vault_Spoke_Adapter.php`](../includes/Adapters/Consent_Vault_Spoke_Adapter.php) |
| `EcosystemIntegration` | [`includes/Frontend/EcosystemIntegration.php`](../includes/Frontend/EcosystemIntegration.php) |
| `MerkleTreeEngine` | [`includes/Crypto/MerkleTreeEngine.php`](../includes/Crypto/MerkleTreeEngine.php) |
| `OutputBlocker` | [`includes/Blocker/OutputBlocker.php`](../includes/Blocker/OutputBlocker.php) |
| `Plugin` | [`includes/Core/Plugin.php`](../includes/Core/Plugin.php) |
| `RestController` | [`includes/API/RestController.php`](../includes/API/RestController.php) |
| `Schema` | [`includes/Database/Schema.php`](../includes/Database/Schema.php) |
| `ScriptIframeBlocker` | [`includes/Blocker/ScriptIframeBlocker.php`](../includes/Blocker/ScriptIframeBlocker.php) |
| `ServiceRegistry` | [`includes/Services/ServiceRegistry.php`](../includes/Services/ServiceRegistry.php) |
| `Settings` | [`includes/Core/Settings.php`](../includes/Core/Settings.php) |
| `SettingsScreen` | [`includes/Admin/SettingsScreen.php`](../includes/Admin/SettingsScreen.php) |

---

## Entry points

**REST routes:** `/nonce`, `/record`, `/verify-merkle`

**WP-CLI:** `consent`

**Shortcodes:** `wm_consent_settings`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_consent_delete_data_on_uninstall` | — | `uninstall.php` |

**Read but never written by this plugin:** `wm_consent_delete_data_on_uninstall`. The behaviour behind them is reachable only through `wp option update` or a filter — there is no screen that sets them, so an operator cannot switch it on.


**Tables** (prefixed with `$wpdb->prefix`): `wm_consent_merkle_nodes`, `wm_consent_records`

---

## Gates

- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-consent-chain.php`
- `tests/test-pot-freshness.php`
- `tests/test-suite.php`
- `tests/test-ui-family.php`
- `tests/wp-runtime-consent.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.

