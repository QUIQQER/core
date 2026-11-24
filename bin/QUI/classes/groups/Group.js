/**
 * Group (Model)
 *
 *
 * @event onRefresh [ {classes/groups/Group} ]
 * @event onActivate [ {classes/groups/Group} ]
 * @event onDeactivate [ {classes/groups/Group} ]
 */
define('classes/groups/Group', [

    'qui/classes/DOM',
    'qui/utils/Object',
    'Ajax'

], function(DOM, ObjectUtils, Ajax) {
    'use strict';

    /**
     * A QUIQQER Group
     *
     * @class classes/groups/Group
     * @param {Number} gid - Group-ID
     * @memberof! <global>
     */
    return new Class({

        Extends: DOM,
        Type: 'classes/groups/Group',

        attributes: {}, // group attributes

        initialize: function(gid) {
            this.$gid = gid;
            this.$loaded = false;
        },

        /**
         * Return the Group-ID
         *
         * @method classes/groups/Group#getId
         * @return {Number} Group-ID
         */
        getId: function() {
            return this.$gid;
        },

        /**
         * Return the group name
         *
         * @method classes/groups/Group#getName
         * @return {String} Group name
         */
        getName: function() {
            return this.getAttribute('name');
        },

        /**
         * Load the group attributes from the db
         *
         * @method classes/groups/Group#load
         * @param {Function} [onfinish] - (optional), callback
         * @return {Promise}
         */
        load: function(onfinish) {
            return new Promise((resolve, reject) => {
                Ajax.get('ajax_groups_get', (result) => {
                    this.setAttributes(result);
                    this.$loaded = true;

                    if (typeof onfinish === 'function') {
                        onfinish(this);
                    }

                    resolve(this);

                    this.fireEvent('refresh', [this]);
                }, {
                    showError: false,
                    gid: this.getId(),
                    onError: reject
                });
            });
        },

        /**
         * Activate the group
         *
         * @returns {Promise}
         */
        activate: function() {
            return new Promise((resolve) => {
                require(['Groups'], (Groups) => {
                    return Groups.activate(this.getId()).then(resolve);
                });
            });
        },

        /**
         * Deactivate the group
         *
         * @returns {Promise}
         */
        deactivate: function() {
            return new Promise((resolve) => {
                require(['Groups'], (Groups) => {
                    return Groups.deactivate(this.getId()).then(resolve);
                });
            });
        },

        /**
         * the group has been loaded once?
         *
         * @return {Boolean}
         */
        isLoaded: function() {
            return this.$loaded;
        },

        /**
         * Return the children groups of the group
         *
         * @method classes/groups/Group#load
         * @param {Function} [onfinish] - (optional), callback
         * @param {Object} [params] - (optional), binded params at the request
         * @return {Promise}
         */
        getChildren: function(onfinish, params) {
            return new Promise((resolve) => {
                params = ObjectUtils.combine(params, {
                    gid: this.getId()
                });

                Ajax.get('ajax_groups_children', function(result) {
                    if (typeof onfinish !== 'undefined') {
                        onfinish(result);
                    }

                    resolve(result);
                }, params);
            });
        },

        /**
         * Save the group with its actualy attributes
         *
         * @method classes/groups/Group#save
         * @param {Function} [onfinish] - (optional), callback
         * @param {Object} [params] - (optional), binded params at the request
         * @return {Promise}
         */
        save: function(onfinish, params) {
            params = ObjectUtils.combine(params, {
                gid: this.getId(),
                attributes: JSON.encode(this.getAttributes()),
                rights: '[]'
            });

            const onError = params.onError;

            return new Promise((resolve, reject) => {
                params.onError = error => {
                    reject(error);

                    if (typeof onError === 'function') {
                        onError(error);
                    }
                };

                Ajax.post('ajax_groups_save', () => {
                    if (typeof onfinish !== 'undefined') {
                        onfinish(this);
                    }

                    resolve(this);

                    this.fireEvent('refresh', [this]);

                    require(['Groups'], function(Groups) {
                        Groups.refreshGroup(this);
                    });
                }, params);
            });
        },

        /**
         * Is the Group active?
         *
         * @method classes/groups/Group#isActive
         * @return {Boolean} true or false
         */
        isActive: function() {
            return !!parseInt(this.getAttribute('active'));
        },

        /**
         * Get all users that are inside the group
         *
         * @method classes/groups/Group#getUsers
         * @param {Function} onfinish - Callback function
         *         the return of the function: {Array}
         * @param {Object} limits - limit params (limit, page, field, order)
         *
         * @return {Promise}
         */
        getUsers: function(onfinish, limits) {
            const params = {
                limit: limits.limit || 50,
                page: limits.page || 1,
                field: limits.field || 'name',
                order: limits.order || 'DESC'
            };

            return new Promise((resolve, reject) => {
                Ajax.get('ajax_groups_users', (result, Request) => {
                    if (typeof onfinish !== 'undefined') {
                        onfinish(result, Request);
                    }

                    resolve(this, result);
                }, {
                    gid: this.getId(),
                    params: JSON.encode(params),
                    onError: reject
                });
            });
        },

        /**
         * Attribute methods
         */

        /**
         * Set an attribute to the Object
         * You can extend the Object with everything you like
         * You can extend the Object width more than the default options
         *
         * @method classes/groups/Group#setAttribute
         * @param {String} k - Name of the Attribute
         * @param {Object|String|Number|Array} v - value
         * @return {Object} this (classes/groups/Group)
         */
        setAttribute: function(k, v) {
            this.attributes[k] = v;
            return this;
        },

        /**
         * If you want set more than one attribute
         *
         * @method classes/groups/Group#setAttribute
         *
         * @param {Object} [attributes] - Object with attributes
         * @return {Object} this (classes/groups/Group)
         *
         * @example Object.setAttributes({
         *   attr1 : '1',
         *   attr2 : []
         * })
         */
        setAttributes: function(attributes) {
            attributes = attributes || {};

            for (let k in attributes) {
                if (attributes.hasOwnProperty(k)) {
                    this.setAttribute(k, attributes[k]);
                }
            }

            return this;
        },

        /**
         * Return an attribute of the Object
         *
         * @method classes/groups/Group#setAttribute
         * @param {Object} k - Object width attributes
         * @return {Boolean|Number|Object|Array|String} wanted attribute
         */
        getAttribute: function(k) {
            if (typeof this.attributes[k] !== 'undefined') {
                return this.attributes[k];
            }

            return false;
        },

        /**
         * Return the default attributes
         *
         * @method classes/groups/Group#getAttributes
         * @return {Object} all attributes
         */
        getAttributes: function() {
            return this.attributes;
        },

        /**
         * Return true if an attribute exist
         *
         * @method classes/groups/Group#existAttribute
         * @param {String} k - wanted attribute
         * @return {Boolean} true | false
         */
        existAttribute: function(k) {
            return typeof this.attributes[k] !== 'undefined';
        }
    });
});
