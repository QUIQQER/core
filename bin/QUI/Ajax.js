/**
 * Ajax request for QUIQQER
 * Ajax Manager, collect, exec multiple requests
 *
 *
 * @example

 require(['Ajax'], function(Ajax)
 {
 Ajax.post('ajax_project_getlist', function(result, Request)
 {
 console.info(result);
 });
 });

 */
define('Ajax', [

    'qui/QUI',
    'qui/classes/request/Ajax',
    'classes/request/Bundler',
    'qui/utils/Object',
    'Locale'

], function(QUI, QUIAjax, Bundler, Utils, Locale) {
    'use strict';

    // load message handling
    QUI.getMessageHandler().catch(function() {
        // nothing
    });

    let apiPoint = '/ajax.php';
    const AjaxBundler = new Bundler();

    if (typeof QUIQQER !== 'undefined' && 'ajax' in QUIQQER) {
        apiPoint = QUIQQER.ajax;
    } else {
        if (typeof URL_SYS_DIR !== 'undefined') {
            apiPoint = URL_SYS_DIR + 'ajax.php';
        }
    }

    let REFRESH_WINDOW_OPEN = false;
    let REFRESH_WINDOW_CANCELED = false;

    let TRY_MAX = 3;
    let TRY_DELAY = 1000;

    const getAdministrationWindow = function() {
        if (typeof window.QUIQQER !== 'undefined' && window.QUIQQER.inAdministration) {
            return window;
        }

        try {
            if (
                window.parent !== window &&
                typeof window.parent.QUIQQER !== 'undefined' &&
                window.parent.QUIQQER.inAdministration
            ) {
                return window.parent;
            }
        } catch (error) {
            // Cross-origin parent windows are intentionally ignored.
        }

        return null;
    };

    const getBackendCsrfToken = function() {
        const AdministrationWindow = getAdministrationWindow();

        if (
            AdministrationWindow === null ||
            typeof AdministrationWindow.QUIQQER.csrfToken !== 'string'
        ) {
            return '';
        }

        return AdministrationWindow.QUIQQER.csrfToken;
    };

    const addBackendCsrfToken = function(params) {
        const token = getBackendCsrfToken();

        if (token !== '') {
            params._csrf = token;
        }

        return params;
    };

    const updateBackendCsrfToken = function(result) {
        const AdministrationWindow = getAdministrationWindow();

        if (
            AdministrationWindow === null ||
            !result ||
            typeof result.csrfToken !== 'string' ||
            result.csrfToken === ''
        ) {
            return;
        }

        AdministrationWindow.QUIQQER.csrfToken = result.csrfToken;
    };

    const showLicenseRequired = function(Exception, Request) {
        if (Number(Exception.getCode()) !== 1599 || getAdministrationWindow() === null) {
            return false;
        }

        if (Request.getAttribute('showError') !== false) {
            require(['controls/licenseKey/RequiredWindow'], function(openWindow) {
                openWindow(Exception);
            }, function() {
                QUI.getMessageHandler().then(function(MessageHandler) {
                    MessageHandler.addException(Exception);
                });
            });
        }

        return true;
    };

    if (typeof QUIQQER_CONFIG !== 'undefined' &&
        typeof QUIQQER_CONFIG.globals !== 'undefined' &&
        (QUIQQER_CONFIG.globals.debug_mode || QUIQQER_CONFIG.globals.development)) {
        TRY_MAX = 0;
    }

    const Ajax = {
        /**
         * Return the CSRF token of the active administration window.
         *
         * @return {String}
         */
        getBackendCsrfToken: function() {
            return getBackendCsrfToken();
        },


        $globalJSF: {}, // global javascript callback functions
        $onprogress: {},
        $url: apiPoint,
        $on401: false,

        /**
         * Send a Request async
         *
         * @method Ajax#request
         *
         * @param {String} call         - PHP function
         * @param {String} method       - Send Method -> post or get
         * @param {Function} [callback] - Callback function if the request is finish
         * @param {Object} [params]     - PHP parameter (optional)
         *
         * @return {Ajax}
         */
        request: function(call, method, callback, params) {
            // if sync, the browser freeze
            let options;
            const self = this,
                id = String.uniqueID();

            method = method || 'post'; // is post, put, get or delete
            callback = callback || function() {
            };

            params = Utils.combine(params, {
                '_rf': call,
                '_FRONTEND': window.QUIQQER_FRONTEND || 0
            });
            addBackendCsrfToken(params);

            if (typeof params.lang === 'undefined') {
                params.lang = Locale.getCurrent();
            }

            if (typeof params.project === 'undefined' && typeof window.QUIQQER_PROJECT !== 'undefined') {
                params.project = JSON.encode(window.QUIQQER_PROJECT);
            }


            // combine all params, so, they are available in the Request Object
            options = Utils.combine(params, {
                callback: callback,
                method: method,
                url: this.$url,
                async: true,
                showError: typeof params.showError !== 'undefined' ? params.showError : true,
                showLogin: typeof params.showLogin !== 'undefined' ? params.showLogin : true,
                events: {
                    onSuccess: function() {
                        const args = arguments;
                        const Request = args[args.length - 1];

                        updateBackendCsrfToken(self.$onprogress[this].$result);

                        if (Request.getAttribute('logout')) {
                            return;
                        }

                        if (Request.getAttribute('hasError')) {
                            return;
                        }

                        if (this in self.$onprogress &&
                            '$result' in self.$onprogress[this] &&
                            self.$onprogress[this].$result.jsCallbacks
                        ) {
                            self.$triggerGlobalJavaScriptCallback(
                                self.$onprogress[this].$result.jsCallbacks,
                                self.$onprogress[this].$result
                            );
                        }

                        self.$versionCheck(self.$onprogress[this].$result);

                        QUI.fireEvent('ajaxResult', [self.$onprogress[this].$result]);

                        // maintenance?
                        if (this in self.$onprogress &&
                            '$result' in self.$onprogress[this] &&
                            'maintenance' in self.$onprogress[this].$result &&
                            parseInt(self.$onprogress[this].$result.maintenance)
                        ) {
                            self.showMaintenanceMessage();
                        }

                        callback.apply(this, arguments);

                        if (this in self.$onprogress) {
                            delete self.$onprogress[this];
                        }
                    }.bind(id),

                    onCancel: function(Request) {
                        if (Request.getAttribute('onCancel')) {
                            return Request.getAttribute('onCancel')(Request);
                        }
                    },

                    onError: function(Exception, Request) {
                        let Response = null,
                            networkError = true,
                            tries = Request.getAttribute('REQUEST_TRIES') || 0;

                        if (typeof Exception.getAttribute !== 'undefined' && Exception.getAttribute('type')) {
                            networkError = false;
                        }

                        if (typeof self.$onprogress[this] !== 'undefined') {
                            Response = self.$onprogress[this];
                        }

                        const requestCallback = function() {
                            callback.apply(this, arguments);
                        }.bind(this);

                        // maintenance?
                        if (Response &&
                            '$result' in Response && Response.$result &&
                            'maintenance' in Response.$result && Response.$result.maintenance &&
                            parseInt(Response.$result && Response.$result.maintenance)
                        ) {
                            self.showMaintenanceMessage();
                        }


                        if (Response &&
                            '$result' in Response && Response.$result &&
                            Response.$result.jsCallbacks
                        ) {
                            self.$triggerGlobalJavaScriptCallback(
                                Response.$result.jsCallbacks,
                                Response.$result
                            );

                            self.$versionCheck(Response.$result);
                        }

                        Request.setAttribute('hasError', true);

                        if (showLicenseRequired(Exception, Request)) {
                            if (Request.getAttribute('onError')) {
                                return Request.getAttribute('onError')(Exception, Request);
                            }

                            QUI.triggerError(Exception, Request);
                            return;
                        }

                        if (Exception.getMessage() === '') {
                            Exception.setAttribute('message', Locale.get('quiqqer/core', 'exception.network.error'));
                            Exception.setAttribute('code', 503);
                            networkError = true;
                        }

                        if (Request.getAttribute('showError') || networkError) {
                            if (tries === TRY_MAX) {
                                QUI.getMessageHandler().then(function(MessageHandler) {
                                    MessageHandler.addException(Exception);
                                });
                            }
                        }

                        if (Exception.getCode() === 401 &&
                            Exception.getAttribute('type') === 'QUI\\Users\\Exception' &&
                            Request.getAttribute('showLogin')
                        ) {
                            Request.setAttribute('logout', true);

                            if (self.$on401) {
                                if (typeof self.$on401 === 'function') {
                                    self.$on401(Exception);
                                }

                                return;
                            }

                            self.$openLogin(call, method, requestCallback, params);

                            return;
                        }

                        if ('QUIQQER' in window &&
                            'inAdministration' in QUIQQER &&
                            QUIQQER.inAdministration &&
                            Exception.getCode() === 440
                        ) {
                            Request.setAttribute('logout', true);
                            self.$openLogin(call, method, requestCallback, params);
                            return;
                        }

                        switch (Exception.getCode()) {
                            // if server error, then test again
                            case '':
                            case 0:
                            case 503:
                            case 504:
                            case 507:
                            case 509:
                            case 510:
                                break;

                            default:
                                if (Request.getAttribute('showError') !== false) {
                                    QUI.getMessageHandler().then(function(MessageHandler) {
                                        MessageHandler.addException(Exception);
                                    });
                                }

                                if (Request.getAttribute('onError')) {
                                    return Request.getAttribute('onError')(Exception, Request);
                                }

                                QUI.triggerError(Exception, Request);
                                return;
                        }

                        // another try
                        if (tries < TRY_MAX && networkError) {
                            tries++;
                            Request.setAttribute('REQUEST_TRIES', tries);

                            (function() {
                                options._rf = call;
                                self.$onprogress[id].send(options);
                            }).delay(TRY_DELAY * tries);

                            return;
                        }

                        if (Request.getAttribute('onError')) {
                            return Request.getAttribute('onError')(Exception, Request);
                        }

                        QUI.triggerError(Exception, Request);
                    }.bind(id)
                }
            });

            this.$onprogress[id] = new QUIAjax(options);

            let useBundler = true;

            if (typeof window.QUIQQER_CONFIG !== 'undefined' &&
                typeof window.QUIQQER_CONFIG.globals !== 'undefined' &&
                typeof window.QUIQQER_CONFIG.globals.no_ajax_bundler !== 'undefined') {
                useBundler = !parseInt(window.QUIQQER_CONFIG.globals.no_ajax_bundler);
            }

            if (typeof window.QUIQQER_FRONTEND !== 'undefined' && window.QUIQQER_FRONTEND) {
                useBundler = false;
            }

            if (useBundler === false || typeof options.bundle !== 'undefined' && options.bundle === false) {
                this.$onprogress[id].send(options);

                return this.$onprogress[id];
            }

            // bundle the request
            let Done;

            switch (method) {
                case 'put':
                    Done = AjaxBundler.put(call, options);
                    break;

                case 'del':
                    Done = AjaxBundler.del(call, options);
                    break;

                default:
                case 'get':
                case 'post':
                    Done = AjaxBundler.post(call, options);
                    break;
            }

            Done.then(function(result) {
                const Request = self.$onprogress[this];

                Request.$result = result;
                Request.$parseResult();
            }.bind(id));

            return this.$onprogress[id];
        },

        /**
         * Send a POST request that may continue while the page unloads.
         *
         * The request uses window.fetch intentionally, allowing packages to
         * extend it with security headers.
         *
         * @param {String} call
         * @param {Object} [params]
         * @return {Boolean}
         */
        keepalive: function(call, params = {}) {
            if (typeof window.fetch !== 'function') {
                return false;
            }

            const requestParams = Object.assign({}, params, {
                _rf      : JSON.stringify([call]),
                _FRONTEND: window.QUIQQER_FRONTEND || 0
            });
            addBackendCsrfToken(requestParams);
            const body = new URLSearchParams();

            Object.entries(requestParams).forEach(function([name, value]) {
                if (typeof value === 'undefined') {
                    return;
                }

                body.append(
                    name,
                    typeof value === 'string' ? value : JSON.stringify(value)
                );
            });

            window.fetch(this.$url + '?beacon=1', {
                method     : 'POST',
                body       : body,
                credentials: 'same-origin',
                keepalive  : true
            }).catch(function() {
                // The page may already be unloading.
            });

            return true;
        },

        /**
         * Method to open the login
         *
         * API
         * ------
         * its possible to overwrite the Login:
         * QUI.addEvent('onAjaxLogin', function() { return true; });
         *
         * @param call
         * @param method
         * @param callback
         * @param params
         */
        $openLogin: function(call, method, callback, params) {
            const self = this;

            // check if events exists and login is overwritten
            const events = QUI.$events;

            if (typeof events.ajaxLogin !== 'undefined') {
                const eventResults = events.ajaxLogin.map(function(f) {
                    return f(Ajax, call, method, callback, params);
                });

                if (eventResults.indexOf(true) !== -1) {
                    return true;
                }
            }

            require(['controls/users/LoginWindow'], function(Login) {
                new Login({
                    events: {
                        onSuccess: function() {
                            self.request(call, method, callback, params);
                        }
                    }
                }).open();
            });
        },

        /**
         * Register a global callback javascript function
         * This functions are to be executed after every request
         * the execution is controlled via php
         * Ajax->triggerGlobalJavaScriptCallback();
         *
         * @param {String} fn - Function name
         * @param {Function} callback - Callback function
         */
        registerGlobalJavaScriptCallback: function(fn, callback) {
            if (typeOf(callback) === 'function') {
                this.$globalJSF[fn] = callback;
            }
        },

        /**
         * Excute globale functions
         *
         * @param {Array} functionList - list of functions
         * @param response - Request response
         */
        $triggerGlobalJavaScriptCallback: function(functionList, response) {
            if (typeOf(functionList) !== 'object') {
                return;
            }

            if (!Object.getLength(functionList)) {
                return;
            }

            for (const f in functionList) {
                if (f in this.$globalJSF && this.$globalJSF.hasOwnProperty(f)) {
                    this.$globalJSF[f](response, functionList[f]);
                }
            }
        },

        /**
         * show a maintenance message
         */
        showMaintenanceMessage: function() {
            QUI.getMessageHandler(function(MH) {
                MH.addInformation(
                    Locale.get('quiqqer/core', 'message.maintenance')
                );
            });
        },

        /**
         * Send a Request sync
         *
         * @method Ajax#syncRequest
         *
         * @param {String} call     - PHP function
         * @param {String} method   - Send Method -> post or get
         * @param {Object} [params] - PHP parameter (optional)
         *
         * @return {Ajax}
         */
        syncRequest: function(call, method, params) {
            const id = String.uniqueID();

            method = method || 'post'; // is post, put, get or delete

            params = Utils.combine(params, {
                '_rf': call
            });
            addBackendCsrfToken(params);

            this.$onprogress[id] = new QUIAjax(
                // combine all params, so, they are available in the Request Object
                Utils.combine(params, {
                    method: method,
                    url: this.$url,
                    async: false,
                    timeout: 5000,
                    events: {
                        onCancel: function(Request) {
                            if (Request.getAttribute('onCancel')) {
                                return Request.getAttribute('onCancel')(Request);
                            }
                        },

                        onError: function(Exception, Request) {
                            if (!showLicenseRequired(Exception, Request)) {
                                QUI.getMessageHandler(function(MessageHandler) {
                                    MessageHandler.addException(Exception);
                                });
                            }


                            if (Request.getAttribute('onError')) {
                                return Request.getAttribute('onError')(Exception, Request);
                            }

                            QUI.triggerError(Exception, Request);
                        }
                    }
                })
            );

            this.$onprogress[id].send(params);

            return this.$onprogress[id];
        },

        /**
         * Send a POST Request
         *
         * @method Ajax#post
         *
         * @param {String|Array} call - PHP function
         * @param {Function} callback - Callback function if the Request is finish
         * @param {Object} [params]   - PHP parameter (optional)
         *
         * @return {Ajax}
         */
        post: function(call, callback, params) {
            return this.request(call, 'post', callback, params);
        },

        /**
         * Send a GET Request
         *
         * @method Ajax#get
         *
         * @param {String|Array} call - PHP function
         * @param {Function} callback - Callback function if the Request is finish
         * @param {Object} [params]   - PHP parameter (optional)
         *
         * @return {Ajax}
         */
        get: function(call, callback, params) {
            // chrome cache get request, so we must extend the request
            if (typeof params === 'undefined') {
                params = {};
            }

            params.preventCache = String.uniqueID();

            return this.request(call, 'get', callback, params);
        },

        /**
         * Parse params to a ajax request string
         *
         * @method Ajax#parseParams
         *
         * @param {String|Array} call - PHP function
         * @param {Object} params     - PHP parameter (optional)
         */
        parseParams: function(call, params) {
            params = Utils.combine(params, {
                '_rf': call
            });
            addBackendCsrfToken(params);

            if (typeof this.$AjaxHelper === 'undefined') {
                this.$AjaxHelper = new QUIAjax();
            }

            return Object.toQueryString(
                this.$AjaxHelper.parseParams(params)
            );
        },

        /**
         * Send a PUT Request
         *
         * @method Ajax#get
         *
         * @param {String|Array} call - PHP function
         * @param {Function} callback - Callback function if the Request is finish
         * @param {Object} [params]   - PHP parameter (optional)
         *
         * @return {Ajax}
         */
        put: function(call, callback, params) {
            // chrome cache get request, so we must extend the request
            if (typeof params === 'undefined') {
                params = {};
            }

            params.preventCache = String.uniqueID();

            return this.request(call, 'put', callback, params);
        },

        /**
         * Send a DELETE Request
         *
         * @method Ajax#get
         *
         * @param {String|Array} call - PHP function
         * @param {Function} callback - Callback function if the Request is finish
         * @param {Object} [params]   - PHP parameter (optional)
         *
         * @return {Ajax}
         */
        del: function(call, callback, params) {
            // chrome cache get request, so we must extend the request
            if (typeof params === 'undefined') {
                params = {};
            }

            params.preventCache = String.uniqueID();

            return this.request(call, 'delete', callback, params);
        },

        $versionCheck: function(requestResult) {
            if (typeof window.QUIQQER === 'undefined') {
                return;
            }

            if (typeof window.QUIQQER_FRONTEND !== 'undefined' && window.QUIQQER_FRONTEND) {
                return;
            }

            if (typeof requestResult.vMd5 === 'undefined') {
                return;
            }

            if (requestResult.vMd5 === window.QUIQQER.vMd5) {
                return;
            }

            if (document.getElement('.window-update-refresh-info')) {
                return;
            }

            if (REFRESH_WINDOW_OPEN || REFRESH_WINDOW_CANCELED) {
                return;
            }

            if (typeof requestResult.ajax_settings_save !== 'undefined') {
                return;
            }

            require([
                'controls/system/UpdateRefreshWindow'
            ], function(UpdateRefreshWindow) {
                if (document.getElement('.window-update-refresh-info')) {
                    return;
                }

                if (REFRESH_WINDOW_OPEN || REFRESH_WINDOW_CANCELED) {
                    return;
                }

                REFRESH_WINDOW_OPEN = true;

                new UpdateRefreshWindow({
                    events: {
                        onClose: function() {
                            REFRESH_WINDOW_OPEN = false;
                        },
                        onCancel: function() {
                            REFRESH_WINDOW_CANCELED = true;
                        }
                    }
                }).open();
            });
        }
    };

    return Ajax;
});
