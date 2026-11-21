# Contributing to WM Sovereign Consent Vault

Thank you for your interest in contributing to **WM Sovereign Consent Vault**. As part of the **WM Suite** WordPress plugin family, this repository keeps its documentation measured against its code.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-sovereign-consent-vault/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Core Development Standards

1. **PHP 8.2+ Strict Typing:** Every PHP file starts with `declare(strict_types=1);`.
2. **Zero-CDN Architecture:** No external resources (CDNs, Google Fonts, external telemetry) may be loaded at runtime.
3. **Chain invariants:** every record signature and link is an HMAC with the site key, verified with `hash_equals()`; any change to the signed payload needs a migration and a test that verification still detects altered, missing and truncated rows.
4. **No claims in visitor-facing text:** the banner and the placeholder must not state facts about the operator's site or compliance with a law or judgment.
5. **Admin UI:** WM Suite family sheets, dashicons instead of emoji, visible focus states, `textContent` for server text in scripts.

---

## Development Workflow

```bash
# 1. Clone the repository
git clone https://github.com/arnoldwender/wm-wp-sovereign-consent-vault.git
cd wm-sovereign-consent-vault

# 2. Run the standalone suites
php tests/test-suite.php

# 3. Inside a disposable WordPress only (truncates the consent tables)
wp eval-file wp-content/plugins/wm-sovereign-consent-vault/tests/test-consent-chain.php --allow-root
```

---

## Commit Conventions

- Use the format `[Feature] ...`, `[Fix] ...`, `[Security] ...`, `[Docs] ...`.
- Pull requests that change behaviour include assertions in `tests/test-suite.php`, and update `README.md` and `DEVELOPERS.md` when what the plugin ships changes.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).
