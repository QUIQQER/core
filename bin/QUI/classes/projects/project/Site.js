/**
 * A project Site Object
 *
 *
 * @event onLoad [ this ]
 * @event onGetChildren [ this, {Array} ]
 * @event onActivate [ this ]
 * @event onDeactivate [ this ]
 * @event onDelete [ this ]
 * @event onUnlink [ this, parentId ]
 * @event createChild [ this ]
 * @event sortSave [ this ] --> triggerd by SiteChildren.js
 */
define('classes/projects/project/Site', [

    'qui/QUI',
    'qui/classes/DOM',
    'Ajax',
    'Locale'

], function (QUI, DOM, Ajax, QUILocale) {
    "use strict";

    const WORKING_STORAGE_MAX_AGE = 28 * 24 * 60 * 60 * 1000;

    /**
     * @class classes/projects/project/Site
     *
     * @param {Object} Project - classes/projects/Project
     * @param {Number} id - Site ID
     *
     * @fires onStatusEditBegin - this
     * @fires onStatusEditEnd   - this
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: DOM,
        Type: 'classes/projects/project/Site',

        Binds: [
            'setAttributes',
            'setAttribute',
            'getAttributes',
            'getAttribute'
        ],

        options: {
            Project: '',
            id: 0,
            attributes: {}
        },

        initialize: function (Project, id) {
            this.$Project = Project;
            this.$has_children = false;
            this.$parentid = false;
            this.$loaded = false;
            this.$urlRequest = 0;

            this.$workingId = 'site-' +
                Project.getName() + '-' +
                Project.getLang() + '-' +
                id;

            this.$modulesLoaded = false;

            this.parent({
                id: id
            });
        },

        /**
         * Decode the site
         * eq for ajax request
         *
         * @return {Object}
         */
        encode: function () {
            var Project = this.getProject(),
                encode = Project.encode();

            encode = JSON.decode(encode);
            encode.id = this.getId();

            return JSON.encode(encode);
        },

        /**
         * Load the site
         * Get all attributes from the DB
         *
         * @method classes/projects/project/Site#load
         * @param {Function} [onfinish] - (optional) callback Function
         * @return {Object} this (classes/projects/project/Site)
         */
        load: function (onfinish) {
            var params = this.ajaxParams(),
                Site = this;

            Ajax.get('ajax_site_get', function (result) {
                Site.$urlRequest++;
                Site.setAttributes(result.attributes);
                //Site.clearWorkingStorage();

                Site.$has_children = false;
                Site.$parentid = false;
                Site.$url = '';
                Site.$loaded = true;

                if ("has_children" in result) {
                    Site.$has_children = parseInt(result.has_children);
                }

                if ("parentid" in result) {
                    Site.$parentid = result.parentid;
                }

                if ("url" in result) {
                    Site.$url = result.url;
                }

                if (Site.$modulesLoaded === false &&
                    "modules" in result &&
                    "js" in result.modules) {
                    Site.$modulesLoaded = true;

                    var onSiteLoad = [],
                        jsModules = result.modules.js;

                    for (var i in jsModules) {
                        if (!jsModules.hasOwnProperty(i)) {
                            continue;
                        }

                        if (i === 'onSiteLoad') {
                            onSiteLoad.append(jsModules[i]);
                        }
                    }

                    if (onSiteLoad.length) {
                        require(onSiteLoad, function () {
                            for (var i = 0, len = onSiteLoad.length; i < len; i++) {
                                if (typeOf(arguments[i]) === 'class') {
                                    new arguments[i](Site);
                                    continue;
                                }

                                if (typeOf(arguments[i]) === 'function') {
                                    arguments[i](Site);
                                }
                            }


                            Site.fireEvent('load', [Site]);

                            if (typeof onfinish === 'function') {
                                onfinish(Site);
                            }
                        });
                    }

                    Site.fireEvent('load', [Site]);

                    return;
                }

                Site.fireEvent('load', [Site]);

                if (typeof onfinish === 'function') {
                    onfinish(Site);
                }
            }, params);

            return this;
        },

        /**
         * the site has been loaded once?
         *
         * @return {Boolean}
         */
        isLoaded: function () {
            return this.$loaded;
        },

        /**
         * Get the site ID
         *
         * @method classes/projects/project/Site#getId
         * @return {Number}
         */
        getId: function () {
            return parseInt(this.getAttribute('id'));
        },

        /**
         * Get the site project
         *
         * @method classes/projects/project/Site#getProject
         * @return {Object} classes/projects/Project
         */
        getProject: function () {
            return this.$Project;
        },

        /**
         * Return the rewrited url
         *
         * @return {String}
         */
        getUrl: function () {
            if (typeof this.$url !== 'undefined') {
                return this.$url;
            }

            return '';
        },

        /**
         * Refresh location metadata without replacing unsaved editor attributes.
         */
        refreshUrl: function () {
            const request = ++this.$urlRequest;

            return new Promise((resolve, reject) => {
                Ajax.get('ajax_site_getUrl', result => {
                    if (request === this.$urlRequest) {
                        this.$url = result.url;
                        this.$parentid = result.parentid;
                        this.fireEvent('urlChange', [this]);
                    }

                    resolve();
                }, {...this.ajaxParams(), onError: reject});
            });
        },

        /**
         * Has the site children
         *
         * @return {Boolean}
         */
        hasChildren: function () {
            return !!this.$has_children;
        },

        /**
         * Return the children count
         *
         * @return {Number}
         */
        countChild: function () {
            if (!this.$has_children) {
                return 0;
            }

            return parseInt(this.$has_children);
        },

        /**
         * Get the children
         *
         * @method classes/projects/project/Site#getChildren
         * @param {Object} [params] - (optional)
         * @param {Function} [callback] - (optional), callback function
         * @returns {Object} this (classes/projects/project/Site)
         */
        getChildren: function (params, callback) {
            return new Promise(function (resolve, reject) {

                var data = this.ajaxParams(),
                    Site = this;

                data.params = JSON.encode(params || {});
                data.onError = reject;

                Ajax.get('ajax_site_getchildren', function (result) {
                    var i, len, Child;
                    var children = result.children,
                        Project = Site.getProject();

                    for (i = 0, len = children.length; i < len; i++) {
                        Child = Project.get(children[i].id);
                        Child.setAttributes(children[i]);

                        if ("has_children" in children[i]) {
                            Child.$has_children = parseInt(children[i].has_children);
                        }

                        if ("parentid" in children[i]) {
                            Child.$parentid = children[i].parentid;
                        }

                        if ("url" in children[i]) {
                            Child.$url = children[i].url;
                        }
                    }

                    if (typeof callback === 'function') {
                        callback(children, result);
                    }

                    Site.fireEvent('getChildren', [
                        Site,
                        children,
                        result
                    ]);

                    resolve(result);

                }, data);

            }.bind(this));
        },

        /**
         * Return the parent
         *
         * @method classes/projects/project/Site#getParent
         * @return {Object|Boolean} classes/projects/project/Site | false
         */
        getParent: function () {
            if (!this.$parentid) {
                return false;
            }

            return this.getProject().get(this.$parentid);
        },

        /**
         * Activate the site
         *
         * @method classes/projects/project/Site#ajaxParams
         * @param {Function} [onfinish] - (optional), callback function
         * @return {Object} this (classes/projects/project/Site)
         */
        activate: function (onfinish) {
            var Site = this;

            Ajax.post('ajax_site_activate', function (result) {
                if (result) {
                    Site.setAttribute('active', 1);
                }

                if (typeof onfinish === 'function') {
                    onfinish(result);
                }

                if (result) {
                    Site.fireEvent('activate', [Site]);
                } else {
                    Site.setAttribute('release_from', '');
                    Site.fireEvent('deactivate', [Site]);
                }

            }, this.ajaxParams());

            return this;
        },

        /**
         * Deactivate the site
         *
         * @method classes/projects/project/Site#deactivate
         * @param {Function} [onfinish] - (optional), callback function
         * @return {Object} this (classes/projects/project/Site)
         */
        deactivate: function (onfinish) {
            var Site = this;

            Ajax.post('ajax_site_deactivate', function (result) {
                if (result === 0) {
                    Site.setAttribute('active', 0);
                    Site.setAttribute('release_from', '');
                }

                if (typeof onfinish === 'function') {
                    onfinish(result);
                }

                if (result === 0) {
                    Site.fireEvent('deactivate', [Site]);
                } else {
                    Site.fireEvent('activate', [Site]);
                }

            }, this.ajaxParams());

            return this;
        },

        /**
         * Save the site
         *
         * @method classes/projects/project/Site#save
         * @param {Function} [onfinish] - (optional), callback function
         * @return {Promise}
         */
        save: function (onfinish, lockToken = null) {
            // Callers without an open editor acquire a lease for this one save.
            if (lockToken === null) {
                const token = Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
                const request = action => new Promise((resolve, reject) => {
                    Ajax.post('ajax_site_' + action, resolve, {
                        ...this.ajaxParams(), token, force: 0, onError: reject
                    });
                });
                return request('lock').then(acquired => {
                    if (acquired !== true) {
                        throw new Error(QUILocale.get('quiqqer/core', 'exception.site.is.being.edited'));
                    }
                    return this.save(onfinish, token).finally(() => request('unlock'));
                });
            }

            var Site = this,
                params = this.ajaxParams(),
                status = this.getAttribute('active');

            return new Promise(function (resolve, reject) {
                params.attributes = JSON.encode(Site.getAttributes());
                params.lockToken = lockToken ?? '';
                params.onError = reject;

                Ajax.post('ajax_site_save', function (result) {
                    Site.$urlRequest++;
                    if (result && result.attributes) {
                        Site.setAttributes(result.attributes);
                    }

                    if (result) {
                        Site.$has_children = parseInt(result.has_children) || false;
                        Site.$parentid = parseInt(result.parentid) || false;
                        Site.$url = result.url || '';
                    }

                    // if status change, trigger events
                    if (Site.getAttribute('active') != status) {
                        if (Site.getAttribute('active') == 1) {
                            Site.fireEvent('activate', [Site]);
                        } else {
                            Site.fireEvent('deactivate', [Site]);
                        }
                    }

                    Site.fireEvent('save', [Site]);

                    if (typeof onfinish === 'function') {
                        onfinish(result);
                    }

                    resolve(result);
                }, params);
            });
        },

        /**
         * Delete the site
         * Delete it in the Database, too
         *
         * @method classes/projects/project/Site#del
         * @param {Function} [onfinish] - (optional), callback function
         */
        del: function (onfinish) {
            return new Promise((resolve, reject) => {
                let params = this.ajaxParams();

                params.onError = reject;

                Ajax.post('ajax_site_delete', (result) => {
                    if (typeof onfinish === 'function') {
                        onfinish(result);
                    }

                    this.clearWorkingStorage();
                    this.fireEvent('delete', [this]);

                    resolve(result);
                }, params);
            });
        },

        /**
         * Move the site to another parent site
         *
         * @param {Number} newParentId - ID of the new parent
         * @param {Function} [callback] - (optional), callback function
         * @return Promise
         */
        move: function (newParentId, callback) {
            return new Promise(function (resolve, reject) {
                var Site = this,
                    params = this.ajaxParams();

                params.newParentId = newParentId;
                params.onError = reject;

                Ajax.post('ajax_site_move', function (result) {
                    Site.$parentid = newParentId;

                    // A failed URL refresh must not hide an already completed move.
                    // Ajax reports the refresh error through its normal error handling.
                    Site.refreshUrl().catch(() => {}).then(() => {
                        Site.fireEvent('move', [Site, newParentId]);

                        if (typeof callback === 'function') {
                            callback(result);
                        }

                        resolve(result);
                    });
                }, params);
            }.bind(this));
        },

        /**
         * Copy the site to another parent site
         *
         * @param {Number|Object} newParent - ID of the new parent, or parent data
         * @param {Function} [callback] - (optional) callback function
         * @return Promise
         */
        copy: function (newParent, callback) {
            return new Promise(function (resolve, reject) {

                var Site = this,
                    params = this.ajaxParams();

                params.newParent = JSON.encode(newParent);
                params.onError = reject;

                Ajax.post('ajax_site_copy', function (result) {
                    if (typeof callback === 'function') {
                        callback(result);
                    }

                    Site.fireEvent('copy', [
                        Site,
                        newParent
                    ]);

                    resolve(result);

                }, params);

            }.bind(this));
        },

        /**
         * Create a link into the parent to the site
         *
         * @param {Number} newParentId - ID of the parent
         * @param {Function} [callback] - (optional) callback function
         */
        linked: function (newParentId, callback) {
            var Site = this,
                params = this.ajaxParams();

            params.newParentId = newParentId;

            Ajax.post('ajax_site_linked', function (result) {
                if (typeof callback === 'function') {
                    callback(result);
                }

                Site.fireEvent('linked', [
                    Site,
                    newParentId
                ]);
            }, params);
        },

        /**
         * lock the site
         *
         * @param {function} [callback]
         * @return {Promise}
         */
        lock: function (callback) {
            return new Promise(function (resolve, reject) {
                var params = this.ajaxParams();

                params.onError = reject;

                Ajax.post('ajax_site_lock', function () {
                    if (typeof callback === 'function') {
                        callback();
                    }

                    resolve();
                }, params);
            }.bind(this));
        },

        /**
         * unlock the site
         *
         * @param {function} [callback]
         * @return {Promise}
         */
        unlock: function (callback) {
            return new Promise(function (resolve, reject) {
                var params = this.ajaxParams();

                params.onError = reject;
                params.force = 1;

                Ajax.post('ajax_site_unlock', function () {
                    if (typeof callback === 'function') {
                        callback();
                    }

                    resolve();
                }, params);
            }.bind(this));
        },

        /**
         * Create a child site
         *
         * @method classes/projects/project/Site#createChild
         *
         * @param {String|Object} newname - String = new name of the child, Object = { name:'', title:'' }
         * @param {Function} [onfinish] - (optional) callback function
         * @param {Function} [onerror]   - (optional) function, that is triggered if an error occurred
         */
        createChild: function (newname, onfinish, onerror) {
            if (typeof newname === 'undefined') {
                return;
            }

            var params = this.ajaxParams();

            if (typeOf(newname) === 'object') {
                params.attributes = JSON.encode(newname);

            } else {
                params.attributes = JSON.encode({
                    name: newname
                });
            }


            if (typeof onerror !== 'undefined') {
                params.showError = false;
                params.onError = onerror;
            }

            var Site = this;

            Ajax.post('ajax_site_children_create', function (result) {
                if (!result) {
                    return;
                }

                Site.$has_children = Site.countChild() + 1;

                if (typeof onfinish === 'function') {
                    onfinish(result);
                }

                Site.fireEvent('createChild', [
                    Site,
                    result.id
                ]);

            }, params);
        },

        /**
         * Is the Site active?
         *
         * @method classes/projects/project/Site#getAttribute
         * @return {Boolean}
         */
        isActive: function () {
            return this.getAttribute('active');
        },

        /**
         * Delete a link from a site
         * Remove the linked site, not the original site
         *
         * @param {Number} parentId - ID of the parent
         * @param {Boolean} all - delete all links
         * @returns {Promise}
         */
        unlink: function (parentId, all) {
            return new Promise(function (resolve, reject) {
                var Site = this,
                    params = this.ajaxParams();

                params.onError = reject;
                params.parentId = parentId;
                params.all = all || false;

                Ajax.post('ajax_site_unlink', function () {
                    resolve();
                    Site.fireEvent('unlink', [
                        Site,
                        parentId
                    ]);
                }, params);
            }.bind(this));
        },

        /**
         * Return the path from the site shortcut
         *
         * @param parentId
         * @returns {Promise}
         */
        getLinkedPath: function (parentId) {
            return new Promise(function (resolve, reject) {
                var params = this.ajaxParams();

                params.onError = reject;
                params.parentId = parentId;

                Ajax.post('ajax_site_getLinkedPath', resolve, params);
            }.bind(this));
        },

        /**
         * Working data
         */

        /**
         * clears the working storage for the site
         */
        clearWorkingStorage: function () {
            QUI.Storage.remove(this.getWorkingStorageId());
        },

        /**
         * return the working storage id of the site
         *
         * @return {string}
         */
        getWorkingStorageId: function () {
            return this.$workingId;
        },

        /**
         * Has the site an working storage?
         *
         * @return {boolean}
         */
        hasWorkingStorage: function () {
            return !!this.getWorkingStorage();
        },

        /**
         * has the site some changes in the storage?
         *
         * @return {Promise}
         */
        hasWorkingStorageChanges: function () {
            if (this.hasWorkingStorage() === false) {
                return Promise.resolve(false);
            }

            const self = this;

            return new Promise(function (resolve) {
                var Data;

                if (self.isLoaded()) {
                    Data = Promise.resolve();
                } else {
                    Data = new Promise(function (resolve) {
                        self.load(resolve);
                    });
                }

                Data.then(function () {
                    const storageData = self.getWorkingStorage();

                    if (!storageData) {
                        resolve(false);
                        return;
                    }

                    var siteData = self.getAttributes();

                    var hasChanges = function () {
                        for (var attribute in storageData) {
                            if (!storageData.hasOwnProperty(attribute)) {
                                continue;
                            }

                            if (!siteData.hasOwnProperty(attribute)) {
                                continue;
                            }

                            if (JSON.encode(siteData[attribute]) !== JSON.encode(storageData[attribute])) {
                                return true;
                            }
                        }

                        return false;
                    };

                    if (hasChanges()) {
                        resolve(storageData);
                        return;
                    }

                    resolve(false);
                });
            });
        },

        /**
         * Return a valid draft, removing entries that cannot be dated or have expired.
         *
         * @returns {object|boolean}
         */
        getWorkingStorage: function () {
            const storage = QUI.Storage.get(this.getWorkingStorageId());

            if (storage === null || storage === undefined) {
                return false;
            }

            let data;

            try {
                data = JSON.parse(storage);
            } catch (e) {
                this.clearWorkingStorage();
                return false;
            }

            if (!data || typeof data !== 'object' || Array.isArray(data)) {
                this.clearWorkingStorage();
                return false;
            }

            const storageTime = data.__storageTime;
            const now = Date.now();

            if (
                !Number.isFinite(storageTime)
                || storageTime <= 0
                || storageTime > now
                || now - storageTime >= WORKING_STORAGE_MAX_AGE
            ) {
                this.clearWorkingStorage();
                return false;
            }

            return data;
        },

        /**
         * Set the working storage data to the site
         */
        restoreWorkingStorage: function () {
            var data = this.getWorkingStorage();

            if (data) {
                this.options.attributes = data;
            }
        },

        /**
         * Site attributes
         */

        /**
         * Get an site attribute
         *
         * @method classes/projects/project/Site#getAttribute
         * @param {String} k - Attribute name
         * @return {Boolean|Function|Number|String|Object}
         */
        getAttribute: function (k) {
            var attributes = this.options.attributes;

            if (typeof attributes[k] !== 'undefined') {
                return attributes[k];
            }

            var oid = Slick.uidOf(this);

            if (typeof window.$quistorage[oid] === 'undefined') {
                return false;
            }

            if (typeof window.$quistorage[oid][k] !== 'undefined') {
                return window.$quistorage[oid][k];
            }

            return false;
        },

        /**
         * Get all attributes from the Site
         *
         * @method classes/projects/project/Site#getAttributes
         * @return {Object} Site attributes
         */
        getAttributes: function () {
            return this.options.attributes;
        },

        /**
         * Set an site attribute
         * -> bool vars converted to 1 and 0
         *
         * @method classes/projects/project/Site#setAttribute
         *
         * @param {String} k        - Name of the Attribute
         * @param {Boolean|Number|Function|Object} v - Value of the Attribute
         */
        setAttribute: function (k, v) {
            // convert bool to 1 and 0
            if (typeOf(v) === 'boolean') {
                v = v ? 1 : 0;
            }

            // if the value not changed, do nothing
            if (k in this.options.attributes &&
                v == this.options.attributes[k]) {
                return;
            }

            this.options.attributes[k] = v;

            if (this.$loaded === false) {
                return;
            }

            if (k === 'id') {
                return;
            }

            // locale storage
            var attributes = this.options.attributes;
            attributes.__storageTime = new Date().getTime();

            QUI.Storage.set(this.getWorkingStorageId(), JSON.encode(attributes));
        },

        /**
         * If you want to set more than one attribute
         *
         * @method classes/projects/project/Site#setAttributes
         *
         * @param {Object} attributes - Object with attributes
         * @return {Object} this (classes/projects/project/Site)
         *
         * @example
         * Site.setAttributes({
         *   attr1 : '1',
         *   attr2 : []
         * })
         */
        setAttributes: function (attributes) {
            attributes = attributes || {};

            for (var k in attributes) {
                if (attributes.hasOwnProperty(k)) {
                    this.setAttribute(k, attributes[k]);
                }
            }

            return this;
        },

        /**
         * Returns the needle request (Ajax) params
         *
         * @method classes/projects/project/Site#ajaxParams
         * @return {Object}
         */
        ajaxParams: function () {
            return {
                project: this.getProject().encode(),
                id: this.getId()
            };
        }
    });
});
