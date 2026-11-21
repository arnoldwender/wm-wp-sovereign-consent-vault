# Security Policy — WM Sovereign Consent Vault (TDDDG § 25 & DSGVO)

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.2.x` (`1.2.2`) | Current release branch |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private inquiries or support emails to `arnoldwender.com`, email addresses, or social/direct messages (private channels are unmonitored for open-source plugins).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/wm-wp-sovereign-consent-vault/security/advisories/new](https://github.com/arnoldwender/wm-wp-sovereign-consent-vault/security/advisories/new)

> **Best-Effort Only / Zero Warranty / Zero Support:** This open-source repository carries **zero technical or user support** ([SUPPORT.md](SUPPORT.md)) and **zero response-time SLA**. Advisory reports may be reviewed at the author's sole discretion without obligation, timeline, or liability ("AS IS" under [LICENSE](LICENSE)).

### What to include

1. Reproduction steps or a proof of concept.
2. The affected component: the banner script, `/nonce`, `/record` or `/verify-merkle`, the page-wide blocker, the placeholder filter, the chain engine, the settings or services screen and their `admin_post_` handlers, WP-CLI or the hub adapter.
3. The impact you observed (for example a forged or altered record that still verifies, a script of a registered service that loads before its category is accepted, stored XSS, a bypass of the rate limit or of a form's capability and nonce check).

### Attack surface

- **Public:** `GET /wm-consent/v1/nonce`, `POST /wm-consent/v1/record` (REST nonce, 10 requests per minute per client, uuid pattern, declared categories and policy version), the banner script and the `wm_consent` cookie it writes, the rewritten markup of every front-end page.
- **Administrators only (`manage_options`):** `/verify-merkle`, the admin screen, the dashboard widget, and the five `admin_post_` handlers of the settings and services forms, each behind its own nonce.
- **Stored data:** two tables (keyed hash of the IP address, user agent, decision flags, categories, policy version, signatures) and five options. Anyone with the site key (`AUTH_KEY`) can rewrite the chain consistently; the chain detects changes made without it.
- **What the blocking is not:** the decision lives in the visitor's browser, so the visitor can always load what they want. Blocking keeps a site from loading a registered service before consent; it is not a defence against the visitor.

Reports are acknowledged as soon as they are read; there is no contractual response time.
