/**
 * quiqqer locale
 * Extends the QUI locale with some quiqqer relevant methods
 */
var needle = ['qui/classes/Locale'];

// intl polyfill
if (typeof window.Intl === 'undefined') {
    define('qui/classes/intl', [
        URL_OPT_DIR + 'bin/intl/dist/Intl.js'
    ], function (Intl) {
        window.Intl         = Intl;
        window.IntlPolyfill = Intl;
    });

    needle.push('intl/en');
    needle.push('intl/de');

    require.config({
        paths: {
            'intl/en': URL_OPT_DIR + 'bin/intl/locale-data/jsonp/en',
            'intl/de': URL_OPT_DIR + 'bin/intl/locale-data/jsonp/de'
        },
        shim : {
            'intl/en': {
                deps: ['qui/classes/intl']
            },
            'intl/de': {
                deps: ['qui/classes/intl']
            }
        }
    });
}

define('classes/Locale', needle, function (QUILocale) {
    "use strict";

    const requests = new Map();
    const instances = new WeakMap();

    function request(moduleId, language, version) {
        if (!requests.has(moduleId)) {
            const pending = new Promise(function (resolve, reject) {
                require([moduleId], resolve, reject);
            }).then(function (payload) {
                if (
                    !payload ||
                    payload.language !== language ||
                    payload.version !== version ||
                    !payload.groups
                ) {
                    throw new Error('Invalid published locale module: ' + moduleId);
                }

                return payload;
            }).catch(function (error) {
                requests.delete(moduleId);
                const loader = window.requirejs || require;

                if (typeof loader.undef === 'function') {
                    loader.undef(moduleId);
                }

                throw error;
            });

            requests.set(moduleId, pending);
        }

        return requests.get(moduleId);
    }

    return new Class({

        Extends: QUILocale,
        Type   : 'classes/Locale',

        /**
         * Load one published context for the language selected at invocation.
         * Await this promise before synchronously reading its translations.
         *
         * @param {String} context
         * @return {Promise}
         */
        loadContext: function (context) {
            const Locale = this;

            if (typeof context !== 'string') {
                return Promise.reject(new TypeError('A locale context name is required.'));
            }

            context = context.trim();

            if (
                !/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(context) ||
                /^(common|all|manifest)$/i.test(context)
            ) {
                return Promise.reject(new Error('Invalid locale context: ' + context));
            }

            const manifest = window.QUIQQER_LOCALE_CONTEXTS;
            const language = Locale.getCurrent();

            if (!manifest || !manifest.contexts.includes(context)) {
                return Promise.reject(new Error('Unknown locale context: ' + context));
            }

            if (!manifest.languages.includes(language)) {
                return Promise.reject(new Error('Unpublished locale language: ' + language));
            }

            const version = manifest.version;
            const token = 'c' + Array.from(
                context,
                character => character.charCodeAt(0).toString(16).padStart(2, '0')
            ).join('');
            const prefix = 'locale/_contexts/' + version + '/';
            const identity = prefix + token + '/' + language;

            if (!instances.has(Locale)) {
                instances.set(Locale, new Map());
            }

            const loads = instances.get(Locale);

            if (!loads.has(identity)) {
                const pending = Promise.all([
                    request(prefix + 'common/' + language, language, version),
                    request(identity, language, version)
                ]).then(function (payloads) {
                    for (const payload of payloads) {
                        for (const group of Object.keys(payload.groups)) {
                            Locale.set(language, group, payload.groups[group]);
                        }
                    }

                    return Locale;
                }).catch(function (error) {
                    loads.delete(identity);
                    throw error;
                });

                loads.set(identity, pending);
            }

            return loads.get(identity);
        },

        /**
         * Translate a locale code de_DE, en_EN, de_AT
         *
         * @param localeId
         * @returns {String}
         */
        translateCode: function (localeId) {
            var lang    = localeId.split('_')[0],
                country = localeId.split('_')[1];

            var locLang    = QUILocale.get('quiqqer/core', 'language.' + lang),
                locCountry = QUILocale.get('quiqqer/countries', 'country.' + country);

            return locLang + ' (' + locCountry + ')';
        },

        /**
         * Return decimal separator
         * @returns {String}
         */
        getDecimalSeparator: function () {
            return this.get('quiqqer/core', 'numberFormat.decimal_separator');
        },

        /**
         * Return grouping separator
         * @returns {String}
         */
        getGroupingSeparator: function () {
            return this.get('quiqqer/core', 'numberFormat.grouping_separator');
        },

        /**
         * Return the decimal pattern
         * @returns {String}
         */
        getDecimalPattern: function () {
            return this.get('quiqqer/core', 'numberFormat.decimal_pattern');
        },

        /**
         * Return the percent pattern
         * @returns {String}
         */
        getPercentPattern: function () {
            return this.get('quiqqer/core', 'numberFormat.percent_pattern');
        },

        /**
         * Return the currency pattern
         * @returns {String}
         */
        getCurrencyPattern: function () {
            return this.get('quiqqer/core', 'numberFormat.currency_pattern');
        },

        /**
         * Return the accounting currency pattern
         * @returns {String}
         */
        getAccountingCurrencyPattern: function () {
            return this.get('quiqqer/core', 'numberFormat.accounting_currency_pattern');
        },

        /**
         * Return a Intl.NumberFormat object dependent on the locale
         *
         * @param {Object} options
         * @returns {Object} Intl.NumberFormat
         */
        getNumberFormatter: function (options) {
            var locale = this.getCurrent();

            if (!locale.match('_')) {
                locale = locale.toLowerCase() + '_' + locale.toUpperCase();
            }

            locale = locale.replace('_', '-');

            try {
                if (typeof options === 'undefined') {
                    return window.Intl.NumberFormat(locale);
                }

                return window.Intl.NumberFormat(locale, options);
            } catch (e) {
                return window.Intl.NumberFormat(locale);
            }
        },

        /**
         *
         * @param options
         * @return {Intl.DateTimeFormat}
         */
        getDateTimeFormatter: function (options) {
            var locale = this.getCurrent();

            if (!locale.match('_')) {
                locale = locale.toLowerCase() + '_' + locale.toUpperCase();
            }

            locale = locale.replace('_', '-');

            try {
                if (typeof options === 'undefined') {
                    return window.Intl.DateTimeFormat(locale);
                }

                return window.Intl.DateTimeFormat(locale, options);
            } catch (e) {
                return window.Intl.DateTimeFormat(locale);
            }
        },

        /**
         * Return a locale which represent the system locale (not the user locale)
         *
         * @return {Promise}
         */
        getSystemLocale: function () {
            var standardLanguage = QUIQQER_CONFIG.globals.standardLanguage || false;

            return new Promise(function (resolve) {
                require(['classes/Locale'], function (Locale) {
                    if (!standardLanguage) {
                        return resolve(new Locale());
                    }

                    var L = new Locale();
                    L.setCurrent(standardLanguage);

                    resolve(L);
                });
            });
        }
    });
});
