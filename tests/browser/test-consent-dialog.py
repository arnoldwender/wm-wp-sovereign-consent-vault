#!/usr/bin/env python3
"""
tests/browser/test-consent-dialog.py — what only a browser can answer: does the dialog behave, and does the
blocked markup really stay inert until a category is accepted?

It renders the fixture with the plugin's own classes (tests/browser/render-fixture.php), serves it over HTTP
(cookies do not work on file://), and drives Chromium through Playwright. Nothing leaves the machine: the REST
routes and every third-party host are answered by route stubs, and a request to a stubbed host is counted, so
"the script did not run" is measured and not assumed.

Checks: nothing of a registered service runs before a decision · the dialog is modal, keyboard-operable and
keeps the focus · Escape does not dismiss a first visit · the category view saves what was ticked · the
accepted script runs and the refused one does not · the cookie and the localStorage keys carry the decision ·
a reopened dialog shows the stored decision, closes on Escape and gives focus back · accepting external media
loads the placeholder · revoking a category deletes its cookies and reloads.

Usage: python3 tests/browser/test-consent-dialog.py [--headed]
Exit:  0 when every check passed, 1 otherwise, 2 when the fixture could not be built or Playwright is missing.
"""
from __future__ import annotations

import functools
import http.server
import shutil
import subprocess
import sys
import tempfile
import threading
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PASSED: list[str] = []
FAILED: list[str] = []


def check(label: str, ok: bool, detail: str = "") -> None:
    if ok:
        PASSED.append(label)
        print(f"  [PASS] {label}")
    else:
        FAILED.append(label)
        print(f"  [FAIL] {label}" + (f": {detail}" if detail else ""))


def build_fixture(target: Path) -> None:
    """Render the page with the plugin's own classes and put the real assets next to it."""
    html = subprocess.run(
        ["php", str(ROOT / "tests/browser/render-fixture.php")],
        capture_output=True, text=True, check=True,
    ).stdout
    (target / "index.html").write_text(html, encoding="utf-8")
    shutil.copy(ROOT / "assets/css/wm-consent-vault.css", target / "wm-consent-vault.css")
    shutil.copy(ROOT / "assets/js/wm-consent-vault.js", target / "wm-consent-vault.js")
    # A script of no registered service: it must run from the start, or the blocker is blocking everything.
    (target / "neutral.js").write_text("window.__neutral = true;\n", encoding="utf-8")


