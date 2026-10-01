/**
 * a panel based on a xml file
 * eg settings.xml
 */
define('controls/desktop/panels/XML', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/buttons/Button',
    'qui/controls/buttons/Separator',
    'qui/utils/Object',
    'Ajax',
    'Locale',
    'utils/Controls',

    'css!controls/desktop/panels/XML.css'

], function(QUI, QUIPanel, QUIButton, QUISeparator, QUIObjectUtils, Ajax, QUILocale, ControlUtils) {
    'use strict';

    /**
     * @class controls/desktop/panels/XML
     *
     * @param {String} xmlfile - the xml file what would be interpreted
     */
    return new Class({

        Extends: QUIPanel,
        Type: 'controls/desktop/panels/XML',

        Binds: [
            '$onCreate',
            '$onCategoryActive',
            'loadCategory',
            'unloadCategory',
            'save'
        ],

        options: {
            category: false,  // which category should be open at the start
            name: false,
            'alphabetical-order': true
        },

        initialize: function(xmlfile, options) {
            this.$file = xmlfile;
            this.$config = null;
            this.$Control = null;
            this.$title = null;

            this.addEvent('onCreate', this.$onCreate);

            this.parent(options);
        },

        /**
         * Return the data for the workspace
         *
         * @method qui/controls/desktop/Tasks#serialize
         * @return {Object}
         */
        serialize: function() {
            return {
                attributes: this.getAttributes(),
                type: this.getType(),
                file: this.$file
            };
        },

        /**
         * Import the saved data
         *
         * @method qui/controls/desktop/Tasks#unserialize
         * @param {Object} data
         */
        unserialize: function(data) {
            this.setAttributes(data.attributes);

            this.$file = data.file;

            if (!this.$Elm) {
                this.$serialize = data;
                return this;
            }
        },

        /**
         * Return the path of the xml file
         * @returns {String} - xml file
         */
        getFile: function() {
            return this.$file;
        },

        /**
         * Internal creation
         */
        $onCreate: function() {
            const self = this;

            this.Loader.show();
            this.getElm().addClass('quiqqer-xml-panel');

            Ajax.get([
                'ajax_settings_window',
                'ajax_settings_get'
            ], (result, config) => {
                const categories = result.categories || [],
                    buttons = result.buttons || [];

                if (typeof result.categories !== 'undefined') {
                    delete result.categories;
                }

                if (typeof result.buttons !== 'undefined') {
                    delete result.buttons;
                }

                self.$config = config;

                self.getButtonBar().clear();
                self.getCategoryBar().clear();

                // load categories
                let i, len, Category;

                if (this.getAttribute('alphabetical-order')) {
                    categories.sort(function(a, b) {
                        const aIndex = 'index' in a && a.index !== '' ? a.index : Infinity;
                        const bIndex = 'index' in b && b.index !== '' ? b.index : Infinity;

                        if (typeof a.text === 'undefined' || typeof b.text === 'undefined') {
                            return aIndex - bIndex;
                        }

                        return aIndex - bIndex || a.text.localeCompare(b.text);
                    });
                }

                for (i = 0, len = categories.length; i < len; i++) {
                    Category = new QUIButton(categories[i]);

                    Category.addEvents({
                        onActive: self.$onCategoryActive
                    });

                    self.addCategory(Category);
                }

                // load buttons
                self.addButton({
                    name: 'save',
                    text: QUILocale.get('quiqqer/core', 'desktop.panels.xml.btn.save'),
                    textimage: 'fa fa-save',
                    events: {
                        onClick: self.save
                    }
                });

                self.addButton({
                    name: 'reload',
                    text: QUILocale.get('quiqqer/core', 'desktop.panels.xml.btn.cancel'),
                    textimage: 'fa fa-ban',
                    events: {
                        onClick: self.$onCreate
                    }
                });

                if (buttons.length) {
                    self.addButton(
                        new QUISeparator()
                    );
                }

                for (i = 0, len = buttons.length; i < len; i++) {
                    self.addButton(buttons[i]);
                }

                self.setAttributes(result);

                if (typeof result.title !== 'undefined') {
                    self.$title = result.title;
                }

                self.setAttribute(
                    'title',
                    QUILocale.get('quiqqer/core', 'panel.settings.title', {
                        title: self.$title,
                        category: ''
                    })
                );

                self.refresh();
                self.fireEvent('createEnd', [self]);

                if (self.getAttribute('category')) {
                    const Wanted = self.getCategoryBar().getElement(
                        self.getAttribute('category')
                    );

                    if (Wanted) {
                        Wanted.click();
                        return;
                    }
                }

                self.getCategoryBar().firstChild().click();
            }, {
                file: JSON.encode(this.$file),
                windowName: this.getAttribute('name')
            });
        },

        /**
         * Request the category
         *
         * @param {Object} Category - qui/controls/buttons/Button
         */
        loadCategory: function(Category) {
            const self = this;

            this.Loader.show();

            let file = Category.getAttribute('file');

            if (!file || file === '') {
                file = this.$file;
            }

            Ajax.get('ajax_settings_category', function(result) {
                const Body = self.getBody();

                if (!result) {
                    result = '';
                }

                Body.set(
                    'html',
                    '<form class="qui-xml-panel" autocomplete="off">' + result + '</form>'
                );

                // set the form
                var i, len, Elm, value;

                var Form = Body.getElement('form'),
                    elements = Form.elements,
                    config = self.$config;

                for (i = 0, len = elements.length; i < len; i++) {
                    Elm = elements[i];
                    value = QUIObjectUtils.getValue(Elm.name, config);

                    if (!value) {
                        continue;
                    }

                    if (Elm.type === 'checkbox' || Elm.type === 'radio') {
                        Elm.checked = parseInt(value);
                        continue;
                    }

                    Elm.value = value;
                }

                // parse controls
                Promise.all([
                    QUI.parse(Body),
                    ControlUtils.parse(Body)
                ]).then(function() {
                    var i, len, Node, Control, nodeName;
                    var quiElements = Body.getElements('[data-quiid]');

                    for (i = 0, len = quiElements.length; i < len; i++) {
                        Node = quiElements[i];
                        nodeName = Node.nodeName;

                        if (nodeName !== 'INPUT' &&
                            nodeName !== 'TEXTAREA' &&
                            nodeName !== 'SELECT') {
                            continue;
                        }

                        Control = QUI.Controls.getById(Node.get('data-quiid'));

                        if (!Control) {
                            continue;
                        }

                        if (!('setValue' in Control)) {
                            continue;
                        }

                        if (!(Node.get('name') in self.$config)) {
                            continue;
                        }

                        Control.setValue(self.$config[Node.get('name')]);
                    }

                    // require?
                    if (!Category.getAttribute('require')) {
                        self.Loader.hide();
                        return;
                    }

                    require([Category.getAttribute('require')], function(R) {
                        var type = typeOf(R);

                        if (type === 'function') {
                            R(self);
                        } else {
                            if (type === 'class') {
                                self.$Control = new R(self);

                                if (self.getContent().get('html') === '') {
                                    self.$Control.inject(Form);
                                } else {
                                    self.$Control.imports(Form);
                                }

                                // Categories can load additional settings asynchronously.
                                const Control = self.$Control;

                                if (typeof Control.whenLoaded === 'function') {
                                    const hideLoader = () => {
                                        if (self.$Control === Control) {
                                            self.Loader.hide();
                                        }
                                    };

                                    Promise.resolve(Control.whenLoaded()).then(hideLoader, hideLoader);
                                    return;
                                }
                            }
                        }

                        self.Loader.hide();
                    }, function(err) {
                        QUI.getMessageHandler(function(MH) {
                            MH.addAttention(
                                'Some error occured. Control could not be loaded: ' +
                                Category.getAttribute('require')
                            );

                            console.error(err);
                        });

                        self.Loader.hide();
                    });
                });
            }, {
                file: JSON.encode(file),
                category: Category.getAttribute('name'),
                windowName: this.getAttribute('name')
            });
        },

        /**
         * Unload the Category and set all settings
         *
         * @param {Boolean} [clear] - Clear the html, default = true
         */
        unloadCategory: function(clear) {
            let i, len, Elm, name, tok, namespace;

            if (typeof clear === 'undefined') {
                clear = true;
            }

            const Body = this.getBody(),
                Form = Body.getElement('form'),
                values = {};

            if (Form) {
                for (i = 0, len = Form.elements.length; i < len; i++) {
                    Elm = Form.elements[i];
                    name = Elm.name;

                    if (name === '') {
                        continue;
                    }


                    if (Elm.type === 'radio' || Elm.type === 'checkbox') {
                        if (Elm.checked) {
                            values[name] = 1;
                        } else {
                            values[name] = 0;
                        }

                        continue;
                    }

                    values[name] = Elm.value;
                }
            }

            // set the values to the $config
            for (namespace in values) {
                if (!values.hasOwnProperty(namespace)) {
                    continue;
                }

                if (!namespace.match('.')) {
                    this.$config[namespace] = values[namespace];
                    continue;
                }

                tok = namespace.split('.');

                if (typeof tok[0] !== 'undefined' && typeof tok[1] !== 'undefined') {
                    if (typeof this.$config[tok[0]] === 'undefined') {
                        this.$config[tok[0]] = {};
                    }

                    this.$config[tok[0]][tok[1]] = values[namespace];
                }
            }

            if (this.$Control && clear) {
                this.$Control.destroy();
                this.$Control = null;
            }
        },

        /**
         * event : click on category button
         *
         * @param {Object} Category - qui/controls/buttons/Button
         */
        $onCategoryActive: function(Category) {
            if (Category.getAttribute('click') && Category.getAttribute('click') !== '') {
                require([Category.getAttribute('click')], function(f) {
                    f();
                });

                // select old category
                const OldCategory = this.getActiveCategory();
                this.unloadCategory();

                setTimeout(function() {
                    OldCategory.click();
                }, 200);

                return;
            }

            this.setAttribute(
                'title',
                QUILocale.get('quiqqer/core', 'panel.settings.title', {
                    title: this.$title,
                    category: Category.getAttribute('title')
                })
            );

            this.refresh();

            this.unloadCategory();
            this.loadCategory(Category);
        },

        /**
         * Send the configuration to the server
         */
        save: function() {
            this.Loader.show();
            this.unloadCategory(false);

            const inList = {};

            // filter controls with save method
            const saveAble = QUI.Controls.getControlsInElement(this.getBody()).filter(function(Control) {
                if (Control.getId() in inList) {
                    return false;
                }

                if (typeof Control.save === 'undefined') {
                    return false;
                }

                inList[Control.getId()] = true;
                return true;
            });

            let promises = saveAble.map(function(Control) {
                return Control.save();
            }).filter(function(Promise) {
                return typeof Promise.then === 'function';
            });

            if (!promises.length) {
                promises = [Promise.resolve()];
            }

            Promise.all(promises).then(() => {
                const Save = this.getButtonBar().getElement('save');
                Save.setAttribute('textimage', 'fa fa-refresh fa-spin');

                Ajax.post('ajax_settings_save', () => {
                    Save.setAttribute('textimage', 'fa fa-save');
                    this.Loader.hide();
                }, {
                    file: JSON.encode(this.$file),
                    params: JSON.encode(this.$config)
                });

            });
        }
    });
});
