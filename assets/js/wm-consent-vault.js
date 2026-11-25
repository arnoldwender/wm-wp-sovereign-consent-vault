/**
 * WM Sovereign Consent Vault — consent dialog, stored decision, activation of held-back scripts and embeds.
 *
 * Configuration: the JSON data block #wm-consent-config written by ConsentBanner::config() (policy version, consent
 * duration, REST routes, categories, services with their cookies).
 *
 * Browser storage (localStorage, every access guarded):
 *   wm_consent_uuid, wm_consent_analytics, wm_consent_marketing, wm_consent_recorded — since the first release;
 *   wm_consent_categories, wm_consent_policy_version, wm_consent_decided_at — since the categories (2026-09-17).
 * Cookie `wm_consent`: "<policy version>|<categories>|<seconds>", read by wm_consent_has() in PHP.
 *
 * Developer API: window.wmConsent.open(), window.wmConsent.has(category), window.wmConsent.categories(); the event
 * `wm-consent:decision` on document (detail: { categories, source: 'stored' | 'banner' }); any element with
 * `data-wm-consent-open` or a link to `#wm-consent-settings` opens the dialog.
 */

// ---------------------------------------------------------------------------------------------- storage helpers

// Reading localStorage throws where the browser blocks site data; the banner used to stay hidden
// for those visitors, so they never saw it at all.
function wmConsentRead(key) {
	try { return localStorage.getItem(key); } catch (e) { return null; }
}
function wmConsentWrite(key, value) {
	try { localStorage.setItem(key, value); } catch (e) {}
}
function wmConsentForget(key) {
	try { localStorage.removeItem(key); } catch (e) {}
}

// ---------------------------------------------------------------------------------------------- configuration

/** Parsed #wm-consent-config, or null on a page without the dialog (then nothing is opened or activated). */
function wmConsentConfig() {
	if (wmConsentConfig.cache !== undefined) {
		return wmConsentConfig.cache;
	}
	var node = document.getElementById('wm-consent-config');
	var parsed = null;
	if (node) {
		try { parsed = JSON.parse(node.textContent || '{}'); } catch (e) { parsed = null; }
	}
	if (parsed && (!Array.isArray(parsed.categories) || typeof parsed.policyVersion !== 'string')) {
		parsed = null;
	}
	wmConsentConfig.cache = parsed;
	return parsed;
}

/** The slug of a category name or alias, or null. */
function wmConsentNormalize(name) {
	var cfg = wmConsentConfig();
	var slug = String(name || '').trim().toLowerCase();
	var slugs = cfg ? cfg.categories : ['necessary', 'statistics', 'marketing', 'media'];
	if (slugs.indexOf(slug) !== -1) {
		return slug;
	}
	var aliases = cfg && cfg.aliases ? cfg.aliases : {};
	return Object.prototype.hasOwnProperty.call(aliases, slug) ? aliases[slug] : null;
}

/** Known slugs, no duplicates, canonical order, `necessary` always included. */
function wmConsentCanonical(names) {
	var cfg = wmConsentConfig();
	var slugs = cfg ? cfg.categories : ['necessary', 'statistics', 'marketing', 'media'];
	var chosen = { necessary: true };
	(names || []).forEach(function (name) {
		var slug = wmConsentNormalize(name);
		if (slug) {
			chosen[slug] = true;
		}
	});
	return slugs.filter(function (slug) { return chosen[slug] === true; });
}

// ---------------------------------------------------------------------------------------------- stored decision

/** The decision this browser holds, current or not; null when there is none. */
function wmConsentStoredDecision() {
	var uuid = wmConsentRead('wm_consent_uuid');
	if (!uuid) {
		return null;
	}
	var stored = wmConsentRead('wm_consent_categories');
	var categories;
	if (stored === null) {
		// A decision from the two-button banner: statistics and marketing from its flags, no policy version.
		categories = ['necessary'];
		if (wmConsentRead('wm_consent_analytics') === '1') {
			categories.push('statistics');
		}
		if (wmConsentRead('wm_consent_marketing') === '1') {
			categories.push('marketing');
		}
	} else {
		categories = wmConsentCanonical(stored.split(','));
	}
	return {
		uuid: uuid,
		categories: wmConsentCanonical(categories),
		policyVersion: wmConsentRead('wm_consent_policy_version'),
		decidedAt: parseInt(wmConsentRead('wm_consent_decided_at'), 10) || 0
	};
}