def serve(directory: Path) -> tuple[http.server.ThreadingHTTPServer, str]:
    handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(directory))
    handler.log_message = lambda *args, **kwargs: None  # type: ignore[assignment]
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server, f"http://127.0.0.1:{server.server_port}/index.html"


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright is not installed: pip install playwright && playwright install chromium")
        return 2

    with tempfile.TemporaryDirectory() as tmp:
        target = Path(tmp)
        try:
            build_fixture(target)
        except subprocess.CalledProcessError as error:
            print("the fixture could not be rendered:\n" + error.stderr)
            return 2
        server, url = serve(target)

        third_party: list[str] = []
        with sync_playwright() as p:
            browser = p.chromium.launch(headless="--headed" not in sys.argv)
            page = browser.new_page()

            # Every outside host is answered here; a request that arrives is recorded, so "nothing loaded" is
            # a measurement.
            def stub(route, body: str, content_type: str):
                third_party.append(route.request.url)
                route.fulfill(status=200, content_type=content_type, body=body)

            rest_calls: list[str] = []

            def rest(route, body: str):
                rest_calls.append(route.request.url)
                route.fulfill(status=200, content_type="application/json", body=body)

            page.route("**/wp-json/wm-consent/v1/nonce", lambda r: rest(r, '{"nonce":"fixture-nonce"}'))
            page.route("**/wp-json/wm-consent/v1/record", lambda r: rest(r, '{"success":true}'))
            page.route("https://www.googletagmanager.com/**", lambda r: stub(r, "window.__ga = true;", "application/javascript"))
            page.route("https://www.youtube-nocookie.com/**", lambda r: stub(r, "<html><body>embed</body></html>", "text/html"))

            page.goto(url, wait_until="networkidle")

            # --- before any decision
            check(
                "nothing of a registered service runs before a decision, everything else does",
                page.evaluate("() => window.__ga === undefined && window.__fb === undefined && window.__neutral === true")
                and not third_party,
                f"third-party requests: {third_party}",
            )
            check(
                "the blocked script keeps its source in a data attribute and the embed is a placeholder",
                page.locator('script[type="text/plain"][data-wm-consent-category="statistics"]').count() == 1
                and page.locator(".wm-2click-wrapper").count() == 1
                and page.locator("iframe").count() == 0,
                page.locator('script[type="text/plain"]').count(),
            )

            dialog = page.locator('[role="dialog"]')
            check(
                "the dialog is open, modal and holds the focus",
                dialog.is_visible() and dialog.get_attribute("aria-modal") == "true"
                and page.evaluate("() => document.querySelector('[role=\"dialog\"]').contains(document.activeElement)"),
                page.evaluate("() => document.activeElement && document.activeElement.className"),
            )
            page.keyboard.press("Escape")
            check("Escape does not dismiss the dialog of a first visit", dialog.is_visible())

            for _ in range(14):
                page.keyboard.press("Tab")
            check(
                "Tab stays inside the dialog (focus trap)",
                page.evaluate("() => document.querySelector('[role=\"dialog\"]').contains(document.activeElement)"),
                page.evaluate("() => document.activeElement && document.activeElement.outerHTML.slice(0, 80)"),
            )

            # --- the category view
            page.click('[data-wm-consent-action="settings"]')
            check(
                "the category view offers one switch per category, necessary on and locked",
                page.locator('[data-wm-consent-category-input]').count() == 4
                and page.is_checked("#wm-consent-category-necessary")
                and page.is_disabled("#wm-consent-category-necessary")
                and not page.is_checked("#wm-consent-category-statistics"),
            )
            page.check("#wm-consent-category-statistics")
            page.click('[data-wm-consent-action="save"]')
            page.wait_for_timeout(400)

            check("the dialog closes after a decision", not dialog.is_visible())
            check(
                "the accepted script runs, the refused one does not",
                page.evaluate("() => window.__ga === true && window.__fb === undefined"),
                page.evaluate("() => [window.__ga, window.__fb]"),
            )
            cookie = next((c for c in page.context.cookies() if c["name"] == "wm_consent"), None)
            check(
                "the cookie carries the policy version and the accepted categories",
                cookie is not None and urllib.parse.unquote(cookie["value"]).startswith("4|necessary,statistics|"),
                str(cookie),
            )
            check(
                "the decision is posted to the log and the browser keeps it with its policy version",
                page.evaluate("() => localStorage.getItem('wm_consent_categories')") == "necessary,statistics"
                and page.evaluate("() => localStorage.getItem('wm_consent_policy_version')") == "4"
                and any(u.endswith("/record") for u in rest_calls) and any(u.endswith("/nonce") for u in rest_calls),
                f"REST calls: {rest_calls} · storage: " + page.evaluate("() => JSON.stringify(Object.fromEntries(Object.entries(localStorage)))"),
            )
            check(
                "external media was not accepted, so the embed is still a placeholder",
                page.locator(".wm-2click-wrapper").count() == 1 and page.locator("iframe").count() == 0,
            )

            # --- reopening
            page.click("[data-wm-consent-open]")
            page.wait_for_timeout(200)
            page.click('[data-wm-consent-action="settings"]')
            check(
                "a reopened dialog shows the stored decision",
                page.is_checked("#wm-consent-category-statistics") and not page.is_checked("#wm-consent-category-marketing"),
            )
            page.keyboard.press("Escape")  # back to the main view
            page.keyboard.press("Escape")  # close the reopened dialog
            page.wait_for_timeout(200)
            check(
                "Escape closes a dialog opened on purpose and gives the focus back to what opened it",
                not dialog.is_visible()
                and page.evaluate("() => document.activeElement && document.activeElement.hasAttribute('data-wm-consent-open')"),
                page.evaluate("() => document.activeElement && document.activeElement.outerHTML.slice(0, 80)"),
            )

            # --- accepting everything
            page.click("[data-wm-consent-open]")
            page.wait_for_timeout(200)
            page.click('[data-wm-consent-action="accept-all"]')
            page.wait_for_timeout(600)
            check(
                "accepting external media loads the placeholder and the marketing snippet runs",
                page.locator("iframe").count() == 1 and page.locator(".wm-2click-wrapper").count() == 0
                and page.evaluate("() => window.__fb === true")
                and any("youtube-nocookie" in u for u in third_party),
                f"third-party requests: {third_party}",
            )

            # --- revoking
            page.evaluate("() => { document.cookie = '_ga=GA1.1.fixture; path=/'; }")
            page.click("[data-wm-consent-open]")
            page.wait_for_timeout(200)
            page.click('[data-wm-consent-action="settings"]')
            page.uncheck("#wm-consent-category-statistics")
            with page.expect_navigation(wait_until="load"):
                page.click('[data-wm-consent-action="save"]')
            page.wait_for_timeout(400)
            names = [c["name"] for c in page.context.cookies()]
            check(
                "revoking a category deletes its cookies and reloads the page",
                "_ga" not in names and page.evaluate("() => window.__ga === undefined"),
                f"cookies after the revocation: {names}",
            )
            check(
                "after the reload the refused category is blocked again and the accepted one is not",
                page.locator('script[type="text/plain"][data-wm-consent-category="statistics"]').count() == 1
                and page.evaluate("() => window.__fb === true"),
                page.evaluate("() => [window.__ga, window.__fb]"),
            )

            browser.close()
        server.shutdown()

    print(f"\n  BROWSER CHECKS: {len(PASSED)} PASSED | {len(FAILED)} FAILED\n")
    return 0 if not FAILED else 1


if __name__ == "__main__":
    sys.exit(main())
