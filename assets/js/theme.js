/**
 * Portal theme: light / dark / system (localStorage key: portal-theme)
 */
(function () {
    'use strict';

    var KEY = 'portal-theme';

    function getPref() {
        try {
            return localStorage.getItem(KEY) || 'system';
        } catch (e) {
            return 'system';
        }
    }

    function resolve(pref) {
        if (pref === 'dark') return 'dark';
        if (pref === 'light') return 'light';
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function apply(pref) {
        var theme = resolve(pref);
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.documentElement.setAttribute('data-theme-pref', pref);
        try {
            localStorage.setItem(KEY, pref);
        } catch (e) { /* ignore */ }
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            btn.title = theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему';
        });
    }

    function toggle() {
        var current = resolve(getPref());
        apply(current === 'dark' ? 'light' : 'dark');
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-theme-toggle]');
        if (!btn) return;
        e.preventDefault();
        toggle();
    });

    try {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (getPref() === 'system') apply('system');
        });
    } catch (e) { /* ignore */ }

    apply(getPref());

    window.PortalTheme = {
        apply: apply,
        toggle: toggle,
        getPref: getPref,
        resolve: resolve
    };
})();