/**
 * Whether a stored decision still counts: given for the current policy version and younger than the consent
 * duration. A decision that does not count activates nothing and the dialog asks again; that covers a raised policy
 * version, an expired decision and every decision of the two-button banner (it has no policy version).
 */
function wmConsentIsCurrent(decision) {
	var cfg = wmConsentConfig();
	if (!cfg || !decision || decision.policyVersion !== cfg.policyVersion || !decision.decidedAt) {
		return false;
	}
	var age = Date.now() - decision.decidedAt;
	// Five minutes of clock drift into the future are tolerated, as in ConsentCookie::decision().
	return age > -300000 && age < cfg.durationDays * 86400000;
}

/** The categories of the current decision; only `necessary` without one. */
function wmConsentCurrentCategories() {
	var decision = wmConsentStoredDecision();
	return decision && wmConsentIsCurrent(decision) ? decision.categories : ['necessary'];
}

/** A decision id matching the route's pattern [A-Za-z0-9_-]{8,64}. */
function wmConsentNewId() {
	if (window.crypto && typeof window.crypto.randomUUID === 'function') {
		return window.crypto.randomUUID();
	}
	return 'c_' + Math.random().toString(36).slice(2, 11) + Date.now().toString(36);
}

/** The cookie wm_consent_has() reads; lives as long as the consent duration. */
function wmConsentWriteCookie(categories, decidedAt) {
	var cfg = wmConsentConfig();
	var value = encodeURIComponent(cfg.policyVersion + '|' + categories.join(',') + '|' + Math.floor(decidedAt / 1000));
	document.cookie = cfg.cookieName + '=' + value + '; path=/; max-age=' + (cfg.durationDays * 86400) + '; SameSite=Lax' + (cfg.secure ? '; Secure' : '');
}

/**
 * Delete the cookies registered for services of revoked categories. A name ending in `*` covers every cookie starting
 * with the rest. Each is expired on the host and on every parent domain, where trackers usually set them; cookies of
 * another domain (an embedded provider's own) cannot be reached from this page.
 */
function wmConsentDeleteCookies(patterns) {
	if (!patterns.length || !document.cookie) {
		return;
	}
	var parts = location.hostname.split('.');
	var domains = [''];
	for (var i = 0; i < parts.length - 1; i++) {
		domains.push('; domain=.' + parts.slice(i).join('.'));
	}
	document.cookie.split(';').forEach(function (pair) {
		var name = pair.split('=')[0].trim();
		var matches = patterns.some(function (pattern) {
			return pattern.slice(-1) === '*' ? name.indexOf(pattern.slice(0, -1)) === 0 : name === pattern;
		});
		if (!matches) {
			return;
		}
		domains.forEach(function (domain) {
			document.cookie = name + '=; path=/; max-age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT' + domain;
		});
	});
}

// ---------------------------------------------------------------------------------------------- recording

