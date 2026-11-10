/**
 * Global locale object, ready for synchronous frontend translation reads.
 * Frontend headers map translation modules to classes/LocaleInstance to avoid a circular dependency.
 */
(function () {
    'use strict';

    const dependencies = ['classes/LocaleInstance'];
    const frontend = window.QUIQQER_FRONTEND === 1;

    if (frontend && Array.isArray(window.QUIQQER_LOCALE)) {
        dependencies.push(...window.QUIQQER_LOCALE);
    }

    define('Locale', dependencies, function (Locale) {
        if (frontend && window.QUIQQER_PROJECT?.lang) {
            Locale.setCurrent(window.QUIQQER_PROJECT.lang);
        }

        return Locale;
    });
})();
