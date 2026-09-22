/**
 * Project settings panel
 */
define('controls/projects/project/Settings', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/buttons/Button',
    'qui/controls/windows/Confirm',
    'qui/utils/Form',
    'utils/Template',
    'controls/lang/Popup',
    'Projects',
    'Ajax',
    'Locale',
    'utils/Controls',
    'package/quiqqer/translator/bin/controls/Create',

    'css!controls/projects/project/Settings.css',
    'css!controls/desktop/panels/XML.css'

], function (QUI,
             QUIPanel,
             QUIButton,
             QUIConfirm,
             QUIFormUtils,
             UtilsTemplate,
             LangPopup,
             Projects,
             Ajax,
             Locale,
             ControlUtils,
             Translation) {
    "use strict";

    const lg = 'quiqqer/core';

    /**
     * The Project settings panel
     *
     * @class controls/projects/project/Settings
     *
     * @param {String} project
     * @param {Object} options
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QUIPanel,
        Type   : 'controls/projects/project/Settings',

        Binds: [
            '$onCreate',
            '$onResize',
            '$onCategoryEnter',
            '$onCategoryLeave',
            '$openCreatePageStructureDialog',

            'save',
            'del',
            'openSettings',
            'openCustomCSS',
            'openCustomJS',
            'openAdminSettings',
            'openBackup',
            'openMediaSettings'
        ],

        options: {
            project : '',
            category: false // open category on settings panel load
        },

        initialize: function (options) {
            this.parent(options);

            if (!this.getAttribute('project') && "attributes" in options) {
                this.parent(options.attributes);
            }

            // defaults
            this.$Project = Projects.get(
                this.getAttribute('project')
            );

            this.$Control = null;
            this.$ProjectTitle = null;
            this.$Prefix = null;
            this.$Suffix = null;

            this.$config = {};
            this.$defaults = {};

            this.addEvents({
                onCreate       : this.$onCreate,
                onResize       : this.$onResize,
                onCategoryEnter: this.$onCategoryEnter
            });
        },

        /**
         * Return the Project of the Panel
         *
         * @method controls/projects/project/Settings#getProject
         * @return {Object} classes/projects/Project -  Project of the Panel
         */
        getProject: function () {
            return this.$Project;
        },

        /**
         * Create the project settings body
         *
         * @method controls/projects/project/Settings#$onCreate
         */
        $onCreate: function () {
            const self = this;

            this.getElm().classList.add('quiqqer-xml-panel');
            this.Loader.show();
            this.getContent().addClass('qui-project-settings');

            this.addButton({
                name     : 'save',
                text     : Locale.get(lg, 'projects.project.panel.settings.btn.save'),
                textimage: 'fa fa-save',
                events   : {
                    onClick: this.save
                }
            });

            this.addButton({
                name     : 'remove',
                text     : Locale.get(lg, 'projects.project.panel.settings.btn.remove'),
                textimage: 'fa fa-remove',
                events   : {
                    onClick: this.del
                }
            });

            this.addCategory({
                name  : 'settings',
                text  : Locale.get(lg, 'projects.project.panel.settings.btn.settings'),
                icon  : 'fa fa-gear',
                events: {
                    onClick: this.openSettings
                }
            });

            this.addCategory({
                name  : 'adminSettings',
                text  : Locale.get(lg, 'projects.project.panel.settings.btn.adminSettings'),
                icon  : 'fa fa-gear',
                events: {
                    onClick: this.openAdminSettings
                }
            });

            this.addCategory({
                name  : 'mediaSettings',
                text  : Locale.get(lg, 'projects.project.panel.settings.btn.media'),
                icon  : 'fa fa-picture-o',
                events: {
                    onClick: this.openMediaSettings
                }
            });

            this.addCategory({
                name  : 'customCSS',
                text  : Locale.get(lg, 'projects.project.panel.settings.btn.customCSS'),
                icon  : 'fa fa-css3',
                events: {
                    onClick: this.openCustomCSS
                }
            });

            this.addCategory({
                name  : 'customJS',
                text  : Locale.get(lg, 'projects.project.panel.settings.btn.customJS'),
                icon  : 'fa fa-code',
                events: {
                    onClick: this.openCustomJS
                }
            });

            this.$Container = new Element('div', {
                styles: {
                    left    : 0,
                    height  : '100%',
                    padding : 10,
                    position: 'absolute',
                    top     : 0,
                    width   : '100%'
                }
            }).inject(this.getBody());

            this.getBody().setStyles({
                position: 'relative'
            });


            Ajax.get('ajax_project_panel_categories_get', function (list) {
                for (let i = 0, len = list.length; i < len; i++) {
                    self.addCategory(list[i]);
                }

                self.refreshData();
            }, {
                project: this.getProject().encode()
            });
        },

        /**
         * Refresh the project data
         */
        refreshData: function () {
            this.Loader.show();

            const self = this;

            Promise.all([
                this.getProject().getConfig(),
                this.getProject().getDefaults()
            ]).then(function (result) {
                self.setAttributes({
                    name : 'projects-panel',
                    icon : 'fa fa-home',
                    title: self.getProject().getTitle()
                });

                self.$Title.textContent = self.getAttribute('title');

                self.$config = result[0];
                self.$defaults = result[1];

                self.Loader.hide();

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
                self.refresh();
            });
        },

        /**
         * Save the project settings
         *
         * @return {Promise}
         */
        save: function () {
            const self = this;

            this.Loader.show();
            this.$onCategoryLeave(false);
            
            const Project  = this.getProject(),
                  name     = Project.getName(),
                  loadHide = function () {
                    self.Loader.hide();
                };

            for (const project in Projects.$projects) {
                if (!Projects.$projects.hasOwnProperty(project)) {
                    continue;
                }

                if (project.match(name + '-')) {
                    if ("$config" in Projects.$projects[project]) {
                        Projects.$projects[project].$config = false;
                    }
                }
            }


            const promises = [Project.setConfig(this.$config)];
            let localeSaving = Promise.resolve();

            if (this.$ProjectTitle) {
                localeSaving = localeSaving.then(function () {
                    return self.$saveProjectTitle();
                });
            }

            if (this.$Suffix) {
                localeSaving = localeSaving.then(function () {
                    return self.$Suffix.save();
                });
            }

            if (this.$Prefix) {
                localeSaving = localeSaving.then(function () {
                    return self.$Prefix.save();
                });
            }

            promises.push(localeSaving);

            return Promise.all(promises).then(loadHide).catch(loadHide);
        },

        /**
         * Opens the delete dialog
         */
        del: function () {
            const self = this;

            new QUIConfirm({
                icon       : 'fa fa-exclamation-circle',
                title      : Locale.get(lg, 'projects.project.project.delete.window.title'),
                text       : Locale.get(lg, 'projects.project.project.delete.window.text'),
                texticon   : 'fa fa-exclamation-circle',
                information: Locale.get(lg, 'projects.project.project.delete.window.information'),
                maxWidth   : 450,
                maxHeight  : 300,
                events     : {
                    onSubmit: function () {
                        new QUIConfirm({
                            icon     : 'fa fa-exclamation-circle',
                            title    : Locale.get(lg, 'projects.project.project.delete.window.title'),
                            text     : Locale.get(lg, 'projects.project.project.delete.window.text.2'),
                            texticon : 'fa fa-exclamation-circle',
                            maxWidth : 450,
                            maxHeight: 300,
                            events   : {
                                onSubmit: function () {
                                    Projects.deleteProject(self.$Project.getName(), function () {
                                        self.destroy();
                                    });
                                }
                            }
                        }).open();
                    }
                }
            }).open();
        },

        /**
         * Opens the Settings
         *
         * @method controls/projects/project/Settings#openSettings
         *
         * @return {Promise}
         */
        openSettings: function () {
            const self = this,
                  Body = this.$Container;

            return new Promise(function (resolve) {
                self.$hideBody().then(function () {
                    return Promise.all([
                        self.$Project.getTitleLocaleData(),
                        self.$getLocaleData('project/' + self.$Project.getName(), 'template.prefix', 'quiqqer/core'),
                        self.$getLocaleData('project/' + self.$Project.getName(), 'template.suffix', 'quiqqer/core')
                    ]);
                }).then(function (localeData) {
                    Ajax.get('ajax_project_panel_settings', function (result) {
                        Body.set('html', result);

                        // set data
                        const Form     = Body.getElement('Form'),
                              Standard = Form.elements.default_lang,
                              Template = Form.elements.template,
                              Langs    = Form.elements.langs,

                              langs = self.$config.langs.split(',');

                        for (let i = 0, len = langs.length; i < len; i++) {
                            new Element('option', {
                                html : langs[i],
                                value: langs[i]
                            }).inject(Standard);

                            new Element('option', {
                                html : langs[i],
                                value: langs[i]
                            }).inject(Langs);
                        }

                        // project title
                        self.$ProjectTitle = new Translation({
                            'group'  : 'project/' + self.$Project.getName(),
                            'var'    : 'title',
                            'type'   : 'php,js',
                            'package': false,
                            'data'   : localeData[0]
                        }).inject(
                            Body.getElement('.project-title-settings-container')
                        );

                        // prefix
                        self.$Prefix = new Translation({
                            'group'  : 'project/' + self.$Project.getName(),
                            'var'    : 'template.prefix',
                            'type'   : 'php,js',
                            'package': 'quiqqer/core',
                            'data'   : localeData[1]
                        }).inject(
                            Body.getElement('.prefix-settings-container')
                        );

                        // suffix
                        self.$Suffix = new Translation({
                            'group'  : 'project/' + self.$Project.getName(),
                            'var'    : 'template.suffix',
                            'type'   : 'php,js',
                            'package': 'quiqqer/core',
                            'data'   : localeData[2]
                        }).inject(
                            Body.getElement('.suffix-settings-container')
                        );

                        new QUIButton({
                            text     : Locale.get(lg, 'projects.project.panel.btn.addlanguage'),
                            textimage: 'fa fa-plus',
                            styles   : {
                                width: '100%',
                                clear: 'both'
                            },
                            events   : {
                                onClick: function () {
                                    new LangPopup({
                                        events: {
                                            onSubmit: function (value) {
                                                self.addLangToProject(value[0]);
                                            }
                                        }
                                    }).open();
                                }
                            }
                        }).inject(Langs, 'after');


                        Standard.value = self.$config.default_lang;
                        Template.value = self.$config.template;

                        new QUIButton({
                            text  : Locale.get('quiqqer/core', 'projects.project.settings.panel.defaultSitestructure.button'),
                            styles: {
                                width: '100%'
                            },
                            events: {
                                onClick: self.$openCreatePageStructureDialog
                            }
                        }).inject(Form.getElement('.create-default-structure'));


                        QUIFormUtils.setDataToForm(self.$config, Form);

                        Promise.all([
                            QUI.parse(Body),
                            ControlUtils.parse(Body)
                        ]).then(function () {
                            QUI.Controls.getControlsInElement(Body).each(function (Control) {
                                if ("setProject" in Control) {
                                    Control.setProject(self.$Project);
                                }
                            });

                            return self.$showBody();
                        }).then(resolve);
                    }, {
                        project: self.getProject().encode()
                    });
                });
            });
        },

        /**
         * Opens the Settings for the administration
         *
         * @method controls/projects/project/Settings#openAdminSettings
         *
         * @return {Promise}
         */
        openAdminSettings: function () {
            return new Promise(function (resolve) {
                const self = this,
                      Body = this.$Container;

                this.$onCategoryLeave().then(function () {
                    UtilsTemplate.get('project/settingsAdmin', function (result) {
                        Body.set('html', result);

                        QUIFormUtils.setDataToForm(self.$config, Body.getElement('Form'));

                        self.$showBody().then(resolve);
                    });
                });
            }.bind(this));
        },

        /**
         * Open Custom CSS
         *
         * @return {Promise}
         */
        openCustomCSS: function () {
            return this.$onCategoryLeave().then(() => {
                return new Promise((resolve) => {
                    this.$Container.set('html', '<form></form>');

                    require(['controls/projects/project/settings/CustomCSS'], (CustomCSS) => {
                        let css  = false,
                            Form = this.getBody().getElement('form');

                        if ("project-custom-css" in this.$config) {
                            css = this.$config["project-custom-css"];
                        }

                        this.$Control = new CustomCSS({
                            Project: this.getProject(),
                            css    : css,
                            events : {
                                onLoad: () => {
                                    this.$showBody().then(resolve);
                                }
                            }
                        }).inject(Form);

                        Form.setStyles({
                            'float': 'left',
                            height : '100%',
                            width  : '100%'
                        });
                    });
                });
            });
        },

        /**
         * Open Custom JavaScript
         *
         * @return {Promise}
         */
        openCustomJS: function () {
            return this.$onCategoryLeave().then(() => {
                return new Promise((resolve) => {
                    this.$Container.set('html', '<form></form>');

                    require(['controls/projects/project/settings/CustomJS'], (CustomJS) => {
                        let js   = false,
                            Form = this.getBody().getElement('form');

                        if ("project-custom-js" in this.$config) {
                            js = this.$config["project-custom-js"];
                        }

                        this.$Control = new CustomJS({
                            Project   : this.getProject(),
                            javascript: js,
                            events    : {
                                onLoad: () => {
                                    this.$showBody().then(resolve);
                                }
                            }
                        }).inject(Form);

                        Form.setStyles({
                            'float': 'left',
                            height : '100%',
                            width  : '100%'
                        });
                    });
                });
            });
        },

        /**
         * Opens the Media Settings
         *
         * @method controls/projects/project/Settings#openMediaSettings
         *
         * @return {Promise}
         */
        openMediaSettings: function () {
            return this.$onCategoryLeave().then(function () {
                const self      = this,
                      Container = this.$Container;

                Container.set('html', '');

                return new Promise(function (resolve) {
                    require(['controls/projects/project/settings/Media'], function (MediaSettings) {
                        new MediaSettings({
                            config : self.$config,
                            Project: self.$Project,
                            events : {
                                onLoad: function () {
                                    self.$showBody().then(resolve);
                                }
                            }
                        }).inject(Container);
                    });
                });
            }.bind(this));
        },

        /**
         * event : on panel resize
         *
         * @method controls/projects/project/Settings#$onResize
         */
        $onResize: function () {

        },

        /**
         * unload the category and set the values into the config
         *
         * @param {Boolean} [noHide] - hide effect, default = true
         * @return {Promise}
         */
        $onCategoryLeave: function (noHide) {
            const Content = this.getContent(),
                  Form    = Content.getElement('form');

            if (typeof noHide === 'undefined') {
                noHide = true;
            }

            if (this.$Control && typeof this.$Control.save === 'function') {
                this.$Control.save();

                if (noHide === true) {
                    this.$Control = null;
                }
            }

            if (!Form) {
                return Promise.resolve();
            }


            if (this.$ProjectTitle && noHide) {
                this.$ProjectTitle.destroy();
                this.$ProjectTitle = null;
            }

            if (this.$Prefix && noHide) {
                this.$Prefix.destroy();
                this.$Prefix = null;
            }

            if (this.$Suffix && noHide) {
                this.$Suffix.destroy();
                this.$Suffix = null;
            }

            let data = QUIFormUtils.getFormData(Form);

            for (let i in data) {
                if (data.hasOwnProperty(i)) {
                    this.$config[i] = data[i];
                }
            }

            // exist langs?
            if (typeof Form.elements.langs !== 'undefined') {
                let Langs = Form.elements.langs,
                    langs = Langs.getElements('option').map(function (Elm) {
                        return Elm.value;
                    });

                this.$config.langs = langs.join(',');
            }

            if (noHide === false) {
                return Promise.resolve();
            }

            return this.$hideBody();
        },

        /**
         * Add a language to the project
         *
         * @param {String} lang
         */
        addLangToProject: function (lang) {
            const self = this;

            self.Loader.show();

            this.$Project.getConfig(function (config) {
                const langs = config.langs.split(',');
                langs.push(lang);

                self.$Project.setConfig({
                    langs: langs.join(',')
                }).then(function () {
                    // self.Loader.hide();
                    self.refreshData();
                }).catch(function () {
                    // self.Loader.hide();
                    self.refreshData();
                });
            });
        },

        /**
         * event : on category enter
         *
         * @param {Object} Panel - qui/controls/desktop/Panel
         * @param {Object} Category - qui/controls/buttons/Button
         *
         * @return {Promise}
         */
        $onCategoryEnter: function (Panel, Category) {
            const self = this,
                  name = Category.getAttribute('name');

            switch (name) {
                case "settings":
                case "adminSettings":
                case "customCSS":
                case "customJS":
                case "mediaSettings":
                    return Promise.resolve(1);
            }

            this.$onCategoryLeave().then(function () {
                this.$Container.set('html', '');

                return new Promise(function (resolve) {
                    Ajax.get('ajax_project_panel_categories_category', function (result) {
                        const Body = self.$Container;

                        if (!result) {
                            result = '';
                        }

                        Body.set('html', '<form>' + result + '</form>');
                        Body.getElements('tr td:first-child').addClass('first');

                        const Form = Body.getElement('form');

                        Form.name = Category.getAttribute('name');
                        Form.addEvent('submit', function (event) {
                            event.stop();
                        });

                        // set data to the form
                        QUIFormUtils.setDataToForm(self.$config, Form);

                        Form.getElements('input').each(function (Input) {
                            const name = Input.get('name');
                            if (name in self.$defaults) {
                                Input.set('data-qui-options-defaultcolor', self.$defaults[name]);
                            }
                        });

                        Promise.all([
                            QUI.parse(Body),
                            ControlUtils.parse(Body)
                        ]).then(function () {
                            let i, len, Control;
                            const quiids = Body.getElements('[data-quiid]');

                            for (i = 0, len = quiids.length; i < len; i++) {
                                Control = QUI.Controls.getById(quiids[i].get('data-quiid'));

                                if (!Control) {
                                    continue;
                                }

                                if (typeOf(Control.setProject) === 'function') {
                                    Control.setProject(self.getProject());
                                }
                            }

                            self.$showBody().then(resolve);
                        });
                    }, {
                        file    : Category.getAttribute('file'),
                        category: Category.getAttribute('name'),
                        project : self.getProject().encode()
                    });
                });
            }.bind(this));
        },

        /**
         * open the dialog for default page structure creation
         *
         * @param {Object} Btn
         */
        $openCreatePageStructureDialog: function (Btn) {
            const self = this;

            const defaultButtonText = Locale.get(
                'quiqqer/core',
                'projects.project.settings.panel.defaultSitestructure.button'
            );

            if (Btn) {
                Btn.setAttribute('text', '<span class="fa fa-spinner fa-spin"></span>');
            }

            new QUIConfirm({
                icon       : 'fa fa-sitemap',
                texticon   : 'fa fa-sitemap',
                title      : Locale.get('quiqqer/core', 'projects.project.settings.panel.defaultSitestructure.win.title'),
                text       : Locale.get('quiqqer/core', 'projects.project.settings.panel.defaultSitestructure.win.text'),
                information: Locale.get('quiqqer/core', 'projects.project.settings.panel.defaultSitestructure.win.information'),
                maxHeight  : 300,
                maxWidth   : 600,
                autoclose  : false,
                events     : {
                    onSubmit: function (Win) {
                        Win.Loader.show();

                        Ajax.post('ajax_project_createDefaultStructure', function () {
                            if (Btn) {
                                Btn.setAttribute('text', '<span class="fa fa-check"></span>');

                                (function () {
                                    Btn.setAttribute('text', defaultButtonText);
                                }).delay(2000);
                            }

                            Win.close();
                        }, {
                            project: self.getProject().encode(),
                            onError: function () {
                                Btn.setAttribute('text', defaultButtonText);
                            }
                        });
                    },

                    onCancel: function () {
                        Btn.setAttribute('text', defaultButtonText);
                    }
                }
            }).open();
        },

        /**
         * Hide the body
         *
         * @returns {Promise}
         */
        $hideBody: function () {
            return new Promise(function (resolve) {
                moofx(this.$Container).animate({
                    opacity: 0,
                    top    : -50
                }, {
                    duration: 200,
                    callback: resolve
                });
            }.bind(this));
        },

        /**
         * Show the body
         *
         * @returns {Promise}
         */
        $showBody: function () {
            const Body = this.$Container;

            Body.setStyles({
                top: -50
            });

            return new Promise(function (resolve) {
                moofx(Body).animate({
                    opacity: 1,
                    top    : 0
                }, {
                    duration: 200,
                    callback: resolve
                });
            });
        },

        /**
         * @param group
         * @param v
         * @param p
         * @return {Promise}
         */
        $getLocaleData: function (group, v, p) {
            return new Promise(function (resolve) {
                Ajax.get('package_quiqqer_translator_ajax_getVarData', resolve, {
                    'package': 'quiqqer/translator',
                    'group'  : group,
                    'var'    : v,
                    'pkg'    : p
                });
            });
        },

        /**
         * Save the project title without changing original package locale values.
         *
         * @return {Promise}
         */
        $saveProjectTitle: function () {
            const translations = {};
            const inputs = this.$ProjectTitle.getElm().getElements('input');

            for (let i = 0, len = inputs.length; i < len; i++) {
                if (!inputs[i].name.match(/^[a-z]{2}$/)) {
                    continue;
                }

                translations[inputs[i].name] = inputs[i].value;
            }

            return this.$Project.setTitleLocaleData(translations).then(function (localeData) {
                this.$ProjectTitle.setAttribute('data', localeData);
            }.bind(this));
        }
    });
});