function wmSendConsent(uuid, analytics, marketing) {
	var cfg = wmConsentConfig();
	if (!cfg) {
		return Promise.resolve();
	}
	var body = { uuid: uuid, essential: true, analytics: analytics, marketing: marketing };
	// Categories and policy version travel only with the decision they belong to: a decision stored by the
	// two-button banner has neither and is recorded with categories derived from its flags.
	if (wmConsentRead('wm_consent_uuid') === uuid && wmConsentRead('wm_consent_categories') !== null) {
		body.categories = wmConsentCanonical(wmConsentRead('wm_consent_categories').split(','));
		var version = wmConsentRead('wm_consent_policy_version');
		if (version) {
			body.policy_version = version;
		}
	}
	// The nonce is fetched, not baked into the page: a full-page cache would serve a
	// stale one and every consent after 24 h would be refused by the vault.
	return fetch(cfg.nonceUrl, { credentials: 'same-origin', cache: 'no-store' })
		.then(function (r) { return r.json(); })
		.then(function (n) {
			return fetch(cfg.recordUrl, {
				method: 'POST',
				credentials: 'same-origin',
				// keepalive: a revocation reloads the page right after this request; it must still arrive.
				keepalive: true,
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (n && n.nonce) ? n.nonce : '' },
				body: JSON.stringify(body)
			});
		})
		.then(function (r) {
			// 409: this decision is already in the log, which is what the flag records.
			if (r && (r.ok || r.status === 409)) {
				wmConsentWrite('wm_consent_recorded', '1');
			}
		})
		.catch(function () {});
}

/**
 * Store a decision, record it, and apply it: activate what it allows, or — when it takes back a category the
 * previous decision granted — delete that category's cookies and reload, because scripts that already ran cannot be
 * unloaded.
 */
function wmConsentSave(names) {
	var cfg = wmConsentConfig();
	if (!cfg) {
		return;
	}
	var categories = wmConsentCanonical(names);
	var previous = wmConsentCurrentCategories();
	var analytics = categories.indexOf('statistics') !== -1;
	var marketing = categories.indexOf('marketing') !== -1;
	var uuid = wmConsentNewId();
	var decidedAt = Date.now();

	wmConsentWrite('wm_consent_uuid', uuid);
	wmConsentWrite('wm_consent_analytics', analytics ? '1' : '0');
	wmConsentWrite('wm_consent_marketing', marketing ? '1' : '0');
	wmConsentWrite('wm_consent_categories', categories.join(','));
	wmConsentWrite('wm_consent_policy_version', cfg.policyVersion);
	wmConsentWrite('wm_consent_decided_at', String(decidedAt));
	wmConsentForget('wm_consent_recorded');
	wmConsentWriteCookie(categories, decidedAt);

	wmConsentClose();
	var sent = wmSendConsent(uuid, analytics, marketing);
	wmConsentAnnounce(categories, 'banner');

	var revoked = previous.filter(function (category) {
		return category !== 'necessary' && categories.indexOf(category) === -1;
	});
	if (revoked.length) {
		var cookies = [];
		(cfg.services || []).forEach(function (service) {
			if (revoked.indexOf(service.category) !== -1) {
				cookies = cookies.concat(service.cookies || []);
			}
		});
		wmConsentDeleteCookies(cookies);
		var reloaded = false;
		var reload = function () {
			if (!reloaded) {
				reloaded = true;
				location.reload();
			}
		};
		sent.then(reload, reload);
		setTimeout(reload, 4000);
		return;
	}
	wmConsentActivate(categories);
}

/** Kept for code written against the two-button banner: accepting both flags accepts every category. */
function wmSubmitConsent(analytics, marketing, media) {
	var names = ['necessary'];
	if (analytics) {
		names.push('statistics');
	}
	if (marketing) {
		names.push('marketing');
	}
	if (media === undefined ? (analytics && marketing) : media) {
		names.push('media');
	}
	wmConsentSave(names);
}

function wmConsentAnnounce(categories, source) {
	var detail = { categories: categories.slice(), source: source };
	var event;
	try {
		event = new CustomEvent('wm-consent:decision', { detail: detail });
	} catch (e) {
		return;
	}
	document.dispatchEvent(event);
}

// ---------------------------------------------------------------------------------------------- activation

/**
 * Activate every held-back element of the given categories, in document order. An external script that was not
 * async is awaited before the next element runs, so an inline snippet that depends on it finds it loaded.
 */
