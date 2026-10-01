/**
 * User Manager - class
 *
 *
 * @event onSwitchStatus [this, result, Request]
 * @event onActivate [this, result, Request]
 * @event onDeactivate [this, result, Request]
 * @event onDelete [this, uids]
 * @event onRefresh [this, User]
 * @event onSave [this, User]
 */
define('classes/users/Manager', [

    'qui/classes/DOM',
    'classes/users/User',
    'classes/users/Nobody',
    'classes/users/SystemUser',
    'Ajax',
    'qui/utils/Object'

], function(DOM, User, Nobody, SystemUser, Ajax, ObjectUtils) {
    'use strict';

    /**
     * @class classes/users/Manager
     * @desc User Manager (Model)
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: DOM,                   // @member classes/users/Manager
        Type: 'classes/users/Users', // @member classes/users/Manager
        $users: {},                    // @member classes/users/Manager

        /**
         * Return a user
         *
         * @method classes/users/Manager#get
         * @param {Number|String} uid - Id of the User
         * @return {Object} User - controls/users/User
         */
        get: function(uid) {
            if (uid === 0 || uid === false || uid === '0') {
                return new Nobody();
            }

            if (uid === 5 || uid === '5') {
                return new SystemUser();
            }

            if (typeof this.$users[uid] === 'undefined') {
                this.$users[uid] = new User(uid);
            }

            return this.$users[uid];
        },

        /**
         * Return the loged in user (session user)
         *
         * @method classes/users/Manager#getUserBySession
         * @return {Object} User - controls/users/User
         */
        getUserBySession: function() {
            if (typeof this.$users[USER.id] === 'undefined') {
                this.$users[USER.id] = new User(USER.id);
            }

            return this.$users[USER.id];
        },

        /**
         * Return the user list
         *
         * @method classes/users/Manager#getList
         * @param {Object} search     - search options
         * @param {Object} [params]     - (optional), extra params
         * @return {Promise}
         */
        getList: function(search, params) {
            return new Promise((resolve, reject) => {
                Ajax.get('ajax_users_search', resolve, ObjectUtils.combine(params, {
                    params: JSON.stringify(search),
                    onError: reject
                }));
            });
        },

        /**
         * Is the mixed an user object?
         *
         * @param {unknown} User
         * @return {boolean}
         */
        isUser: function(User) {
            const type = typeOf(User);

            if (type === 'classes/users/Nobody') {
                return true;
            }

            if (type === 'classes/users/SystemUser') {
                return true;
            }

            return type === 'classes/users/User';
        },

        /**
         * Switch the status to activate or deactivate from an user
         *
         * @method classes/users/Manager#switchStatus
         * @param {Array|Number} uid    - search options
         * @param {Function} [onfinish] - (optional), callback function
         * @param {Object} [params]     - (optional), extra params
         * @return Promise
         */
        switchStatus: function(uid, onfinish, params) {
            return new Promise((resolve, reject) => {
                params = ObjectUtils.combine(params, {
                    uid: JSON.stringify(uid),
                    onError: reject
                });

                Ajax.post('ajax_users_switchstatus', (result) => {
                    if (uid in result && uid in this.$users) {
                        this.$users[uid].setAttribute('active', result[uid]);
                    }

                    if (typeof onfinish !== 'undefined') {
                        onfinish(result);
                    }

                    resolve(this, result);

                    this.fireEvent('switchStatus', [this, result]);
                }, params);
            });
        },

        /**
         * Activate the user / users
         *
         * @method classes/users/Manager#activate
         * @param {Array|Number} uid - search options
         * @param {Function} [onfinish] - (optional), callback function
         * @param {Object} [params]     - (optional), extra params
         * @return Promise
         */
        activate: function(uid, onfinish, params) {
            return new Promise((resolve, reject) => {
                params = ObjectUtils.combine(params, {
                    uid: JSON.stringify(uid),
                    onError: reject
                });

                Ajax.post('ajax_users_activate', (result) => {
                    if (uid in result && uid in this.$users) {
                        this.$users[uid].setAttribute('active', result[uid]);
                    }

                    if (typeof onfinish !== 'undefined') {
                        onfinish(result);
                    }

                    resolve(this, result);

                    this.fireEvent('activate', [this, result]);
                    this.fireEvent('switchStatus', [this, result]);
                }, params);
            });
        },

        /**
         * Deactivate the user / users
         *
         * @method classes/users/Manager#deactivate
         * @param {Array|Number} uid    - search options
         * @param {Function} [onfinish] - (optional), callback function
         * @param {Object} [params]     - (optional), extra params
         * @return Promise
         */
        deactivate: function(uid, onfinish, params) {
            return new Promise((resolve, reject) => {
                params = ObjectUtils.combine(params, {
                    uid: JSON.stringify(uid),
                    onError: reject
                });

                Ajax.post('ajax_users_deactivate', (result, Request) => {
                    if (uid in result && uid in this.$users) {
                        this.$users[uid].setAttribute('active', result[uid]);
                    }

                    if (typeof onfinish !== 'undefined') {
                        onfinish(result, Request);
                    }

                    resolve(this, result);

                    this.fireEvent('deactivate', [this, result]);
                    this.fireEvent('switchStatus', [this, result]);
                }, params);
            });
        },

        /**
         * Checks if the username exists
         *
         * @method classes/users/Manager#existsUsername
         * @param {String} username   - Username
         * @param {Function} onfinish - callback function
         * @param {Object} [params]   - (optional), extra params
         */
        existsUsername: function(username, onfinish, params) {
            params = ObjectUtils.combine(params, {
                username: username
            });

            Ajax.get('ajax_users_exists', function(result, Request) {
                onfinish(result, Request);
            }, params);
        },

        /**
         * create a new user
         *
         * @method classes/users/Manager#createUser
         * @param {String} username - Username
         * @param {Function|Object} [onfinish] - (optional), callback function or extra params
         * @param {Object} [params] - (optional), extra params
         * @return {Promise}
         */
        createUser: function(username, onfinish, params) {
            if (typeof onfinish !== 'function') {
                params = onfinish;
                onfinish = false;
            }

            return new Promise((resolve, reject) => {
                params = ObjectUtils.combine(params, {
                    username: username,
                    onError: reject
                });

                Ajax.post('ajax_users_create', (result, Request) => {
                    if (onfinish) {
                        onfinish(result, Request);
                    }

                    resolve(result);
                }, params);
            });
        },

        /**
         * Create and invite a new user by e-mail address
         *
         * @method classes/users/Manager#inviteUser
         * @param {String} email - E-mail address
         * @param {Array} groups - Group UUIDs
         * @param {Object} [params] - (optional), extra params
         * @return {Promise}
         */
        inviteUser: function(email, groups, params) {
            return new Promise((resolve, reject) => {
                params = ObjectUtils.combine(params, {
                    email: email,
                    groups: JSON.stringify(groups || []),
                    onError: reject
                });

                Ajax.post('ajax_users_invite', resolve, params);
            });
        },

        /**
         * Delete users
         *
         * @method classes/users/Manager#deleteUsers
         * @param {Array} uids          - User-IDs
         * @param {Object} [params]     - (optional), extra params
         * @param {Function} [onfinish] - (optional), callback function
         */
        deleteUsers: function(uids, params, onfinish) {
            return this.$removeUsers('ajax_users_delete', uids, params, onfinish);
        },

        /**
         * Clear account data, retaining user IDs and UUIDs.
         */
        wipeUsers: function(uids, params) {
            return this.$removeUsers('ajax_users_wipe', uids, params);
        },

        $removeUsers: function(endpoint, uids, params, onfinish) {
            return new Promise((resolve, reject) => {
                params = ObjectUtils.combine(params, {
                    uid: JSON.stringify(uids),
                    onError: reject
                });

                Ajax.post(endpoint, (result) => {
                    const removedIds = new Set(uids.map(String));

                    // Accounts may be cached by numeric ID or UUID. Close every matching panel.
                    Object.keys(this.$users).forEach((key) => {
                        const User = this.$users[key];
                        const aliases = [key, User.getId(), User.getAttribute('id'), User.getAttribute('uuid')]
                            .filter(id => id !== null && id !== undefined && id !== false)
                            .map(String);

                        if (aliases.some(id => removedIds.has(id))) {
                            aliases.forEach(id => removedIds.add(id));
                            delete this.$users[key];
                        }
                    });

                    this.fireEvent('delete', [this, Array.from(removedIds)]);

                    if (typeof onfinish !== 'undefined') {
                        onfinish(result);
                    }

                    resolve(result);

                }, params);
            });
        },

        /**
         * Triggerd by an user
         *
         * @method classes/users/Manager#onRefreshUser
         * @param {Object} User - controls/users/User
         */
        onRefreshUser: function(User) {
            this.fireEvent('refresh', [this, User]);
        },

        /**
         * Check if the given user has an email address
         *
         * @param {Number} userId
         * @return {Promise}
         */
        hasEmail: function(userId) {
            return new Promise((resolve, reject) => {
                Ajax.get('ajax_users_hasEmail', resolve, {
                    userId: userId,
                    onError: reject
                });
            });
        },

        /**
         * Save a user with its attributes and rights
         *
         * @method classes/users/Manager#saveUser
         * @param {Object} User         - controls/users/User
         * @param {Object} [params]     - (optional), extra params
         * @param {Function} [onfinish] - (optional), callback
         * @return {Promise}
         */
        saveUser: function(User, params, onfinish) {
            return new Promise((resolve, reject) => {
                const attributes = typeof User.getChangedAttributes === 'function'
                        ? User.getChangedAttributes()
                        : Object.clone(User.getAttributes());

                for (let i in attributes) {
                    if (!attributes.hasOwnProperty(i)) {
                        continue;
                    }

                    if (typeof attributes[i] === 'object') {
                        delete attributes[i];
                    }
                }

                // attributes.extra = User.getExtras();
                params = ObjectUtils.combine(params, {
                    uid: User.getId(),
                    attributes: JSON.stringify(attributes),
                    onError: reject
                });

                Ajax.post('ajax_users_save', (result, Request) => {
                    if (typeof User.applySavedAttributes === 'function') {
                        User.applySavedAttributes(result);
                    }

                    this.fireEvent('save', [this, User]);

                    if (typeof onfinish !== 'undefined') {
                        onfinish(User, Request);
                    }

                    resolve();

                }, params);
            });
        }
    });
});
