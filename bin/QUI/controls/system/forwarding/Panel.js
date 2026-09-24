define('controls/system/forwarding/Panel', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/windows/Confirm',
    'qui/utils/Form',
    'controls/grid/Grid',
    'Ajax',
    'Locale',
    'Mustache',

    'text!controls/system/forwarding/Panel.Forwarding.html'

], function (QUI, QUIPanel, QUIConfirm, QUIFormUtils, Grid, QUIAjax, QUILocale, Mustache, templateForwarding) {
    "use strict";

    var lg = 'quiqqer/core';

    return new Class({

        Extends: QUIPanel,
        Type   : 'controls/system/forwarding/Panel',

        Binds: [
            '$onCreate',
            '$onResize',
            'refresh',
            'openCreateForwarding',
            'openUpdateForwarding',
            'openDeleteForwarding'
        ],

        options: {
            title: QUILocale.get(lg, 'system.forwarding.panel.title'),
            icon : 'fa fa-external-link'
        },

        initialize: function (options) {
            this.setAttributes({
                title: QUILocale.get(lg, 'system.forwarding.panel.title'),
                icon : 'fa fa-external-link'
            });

            this.parent(options);

            this.$Grid = null;

            this.addEvents({
                onCreate: this.$onCreate,
                onInject: this.$onInject,
                onResize: this.$onResize
            });
        },

        /**
         * event : on create
         */
        $onCreate: function () {

            // Buttons
            this.addButton({
                name     : 'add',
                text     : QUILocale.get('quiqqer/core', 'add'),
                textimage: 'fa fa-plus',
                events   : {
                    onClick: this.openCreateForwarding
                }
            });

            this.addButton({
                type: 'separator'
            });

            this.addButton({
                name     : 'edit',
                text     : QUILocale.get('quiqqer/core', 'edit'),
                textimage: 'fa fa-edit',
                disabled : true,
                events   : {
                    onClick: function () {
                        this.openUpdateForwarding(
                            this.$Grid.getSelectedData()[0].from
                        );
                    }.bind(this)
                }
            });

            this.addButton({
                name     : 'remove',
                text     : QUILocale.get('quiqqer/core', 'remove'),
                textimage: 'fa fa-trash-o',
                disabled : true,
                events   : {
                    onClick: function () {
                        var data = this.$Grid.getSelectedData().map(function (Entry) {
                            return Entry.from;
                        });

                        this.openRemoveForwarding(data);
                    }.bind(this)
                }
            });

            // Grid
            var Container = new Element('div').inject(
                this.getContent()
            );

            this.$Grid = new Grid(Container, {
                pagination : true,
                columnModel: [{
                    header   : QUILocale.get(lg, 'system.forwarding.from'),
                    dataIndex: 'from',
                    dataType : 'string',
                    width    : 300
                }, {
                    header   : QUILocale.get(lg, 'system.forwarding.target'),
                    dataIndex: 'target',
                    dataType : 'string',
                    width    : 300
                }, {
                    header   : QUILocale.get(lg, 'system.forwarding.code'),
                    dataIndex: 'code',
                    dataType : 'number',
                    width    : 100
                }],

                onrefresh: this.refresh
            });

            this.$Grid.addEvents({
                onClick: function () {
                    var selected = this.$Grid.getSelectedIndices(),
                        Edit     = this.getButtons('edit'),
                        Delete   = this.getButtons('remove');

                    if (selected === 0) {
                        Edit.disable();
                        Delete.disable();
                        return;
                    }

                    Edit.enable();
                    Delete.enable();
                }.bind(this),

                onDblClick: function () {
                    this.openUpdateForwarding(
                        this.$Grid.getSelectedData()[0].from
                    );
                }.bind(this)
            });
        },

        /**
         * event: on inject
         */
        $onInject: function () {
            this.Loader.show();

            this.refresh().then(function () {
                this.Loader.hide();
            }.bind(this));
        },

        /**
         * event : on resize
         */
        $onResize: function () {
            if (!this.$Grid) {
                return;
            }

            var Body = this.getContent();

            if (!Body) {
                return;
            }

            var size = Body.getSize();

            this.$Grid.setHeight(size.y - 40);
            this.$Grid.setWidth(size.x - 40);
        },

        /**
         * Refresh the table
         */
        refresh: function () {
            return new Promise(function (resolve) {
                QUIAjax.get('ajax_system_forwarding_getList', function (result) {
                    var data = [];

                    for (var key in result) {
                        if (!result.hasOwnProperty(key)) {
                            continue;
                        }

                        data.push({
                            from  : key,
                            target: result[key].target,
                            code  : result[key].code
                        });
                    }

                    this.$Grid.setData({
                        data: data
                    });

                    resolve();
                }.bind(this));
            }.bind(this));
        },

        /**
         * Open the add dialog
         */
        openCreateForwarding: function () {
            var self = this;

            new QUIConfirm({
                icon     : 'fa fa-external-link',
                title    : QUILocale.get(lg, 'system.forwarding.window.create.title'),
                maxHeight: 400,
                maxWidth : 600,
                autoclose: false,
                events   : {
                    onOpen: function (Win) {
                        var Content = Win.getContent();

                        Content.set('html', Mustache.render(templateForwarding, {
                            from  : QUILocale.get(lg, 'system.forwarding.from'),
                            target: QUILocale.get(lg, 'system.forwarding.target'),
                            code  : QUILocale.get(lg, 'system.forwarding.code')
                        }));
                    },

                    onSubmit: function (Win) {
                        var Content = Win.getContent(),
                            Form    = Content.getElement('form');

                        Win.Loader.show();

                        var data = QUIFormUtils.getFormData(Form);

                        QUIAjax.get('ajax_system_forwarding_create', function () {
                            self.refresh().then(function () {
                                Win.close();
                            });
                        }, {
                            from  : data.from,
                            target: data.target,
                            code  : data.code
                        });
                    }
                }
            }).open();
        },

        /**
         * Open the edit dialog
         *
         * @param {String} forwarding
         */
        openUpdateForwarding: function (forwarding) {
            if (typeof forwarding === 'undefined') {
                return;
            }

            if (typeOf(forwarding) !== 'string') {
                return;
            }

            var self = this;

            new QUIConfirm({
                icon     : 'fa fa-edit',
                title    : QUILocale.get(lg, 'system.forwarding.window.edit.title'),
                maxHeight: 400,
                maxWidth : 600,
                autoclose: false,
                events   : {
                    onOpen: function (Win) {
                        var Content = Win.getContent();

                        Win.Loader.show();

                        Content.set('html', Mustache.render(templateForwarding, {
                            from  : QUILocale.get(lg, 'system.forwarding.from'),
                            target: QUILocale.get(lg, 'system.forwarding.target'),
                            code  : QUILocale.get(lg, 'system.forwarding.code')
                        }));

                        var Form = Content.getElement('form');

                        QUIAjax.get('ajax_system_forwarding_get', function (result) {
                            QUIFormUtils.setDataToForm(
                                Object.merge({from: forwarding}, result),
                                Form
                            );

                            const Code = Form.querySelector('[data-name="code"]');

                            if (!Code.value) {
                                Code.value = '301';
                            }

                            Win.Loader.hide();
                        }, {
                            forwarding: forwarding
                        });
                    },

                    onSubmit: function (Win) {
                        var Content = Win.getContent(),
                            Form    = Content.getElement('form'),
                            data    = QUIFormUtils.getFormData(Form);

                        Win.Loader.show();

                        if (forwarding !== data.from) {
                            // remove old one
                            QUIAjax.get('ajax_system_forwarding_delete', function () {
                                // create new one
                                QUIAjax.get('ajax_system_forwarding_create', function () {
                                    self.refresh().then(function () {
                                        Win.close();
                                    });
                                }, {
                                    from  : data.from,
                                    target: data.target,
                                    code  : data.code
                                });
                            }, {
                                from: JSON.encode(forwarding)
                            });

                            return;
                        }

                        QUIAjax.get('ajax_system_forwarding_update', function () {
                            self.refresh().then(function () {
                                Win.close();
                            });
                        }, {
                            from   : data.from,
                            target : data.target,
                            code   : data.code,
                            onError: function () {
                                Win.Loader.hide();
                            }
                        });
                    }
                }
            }).open();
        },

        /**
         * Open the remove dialog
         *
         * @param {Array|String} forwarding
         */
        openRemoveForwarding: function (forwarding) {
            if (typeof forwarding === 'undefined') {
                return;
            }

            if (typeOf(forwarding) === 'string') {
                forwarding = [forwarding];
            }

            var self = this;

            new QUIConfirm({
                icon     : 'fa fa-trash',
                title    : QUILocale.get(lg, 'system.forwarding.window.remove.title'),
                maxHeight: 400,
                maxWidth : 600,
                autoclose: false,
                events   : {
                    onOpen: function (Win) {
                        var Content = Win.getContent();

                        Content.set({
                            html: QUILocale.get(lg, 'system.forwarding.window.remove.text', {
                                list: forwarding.join(', ')
                            })
                        });
                    },

                    onSubmit: function (Win) {
                        Win.Loader.show();

                        QUIAjax.get('ajax_system_forwarding_delete', function () {
                            self.refresh().then(function () {
                                Win.close();
                            });
                        }, {
                            from: JSON.encode(forwarding)
                        });
                    }
                }
            }).open();
        }
    });
});