function wmConsentActivate(categories) {
	var granted = {};
	categories.forEach(function (category) { granted[category] = true; });
	var selector = 'script[type="text/plain"][data-wm-consent-category], script[type="text/plain"][data-wm-consent],'
		+ ' iframe[data-wm-consent-src][data-wm-consent-category], iframe[data-wm-consent-src][data-wm-consent],'
		+ ' .wm-2click-wrapper[data-wm-consent-category]';
	var queue = Array.prototype.filter.call(document.querySelectorAll(selector), function (node) {
		if (node.hasAttribute('data-wm-consent-activated')) {
			return false;
		}
		var category = wmConsentNormalize(node.getAttribute('data-wm-consent-category') || node.getAttribute('data-wm-consent'));
		return category !== null && category !== 'necessary' && granted[category] === true;
	});

	return new Promise(function (resolve) {
		var next = function (index) {
			if (index >= queue.length) {
				resolve();
				return;
			}
			var node = queue[index];
			node.setAttribute('data-wm-consent-activated', '1');
			if (node.tagName === 'SCRIPT') {
				if (!wmConsentRunScript(node, function () { next(index + 1); })) {
					next(index + 1);
				}
			} else if (node.tagName === 'IFRAME') {
				node.setAttribute('src', node.getAttribute('data-wm-consent-src'));
				node.removeAttribute('data-wm-consent-src');
				next(index + 1);
			} else {
				wmLoadEmbed(node, false);
				next(index + 1);
			}
		};
		next(0);
	});
}

/**
 * Replace a held-back script with an executable copy. Returns true when the caller has to wait for `done`.
 * The copy keeps every attribute except the blocking ones, the original type (e.g. module) and the CSP nonce.
 */
function wmConsentRunScript(blocked, done) {
	if (!blocked.parentNode) {
		return false;
	}
	var script = document.createElement('script');
	var src = blocked.getAttribute('data-wm-consent-src') || blocked.getAttribute('src') || '';
	Array.prototype.forEach.call(blocked.attributes, function (attribute) {
		var name = attribute.name;
		if (name === 'type' || name === 'src' || name === 'data-wm-consent' || name.indexOf('data-wm-consent-') === 0) {
			return;
		}
		script.setAttribute(name, attribute.value);
	});
	var originalType = blocked.getAttribute('data-wm-consent-type');
	if (originalType) {
		script.type = originalType;
	}
	if (blocked.nonce) {
		script.nonce = blocked.nonce;
	}

	var wait = false;
	if (src) {
		script.src = src;
		if (!blocked.hasAttribute('async')) {
			// Inserted scripts are async by default; async = false keeps the order of execution.
			script.async = false;
			wait = true;
			var finished = false;
			var finish = function () {
				if (!finished) {
					finished = true;
					done();
				}
			};
			script.addEventListener('load', finish);
			script.addEventListener('error', finish);
			// A request that never settles must not hold back every script after it.
			setTimeout(finish, 10000);
		}
	} else {
		script.text = blocked.text;
	}
	blocked.parentNode.replaceChild(script, blocked);
	return wait;
}

/**
 * Load a click-to-load placeholder. The source, title and height travel in data attributes of the wrapper the
 * content filter wrote, so no URL is printed inside an inline handler.
 */
function wmLoadEmbed(wrapper, moveFocus) {
	const src = wrapper.getAttribute('data-wm-embed-src') || '';
	if (!/^https:\/\//.test(src) || !wrapper.parentNode) {
		return;
	}
	const iframe = document.createElement('iframe');
	iframe.src = src;
	iframe.title = wrapper.getAttribute('data-wm-embed-title') || '';
	iframe.loading = 'lazy';
	iframe.referrerPolicy = 'strict-origin-when-cross-origin';
	iframe.className = 'wm-2click-frame';
	// The height is per embed, so it is the one value set on the element; width and border come from the sheet.
	iframe.style.height = (parseInt(wrapper.getAttribute('data-wm-embed-height'), 10) || 400) + 'px';
	iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
	iframe.allowFullscreen = true;
	wrapper.parentNode.replaceChild(iframe, wrapper);
	if (moveFocus) {
		// The button that had focus is gone with the placeholder; keyboard users continue in the embed.
		iframe.setAttribute('tabindex', '-1');
		iframe.focus();
	}
}

// ---------------------------------------------------------------------------------------------- dialog

