/**
 * A QUIQQER project
 *
 *
 * @events onSiteDelete [this, {Number}]
 * @events onSiteSave [this, {classes/projects/project/Site}]
 * @events onSiteCreate [this, {classes/projects/project/Site}]
 * @events onSiteActivate [this, {classes/projects/project/Site}]
 * @events onSiteDeactivate [this, {classes/projects/project/Site}]
 */
define('classes/projects/Project', [

    'qui/classes/DOM',
    'Ajax',
    'Locale',
    'classes/projects/project/Site',
    'classes/projects/project/Media'

], function (QDOM, Ajax, QUILocale, ProjectSite, Media) {
    "use strict";

    /**
     * A project
     *
     * @class classes/projects/Project
     *
     * @param {Object} options
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QDOM,
        Type: 'classes/projects/Project',

        Binds: [
            '$onChildDelete',
            '$onSiteLoad',
            '$onSiteSave',
            '$onSiteMove',
            '$onSiteCreate',
            '$onSiteActivate',
            '$onSiteDeactivate',
            '$onSiteDelete',
            '$onSiteUnlink'
        ],

        options: {
            name: '',
            lang: 'de',
            host: false
        },

        $ids: {},
        $Media: false,
        $Trash: false,

        initialize: function (options) {
            this.parent(options);

            this.$config = false;
            this.$layouts = null;
        },

        /**
         * Supply project colors and backend labels to generic QUI color controls.
         * Resolving this provider does not load palette data; the control's explicit
         * capabilities decide whether to call load(). This uses the protected admin API.
         *
         * @return {Promise<Object>}
         */
        getColorProvider: function () {
            return new Promise((resolve, reject) => {
                require(['controls/projects/project/ColorProvider'], (ColorProvider) => {
                    resolve(ColorProvider.create(this));
                }, reject);
            });
        },

        /**
         * Decode the project
         * eq for ajax request
         *
         * @return {Object}
         */
        encode: function () {
            var template = false;

            if (this.$config && "template" in this.$config) {
                template = this.$config.template;
            }

            return JSON.encode({
                name: this.getName(),
                lang: this.getLang(),
                template: template
            });
        },

        /**
         * Get a site from the project
         *
         * @method classes/projects/Project#get
         * @param {Number} id - ID of the site
         * @return {Object} classes/projects/project/Site
         */
        get: function (id) {
            if (typeof this.$ids[id] !== 'undefined') {
                return this.$ids[id];
            }

            var Site = new ProjectSite(this, id);

            Site.addEvents({
                onDelete: this.$onSiteDelete,
                onSave: this.$onSiteSave,
                onMove: this.$onSiteMove,
                onActivate: this.$onSiteActivate,
                onDeactivate: this.$onSiteDeactivate,
                onCreateChild: this.$onSiteCreate,
                onSortSave: this.$onSiteSortSave,
                onLoad: this.$onSiteLoad,
                onUnlink: this.$onSiteUnlink
            });

            this.$ids[id] = Site;

            return this.$ids[id];
        },

        /**
         * Return the configuration of the project
         *
         * @param {Function} callback - callback function
         * @param {String} [param] - param name
         * @return Promise
         */
        getConfig: function (callback, param) {
            return new Promise(function (resolve, reject) {
                param = param || false;

                if (this.$config) {
                    if (param) {

                        if (typeof callback === 'function') {
                            callback(this.$config[param]);
                        }

                        resolve(this.$config[param]);
                        return;
                    }

                    if (typeof callback === 'function') {
                        callback(this.$config);
                    }

                    resolve(this.$config);
                    return;
                }


                var self = this;

                Ajax.get('ajax_project_get_config', function (result) {
                    self.$config = result;

                    if (param) {
                        if (typeof callback === 'function') {
                            callback(self.$config[param]);
                        }

                        resolve(self.$config[param]);
                        return;
                    }

                    if (typeof callback === 'function') {
                        callback(self.$config);
                    }

                    resolve(self.$config);
                }, {
                    project: this.getName(),
                    onError: reject
                });
            }.bind(this));
        },

        /**
         * Return project defaults
         * @returns {Promise}
         */
        getDefaults: function () {
            var self = this;
            return new Promise(function (resolve, reject) {
                Ajax.get('ajax_project_get_defaults', function (result) {
                    resolve(result);
                }, {
                    project: self.encode(),
                    onError: reject
                });
            });
        },

        /**
         * return all layouts in the project
         *
         * @return {Promise}
         */
        getLayouts: function () {
            var self = this;

            if (this.$layouts) {
                return Promise.resolve(this.$layouts);
            }

            return new Promise(function (resolve, reject) {
                Ajax.get('ajax_project_get_layouts', function (result) {
                    self.$layouts = result;
                    resolve(result);
                }, {
                    project: self.encode(),
                    onError: reject
                });
            });
        },

        /**
         * Set the config for a project
         * You can set a single config parameter or multiple parameters
         *
         * @param {Function} [callback]
         * @param {Object} [params] - one ore more params
         * @return Promise
         */
        setConfig: function (params, callback) {
            var self = this;
            return new Promise(function (resolve, reject) {
                Ajax.post('ajax_project_set_config', function (result) {
                    self.$config = false;

                    if (typeof callback === 'function') {
                        callback(result);
                    }

                    resolve(result);

                    self.fireEvent('save');

                    require(['Projects'], function (Projects) {
                        Projects.fireEvent('projectSave', [self]);
                    });
                }, {
                    project: self.getName(),
                    params: JSON.encode(params || false),
                    onError: reject
                });
            });
        },

        /**
         * Return the package-independent locale data for the project title.
         *
         * @return {Promise<Object>}
         */
        getTitleLocaleData: function () {
            return new Promise(function (resolve, reject) {
                Ajax.get('ajax_project_get_titleLocaleData', resolve, {
                    project: this.getName(),
                    onError: reject
                });
            }.bind(this));
        },

        /**
         * Save localized project titles as user-edit locale values.
         *
         * @param {Object} translations
         * @return {Promise<Object>}
         */
        setTitleLocaleData: function (translations) {
            const self = this;

            return new Promise(function (resolve, reject) {
                Ajax.post('ajax_project_set_titleLocaleData', function (result) {
                    const group = 'project/' + self.getName();

                    Object.each(translations, function (title, language) {
                        QUILocale.set(language, group, {
                            title: title
                        });
                    });

                    resolve(result);
                }, {
                    project     : self.getName(),
                    translations: JSON.encode(translations),
                    onError     : reject
                });
            });
        },

        /**
         * Return the Media Object for the Project
         *
         * @method classes/projects/Project#getMedia
         * @return {Object} classes/projects/project/Media
         */
        getMedia: function () {
            if (!this.$Media) {
                this.$Media = new Media(this);
            }

            return this.$Media;
        },

        /**
         * Return the Project name
         *
         * @method classes/projects/Project#getName
         * @return {String}
         */
        getName: function () {
            if (this.getAttribute('project')) {
                return this.getAttribute('project');
            }

            return this.getAttribute('name');
        },

        /**
         * Return the Project lang
         *
         * @method classes/projects/Project#getName
         * @return {String}
         */
        getLang: function () {
            return this.getAttribute('lang');
        },

        /**
         * Return all languages
         *
         * @return {Array}
         */
        getLanguages: function () {
            if (!this.getAttribute('langs')) {
                return [this.getLang()];
            }

            return this.getAttribute('langs').split(',');
        },

        /**
         * Return the project title
         *
         * @returns {String}
         */
        getTitle: function () {
            var group = 'project/' + this.getName();

            if (QUILocale.exists(group, 'title')) {
                return QUILocale.get(group, 'title');
            }

            return this.getName();
        },

        /**
         * Return the project host
         *
         * @method classes/projects/Project#getHost
         * @param {Function} callback - callback function
         */
        getHost: function (callback) {
            if (this.getAttribute('host')) {
                callback(this.getAttribute('host'));
                return;
            }

            var self = this;

            Ajax.get([
                'ajax_project_get_config',
                'ajax_vhosts_getList'
            ], function (config, vhosts) {
                var vhost = config.vhost,
                    projectName = self.getName(),
                    projectLang = self.getLang();

                for (var h in vhosts) {
                    if (!vhosts.hasOwnProperty(h)) {
                        continue;
                    }

                    if (h === 404 || h === 301) {
                        continue;
                    }

                    if (vhosts[h].project !== projectName) {
                        continue;
                    }

                    if (vhosts[h].lang !== projectLang) {
                        continue;
                    }

                    if ('httpshost' in vhosts[h] && vhosts[h].httpshost !== '') {
                        vhost = 'https://' + vhosts[h].httpshost;
                        break;
                    }

                    vhost = h;
                    break;
                }

                if (!vhost.match('http://') && !vhost.match('https://')) {
                    vhost = 'http://' + vhost;
                }

                self.setAttribute('host', vhost);

                callback(self.getAttribute('host'));

            }, {
                project: this.getName(),
                params: false
            });
        },

        /**
         * event : on Site deletion
         *
         * @method classes/projects/Project#$onChildDelete
         * @param {Object} Site - classes/projects/project/Site
         * @return {Object} this (classes/projects/Project)
         * @fires siteDelete
         */
        $onSiteDelete: function (Site) {
            var id = Site.getId();

            if (this.$ids[id]) {
                delete this.$ids[id];
            }

            this.fireEvent('siteDelete', [this, id]);

            return this;
        },

        /**
         * event : on Site deletion
         *
         * @method classes/projects/Project#$onChildDelete
         * @param {Object} Site - classes/projects/project/Site
         * @return {Object} this (classes/projects/Project)
         * @fires siteLoad
         */
        $onSiteLoad: function (Site) {
            this.fireEvent('siteLoad', [this, Site]);
        },

        /**
         * event : on Site saving
         *
         * @param {Object} Site - classes/projects/project/Site
         * @fires siteSave
         */
        $onSiteSave: function (Site) {
            this.fireEvent('siteSave', [this, Site]);
        },

        /**
         * Notify open editors that their ancestor paths may have changed.
         */
        $onSiteMove: function (Site, parentId) {
            this.fireEvent('siteMove', [this, Site, parentId]);
        },

        /**
         * event : on Site unlink
         *
         * @param {Object} Site - classes/projects/project/Site
         * @fires siteUnlink
         */
        $onSiteUnlink: function (Site, parentId) {
            this.fireEvent('siteUnlink', [this, Site, parentId]);
        },

        /**
         * event : on Site create
         *
         * @param {Object} Site - classes/projects/project/Site
         * @param {Number} newchildid - id of the new child
         * @fires siteCreate
         */
        $onSiteCreate: function (Site, newchildid) {
            this.fireEvent('siteCreate', [this, Site, newchildid]);
        },

        /**
         * event : on Site activasion
         *
         * @param {Object} Site - classes/projects/project/Site
         * @fires Activate
         */
        $onSiteActivate: function (Site) {
            this.fireEvent('siteActivate', [this, Site]);
        },

        /**
         * event : on Site deactivasion
         *
         * @param {Object} Site - classes/projects/project/Site
         * @fires Activate
         */
        $onSiteDeactivate: function (Site) {
            this.fireEvent('siteDeactivate', [this, Site]);
        },

        /**
         * event : on Site sort saving
         *
         * @param {Object} Site - classes/projects/project/Site
         * @fires sortSave
         */
        $onSiteSortSave: function (Site) {
            this.fireEvent('siteSortSave', [this, Site]);
        }
    });
});
