(function () {
    'use strict';

    var storageKey = 'karoor-theme';
    var root = document.documentElement;
    var media = window.matchMedia('(prefers-color-scheme: light)');

    function storedTheme() {
        try {
            var value = window.localStorage.getItem(storageKey);
            return value === 'light' || value === 'dark' ? value : null;
        } catch (error) {
            return null;
        }
    }

    function preferredTheme() {
        return storedTheme() || (media.matches ? 'light' : 'dark');
    }

    function applyTheme(theme, persist) {
        var next = theme === 'light' ? 'light' : 'dark';
        root.setAttribute('data-bs-theme', next);
        root.style.colorScheme = next;
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', next === 'light' ? '#f4f7fb' : '#0b1120');
        }
        if (persist) {
            try {
                window.localStorage.setItem(storageKey, next);
            } catch (error) {
                // A private browsing policy may disable persistent storage.
            }
        }
        document.dispatchEvent(new CustomEvent('karoor:theme-change', { detail: { theme: next } }));
        return next;
    }

    applyTheme(preferredTheme(), false);
    media.addEventListener('change', function () {
        if (storedTheme() === null) {
            applyTheme(preferredTheme(), false);
        }
    });

    window.KaroorTheme = Object.freeze({
        current: function () { return root.getAttribute('data-bs-theme') || preferredTheme(); },
        set: function (theme) { return applyTheme(theme, true); },
        toggle: function () { return applyTheme(root.getAttribute('data-bs-theme') === 'light' ? 'dark' : 'light', true); }
    });
}());
