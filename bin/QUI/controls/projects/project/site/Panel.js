/**
 * Displays a Site in a Panel
 *
 *
 * @event onQuiqqerSitePanelBuild [self] - Fires when the Site panel is built and categories / buttons are added
 */
define('controls/projects/project/site/Panel', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'Projects',
    'Ajax',
    'qui/controls/buttons/Button',
    'qui/controls/windows/Confirm',
    'qui/utils/Form',
    'qui/utils/Elements',
    'utils/Controls',
    'utils/Panels',
    'utils/Site',
    'Locale',
    'Users',
    'Mustache',

    'text!controls/projects/project/site/Panel.restore.html',
    'css!controls/projects/project/site/Panel.css'

], function () {
    "use strict";

    const QUI             = arguments[0],
          QUIPanel        = arguments[1],
          Projects        = arguments[2],
          Ajax            = arguments[3],
          QUIButton       = arguments[4],
          QUIConfirm      = arguments[5],
          QUIFormUtils    = arguments[6],
          QUIElmUtils     = arguments[7],
          ControlUtils    = arguments[8],
          PanelUtils      = arguments[9],
          SiteUtils       = arguments[10],
          Locale          = arguments[11],
          Users           = arguments[12],
          Mustache        = arguments[13],
          templateRestore = arguments[14];

    const lg = 'quiqqer/core';

    const cleanupUrl = function (value) {
        const notAllowed = Object.keys(SiteUtils.notAllowedUrlSigns()).join('|'),
              reg        = new RegExp('[' + notAllowed + ']', "g");

        value = value.replace(reg, '');
        value = value.replace(/ /g, QUIQQER.Rewrite.URL_SPACE_CHARACTER);

        // quiqqer/core#980 --- to -
        value = value.replace(
            new RegExp(
                QUIQQER.Rewrite.URL_SPACE_CHARACTER + QUIQQER.Rewrite.URL_SPACE_CHARACTER + '+', "g"
            ),
            QUIQQER.Rewrite.URL_SPACE_CHARACTER
        );

        return value;
    };

    /**
     * An SitePanel, opens the Site in an Apppanel
     *
     * @class controls/projects/project/site/Panel
     *
     * @param {Object} Site - classes/projects/Site
     * @param {Object} options
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QUIPanel,
        Type   : 'controls/projects/project/site/Panel',

        Binds: [
            'load',
            'createNewChild',
            'openPermissions',
            'openMedia',
            'openSort',
            'deleteLinked',
            'openSiteInPopup',
            'openSiteInStructure',
            'deactivate',
            'activate',

            '$onCreate',
            '$onDestroy',
            '$onResize',
            '$onInject',
            '$onCategoryEnter',
            '$onCategoryLeave',
            '$onEditorDestroy',
            '$onPanelButtonClick',
            '$onLogin',

            '$onSiteActivate',
            '$onSiteDeactivate',
            '$onSiteSave',
            '$onSiteUrlChange',
            '$onProjectSiteMove',
            '$onSiteDelete',
            '$onKeyDown'
        ],

        options: {
            id           : 'projects-site-panel',
            container    : false,
            editorPeriode: 2000
        },

        initialize: function (Site, options) {
            this.$built = false;
            this.$lockToken = Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
            this.$ownsEditingLock = false;
            this.$lockDestroyed = false;
            this.$lockTimer = null;
            this.$lockExpiryTimer = null;
            this.$Site = null;
            this.$CategoryControl = null;
            this.$Container = null;

            this.$ButtonOpenWebsite = null;
            this.$ButtonPermissions = null;
            this.$ButtonMedia = null;
            this.$ButtonSort = null;
            this.$PreviousCategory = null;
            this.$deletedState = false;
            this.$editorPeriodicalSave = false; // delay for the wysiwyg editor, to save to the locale storage

            if (typeOf(Site) === 'classes/projects/project/Site') {
                const Project = Site.getProject(),
                      id      = 'panel-' +
                                Project.getName() + '-' +
                                Project.getLang() + '-' +
                                Site.getId();

                // default id
                this.setAttribute('id', id);
                this.setAttribute('name', id);

                this.$Site = Site;
            } else {
                // serialize data
                if (typeof Site.attributes !== 'undefined' &&
                    typeof Site.project !== 'undefined' &&
                    typeof Site.lang !== 'undefined' &&
                    typeof Site.id !== 'undefined') {
                    this.unserialize(Site);
                }
            }

            this.parent(options);

            this.addEvents({
                onCreate : this.$onCreate,
                onResize : this.$onResize,
                onDestroy: this.$onDestroy,
                onInject : this.$onInject
            });
        },

        /**
         *
         * @return {Promise}
         */
        getToolTipText: function () {
            const self = this;

            return new Promise(function (resolve) {
                const project = self.$Site.getProject().getName();
                const lang = self.$Site.getProject().getLang();
                const id = self.$Site.getId();

                const tpl = '<table>' +
                            '<tr>' +
                            '   <td>{{localeProject}}</td>' +
                            '   <td>{{project}}</td>' +
                            '</tr>' +
                            '<tr>' +
                            '   <td>{{localeLang}}</td>' +
                            '   <td><img src="' + window.URL_OPT_DIR +
                            'quiqqer/core/bin/16x16/flags/{{lang}}.png" alt="" /> {{lang}}</td>' +
                            '</tr>' +
                            '<tr>' +
                            '   <td>{{localeID}}</td>' +
                            '   <td>{{id}}</td>' +
                            '</tr>' +
                            '<tr>' +
                            '   <td>{{localeUrl}}</td>' +
                            '   <td>{{url}}</td>' +
                            '</tr>' +
                            '</table>';

                const result = Mustache.render(tpl, {
                    localeProject: Locale.get(lg, 'project'),
                    localeLang   : Locale.get(lg, 'language'),
                    localeID     : Locale.get(lg, 'id'),
                    localeUrl    : Locale.get(lg, 'projects.project.site.panel.information.nameUrl'),

                    project: project,
                    lang   : lang,
                    id     : id,
                    url    : self.$Site.getUrl()
                });

                resolve(result);
            });
        },

        /**
         * Save the site panel to the workspace
         *
         * @method controls/projects/project/site/Panel#serialize
         * @return {Object} data
         */
        serialize: function () {
            const Site    = this.getSite(),
                  Project = Site.getProject();

            return {
                attributes: this.getAttributes(),
                id        : this.getSite().getId(),
                lang      : Project.getLang(),
                project   : Project.getName(),
                type      : this.getType()
            };
        },

        /**
         * import the saved data form the workspace
         *
         * @method controls/projects/project/site/Panel#unserialize
         * @param {Object} data
         * @return {Object} this (controls/projects/project/site/Panel)
         */
        unserialize: function (data) {
            this.setAttributes(data.attributes);

            const Project = Projects.get(
                data.project,
                data.lang
            );

            this.$Site = Project.get(data.id);
            this.$delayTest = 0;

            return this;
        },

        /**
         * Return the Site object from the panel
         *
         * @method controls/projects/project/site/Panel#getSite
         * @return {Object} classes/projects/Site
         */
        getSite: function () {
            return this.$Site;
        },

        /**
         * Open the site in a popup
         */
        openSiteInPopup: function () {
            const Site    = this.getSite(),
                  Project = Site.getProject();

            SiteUtils.openSite(
                Project.getName(),
                Project.getLang(),
                Site.getId()
            );
        },

        /**
         * Opens the site in the project panel
         */
        openSiteInStructure: function () {
            let Panel;
            const projectPanels = QUI.Controls.getByType('controls/projects/project/Panel');

            const Site    = this.getSite(),
                  Project = Site.getProject();

            const onOpen = function () {
                Panel.openSite(Site.getId());
            };

            for (let i = 0, len = projectPanels.length; i < len; i++) {
                Panel = projectPanels[i];

                if (Panel.getAttribute('project') === Project.getName() &&
                    Panel.getAttribute('lang') === Project.getLang()) {
                    Panel.openSite(Site.getId());
                    continue;
                }

                Panel.setAttribute('project', Project.getName());
                Panel.setAttribute('lang', Project.getLang());
                Panel.openProject().then(onOpen);
            }
        },

        /**
         * Load the site attributes to the panel
         *
         * @method controls/projects/project/site/Panel#load
         */
        load: function () {
            this.refresh();

            if (this.$isDeleted()) {
                return this.$showDeletedState();
            }

            if (this.$deletedState) {
                this.$destroyRefresh();
                return Promise.resolve();
            }

            if (this.getSite().getAttribute('active') && this.$ButtonOpenWebsite) {
                this.$ButtonOpenWebsite.show();
            }

            if (this.getActiveCategory()) {
                return this.$onCategoryEnter(this.getActiveCategory());
            }

            if (this.getCategoryBar().firstChild()) {
                this.getCategoryBar().firstChild().click();
                return Promise.resolve();
            }

            return new Promise(function (resolve, reject) {
                // if dom is not loaded, we wait 200ms
                (function () {
                    if (this.$delayTest > 10) {
                        const errorMessage = Locale.get('quiqqer/core', 'exception.site.panel.error', {
                            id: this.getSite().getId()
                        });

                        QUI.getMessageHandler(function (MH) {
                            MH.addError(errorMessage);
                        }.bind(this));

                        this.destroy();
                        reject(errorMessage);
                        return;
                    }

                    this.$delayTest++;
                    this.load().then(resolve);
                }).delay(200, this);
            }.bind(this));
        },

        /**
         * Refresh the site panel
         */
        refresh: function () {
            let title, description;

            const Site    = this.getSite(),
                  Project = Site.getProject();

            title = Site.getAttribute('title') + ' (' + Site.getId() + ')';

            description = Site.getAttribute('name') + ' - ' +
                          Site.getId() + ' - ' +
                          Project.getName();

            if (parseInt(Site.getId(), 10) !== 1) {
                description = description + ' - ' + Site.getUrl();
            }

            this.setAttributes({
                title      : title,
                description: description,
                icon       : URL_BIN_DIR + '16x16/flags/' + Project.getLang() + '.png'
            });

            this.parent();
        },

        /**
         * Create the panel design
         *
         * @method controls/projects/project/site/Panel#$onCreate
         */
        $onCreate: function () {
            this.Loader.show();

            window.addEvent('login', this.$onLogin);

            this.$Container = new Element('div', {
                styles: {
                    height  : '100%',
                    position: 'relative',
                    width   : '100%'
                }
            }).inject(this.getContent());

            this.getContent().setStyle('position', 'relative');


            // permissions
            this.$ButtonPermissions = new QUIButton({
                image : 'fa fa-shield',
                name  : 'permissions',
                alt   : Locale.get(lg, 'projects.project.site.panel.btn.permissions'),
                title : Locale.get(lg, 'projects.project.site.panel.btn.permissions'),
                styles: {
                    'border-left-width' : 1,
                    'border-right-width': 1,
                    'float'             : 'right',
                    width               : 40
                },
                events: {
                    onClick: this.openPermissions
                }
            }).inject(this.getHeader());

            this.$ButtonMedia = new QUIButton({
                image : 'fa fa-picture-o',
                name  : 'media',
                alt   : Locale.get(lg, 'projects.project.site.panel.btn.media'),
                title : Locale.get(lg, 'projects.project.site.panel.btn.media'),
                styles: {
                    'border-left-width': 1,
                    'float'            : 'right',
                    width              : 40
                },
                events: {
                    onClick: this.openMedia
                }
            }).inject(this.getHeader());

            this.$ButtonSort = new QUIButton({
                image : 'fa fa-sort',
                name  : 'sort',
                alt   : Locale.get(lg, 'projects.project.site.panel.btn.sort'),
                title : Locale.get(lg, 'projects.project.site.panel.btn.sort'),
                styles: {
                    'border-left-width': 1,
                    'float'            : 'right',
                    width              : 40
                },
                events: {
                    onClick: this.openSort
                }
            }).inject(this.getHeader());
        },

        /**
         * build the categories and so on
         *
         * @return {Promise}
         */
        $buildPanel: function () {
            if (this.$built) {
                return Promise.resolve();
            }

            const self    = this,
                  Site    = this.getSite(),
                  Project = Site.getProject();

            return this.$acquireEditingLock().then(acquired => new Promise(function (resolve) {
                if (self.$lockDestroyed) {
                    resolve();
                    return;
                }
                Ajax.get([
                    'ajax_site_categories_get',
                    'ajax_site_buttons_get',
                    'ajax_site_isLockedFromOther'
                ], function (categories, buttons, isLocked) {
                    if (self.$lockDestroyed) {
                        resolve();
                        return;
                    }
                    let i, ev, fn, len, data, events, category, Category;

                    self.$built = true;

                    for (i = 0, len = buttons.length; i < len; i++) {
                        data = buttons[i];

                        if (data.onclick) {
                            data._onclick = data.onclick;
                            delete data.onclick;

                            data.events = {
                                onClick: self.$onPanelButtonClick
                            };
                        }

                        if (data.name === 'delete' || data.name === 'new') {
                            data.styles = {
                                'float': 'right',
                                width  : 40
                            };
                        }

                        self.addButton(data);
                    }

                    const Save = self.getButtonBar().getChildren('save');

                    if (Save) {
                        Save.getElm().addClass('qui-site-button-save');
                    }


                    for (i = 0, len = categories.length; i < len; i++) {
                        events = {};
                        category = categories[i];

                        if (typeOf(category.events) === 'object') {
                            events = category.events;
                            delete category.events;
                        }

                        Category = new QUIButton(category);

                        Category.addEvents({
                            onActive: self.$onCategoryEnter
                        });

                        for (ev in events) {
                            if (!events.hasOwnProperty(ev)) {
                                continue;
                            }

                            try {
                                eval('fn = ' + events[ev]);

                                Category.addEvent(ev, fn);
                            } catch (e) {
                            }
                        }

                        self.addCategory(Category);
                    }

                    self.$ButtonOpenWebsite = new QUIButton({
                        textimage: 'fa fa-external-link',
                        name     : 'sort',
                        text     : Locale.get('quiqqer/core', 'project.sitemap.open.in.window'),
                        title    : Locale.get('quiqqer/core', 'project.sitemap.open.in.window'),
                        events   : {
                            onClick: self.openSiteInPopup
                        }
                    });

                    self.addButton(self.$ButtonOpenWebsite);
                    self.$ButtonOpenWebsite.hide();

                    if (Site.getAttribute('active')) {
                        self.$ButtonOpenWebsite.show();
                    }

                    if (!acquired || isLocked) {
                        self.$loseEditingLock();
                    }

                    QUI.fireEvent('quiqqerSitePanelBuild', [self]);

                    resolve();
                }, {
                    project: Project.encode(),
                    id     : Site.getId()
                });
            }));
        },

        /**
         * event : on inject
         */
        $onInject: function () {
            const Site = this.getSite();

            Site.addEvents({
                onLoad      : this.load,
                onActivate  : this.$onSiteActivate,
                onDeactivate: this.$onSiteDeactivate,
                onSave      : this.$onSiteSave,
                onUrlChange : this.$onSiteUrlChange,
                onDelete    : this.$onSiteDelete
            });
            Site.getProject().addEvent('siteMove', this.$onProjectSiteMove);

            this.Loader.show();

            if (!Site.hasWorkingStorage()) {
                this.$buildPanel().then(function () {
                    Site.load();
                });

                return;
            }

            document.addEventListener('keydown', this.$onKeyDown);

            const self = this;

            this.$buildPanel().then(function () {
                return Site.hasWorkingStorageChanges();
            }).then(function (hasStorage) {
                if (self.$isDeleted()) {
                    Site.clearWorkingStorage();
                    return;
                }

                if (hasStorage === false) {
                    Site.load();
                    return;
                }

                const EditUser = Users.get(Site.getAttribute('e_user'));

                if (EditUser.isLoaded()) {
                    return Promise.resolve(EditUser);
                }

                return EditUser.load().then(function () {
                    return Promise.resolve(EditUser);
                });
            }).then(function (EditUser) {
                if (self.$isDeleted()) {
                    return;
                }

                if (!EditUser) {
                    Site.load();
                    return;
                }

                // Loading the editor user may outlive the draft's validity period.
                const storage = Site.getWorkingStorage();

                if (!storage) {
                    Site.load();
                    return;
                }

                const Sheet = self.createSheet({
                    icon : 'fa fa-window-restore',
                    title: Locale.get('quiqqer/core', 'panel.site.restore.title', {
                        id: Site.getId()
                    })
                });


                let StorageTime = null;
                let storageDate = '---';
                const EditDate = new Date(Site.getAttribute('e_date'));

                if ("__storageTime" in storage) {
                    StorageTime = new Date(storage.__storageTime);
                    storageDate = StorageTime.toLocaleDateString([], {
                        hour  : '2-digit',
                        minute: '2-digit'
                    });
                }

                const localeParams = {
                    id          : Site.getId(),
                    title       : Site.getAttribute('title'),
                    editUser    : EditUser.getName(),
                    editUsername: EditUser.getUsername(),
                    editDate    : EditDate.toLocaleDateString([], {
                        hour  : '2-digit',
                        minute: '2-digit'
                    }),
                    localeDate  : storageDate
                };

                let text = Locale.get(
                    'quiqqer/core',
                    'panel.site.restore.message.local.newer',
                    localeParams
                );

                if (StorageTime && EditDate > StorageTime) {
                    text = Locale.get(
                        'quiqqer/core',
                        'panel.site.restore.message.online.newer',
                        localeParams
                    );
                }

                Sheet.getContent().set('html', Mustache.render(templateRestore, {
                    id  : Site.getId(),
                    text: text
                }));

                Sheet.clearButtons();

                Sheet.addButton({
                    text  : Locale.get('quiqqer/core', 'panel.site.restore.button.cancel'),
                    events: {
                        onClick: function () {
                            Sheet.hide(function () {
                                Sheet.destroy();
                            });

                            Site.clearWorkingStorage();
                            self.load();
                        }
                    }
                });

                Sheet.addButton({
                    text  : Locale.get('quiqqer/core', 'panel.site.restore.button.restore'),
                    events: {
                        onClick: function () {
                            Sheet.hide(function () {
                                Sheet.destroy();
                            });

                            const currentStorage = Site.getWorkingStorage();

                            if (currentStorage) {
                                Site.setAttributes(currentStorage);
                            }

                            Site.clearWorkingStorage();
                            self.load();
                        }
                    }
                });

                Sheet.show(function () {
                    self.Loader.hide();
                });
            });
        },

        /**
         * event : on destroy
         */
        $onDestroy: function () {
            const Site = this.getSite();

            Site.removeEvent('onLoad', this.load);
            Site.removeEvent('onActivate', this.$onSiteActivate);
            Site.removeEvent('onDeactivate', this.$onSiteDeactivate);
            Site.removeEvent('onSave', this.$onSiteSave);
            Site.removeEvent('onUrlChange', this.$onSiteUrlChange);
            Site.getProject().removeEvent('siteMove', this.$onProjectSiteMove);
            Site.removeEvent('onDelete', this.$onSiteDelete);

            Site.clearWorkingStorage();

            window.removeEvent('login', this.$onLogin);
            document.removeEventListener('keydown', this.$onKeyDown);

            this.$lockDestroyed = true;
            clearTimeout(this.$lockTimer);
            clearTimeout(this.$lockExpiryTimer);
            this.$lockRequest('unlock', {token: this.$lockToken, force: 0}).catch(() => {});
        },

        /**
         * event: key down
         *
         * @param e
         */
        $onKeyDown: function (e) {
            if (e.ctrlKey && e.key === 's' && this.isOpen() && !this.$isDeleted()) {
                e.preventDefault();
                this.save();
            }
        },

        /**
         * event: panel resize
         *
         * @method controls/projects/project/site/Panel#$onResize
         */
        $onResize: function () {
            if (this.$CategoryControl) {
                if ("resize" in this.$CategoryControl) {
                    this.$CategoryControl.resize();
                }
            }

            if (this.getAttribute('Editor')) {
                this.getAttribute('Editor').resize();
            }
        },

        /**
         * Opens the site permissions
         *
         * @method controls/projects/project/site/Panel#openPermissions
         */
        openPermissions: function () {
            const Parent = this.getParent(),
                  Site   = this.getSite();

            require(['controls/permissions/Panel'], function (PermPanel) {
                Parent.appendChild(
                    new PermPanel({
                        Object: Site
                    })
                );
            });
        },

        /**
         * Opens the site media
         *
         * @method controls/projects/project/site/Panel#openMedia
         */
        openMedia: function () {
            const Parent  = this.getParent(),
                  Site    = this.getSite(),
                  Project = Site.getProject(),
                  Media   = Project.getMedia();

            require(['controls/projects/project/media/Panel'], function (Panel) {
                Parent.appendChild(new Panel(Media));
            });
        },

        /**
         * Opens the sort sheet
         *
         * @method controls/projects/project/site/Panel#openSort
         */
        openSort: function () {
            let Site = this.getSite(),
                Sort = false;

            const Sheets = this.createSheet({
                icon   : 'fa fa-sort',
                buttons: false,
                title  : Locale.get(lg, 'projects.project.site.panel.sort.title', {
                    id   : Site.getId(),
                    title: Site.getAttribute('title'),
                    name : Site.getAttribute('name')
                }),
                events : {
                    onOpen: function (Sheet) {
                        require([
                            'controls/projects/project/site/SiteChildrenSort'
                        ], function (SiteSort) {
                            Sort = new SiteSort(Site).inject(Sheet.getContent());
                        });
                    }
                }
            });

            Sheets.clearButtons();
            Sheets.show();
        },

        /**
         * saves site attributes
         *
         * @method controls/projects/project/site/Panel#openPermissions
         */
        save: function () {
            if (this.$isDeleted() || !this.$ownsEditingLock) {
                return Promise.resolve();
            }

            const self = this;

            return this.$onCategoryLeave(this.getActiveCategory()).then(function () {
                return self.getSite().save(null, self.$lockToken);
            }).then(function () {
                // refresh data
                const Form = self.$Container.getElement('form');

                if (Form) {
                    QUIFormUtils.setDataToForm(
                        self.getSite().getAttributes(),
                        Form
                    );
                }

                return self.load();
            }).catch(function (err) {
                console.error(err);
                self.$refreshEditingLock();
                self.Loader.hide();
            });
        },

        /**
         * opens the site delete dialog
         */
        del: function () {
            const Site = this.getSite();

            require(['qui/controls/windows/Confirm'], function (Confirm) {
                new Confirm({
                    title        : Locale.get(lg, 'projects.project.site.panel.window.delete.title', {
                        id: Site.getId()
                    }),
                    icon         : 'fa fa-trash-o',
                    text         : Locale.get(lg, 'projects.project.site.panel.window.delete.text', {
                        id   : Site.getId(),
                        url  : Site.getAttribute('name') + QUIQQER.Rewrite.SUFFIX,
                        name : Site.getAttribute('name'),
                        title: Site.getAttribute('title')
                    }),
                    texticon     : 'fa fa-trash-o',
                    information  : Locale.get(lg, 'projects.project.site.panel.window.delete.information', {
                        id   : Site.getId(),
                        url  : Site.getAttribute('name') + QUIQQER.Rewrite.SUFFIX,
                        name : Site.getAttribute('name'),
                        title: Site.getAttribute('title')
                    }),
                    maxHeight    : 400,
                    maxWidth     : 600,
                    autoclose    : false,
                    cancel_button: {
                        text     : Locale.get(lg, 'cancel'),
                        textimage: 'fa fa-remove'
                    },
                    ok_button    : {
                        text     : Locale.get(lg, 'projects.project.site.panel.window.delete.button'),
                        textimage: 'fa fa-trash-o'
                    },

                    events: {
                        onSubmit: function (Win) {
                            Win.Loader.show();

                            Site.del().then(function () {
                                Win.close();
                            }).catch(function () {
                                Win.Loader.hide();
                            });
                        }
                    }
                }).open();
            });
        },

        /**
         * Deactivate the site
         */
        deactivate: function () {
            (function () {
                this.Loader.show();
                this.getSite().deactivate();
            }).delay(100, this);
        },

        /**
         * Activate the site
         */
        activate: function () {
            (function () {
                this.Loader.show();
                this.getSite().activate();
            }).delay(100, this);
        },

        /**
         * Opens the delete Linked dialog
         *
         * @param {Number} parentId - Parent ID
         * @return {Promise}
         */
        deleteLinked: function (parentId) {
            const self = this,
                  Site = this.getSite();

            return new Promise(function (resolve, reject) {
                if (typeof parentId === 'undefined') {
                    reject();
                    return;
                }

                Site.getLinkedPath(parentId).then(function (path) {
                    require(['qui/controls/windows/Confirm'], function (Confirm) {
                        new Confirm({
                            title      : Locale.get(lg, 'projects.project.site.panel.window.deleteLinked.title', {
                                id      : Site.getId(),
                                parentId: parentId,
                                path    : path
                            }),
                            icon       : 'fa fa-trash-o',
                            text       : Locale.get(lg, 'projects.project.site.panel.window.deleteLinked.text', {
                                id      : Site.getId(),
                                url     : Site.getAttribute('name') + QUIQQER.Rewrite.SUFFIX,
                                name    : Site.getAttribute('name'),
                                title   : Site.getAttribute('title'),
                                parentId: parentId,
                                path    : path
                            }),
                            texticon   : 'fa fa-trash-o',
                            information: Locale.get(lg, 'projects.project.site.panel.window.deleteLinked.information', {
                                id      : Site.getId(),
                                url     : Site.getAttribute('name') + QUIQQER.Rewrite.SUFFIX,
                                name    : Site.getAttribute('name'),
                                title   : Site.getAttribute('title'),
                                parentId: parentId,
                                path    : path
                            }),
                            maxHeight  : 400,
                            maxWidth   : 600,
                            autoclose  : false,

                            cancel_button: {
                                text     : Locale.get(lg, 'cancel'),
                                textimage: 'fa fa-remove'
                            },
                            ok_button    : {
                                text     : Locale.get(lg, 'projects.project.site.panel.window.deleteLinked.button'),
                                textimage: 'fa fa-trash-o'
                            },

                            events: {
                                onSubmit: function (Win) {
                                    Win.Loader.show();

                                    Site.unlink(parentId, false).then(function () {
                                        self.load();
                                        Win.close();
                                        resolve();
                                    }).catch(function () {
                                        Win.Loader.hide();
                                        reject();
                                    });
                                },
                                onCancel: resolve
                            }
                        }).open();
                    });

                });
            });
        },

        /**
         * Create a child site
         *
         * @method controls/projects/project/site/Panel#createChild
         *
         * @param {String} [value] - [optional, if no newname was passed,
         *         a window would be open]
         */
        createNewChild: function (value) {
            SiteUtils.openCreateChild(this.getSite(), value);
        },

        /**
         * Enter the Tab / Category
         * Load the tab content and set the site attributes
         * or exec the plugin event
         *
         * @method controls/projects/project/site/Panel#$tabEnter
         * @fires onSiteTabLoad
         *
         * @param {Object} Category - qui/controls/toolbar/Button
         * @return {Promise}
         */
        $onCategoryEnter: function (Category) {
            const self = this;

            if (this.$isDeleted()) {
                this.Loader.hide();
                return Promise.resolve();
            }

            if (Category === this.getActiveCategory()) {
                this.Loader.hide();
                return Promise.resolve();
            }

            const setProject = function () {
                // set the project to the controls
                let i, len, Control;

                const Site    = self.getSite(),
                      Project = Site.getProject(),
                      Form    = self.getBody().getElement('form'),
                      quiids  = Form.getElements('[data-quiid]');

                for (i = 0, len = quiids.length; i < len; i++) {
                    Control = QUI.Controls.getById(
                        quiids[i].get('data-quiid')
                    );

                    if (!Control) {
                        continue;
                    }

                    if (typeOf(Control.setProject) === 'function') {
                        Control.setProject(Project);
                    }

                    Control.setAttribute('Site', self.getSite());
                }

                return self.$categoryOnLoad(Category);
            };

            this.Loader.show();

            return this.$onCategoryLeaveHide().then(function () {
                return self.$onCategoryLeave(self.$PreviousCategory);
            }).then(function () {
                // cleanup controls
                if (self.getAttribute('Editor')) {
                    self.getAttribute('Editor').destroy();
                }

                if (self.$CategoryControl) {
                    self.$CategoryControl.destroy();
                    self.$CategoryControl = null;
                }

                // create content
                self.$PreviousCategory = Category;

                if (Category.getAttribute('name') === 'content') {
                    return this.loadEditor(
                        this.getSite().getAttribute('content')
                    );
                }

                if (Category.getAttribute('type') === 'wysiwyg') {
                    return this.loadEditor(
                        this.getSite().getAttribute(
                            Category.getAttribute('name')
                        )
                    );
                }

                if (Category.getAttribute('type') === 'xml') {
                    this.$Container.set('html', '');

                    return this.$getCategoryFromXml(Category.getAttribute('name')).then(function (result) {
                        const Form = new Element('form', {
                            html: result
                        }).inject(self.$Container);

                        QUIFormUtils.setDataToForm(self.getSite().getAttributes(), Form);

                        return QUI.parse(Form).then(setProject);
                    });
                }

                if (!Category.getAttribute('template')) {
                    this.$Container.set('html', '');
                    this.$categoryOnLoad(Category);

                    return QUI.parse(this.$Container);
                }

                const Site    = self.getSite(),
                      Project = Site.getProject();

                return new Promise(function (resolve) {
                    Ajax.get([
                        'ajax_site_categories_template'
                    ], function (result) {
                        const Body = self.$Container;

                        if (!result) {
                            Body.set('html', '');
                            self.$categoryOnLoad(Category).then(resolve);
                            return;
                        }

                        Body.set('html', '<form class="qui-site-data">' + result + '</form>');

                        const Form = Body.getElement('form');

                        Form.addEvent('submit', function (event) {
                            event.stop();
                        });

                        // set to the media inputs the right project
                        Body.getElements('.media-image,.media-folder').each(function (Elm) {
                            Elm.set('data-project', Project.getName());
                        });

                        // minimize setting tables
                        if (Category.getAttribute('name') === 'settings') {
                            Body.getElements('.data-table:not(.site-data)')
                                .addClass('data-table-closed');
                        }

                        // search editor controls
                        if (Category.getAttribute('name') === 'settings') {
                            const Editors = Body.getElements('[data-qui="controls/editors/Editor"]');

                            if (Editors.length) {
                                Editors.set('data-qui', null);
                                console.error('Please don\'t include controls/editors/Editor in Settings.');
                            }
                        }


                        // set data
                        QUIFormUtils.setDataToForm(Site.getAttributes(), Form);

                        ControlUtils.parse(Form).then(function () {
                            // information tab
                            if (Category.getAttribute('name') === 'information') {
                                self.$bindNameInputUrlFilter();

                                // site linking
                                let i, len, Row;

                                const LinkinTable     = Body.getElement('.site-linking'),
                                      LinkinLangTable = Body.getElement('.site-langs'),
                                      Locked          = Body.getElement('[data-locked]'),
                                      Title           = Body.getElement('[name="title"]'),
                                      OpenInStructure = Body.getElement('[name="open-in-structure"]'),
                                      SiteType        = Body.getElement('[name="type"]')
                                ;

                                if (OpenInStructure) {
                                    OpenInStructure.addEvent('click', self.openSiteInStructure);
                                    OpenInStructure.set('disabled', false);
                                    OpenInStructure.set('title', Locale.get('quiqqer/core', 'projects.project.site.panel.information.openInSiteStructure'));
                                }

                                if (SiteType) {
                                    SiteType.addEvent('change', function () {
                                        if (SiteType.value === Site.getAttribute('type')) {
                                            return;
                                        }

                                        self.save();
                                    });
                                }

                                if (Title) {
                                    Title.addEvent('blur', function () {
                                        if (parseInt(Site.getId(), 10) === 1) {
                                            return;
                                        }

                                        const attributes = Site.getAttributes();

                                        const name = cleanupUrl(attributes.name);
                                        const title = cleanupUrl(attributes.title);
                                        const value = cleanupUrl(this.value);

                                        if (value === title) {
                                            return;
                                        }

                                        if (value === name) {
                                            return;
                                        }

                                        if (Site.isActive()) {
                                            return;
                                        }

                                        self.$showTitleUrlAdjustment();
                                    });
                                }


                                if (LinkinTable) {
                                    const openDeleteLink = function (Btn) {
                                        Btn.setAttribute('icon', 'fa fa-spinner fa-spin');

                                        self.deleteLinked(
                                            Btn.getElm().getParent().get('data-parentid')
                                        ).then(function () {
                                            Btn.setAttribute('icon', 'fa fa-trash');
                                        }, function () {
                                            Btn.setAttribute('icon', 'fa fa-trash');
                                        });
                                    };

                                    LinkinTable.getElements('.site-linking-entry-button').each(function (Node) {
                                        Node.set('html', '');

                                        new QUIButton({
                                            name  : 'delete-linking',
                                            icon  : 'fa fa-trash',
                                            title : Locale.get(lg, 'projects.project.site.panel.window.deleteLinked.button'),
                                            styles: {
                                                width: 50
                                            },
                                            events: {
                                                onClick: openDeleteLink
                                            }
                                        }).inject(Node);
                                    });
                                }

                                if (LinkinLangTable) {
                                    let Buttons,
                                        rowList = LinkinLangTable.getElements('tbody tr');

                                    new QUIButton({
                                        name  : 'add-linking',
                                        text  : Locale.get(lg, 'projects.project.site.panel.linked.btn.add'),
                                        styles: {
                                            position: 'absolute',
                                            right   : 5,
                                            top     : 5,
                                            zIndex  : 1
                                        },
                                        events: {
                                            onClick: function (Btn, event) {
                                                event.stop();
                                                self.addLanguageLink();
                                            }
                                        }
                                    }).inject(LinkinLangTable.getElement('th'));

                                    // helper functions
                                    const copyLinking = function (Btn) {
                                        self.copySiteToLang(
                                            Btn.getAttribute('lang')
                                        );
                                    };

                                    const openSite = function (Btn) {
                                        PanelUtils.openSitePanel(
                                            Project.getName(),
                                            Btn.getAttribute('lang'),
                                            Btn.getAttribute('siteId')
                                        );
                                    };

                                    const removeLinking = function (Btn) {
                                        self.removeLanguageLink(
                                            Btn.getAttribute('lang'),
                                            Btn.getAttribute('siteId')
                                        );
                                    };

                                    for (i = 0, len = rowList.length; i < len; i++) {
                                        Row = rowList[i];
                                        Buttons = rowList[i].getElement('.site-lang-entry-button');

                                        if (!parseInt(Row.get('data-id'))) {
                                            // seite in sprache kopieren und sprach verknüpfung anlegen
                                            new QUIButton({
                                                name  : 'copy-linking',
                                                icon  : 'fa fa-copy',
                                                alt   : Locale.get(lg, 'copy.site.in.lang'),
                                                title : Locale.get(lg, 'copy.site.in.lang'),
                                                lang  : Row.get('data-lang'),
                                                events: {
                                                    onClick: copyLinking
                                                },
                                                styles: {
                                                    width: 50
                                                }
                                            }).inject(Buttons);

                                            continue;
                                        }

                                        new QUIButton({
                                            name  : 'open-site',
                                            icon  : 'fa fa-file-o',
                                            alt   : Locale.get(lg, 'open.site'),
                                            title : Locale.get(lg, 'open.site'),
                                            lang  : Row.get('data-lang'),
                                            siteId: Row.get('data-id'),
                                            styles: {
                                                width: 50
                                            },
                                            events: {
                                                onClick: openSite
                                            }
                                        }).inject(Buttons);

                                        new QUIButton({
                                            name  : 'remove-linking',
                                            icon  : 'fa fa-remove',
                                            alt   : Locale.get(lg, 'projects.project.site.panel.linked.btn.delete'),
                                            title : Locale.get(lg, 'projects.project.site.panel.linked.btn.delete'),
                                            lang  : Row.get('data-lang'),
                                            siteId: Row.get('data-id'),
                                            styles: {
                                                width: 50
                                            },
                                            events: {
                                                onClick: removeLinking
                                            }
                                        }).inject(Buttons);
                                    }
                                }


                                // locked
                                if (Locked && USER.isSU) {
                                    new QUIButton({
                                        name  : 'unlock',
                                        text  : Locale.get(lg, 'projects.project.site.panel.unlock'),
                                        styles: {
                                            clear  : 'both',
                                            display: 'block',
                                            'float': 'none',
                                            margin : '10px auto',
                                            width  : 200
                                        },
                                        events: {
                                            onClick: function () {
                                                self.unlockSite();
                                            }
                                        }
                                    }).inject(Locked);
                                }
                            }

                            QUI.parse(Form).then(setProject).then(resolve);
                        }).catch(function (error) {
                            console.error(error);
                        });
                    }, {
                        id     : Site.getId(),
                        project: Project.encode(),
                        tab    : Category.getAttribute('name')
                    });
                });
            }.bind(this)).then(function () {
                return self.$onCategoryEntryShow();
            });
        },

        /**
         * Load the category
         *
         * @method controls/projects/project/site/Panel#$categoryOnLoad
         * @param {Object} Category - qui/controls/buttons/Button
         * @return {Promise}
         */
        $categoryOnLoad: function (Category) {
            const self          = this,
                  onloadRequire = Category.getAttribute('onload_require'),
                  onload        = Category.getAttribute('onload');

            return new Promise(function (resolve) {
                if (onloadRequire) {
                    require([onloadRequire], function (Plugin) {
                        if (onload) {
                            eval(onload + '(Category, self);');
                            resolve();
                            return;
                        }

                        const type = typeOf(Plugin);

                        if (type === 'function') {
                            type(Category, self);
                            resolve();
                            return;
                        }

                        if (type === 'class') {
                            self.$CategoryControl = new Plugin({
                                Site : self.getSite(),
                                Panel: self
                            });

                            if (QUI.Controls.isControl(self.$CategoryControl)) {
                                self.$CategoryControl.inject(self.$Container);
                                self.$CategoryControl.setParent(self);
                            }
                        }

                        resolve();
                    }, resolve);

                    return;
                }

                if (onload) {
                    eval(onload + '(Category, self);');
                    return resolve();
                }

                resolve();
            });
        },

        /**
         * Return the html from a xml category
         *
         * @return {Promise}
         */
        $getCategoryFromXml: function (name) {
            const Site    = this.getSite(),
                  Project = Site.getProject();

            return new Promise(function (resolve) {
                Ajax.get('ajax_site_categories_xml', resolve, {
                    project : Project.encode(),
                    id      : Site.getId(),
                    category: name
                });
            });
        },

        /**
         * The site tab leave event
         * Unload the category
         *
         * @method controls/projects/project/site/Panel#$tabLeave
         * @fires onSiteTabUnLoad
         *
         * @param {Object} [Category] - qui/controls/buttons/Button
         * @param {Function} [callback] - (optional) callback function
         * @return {Promise}
         */
        $onCategoryLeave: function (Category, callback) {
            this.Loader.show();

            if (this.$isDeleted()) {
                this.Loader.hide();
                return Promise.resolve();
            }

            const Site = this.getSite(),
                  Body = this.$Container;

            if (typeof Category === 'undefined' || !Category) {
                return Promise.resolve();
            }

            // main content
            if (Category.getAttribute('name') === 'content') {
                if (!this.getAttribute('Editor')) {
                    return Promise.resolve();
                }

                Site.setAttribute(
                    'content',
                    this.getAttribute('Editor').getContent()
                );

                this.$clearEditorPeriodicalSave();

                if (typeof callback === 'function') {
                    callback();
                }

                return Promise.resolve();
                //self.getAttribute('Editor').destroy();
            }

            // wysiwyg type
            if (Category.getAttribute('type') === 'wysiwyg') {
                if (!this.getAttribute('Editor')) {
                    return Promise.resolve();
                }

                Site.setAttribute(
                    Category.getAttribute('name'),
                    this.getAttribute('Editor').getContent()
                );

                this.$clearEditorPeriodicalSave();

                if (typeof callback === 'function') {
                    callback();
                }

                return Promise.resolve();
            }

            // form unload
            if (this.$CategoryControl && "unload" in this.$CategoryControl) {
                this.$CategoryControl.unload();
            }

            if (!Body.getElement('form')) {
                return Promise.resolve().then(function () {
                    if (typeof callback === 'function') {
                        callback();
                    }
                });
            }

            const Form     = Body.getElement('form'),
                  elements = Form.elements;

            if (Category.getAttribute('name') === 'settings') {
                if (typeof elements.layout !== 'undefined') {
                    Site.setAttribute('layout', elements.layout.value);
                }

                if (typeof elements.type !== 'undefined') {
                    Site.setAttribute('type', elements.type.value);
                }
            }

            // information tab
            if (Category.getAttribute('name') === 'information') {
                if (parseInt(Site.getId(), 10) !== 1) {
                    Site.setAttribute('name', elements['site-name'].value);
                }
                Site.setAttribute('title', elements.title.value);
                Site.setAttribute('short', elements.short.value);
                Site.setAttribute('nav_hide', elements.nav_hide.checked);

                if (typeof elements.layout !== 'undefined') {
                    Site.setAttribute('layout', elements.layout.value);
                }

                if (typeof callback === 'function') {
                    callback();
                }

                return Promise.resolve();
            }

            // unload params
            const FormData = QUIFormUtils.getFormData(Form);

            for (let key in FormData) {
                if (key === '') {
                    continue;
                }

                if (FormData.hasOwnProperty(key)) {
                    Site.setAttribute(key, FormData[key]);
                }
            }

            const onunloadRequire = Category.getAttribute('onunload_require'),
                  onunload        = Category.getAttribute('onunload');

            return Promise.resolve().then(function () {
                if (onunloadRequire) {
                    return new Promise(function (resolve) {
                        require([onunloadRequire], function () {
                            eval(onunload + '(Category, self);');

                            if (typeof callback === 'function') {
                                callback();
                            }

                            resolve();
                        }, resolve);
                    });
                }

                if (typeof callback === 'function') {
                    callback();
                }
            });
        },

        /**
         * Helper to hide body
         *
         * @return {Promise}
         */
        $onCategoryLeaveHide: function () {
            const self = this;

            return new Promise(function (resolve) {
                moofx(self.$Container).animate({
                    opacity: 0,
                    top    : -20
                }, {
                    duration: 250,
                    callback: resolve
                });
            });
        },

        /**
         * Helper to hide body
         *
         * @return {Promise}
         */
        $onCategoryEntryShow: function () {
            const self = this;

            return new Promise(function (resolve) {
                moofx(self.$Container).animate({
                    opacity: 1,
                    top    : 0
                }, {
                    duration: 250,
                    callback: function () {
                        self.Loader.hide();
                        resolve();
                    }
                });
            });
        },

        /**
         * Execute the panel onclick from PHP
         *
         * @method controls/projects/project/site/Panel#$onPanelButtonClick
         * @param {Object} Btn - qui/controls/buttons/Button
         */
        $onPanelButtonClick: function (Btn) {
            const Panel = this,
                  Site  = Panel.getSite();

            if (this.$isDeleted()) {
                return;
            }

            const evalButtonClick = function () {
                eval(Btn.getAttribute('_onclick') + '();');
            };

            if (Btn.getAttribute('name') !== 'status') {
                evalButtonClick();
                return;
            }

            // storage cleanung, so that this can be properly compared
            const storageCleanup = function (storage) {
                if (!storage) {
                    return '';
                }

                if (typeof storage === 'string') {
                    return storage;
                }

                if (typeof storage.release_to === 'undefined' ||
                    storage.release_to === null) {
                    storage.release_to = '';
                }

                if (typeof storage.has_children !== 'undefined') {
                    storage.has_children = parseInt(storage.has_children);
                }

                if (typeof storage.__storageTime !== 'undefined') {
                    delete storage.__storageTime;
                }

                return JSON.encode(storage);
            };

            const oldStorage = storageCleanup(Site.getWorkingStorage());

            Panel.$onCategoryLeave(Panel.getActiveCategory()).then(function () {
                return new Promise(function (resolve) {
                    const currentStorage = storageCleanup(Site.getWorkingStorage());

                    // check if site must be saved
                    if (!oldStorage || oldStorage === currentStorage) {
                        evalButtonClick();
                        return resolve();
                    }

                    new QUIConfirm({
                        title        : Locale.get('quiqqer/core', 'site.window.siteChangesExists.title'),
                        text         : Locale.get('quiqqer/core', 'site.window.siteChangesExists.text'),
                        information  : Locale.get('quiqqer/core', 'site.window.siteChangesExists.information'),
                        maxHeight    : 400,
                        maxWidth     : 600,
                        texticon     : 'fa fa-edit',
                        icon         : 'fa fa-edit',
                        ok_button    : {
                            text: Locale.get('quiqqer/core', 'site.window.siteChangesExists.button.ok')
                        },
                        cancel_button: {
                            text: Locale.get('quiqqer/core', 'site.window.siteChangesExists.button.cancel')
                        },
                        events       : {
                            onSubmit: function () {
                                Site.save(function () {
                                    evalButtonClick();
                                    resolve();
                                });
                            },

                            onCancel: function () {
                                evalButtonClick();
                                resolve();
                            }
                        }
                    }).open();
                });
            }).then(function () {
                Panel.$onCategoryEnter(Panel.getActiveCategory());
            });
        },

        /**
         * init the name input key events
         */
        $bindNameInputUrlFilter: function () {
            const Panel = this,
                  Site = this.getSite(),
                  Body = this.$Container;

            const NameInput     = Body.querySelector('[data-name="siteName"]'),
                  UrlDisplay    = Body.querySelector('[data-name="siteUrl"]'),
                  UrlEditButton = Body.querySelector('[data-name="siteUrlEdit"]');

            if (!NameInput) {
                return;
            }

            if (parseInt(Site.getId(), 10) === 1) {
                NameInput.set({
                    value   : "/",
                    disabled: true,
                    readonly: true
                });

                UrlDisplay.set("html", "/");
                UrlEditButton.setStyle("display", "none");

                return;
            }

            UrlDisplay.textContent = Site.getUrl();

            new QUIButton({
                icon  : 'fa fa-edit',
                styles: {
                    width: 50
                },
                events: {
                    onClick: function (Btn) {
                        if (Btn.isActive()) {
                            NameInput.setStyle('display', 'none');
                            UrlDisplay.removeClass('site-url-display-active');
                            Btn.setNormal();
                            return;
                        }

                        NameInput.setStyle('display', null);
                        NameInput.focus();
                        UrlDisplay.addClass('site-url-display-active');
                        Btn.setActive();
                    }
                }
            }).inject(UrlEditButton);

            // filter
            let lastPos  = null,
                hold     = false;

            NameInput.set({
                value : Site.getAttribute('name'),
                events: {
                    keydown: function () {
                        if (hold) {
                            return;
                        }

                        hold = true;
                    },

                    keyup: function (event) {
                        const old = this.value;

                        if (typeof event !== 'undefined') {
                            lastPos = QUIElmUtils.getCursorPosition(event.target);
                            hold = false;
                        }

                        this.value = cleanupUrl(this.value);

                        if (typeof event !== 'undefined' && old !== this.value) {
                            QUIElmUtils.setCursorPosition(this, lastPos - 1);
                        }

                        Panel.$onSiteUrlChange();
                    },

                    blur: function () {
                        this.fireEvent('keyup');
                    },

                    focus: function () {
                        this.fireEvent('keyup');
                    }
                }
            });
        },

        $lockRequest: function (action, values = {}) {
            const Site = this.getSite();
            return new Promise((resolve, reject) => {
                Ajax.post('ajax_site_' + action, resolve, {
                    project: Site.getProject().encode(), id: Site.getId(),
                    token: this.$lockToken, ...values, showError: false, onError: reject
                });
            });
        },

        $acquireEditingLock: function () {
            return this.$lockRequest('lock').then(acquired => {
                if (this.$lockDestroyed) {
                    this.$lockRequest('unlock', {force: 0}).catch(() => {});
                    return false;
                }
                this.$ownsEditingLock = acquired === true;
                if (this.$ownsEditingLock) {
                    this.$scheduleLockRefresh();
                }
                return this.$ownsEditingLock;
            }).catch(() => false);
        },

        $scheduleLockRefresh: function () {
            clearTimeout(this.$lockTimer);
            clearTimeout(this.$lockExpiryTimer);
            this.$lockTimer = setTimeout(() => this.$refreshEditingLock(), 30000);
            // Stop editing even if a heartbeat request never returns.
            this.$lockExpiryTimer = setTimeout(() => this.$loseEditingLock(), 100000);
        },

        $refreshEditingLock: function () {
            if (!this.$ownsEditingLock || this.$lockDestroyed) {
                return Promise.resolve(false);
            }
            return this.$lockRequest('refreshLock').then(refreshed => {
                if (this.$lockDestroyed || !this.$ownsEditingLock) {
                    return false;
                }
                if (refreshed !== true) {
                    this.$loseEditingLock();
                    return false;
                }
                this.$scheduleLockRefresh();
                return true;
            }).catch(() => {
                this.$loseEditingLock();
                return false;
            });
        },

        $loseEditingLock: function () {
            clearTimeout(this.$lockTimer);
            clearTimeout(this.$lockExpiryTimer);
            this.$ownsEditingLock = false;
            if (this.$lockDestroyed) {
                return;
            }
            this.setLocked();
            if (!this.getContent().querySelector('[data-name="editingLockNotice"]')) {
                const Notice = document.createElement('div');
                Notice.dataset.name = 'editingLockNotice';
                Notice.className = 'messages-message message-attention qui-site-lock-notice';
                Notice.setAttribute('role', 'alert');
                const Text = document.createElement('span');
                Text.dataset.name = 'editingLockText';
                Text.className = 'qui-site-lock-notice-text';
                Text.textContent = Locale.get(lg, 'projects.project.site.panel.lockLost');
                Notice.append(Text);
                this.$Container.before(Notice);

                if (typeof USER !== 'undefined' && USER.isSU) {
                    const Unlock = document.createElement('button');
                    Unlock.type = 'button';
                    Unlock.dataset.name = 'editingLockUnlock';
                    Unlock.className = 'btn qui-site-lock-notice-unlock';
                    Unlock.textContent = Locale.get(lg, 'projects.project.site.panel.unlock');
                    Unlock.addEventListener('click', () => this.unlockSite());
                    Notice.append(Unlock);
                }
            }
        },

        /**
         * Disable the buttons, if the site is locked
         */
        setLocked: function () {
            const buttons = this.getButtons();

            for (let i = 0, len = buttons.length; i < len; i++) {
                if ("disable" in buttons[i]) {
                    buttons[i].disable();
                }
            }
        },

        /**
         * Enable the buttons, if the site is unlocked
         */
        setUnlocked: function () {
            if (this.$isDeleted() || !this.$ownsEditingLock) {
                return;
            }

            const buttons = this.getButtons();

            for (let i = 0, len = buttons.length; i < len; i++) {
                if ("enable" in buttons[i]) {
                    buttons[i].enable();
                }
            }
        },

        /**
         * Is the site in the trash?
         *
         * @return {Boolean}
         */
        $isDeleted: function () {
            return parseInt(this.getSite().getAttribute('deleted'), 10) === 1;
        },

        /**
         * Display deleted sites without edit controls.
         *
         * @return {Promise}
         */
        $showDeletedState: function () {
            this.$deletedState = true;
            this.$clearEditorPeriodicalSave();
            this.getSite().clearWorkingStorage();

            if (this.$ButtonOpenWebsite) {
                this.$ButtonOpenWebsite.hide();
            }

            const controls = [
                ...Array.from(this.getButtons()),
                ...Array.from(this.getCategory()),
                this.$ButtonPermissions,
                this.$ButtonMedia,
                this.$ButtonSort
            ];

            controls.forEach(function (Control) {
                if (Control && typeof Control.disable === 'function') {
                    Control.disable();
                }
            });

            this.getElm().classList.add('qui-site-panel--deleted');

            const messageId = this.getAttribute('id') + '-deleted-message-title';
            const Message = document.createElement('section');
            const Title = document.createElement('h3');
            const Text = document.createElement('p');
            const Icon = document.createElement('span');

            Message.className = 'qui-site-panel-deleted messages-message message-attention';
            Message.dataset.name = 'deleted-message';
            Message.setAttribute('aria-labelledby', messageId);
            Message.setAttribute('aria-live', 'polite');

            Title.id = messageId;
            Title.className = 'qui-site-panel-deleted-title';
            Title.textContent = Locale.get(lg, 'projects.project.site.panel.deleted.title');

            Icon.className = 'fa fa-trash-o';
            Icon.setAttribute('aria-hidden', 'true');

            Text.textContent = Locale.get(lg, 'projects.project.site.panel.deleted.message');

            Title.prepend(Icon);
            Message.append(Title, Text);
            this.$Container.replaceChildren(Message);

            this.Loader.hide();

            return Promise.resolve();
        },

        /**
         * unlock the site, only if the user has the permission
         */
        unlockSite: function () {
            const self = this,
                  Site = this.getSite();

            this.Loader.show();

            Site.unlock(function () {
                self.$destroyRefresh();
            }).catch(function () {
                self.Loader.hide();
            });
        },

        /**
         * destroy and refresh the panel
         *
         * @private
         */
        $destroyRefresh: function () {
            const self    = this,
                  Site    = this.getSite(),
                  Project = Site.getProject();

            require(['utils/Panels'], function (Utils) {
                self.destroy();

                Utils.openSitePanel(
                    Project.getName(),
                    Project.getLang(),
                    Site.getId()
                );
            });
        },

        /**
         * event : on login
         */
        $onLogin: function () {
            const Active = this.getActiveCategory(),
                  Task   = this.getAttribute('Task');

            // if task exists, check if task is active
            if (Task &&
                typeOf(Task) === 'qui/controls/taskbar/Task' &&
                Task.isActive() === false
            ) {
                return;
            }

            // no active category? something is wrong, so reload the panel
            if (!Active) {
                this.$destroyRefresh();
                return;
            }

            this.$onCategoryEnter(this.getActiveCategory());
        },

        /**
         * Site event methods
         */

        $onProjectSiteMove: function (Project, MovedSite) {
            const Site = this.getSite();

            if (Site !== MovedSite) {
                // The moved site has already refreshed itself. Other open editors
                // may belong to descendants, even if their ancestors are not loaded.
                return Site.refreshUrl().catch(() => {});
            }
        },

        $onSiteUrlChange: function (UpdatedSite) {
            if (UpdatedSite) {
                this.refresh();
            }

            if (!this.$Container) {
                return;
            }

            const NameInput = this.$Container.querySelector('[data-name="siteName"]');
            const UrlDisplay = this.$Container.querySelector('[data-name="siteUrl"]');

            if (!NameInput || !UrlDisplay) {
                return;
            }

            const Site = this.getSite();

            if (parseInt(Site.getId(), 10) === 1) {
                UrlDisplay.textContent = '/';
                return;
            }

            const sitePath = Site.getUrl().replace(/\\/g, '/').replace(/\/[^\/]*\/?$/, '') + '/';
            UrlDisplay.textContent = sitePath + NameInput.value + QUIQQER.Rewrite.SUFFIX;
        },

        /**
         * event on site save
         */
        $onSiteSave: function () {
            const Form = this.getBody().getElement('form');

            if (Form) {
                const attributes = this.getSite().getAttributes();
                const NameInput = this.getBody().getElement('input[name="site-name"]');

                attributes["site-name"] = parseInt(this.getSite().getId(), 10) === 1 ? "/" : attributes.name;

                QUIFormUtils.setDataToForm(attributes, Form);

                if (NameInput) {
                    NameInput.fireEvent('keyup');
                }
            }

            this.Loader.hide();
        },

        /**
         * event : on {classes/projects/Site} activation
         */
        $onSiteActivate: function () {
            this.$ButtonOpenWebsite.show();

            const Status = this.getButtons('status');

            if (!Status) {
                this.Loader.hide();
                return;
            }

            Status.setAttributes({
                'textimage': Status.getAttribute('dimage'),
                'text'     : Status.getAttribute('dtext'),
                '_onclick' : 'Panel.deactivate'
            });

            this.Loader.hide();
        },

        /**
         * event : on {classes/projects/Site} deactivation
         */
        $onSiteDeactivate: function () {
            this.$ButtonOpenWebsite.hide();

            const Status = this.getButtons('status');

            if (!Status) {
                this.Loader.hide();
                return;
            }

            this.getSite().setAttribute('release_from', '');

            if (this.getContent().getElement('[name="release_from"]')) {
                this.getContent().getElement('[name="release_from"]').value = '';
            }

            Status.setAttributes({
                'textimage': Status.getAttribute('aimage'),
                'text'     : Status.getAttribute('atext'),
                '_onclick' : 'Panel.activate'
            });

            this.Loader.hide();
        },

        /**
         * event : on {classes/projects/Site} delete
         */
        $onSiteDelete: function () {
            this.destroy();
        },

        /**
         * Editor (WYSIWYG) Methods
         */

        /**
         * Load the WYSIWYG Editor in the panel
         *
         * @method controls/projects/project/site/Panel#loadEditor
         * @param {String} content - content of the editor
         * @return {Promise}
         */
        loadEditor: function (content) {
            const self = this,
                  Body = this.$Container;

            Body.set('html', '');
            Body.setStyle('opacity', 0);

            return new Promise(function (resolve) {
                require(['Editors'], function (Editors) {
                    Editors.getEditor().then(function (Editor) {
                        const Site    = self.getSite(),
                              Project = Site.getProject();

                        self.setAttribute('Editor', Editor);

                        // draw the editor
                        Editor.setAttribute('Panel', self);
                        Editor.setAttribute('name', Site.getId());
                        Editor.setAttribute('showLoader', false);
                        Editor.setProject(Project);
                        Editor.addEvent('onDestroy', self.$onEditorDestroy);

                        // set the site content
                        if (typeof content === 'undefined' || !content) {
                            content = '';
                        }

                        Editor.addEvent('onLoaded', resolve);
                        Editor.inject(Body);
                        Editor.setContent(content);

                        self.$startEditorPeriodicalSave();
                    });
                });
            });
        },

        /**
         * event: on editor load
         * if the editor would be destroyed
         *
         * @method controls/projects/project/site/Panel#$onEditorDestroy
         */
        $onEditorDestroy: function () {
            this.setAttribute('Editor', false);
        },

        /**
         * Start the periodical editor save
         */
        $startEditorPeriodicalSave: function () {
            if (this.$editorPeriodicalSave) {
                this.$clearEditorPeriodicalSave();
            }

            let self      = this,
                Category  = this.getActiveCategory(),
                attribute = false;

            if (Category.getAttribute('name') === 'content') {
                attribute = 'content';
            }

            if (Category.getAttribute('type') === 'wysiwyg') {
                attribute = Category.getAttribute('name');
            }

            this.$editorPeriodicalSave = (function (attr) {
                const Editor = self.getAttribute('Editor');

                if (!Editor) {
                    return;
                }

                self.getSite().setAttribute(attr, Editor.getContent());
            }).periodical(this.getAttribute('editorPeriode'), this, [attribute]);
        },

        /**
         * clear / stop the periodical editor save
         */
        $clearEditorPeriodicalSave: function () {
            if (this.$editorPeriodicalSave) {
                clearInterval(this.$editorPeriodicalSave);
                this.$editorPeriodicalSave = false;
            }
        },

        /**
         * Opens a project popup, so, an user can set a languag link
         */
        addLanguageLink: function () {
            const self = this;

            require(['controls/projects/Popup'], function (ProjectPopup) {
                const Site    = self.getSite(),
                      Project = Site.getProject();

                Project.getConfig(function (config) {
                    let langs = config.langs,
                        lang  = Project.getLang();

                    langs = langs.split(',');

                    const needles = [];

                    for (let i = 0, len = langs.length; i < len; i++) {
                        if (langs[i] !== lang) {
                            needles.push(langs[i]);
                        }
                    }

                    new ProjectPopup({
                        project: Project.getName(),
                        langs  : needles,
                        events : {
                            onSubmit: function (Popup, result) {
                                Popup.Loader.show();

                                Ajax.post('ajax_site_language_add', function () {
                                    self.$ActiveCat = null;
                                    self.getSite().load(function () {
                                        Popup.close();
                                        self.load();
                                    });
                                }, {
                                    project     : Project.encode(),
                                    id          : Site.getId(),
                                    linkedParams: JSON.encode({
                                        lang: result.lang,
                                        id  : result.ids[0]
                                    })
                                });
                            }
                        }
                    }).open();
                });
            });
        },

        /**
         * Open the remove languag link popup
         *
         * @param {String} lang - lang of the language link
         * @param {String} id - Site-ID of the language link
         */
        removeLanguageLink: function (lang, id) {
            const self = this;

            const Site    = self.getSite(),
                  Project = Site.getProject();

            new QUIConfirm({
                title    : Locale.get(lg, 'projects.project.site.panel.linked.window.delete.title'),
                icon     : 'fa fa-remove',
                text     : Locale.get(lg, 'projects.project.site.panel.linked.window.delete.text'),
                maxHeight: 300,
                maxWidth : 450,
                events   : {
                    onSubmit: function (Confirm) {
                        Confirm.Loader.show();

                        Ajax.post('ajax_site_language_remove', function () {
                            self.$ActiveCat = null;
                            self.getSite().load(function () {
                                Confirm.close();
                                self.load();
                            });
                        }, {
                            project     : Project.encode(),
                            id          : Site.getId(),
                            linkedParams: JSON.encode({
                                lang: lang,
                                id  : id
                            })
                        });
                    }
                }
            }).open();

        },

        /**
         * Copy site to another language and set the language link
         *
         * @param {String} lang
         */
        copySiteToLang: function (lang) {
            if (!this.$Site) {
                return;
            }

            const self    = this,
                  Project = this.$Site.getProject();

            new QUIConfirm({
                title      : Locale.get(lg, 'projects.project.site.panel.copySiteToLink.window.title', {
                    lang: lang
                }),
                text       : Locale.get(lg, 'projects.project.site.panel.copySiteToLink.window.text', {
                    lang: lang
                }),
                information: Locale.get(lg, 'projects.project.site.panel.copySiteToLink.window.information', {
                    lang: lang
                }),

                icon     : 'fa fa-copy',
                texticon : 'fa fa-copy',
                autoclose: false,
                maxHeight: 400,
                maxWidth : 600,

                events       : {
                    onSubmit: function (Win) {
                        Win.Loader.show();

                        require(['controls/projects/Popup'], function (ProjectPopup) {
                            Win.close();

                            new ProjectPopup({
                                project             : Project.getName(),
                                lang                : lang,
                                disableProjectSelect: true,
                                events              : {
                                    onSubmit: function (Popup, result) {

                                        Popup.Loader.show();

                                        self.$Site.copy({
                                            parentId: result.ids[0],
                                            project : {
                                                name: Project.getName(),
                                                lang: lang
                                            }
                                        }).then(function (newChildId) {
                                            Ajax.post('ajax_site_language_add', function () {
                                                self.$ActiveCat = null;
                                                self.getSite().load(function () {
                                                    Popup.close();
                                                    self.load();
                                                });
                                            }, {
                                                project     : Project.encode(),
                                                id          : self.$Site.getId(),
                                                linkedParams: JSON.encode({
                                                    lang: lang,
                                                    id  : newChildId
                                                })
                                            });

                                        });
                                    }
                                }
                            }).open();
                        });
                    }
                },
                cancel_button: {
                    text     : Locale.get(lg, 'cancel'),
                    textimage: 'fa fa-remove'
                },
                ok_button    : {
                    text     : Locale.get(lg, 'projects.project.site.panel.copySiteToLink.window.submit'),
                    textimage: 'fa fa-check'
                }
            }).open();

            //this.$Site;
        },

        /**
         * Open the preview window
         */
        openPreview: function () {
            const self = this;

            this.Loader.show();

            return this.$onCategoryLeave(this.getActiveCategory()).then(function () {
                const Site    = self.getSite(),
                      Project = Site.getProject();

                const Form = new Element('form', {
                    method: 'POST',
                    action: URL_SYS_DIR + 'bin/preview.php',
                    target: '_blank'
                });

                const attributes = Site.getAttributes();

                new Element('input', {
                    type : 'hidden',
                    value: Project.getName(),
                    name : 'project'
                }).inject(Form);

                new Element('input', {
                    type : 'hidden',
                    value: Project.getLang(),
                    name : 'lang'
                }).inject(Form);

                new Element('input', {
                    type : 'hidden',
                    value: Site.getId(),
                    name : 'id'
                }).inject(Form);


                let val, to;

                for (let key in attributes) {

                    if (!attributes.hasOwnProperty(key)) {
                        continue;
                    }

                    if (!attributes[key]) {
                        continue;
                    }

                    to = typeOf(attributes[key]);
                    val = attributes[key];

                    if (to !== 'string' && to !== 'number') {
                        val = JSON.encode(val);

                        new Element('input', {
                            type : 'hidden',
                            value: val,
                            name : 'siteDataJSON[' + key + ']'
                        }).inject(Form);

                        continue;
                    }

                    new Element('input', {
                        type : 'hidden',
                        value: val,
                        name : 'siteData[' + key + ']'
                    }).inject(Form);

                }

                Form.inject(document.body);
                Form.submit();

                self.Loader.hide();

                (function () {
                    Form.destroy();
                }).delay(1000);
            }).then(function () {
                this.$onCategoryEnter(this.getActiveCategory());
            }.bind(this));
        },

        /**
         * Shows title url customization confirm window
         */
        $showTitleUrlAdjustment: function () {
            const self = this,
                  Site = this.getSite();

            if (parseInt(Site.getId(), 10) === 1) {
                return;
            }

            new QUIConfirm({
                icon         : 'fa fa-file-o',
                texticon     : 'fa fa-file-o',
                title        : Locale.get('quiqqer/core', 'window.title.url.customization'),
                text         : Locale.get('quiqqer/core', 'window.title.url.customization.text'),
                information  : Locale.get('quiqqer/core', 'window.title.url.customization.information'),
                maxHeight    : 400,
                maxWidth     : 600,
                events       : {
                    onSubmit: function () {
                        const Title = self.getBody().getElement('[name="title"]'),
                              Name  = self.getBody().getElement('[name="site-name"]');

                        Name.value = cleanupUrl(Title.value);
                        Name.fireEvent('keyup');

                        Site.setAttribute('name', Name.value);
                        Site.setAttribute('title', Title.value);
                    },
                    onCancel: function () {
                        const Title = self.getBody().getElement('[name="title"]'),
                              Name  = self.getBody().getElement('[name="site-name"]');

                        Site.setAttribute('name', Name.value);
                        Site.setAttribute('title', Title.value);
                    }
                },
                ok_button    : {
                    text: Locale.get('quiqqer/core', 'window.title.url.customization.button.ok')
                },
                cancel_button: {
                    text: Locale.get('quiqqer/core', 'window.title.url.customization.button.cancel')
                }
            }).open();
        }
    });
});
