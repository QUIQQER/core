/**
 * Ajax Bundler
 *
 * Send ajax request, but bundle the requests
 * the bundler sends the request between a timeframe
 */

define('classes/request/Bundler', [

    'qui/QUI',
    'qui/classes/DOM'

], function (QUI, QUIDom) {
    "use strict";

    return new Class({

        Extends: QUIDom,
        Type   : 'classes/request/Bundler',

        initialize: function (options) {
            this.setAttributes({
                timeframe     : 100,
                requestTimeout: 10000,
                maxRequests   : 20
            });

            this.parent(options);

            this.$stack = {
                get : [],
                post: [],
                put : [],
                del : []
            };

            this.$delay = false;
        },

        /**
         * API
         */

        /**
         * send a get request
         *
         * @param {String} request - Name of the PHP Function
         * @param {Object} params - Function parameter
         *
         * @return Promise
         */
        get: function (request, params) {
            return this.$addCall('get', request, params);
        },

        /**
         * send a get request
         *
         * @param {String} request - Name of the PHP Function
         * @param {Object} params - Function parameter
         *
         * @return Promise
         */
        post: function (request, params) {
            return this.$addCall('post', request, params);
        },

        /**
         * send a get request
         *
         * @param {String} request - Name of the PHP Function
         * @param {Object} params - Function parameter
         *
         * @return Promise
         */
        put: function (request, params) {
            return this.$addCall('put', request, params);
        },

        /**
         * send a get request
         *
         * @param {String} request - Name of the PHP Function
         * @param {Object} params - Function parameter
         *
         * @return Promise
         */
        del: function (request, params) {
            return this.$addCall('del', request, params);
        },

        /**
         * Methods
         */

        /**
         * add request call
         *
         * @param {String} method - get, post, put, del
         * @param {String} request - php function name
         * @param {Object} params - parameter
         * @returns {Promise|Boolean}
         */
        $addCall: function (method, request, params) {
            if (!(method in this.$stack)) {
                return false;
            }

            return new Promise(function (resolve, reject) {
                this.$stack[method].push({
                    request: request,
                    params : params,
                    resolve: resolve,
                    reject : reject,
                    rid    : String.uniqueID()
                });

                this.$start();
            }.bind(this));
        },

        /**
         * start the delay
         */
        $start: function () {
            if (this.$delay) {
                return;
            }

            this.$delay = (function () {
                this.execute();
                this.$delay = false;
            }).delay(this.getAttribute('timeframe'), this);
        },

        /**
         * Execute the stack and set the calls back
         */
        execute: function () {
            var Get  = this.$stack.get;
            var Post = this.$stack.post;
            var Put  = this.$stack.put;
            var Del  = this.$stack.del;

            this.$stack.get  = [];
            this.$stack.post = [];
            this.$stack.put  = [];
            this.$stack.del  = [];

            if (Get.length) {
                this.$request('get', Get);
            }

            if (Post.length) {
                this.$request('post', Post);
            }

            if (Put.length) {
                this.$request('put', Put);
            }

            if (Del.length) {
                this.$request('delete', Del);
            }
        },

        /**
         * Sends request to the php bundler
         *
         * @param {String} method - get, post, put, delete
         * @param {Object} Params - request params
         */
        $request: function (method, Params) {
            var maxRequests = this.getAttribute('maxRequests');

            if (maxRequests > 0 && Params.length > maxRequests) {
                var Requests = [];

                for (var i = 0, len = Params.length; i < len; i += maxRequests) {
                    Requests.push(
                        this.$request(method, Params.slice(i, i + maxRequests))
                    );
                }

                return Requests;
            }

            var requestData = this.$parseStackForSend(Params);

            var R = new Request({
                url    : URL_DIR + 'ajaxBundler.php',
                method : method || 'post',
                async  : true,
                timeout: this.getAttribute('requestTimeout'),

                onProgress: function () {
                    this.fireEvent('requestProgress', [this]);
                }.bind(this),

                onComplete: function () {
                    this.fireEvent('requestComplete', [this]);
                }.bind(this),

                onSuccess: function (responseText) {
                    this.$parseResponse(responseText, Params, requestData);
                }.bind(this),

                onCancel: function () {
                    this.fireEvent('requestCancel', [this]);
                }.bind(this),

                onFailure: function (xhr) {
                    this.$parseResponse(xhr.responseText, Params, requestData, xhr);

                    this.fireEvent('requestFailure', [this]);
                    this.fireEvent('requestCancel', [this]);
                }.bind(this)
            });

            R.send(
                Object.toQueryString({
                    quiqqerBundle: requestData,
                    _FRONTEND    : window.QUIQQER_FRONTEND || 0
                })
            );

            return R;
        },

        /**
         * Parse stack variables for send
         *
         * @param {Array} stack
         * @returns {Array}
         */
        $parseStackForSend: function (stack) {
            var i, k, p, len, stackEntry, stackParams, type_of;
            var result = [];

            for (i = 0, len = stack.length; i < len; i++) {
                stackEntry  = stack[i];
                stackParams = {};

                p = stackEntry.params;

                for (k in p) {
                    if (!p.hasOwnProperty(k)) {
                        continue;
                    }

                    if (typeof p[k] === 'undefined') {
                        continue;
                    }

                    type_of = typeOf(p[k]);

                    if (type_of !== 'string' &&
                        type_of !== 'number' &&
                        type_of !== 'array') {
                        continue;
                    }

                    stackParams[k] = JSON.encode(p[k]);
                }

                result.push({
                    request: stackEntry.request,
                    rid    : stackEntry.rid,
                    params : stackParams
                });
            }

            return result;
        },

        /**
         *
         * @param {String} responseText
         * @param {Object} Params
         * @param {Object} requestData
         */
        $parseResponse: function (responseText, Params, requestData, failure) {
            let result;

            try {
                result = JSON.parse(responseText);
            } catch (e) {
                result = null;
            }

            const fallback = {
                Exception: result?.Exception || {
                    message: failure?.statusText || 'Invalid AJAX bundle response',
                    code: failure?.status || 502
                }
            };
            let maintenance = false;

            // Only queued request IDs have callbacks. Metadata and global errors
            // must not be treated as individual calls or leave promises pending.
            for (const entry of Params) {
                const entryResult = result && Object.hasOwn(result, entry.rid)
                    ? result[entry.rid]
                    : fallback;

                entry.resolve(entryResult);

                if (
                    entryResult?.Exception
                    && typeof entryResult.Exception.code === 'undefined'
                ) {
                    maintenance = true;
                }
            }

            if (maintenance) {
                require(['Locale'], function (QUILocale) {
                    QUI.getMessageHandler(function (MH) {
                        MH.addInformation(
                            QUILocale.get('quiqqer/core', 'message.maintenance')
                        );
                    });
                });
            }
        }
    });
});
