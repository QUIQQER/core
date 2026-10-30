/**
 * @event onLoad
 * @event onSearchBegin
 * @event onSearchEnd
 */
define('controls/packages/Search', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/loader/Loader',
    'Packages',
    'Mustache',
    'controls/packages/PackageList',
    'classes/packages/StoreApi',

    'Locale',
    'Ajax',

    'text!controls/packages/Search.TermsOfUse.html',
    'text!controls/packages/Search.OtherSources.html',
    'css!controls/packages/Search.css'

], function (QUI, QUIControl, QUIButton, QUILoader, Packages,
             Mustache, PackageList, StoreApi, QUILocale, QUIAjax,
             templateTermsOfUse, templateOtherSources) {
    "use strict";

    var lg = 'quiqqer/core';

    return new Class({

        Extends: QUIControl,
        Type: 'controls/packages/Search',

        Binds: [
            'openStore',
            'openOtherSources',
            '$onInject',
            '$onClickInstall',
            '$loadUpload',
            '$onResize',
            '$storeApiController'
        ],

        options: {
            buttons: []
        },

        initialize: function (options) {
            this.parent(options);

            this.$OtherSourcesResultList = null;
            this.$storeApiEventRegistered = false;

            this.Loader = new QUILoader();

            this.$Results = null;
            this.$Input = null;
            this.$TermsOfUse = null;
            this.$Content = null;
            this.$Panel = null;

            window.$StoreApi = new StoreApi();
            this.$Store = null;

            this.$StoreButton = null;
            this.$PackageButton = null;
            this.$SearchButton = null;

            this.addEvents({
                onInject: this.$onInject
            });
        },

        /**
         * Create the domnode element
         *
         * @returns {HTMLDivElement}
         */
        create: function () {
            this.$Elm = new Element('div', {
                'class': 'qui-control-packages-search'
            });

            this.Loader.inject(this.$Elm);

            this.$Buttons = new Element('div', {
                'class': 'quiqqer-packages-search-buttons qui-panel-buttons'
            }).inject(this.$Elm);

            this.$Container = new Element('div', {
                'class': 'quiqqer-packages-search-container'
            }).inject(this.$Elm);


            this.$StoreButton = new QUIButton({
                text: QUILocale.get('quiqqer/core', 'controls.packages.search.template.togglePackageStore'),
                events: {
                    onClick: this.openStore
                }
            }).inject(this.$Buttons);

            this.$PackageButton = new QUIButton({
                text: QUILocale.get('quiqqer/core', 'controls.packages.search.template.upload'),
                title: QUILocale.get('quiqqer/core', 'dialog.packages.install.upload.title'),
                events: {
                    onClick: this.openUpload
                }
            }).inject(this.$Buttons);

            this.$SearchButton = new QUIButton({
                icon: 'fa fa-search',
                title: QUILocale.get('quiqqer/core', 'controls.packages.search.othersources.template.labelSearch'),
                styles: {
                    'float': 'right'
                },
                events: {
                    onClick: this.openOtherSources
                }
            }).inject(this.$Buttons);


            return this.$Elm;
        },

        /**
         * Return the result list
         *
         * @returns {*} PackageList
         */
        getList: function () {
            return this.$OtherSourcesResultList;
        },

        /**
         * event : on inject
         */
        $onInject: function () {
            var self = this;

            this.Loader.show();

            this.$checkTermsOfUse().then(function (agreed) {
                if (!agreed) {
                    self.$showTermsOfUse();
                } else {
                    self.openStore();
                }

                self.fireEvent('load');
                self.Loader.hide();
            });
        },

        /**
         * Show the package store
         */
        openStore: function () {
            var self = this;

            this.$activateButton(this.$StoreButton);

            require(['controls/packages/store/Store'], function (Store) {
                self.$Container.set('html', '');
                self.$Store = new Store().inject(self.$Container);
                window.$Store = self.$Store;
            });

            if ("$storeApiController" in window) {
                return;
            }

            window.$storeApiController = function (event) {
                if (!window.$Store) {
                    return;
                }

                const Frame = window.$Store.getFrame();

                if (!Frame || !Frame.isConnected) {
                    return;
                }

                const frameWindow = Frame.contentWindow;

                if (!frameWindow || event.source !== frameWindow) {
                    return;
                }

                let storeUrl;

                try {
                    storeUrl = new URL(Frame.src, window.location.href);
                } catch (e) {
                    return;
                }

                if (
                    !['https:', 'http:'].includes(storeUrl.protocol) ||
                    event.origin !== storeUrl.origin
                ) {
                    return;
                }

                const Data = event.data;

                if (
                    !Data ||
                    typeof Data !== 'object' ||
                    Array.isArray(Data) ||
                    typeof Data.func !== 'string'
                ) {
                    return;
                }

                const methods = {
                    init: 0,
                    getInstalledPackages: 0,
                    installPackage: 2,
                    getLicenseAuthData: 0,
                    getMaxExecutionTime: 0,
                    getQuiqqerVersion: 0
                };

                if (!Object.prototype.hasOwnProperty.call(methods, Data.func)) {
                    return;
                }

                const params = Data.params === undefined ? [] : Data.params;

                if (!Array.isArray(params) || params.length !== methods[Data.func]) {
                    return;
                }

                if (
                    Data.func === 'installPackage' &&
                    (
                        typeof params[0] !== 'string' ||
                        params[0].trim() === '' ||
                        typeof params[1] !== 'string' ||
                        params[1].trim() === ''
                    )
                ) {
                    return;
                }

                const identifier = Data.func + params.join('-');
                const reply = (data) => {
                    if (
                        window.$Store &&
                        window.$Store.getFrame() === Frame &&
                        Frame.isConnected &&
                        Frame.contentWindow === frameWindow
                    ) {
                        frameWindow.postMessage(data, storeUrl.origin);
                    }
                };

                // init request
                if (Data.func === 'init') {
                    reply({
                        result: true,
                        identifier: identifier
                    });
                    return;
                }

                if (!window.$StoreApi || typeof window.$StoreApi[Data.func] !== 'function') {
                    reply({
                        result: null,
                        identifier: identifier
                    });
                    return;
                }

                // regular request
                Promise.resolve().then(() => window.$StoreApi[Data.func](...params)).then((result) => {
                    reply({
                        result: result,
                        identifier: identifier
                    });
                }, (e) => {
                    const errorMessage = typeof e?.getMessage === 'function' ? e.getMessage() : e?.message;

                    reply({
                        result: null,
                        identifier: identifier,
                        errorMessage: typeof errorMessage === 'string' ? errorMessage : ''
                    });
                });
            };

            window.addEventListener('message', window.$storeApiController);
        },

        /**
         * Show other sources search
         */
        openOtherSources: function () {
            var self = this;

            this.$activateButton(this.$SearchButton);

            this.$Container.set('html', Mustache.render(templateOtherSources, {
                labelSearch: QUILocale.get(lg, 'controls.packages.search.othersources.template.labelSearch'),
                placeholderSearch: QUILocale.get(lg, 'controls.packages.search.othersources.template.placeholderSearch')
            }));

            var SearchInput = this.$Container.getElement('input');

            SearchInput.addEvent('keydown', function (event) {
                if (typeof event !== 'undefined' && event.code === 13) {
                    event.target.blur();

                    self.search(event.target.value).then(function () {
                        event.target.focus();
                    });
                }
            });

            SearchInput.focus();

            this.$OtherSourcesResultList = new PackageList({
                buttons: [{
                    icon: 'fa fa-download',
                    title: QUILocale.get(lg, 'packages.panel.system.packageInstall.title'),
                    styles: {
                        width: '100%'
                    },
                    events: {
                        onClick: this.$onClickInstall
                    }
                }]
            }).inject(this.$Container.getElement('.qui-controls-packages-search-othersources-content'));
        },

        /**
         * activate the wanted button
         * and normalize the other
         */
        $activateButton: function (Btn) {
            this.$StoreButton.setNormal();
            this.$PackageButton.setNormal();
            this.$SearchButton.setNormal();

            if (this.$Store) {
                this.$Store.destroy();
            }

            Btn.setActive();
        },

        /**
         * Load package upload
         */
        openUpload: function () {
            require(['controls/packages/upload/Window'], function (Window) {
                new Window().open();
            });
        },

        /**
         * Get URL for package store
         *
         * @return {Promise}
         */
        $getStoreUrl: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_packages_getStoreUrl', resolve, {
                    onError: reject
                });
            });
        },

        /**
         * Check if current quiqqer system has approved terms of use
         * and display terms of use if not
         */
        $checkTermsOfUse: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_packages_checkTermsOfUse', resolve, {
                    onError: reject
                });
            });
        },

        /**
         * Agree to package store terms of use
         *
         * @return {Promise}
         */
        $agreeToTermsOfUse: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('ajax_packages_agreeToTermsOfUse', resolve, {
                    onError: reject
                });
            });
        },

        /**
         * Show terms of use layer
         */
        $showTermsOfUse: function () {
            this.$activateButton(this.$StoreButton);

            var template;

            var self = this,
                lang = QUILocale.getCurrent(),
                lgPrefix = 'controls.packages.search.termsofuse.';

            switch (lang) {
                case 'de':
                    template = 'text!controls/packages/termsOfUse/' + lang + '.html';
                    break;

                default:
                case 'en':
                    template = 'text!controls/packages/termsOfUse/en.html';
                    break;
            }

            require([template], function (templateTOU) {
                self.$TermsOfUse = new Element('div', {
                    'class': 'quiqqer-packages-search-termsofuse',
                    html: Mustache.render(templateTermsOfUse, {
                        header: QUILocale.get(lg, lgPrefix + 'header'),
                        content: Mustache.render(templateTOU),
                        acceptBtnText: QUILocale.get(lg, lgPrefix + 'acceptBtnText')
                    })
                }).inject(self.$Container);

                self.$TermsOfUse.getElement('button').addEvent('click', function () {
                    self.Loader.show();

                    self.$agreeToTermsOfUse().then(function () {
                        if (self.$TermsOfUse) {
                            self.$TermsOfUse.destroy();
                        }

                        self.Loader.hide();
                        self.openStore();
                    });
                });
            });
        },

        /**
         * Return the list
         *
         * @param {String} term
         * @returns {Promise}
         */
        search: function (term) {
            this.fireEvent('searchBegin', [this]);

            return Packages.search(term).then(function (result) {
                this.$OtherSourcesResultList.clear();

                for (var name in result) {
                    if (!result.hasOwnProperty(name)) {
                        continue;
                    }

                    this.$OtherSourcesResultList.addPackage({
                        name: name,
                        title: name,
                        description: result[name],
                        installed: false
                    });
                }

                this.$OtherSourcesResultList.refresh();

                this.fireEvent('searchEnd', [this]);
            }.bind(this)).catch(function () {
                this.$OtherSourcesResultList.clear();
                this.fireEvent('searchEnd', [this]);
            }.bind(this));
        },

        /**
         * event: install button click
         *
         * @param {Object} Btn - qui/controls/buttons/Button
         * @param {event} event
         */
        $onClickInstall: function (Btn, event) {
            event.stop();

            var self = this,
                SearchInput = this.$Content.getElement('input');

            this.fireEvent('onShowLoader', [this]);

            Packages.install([
                Btn.getAttribute('package')
            ]).then(function () {
                return self.search(SearchInput.value);
            }).then(function () {
                self.fireEvent('onHideLoader', [this]);
            });
        }
    });
});
