/**
 * Rocket Maps theme — tiny runtime.
 *
 * Three responsibilities:
 *   1. Mobile burger toggle (header nav drawer).
 *   2. Dark / light theme toggle, persisted to localStorage and
 *      honouring `prefers-color-scheme` on first visit.
 *   3. Engine switcher click handler — uses History API to flip
 *      `?rmaps_engine=<slug>` and triggers a page reload so the
 *      plugin re-renders under the new engine. The buttons are
 *      real anchors with `href` set server-side, so this just
 *      keeps the URL clean (drops other params we don't need to
 *      touch) and adds a small loading state.
 *
 * No build step — single ES2017-compatible IIFE.
 */
(function () {
	'use strict';

	var data = (typeof window !== 'undefined' && window.rmapsThemeData) || {};

	/* ------------------------------------------------
	 * Theme (dark/light) toggle
	 * ------------------------------------------------*/
	var STORAGE_KEY = 'rmaps-theme';

	function applyTheme(theme) {
		document.documentElement.setAttribute('data-theme', theme);
		var btn = document.querySelector('.rmaps-theme-toggle');
		if (btn) {
			btn.setAttribute('aria-label',
				theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
			btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
		}
		// Tell the plugin's React MapApp(s) to swap the rendered map
		// style to its per-engine dark/light preset — no reload. The
		// listener lives in
		// `react/src/maps/components/Map/MapApp.tsx` and PATCH_CONFIGs
		// `map_style` from `window.rmapsDarkStyles`. Wrapped in a
		// try/catch because (a) very old browsers without CustomEvent
		// would throw, and (b) the plugin may not be on this page so
		// nobody is listening — both cases are non-fatal.
		try {
			window.dispatchEvent(new CustomEvent('rmaps:set-map-theme', {
				detail: { theme: theme }
			}));
		} catch (_) { /* no-op */ }
	}

	function initTheme() {
		var stored = null;
		try { stored = localStorage.getItem(STORAGE_KEY); } catch (_) {}
		var theme;
		if (stored === 'dark' || stored === 'light') {
			theme = stored;
		} else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
			theme = 'dark';
		} else {
			theme = 'light';
		}
		applyTheme(theme);

		var btn = document.querySelector('.rmaps-theme-toggle');
		if (!btn) return;
		btn.addEventListener('click', function () {
			var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
			applyTheme(next);
			try { localStorage.setItem(STORAGE_KEY, next); } catch (_) {}
		});
	}

	/* ------------------------------------------------
	 * Burger menu
	 * ------------------------------------------------*/
	function initBurger() {
		var burger = document.querySelector('.rmaps-burger');
		var nav    = document.getElementById('rmaps-primary-menu');
		if (!burger || !nav) return;

		burger.addEventListener('click', function () {
			var open = nav.classList.toggle('is-open');
			burger.setAttribute('aria-expanded', open ? 'true' : 'false');
		});

		// Close on outside click — feels right on mobile when an
		// element behind the drawer is tapped.
		document.addEventListener('click', function (e) {
			if (!nav.classList.contains('is-open')) return;
			if (nav.contains(e.target) || burger.contains(e.target)) return;
			nav.classList.remove('is-open');
			burger.setAttribute('aria-expanded', 'false');
		});

		// Close on Escape
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && nav.classList.contains('is-open')) {
				nav.classList.remove('is-open');
				burger.setAttribute('aria-expanded', 'false');
				burger.focus();
			}
		});
	}

	/* ------------------------------------------------
	 * Engine switcher click handling
	 * Server-rendered anchors already point at the right URL; this
	 * just (a) adds a loading class for visual feedback and (b)
	 * lets us hook analytics later without re-rendering.
	 * ------------------------------------------------*/
	function initEngineSwitcher() {
		var buttons = document.querySelectorAll('.rmaps-engine-button[data-engine]');
		if (!buttons.length) return;

		Array.prototype.forEach.call(buttons, function (btn) {
			btn.addEventListener('click', function (e) {
				if (btn.classList.contains('is-active')) {
					e.preventDefault();
					return;
				}
				// Same-tab navigation only — modifier keys (ctrl/cmd
				// for "new tab", shift for "new window") fall through
				// to the browser's default handling.
				if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

				var wrap = btn.closest('.rmaps-engine-switcher');
				if (wrap) wrap.classList.add('is-loading');

				if (!data.urlOverrideActive) {
					console.warn('[rmaps-theme] RMAPS_ALLOW_ENGINE_URL_OVERRIDE is not enabled in wp-config.php — the plugin will ignore the URL parameter.');
				}
			});
		});
	}

	/* ------------------------------------------------
	 * Boot
	 * ------------------------------------------------*/
	function boot() {
		initTheme();
		initBurger();
		initEngineSwitcher();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
