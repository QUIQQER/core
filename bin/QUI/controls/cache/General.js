/**
 * Cache type setting
 */
define('controls/cache/General', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'Ajax',
    'Locale'

], function (QUI, QUIControl, QUIButton, QUIAjax, QUILocale) {
    "use strict";

    return new Class({

        Extends: QUIControl,
        Type: 'controls/cache/General',

        Binds: [
            '$onImport',
            '$onTypeChange',
            'redisCheck'
        ],

        initialize: function (Settings) {
            this.$Settings = Settings;
            this.$RedisCheck = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * event : on inject
         */
        $onImport: function () {
            var i, len, Table;

            var Elm = this.getElm(),
                CacheType = Elm.querySelector('[name="general.cacheType"]'),
                RedisTable = Elm.querySelector('[name="general.redis"]').getParent('table');

            const config = this.$Settings.getConfig();
            const handlers = config.handlers ?? {};
            const cacheType = config.general?.cacheType;

            // default setting check
            if (cacheType !== undefined && Object.prototype.hasOwnProperty.call(handlers, cacheType)) {
                Object.keys(handlers).forEach(key => {
                    handlers[key] = 0;
                });

                handlers[cacheType] = 1;
            }

            for (const [handler, enabled] of Object.entries(handlers)) {
                if (parseInt(enabled, 10)) {
                    CacheType.value = handler;
                }
            }

            // type changing
            CacheType.addEventListener('change', this.$onTypeChange);

            // table handling
            var tables = Elm.querySelectorAll('table');

            for (i = 0, len = tables.length; i < len; i++) {
                Table = tables[i];

                if (Table.querySelector('[name="general.nocache"]')) {
                    continue;
                }

                Table.setStyle('display', 'none');
            }

            this.$onTypeChange();

            // redis check
            this.$RedisCheck = new QUIButton({
                text: QUILocale.get('quiqqer/core', 'quiqqer.settings.cache.redis.check.button'),
                events: {
                    onClick: this.redisCheck
                }
            }).inject(
                RedisTable.getElement('tbody label')
            );
        },

        /**
         * event: on type change
         */
        $onTypeChange: function () {
            var self = this,
                Elm = this.getElm(),
                CacheType = Elm.querySelector('[name="general.cacheType"]'),
                RedisTable = Elm.querySelector('[name="general.redis"]').getParent('table'),
                APCTable = Elm.querySelector('[name="apc.namespace"]').getParent('table'),
                MemTable = Elm.querySelector('[name="memcache.servers"]').getParent('table'),
                MongoTable = Elm.querySelector('[name="mongo.host"]').getParent('table'),
                FileTable = Elm.querySelector('[name="filesystem.path"]').getParent('table');

            RedisTable.setStyle('display', 'none');
            APCTable.setStyle('display', 'none');
            MemTable.setStyle('display', 'none');
            FileTable.setStyle('display', 'none');
            MongoTable.setStyle('display', 'none');

            switch (CacheType.value) {
                case 'apc':
                    APCTable.setStyle('display', null);
                    break;

                case 'memcache':
                    MemTable.setStyle('display', null);
                    break;

                case 'redis':
                    RedisTable.setStyle('display', null);
                    break;

                case 'mongo':
                    MongoTable.setStyle('display', null);
                    CacheType.disabled = true;

                    // availability check
                    this.checkMongoAvailability().then(function (availability) {
                        CacheType.disabled = false;
                        MongoTable.getElements('.mongo-error-message').destroy();
                        MongoTable.getElements('.mongo-check-button').destroy();

                        if (!availability) {
                            var RowMessage = new Element('tr', {
                                'class': 'mongo-error-message',
                                html: '<td>' +
                                    '<div class="messages-message message-error">' +
                                    QUILocale.get('quiqqer/core', 'message.quiqqer.mongo.missing') +
                                    '</div>' +
                                    '</td>'
                            });

                            RowMessage.inject(
                                MongoTable.getElement('tbody'),
                                'top'
                            );

                            return;
                        }

                        new Element('tr', {
                            'class': 'mongo-check-button',
                            html: '<td>' +
                                '<button class="qui-button" style="float: right">' +
                                QUILocale.get('quiqqer/core', 'message.quiqqer.mongo.button') +
                                '</button>' +
                                '</td>'
                        }).inject(MongoTable.getElement('tbody'));

                        var Button = MongoTable.getElement('button');

                        Button.addEvent('click', function () {
                            Button.disabled = true;
                            Button.set('html', '<span class="fa fa-spinner fa-spin"></span>');

                            self.checkMongoDB().then(function () {
                                Button.disabled = false;
                                Button.set('html', QUILocale.get('quiqqer/core', 'message.quiqqer.mongo.button'));
                            });
                        });
                    });

                    break;

                default:
                case 'filesystem':
                    FileTable.setStyle('display', null);
                    break;
            }
        },

        /**
         * Check redis server
         */
        redisCheck: function () {
            var self = this;

            this.$RedisCheck.setAttribute('text', '<span class="fa fa-spinner fa-spin"></span>');

            QUIAjax.get('ajax_system_cache_redisCheck', function (result) {
                self.$RedisCheck.setAttribute(
                    'text',
                    QUILocale.get('quiqqer/core', 'quiqqer.settings.cache.redis.check.button')
                );

                var message = result.message;
                var status = result.status;

                QUI.getMessageHandler().then(function (MH) {
                    if (status === -1) {
                        MH.addError(message);
                        return;
                    }

                    if (status) {
                        MH.addSuccess(message);
                        return;
                    }

                    MH.addError(message);
                });
            }, {
                server: this.getElm().querySelector('[name="general.redis"]').value
            });
        },

        /**
         * Checks, if mongoDB can be used
         *
         * @return {Promise}
         */
        checkMongoAvailability: function () {
            return new Promise(function (resolve) {
                QUIAjax.get('ajax_system_cache_mongoAvailable', resolve);
            });
        },

        /**
         * Checks, if mongoDB can be used
         *
         * @return {Promise}
         */
        checkMongoDB: function () {
            var Elm = this.getElm(),
                Form = Elm.querySelector('[name="mongo.host"]').getParent('form');

            return new Promise(function (resolve) {
                QUIAjax.get('ajax_system_cache_mongoCheck', resolve, {
                    'host': Form.elements['mongo.host'].value,
                    'database': Form.elements['mongo.database'].value,
                    'collection': Form.elements['mongo.collection'].value,
                    'username': Form.elements['mongo.username'].value,
                    'password': Form.elements['mongo.password'].value
                });
            });
        }
    });
});