var wmConsentDialogState = { mode: null, returnFocus: null };

function wmConsentRoot() {
	return document.getElementById('wm-consent-vault-banner');
}

function wmConsentDialog() {
	var root = wmConsentRoot();
	return root ? root.querySelector('[role="dialog"]') : null;
}

/**
 * Open the dialog. `first`: no current decision, nothing closes it except a choice. `reopen`: opened on purpose
 * (shortcode, link, API); Escape and the close button leave without changing the decision.
 */
function wmConsentOpen(mode) {
	var root = wmConsentRoot();
	var dialog = wmConsentDialog();
	if (!root || !dialog || !wmConsentConfig()) {
		return;
	}
	if (root.hidden) {
		wmConsentDialogState.returnFocus = document.activeElement;
	}
	wmConsentDialogState.mode = mode === 'first' ? 'first' : 'reopen';
	wmConsentShowView('main');
	wmConsentSyncSwitches();
	var close = root.querySelector('[data-wm-consent-action="close"]');
	if (close) {
		close.hidden = wmConsentDialogState.mode === 'first';
	}
	root.hidden = false;
	document.documentElement.classList.add('wm-consent-is-open');
	dialog.focus();
}

/** Hide the dialog and give focus back to the element that had it before it opened. */
function wmConsentClose() {
	var root = wmConsentRoot();
	if (!root || root.hidden) {
		return;
	}
	root.hidden = true;
	document.documentElement.classList.remove('wm-consent-is-open');
	var target = wmConsentDialogState.returnFocus;
	wmConsentDialogState.returnFocus = null;
	wmConsentDialogState.mode = null;
	if (target && typeof target.focus === 'function' && target !== document.body && document.contains(target)) {
		target.focus();
	}
}

function wmConsentShowView(name) {
	var root = wmConsentRoot();
	Array.prototype.forEach.call(root.querySelectorAll('[data-wm-consent-view]'), function (view) {
		view.hidden = view.getAttribute('data-wm-consent-view') !== name;
	});
	var toggle = root.querySelector('[data-wm-consent-action="settings"]');
	if (toggle) {
		toggle.setAttribute('aria-expanded', name === 'settings' ? 'true' : 'false');
	}
}

/** Switches show the current decision; on a first visit every optional category starts switched off. */
function wmConsentSyncSwitches() {
	var current = wmConsentCurrentCategories();
	Array.prototype.forEach.call(wmConsentRoot().querySelectorAll('[data-wm-consent-category-input]'), function (input) {
		if (!input.disabled) {
			input.checked = current.indexOf(input.value) !== -1;
		}
	});
}

function wmConsentFocusable(container) {
	var selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
	return Array.prototype.filter.call(container.querySelectorAll(selector), function (element) {
		return !element.closest('[hidden]') && element.getClientRects().length > 0;
	});
}

/** The page must not suggest a choice where it explains one: no automatic dialog on the privacy page or legal notice. */
function wmConsentSuppressed() {
	var cfg = wmConsentConfig();
	var here = location.pathname.replace(/\/+$/, '');
	return (cfg.suppressOn || []).some(function (path) {
		return String(path).replace(/\/+$/, '') === here;
	});
}

// ---------------------------------------------------------------------------------------------- events

