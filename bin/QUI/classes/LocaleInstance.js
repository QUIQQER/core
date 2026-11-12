/**
 * Internal locale instance used while published translations are registered.
 * Controls use Locale, which also waits for the initial frontend translations.
 */
define('classes/LocaleInstance', ['classes/Locale'], function (QUILocale) {
    'use strict';

    if (window.location.search.match('lang=false')) {
        QUILocale.no_translation = true;
    } else if (window.location.toString().match('_lang_false')) {
        QUILocale.no_translation = true;
    }

    return new QUILocale();
});