document.addEventListener('click', function (event) {
	var target = event.target instanceof Element ? event.target : null;
	if (!target) {
		return;
	}

	// Click-to-load placeholders.
	const button = target.closest('.wm-2click-unlock');
	if (button) {
		const wrapper = button.closest('.wm-2click-wrapper');
		if (wrapper) {
			wmLoadEmbed(wrapper, true);
		}
		return;
	}

	// Anything that opens the dialog: [wm_consent_settings], the placeholder's second button, a menu link.
	if (target.closest('[data-wm-consent-open], a[href="#wm-consent-settings"]')) {
		event.preventDefault();
		wmConsentOpen('reopen');
		return;
	}

	var action = target.closest('[data-wm-consent-action]');
	var root = wmConsentRoot();
	var cfg = wmConsentConfig();
	if (!action || !root || !cfg || !root.contains(action)) {
		return;
	}
	switch (action.getAttribute('data-wm-consent-action')) {
		case 'accept-all':
			wmConsentSave(cfg.categories);
			break;
		case 'necessary':
			wmConsentSave(['necessary']);
			break;
		case 'save':
			wmConsentSave(Array.prototype.filter.call(root.querySelectorAll('[data-wm-consent-category-input]'), function (input) {
				return input.checked;
			}).map(function (input) { return input.value; }));
			break;
		case 'settings':
			wmConsentShowView('settings');
			document.getElementById('wm-consent-vault-settings-title').focus();
			break;
		case 'back':
			wmConsentShowView('main');
			root.querySelector('[data-wm-consent-action="settings"]').focus();
			break;
		case 'close':
			wmConsentClose();
			break;
	}
});

// Focus trap: Tab and Shift+Tab cycle inside the dialog; Escape goes back from the settings, and closes a dialog that
// was opened on purpose. A first-visit dialog has no Escape: leaving it without a choice is not a choice.
document.addEventListener('keydown', function (event) {
	var root = wmConsentRoot();
	var dialog = wmConsentDialog();
	if (!root || root.hidden || !dialog) {
		return;
	}
	if (event.key === 'Escape') {
		var settings = root.querySelector('[data-wm-consent-view="settings"]');
		if (settings && !settings.hidden) {
			event.preventDefault();
			wmConsentShowView('main');
			root.querySelector('[data-wm-consent-action="settings"]').focus();
		} else if (wmConsentDialogState.mode === 'reopen') {
			event.preventDefault();
			wmConsentClose();
		}
		return;
	}
	if (event.key !== 'Tab') {
		return;
	}
	var focusable = wmConsentFocusable(dialog);
	if (!focusable.length) {
		event.preventDefault();
		dialog.focus();
		return;
	}
	var first = focusable[0];
	var last = focusable[focusable.length - 1];
	var active = document.activeElement;
	if (!dialog.contains(active) || active === dialog) {
		event.preventDefault();
		(event.shiftKey ? last : first).focus();
	} else if (event.shiftKey && active === first) {
		event.preventDefault();
		last.focus();
	} else if (!event.shiftKey && active === last) {
		event.preventDefault();
		first.focus();
	}
});

// Focus that lands outside the open dialog (a screen reader's virtual cursor, a script) is brought back.
document.addEventListener('focusin', function (event) {
	var root = wmConsentRoot();
	var dialog = wmConsentDialog();
	if (root && dialog && !root.hidden && !root.contains(event.target)) {
		dialog.focus();
	}
});

// ---------------------------------------------------------------------------------------------- start

window.wmConsent = {
	open: function () { wmConsentOpen('reopen'); },
	has: function (category) {
		var slug = wmConsentNormalize(category);
		return slug !== null && wmConsentCurrentCategories().indexOf(slug) !== -1;
	},
	categories: function () { return wmConsentCurrentCategories().slice(); }
};

function wmConsentInit() {
	if (!wmConsentConfig()) {
		return;
	}
	var decision = wmConsentStoredDecision();
	// A decision whose request never arrived (offline, 403 on an expired token, 503) used to be
	// lost: the banner stayed hidden and nothing reached the log. It is sent again on the next
	// page view until the server confirms it.
	if (decision) {
		if (wmConsentRead('wm_consent_recorded') !== '1') {
			wmSendConsent(decision.uuid, decision.categories.indexOf('statistics') !== -1, decision.categories.indexOf('marketing') !== -1);
		}
	}
	if (decision && wmConsentIsCurrent(decision)) {
		wmConsentActivate(decision.categories);
		wmConsentAnnounce(decision.categories, 'stored');
		return;
	}
	if (!wmConsentSuppressed()) {
		wmConsentOpen('first');
	}
}

// Scripts other plugins print after this one are only in the DOM once parsing is done.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', wmConsentInit);
} else {
	wmConsentInit();
}
