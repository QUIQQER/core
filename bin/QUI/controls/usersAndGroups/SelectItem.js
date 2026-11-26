define('controls/usersAndGroups/SelectItem', [

    'qui/QUI',
    'qui/controls/elements/SelectItem',
    'Ajax',
    'Locale',
    'Groups',
    'Users'

], function(QUI, QUIElementSelectItem, QUIAjax, QUILocale, Groups, Users) {
    'use strict';

    return new Class({

        Extends: QUIElementSelectItem,
        Type: 'controls/usersAndGroups/SelectItem',

        Binds: [
            'refresh'
        ],

        initialize: function(options) {
            this.parent(options);
            this.$destroyed = false;
            this.$refreshVersion = 0;
            this.addEvent('onDestroy', () => {
                this.$destroyed = true;
                this.$refreshVersion++;
            });
            this.setAttribute('icon', 'fa fa-group');
        },

        /**
         * Refresh the display
         *
         * @returns {Promise}
         */
        refresh: function() {
            if (this.$destroyed) {
                return Promise.resolve();
            }

            const id = String(this.getAttribute('id'));
            const version = ++this.$refreshVersion;
            const isGroup = id.startsWith('g');
            const Entity = isGroup ? Groups.get(id.substring(1)) : Users.get(id.substring(1));
            const loaded = Entity.isLoaded() ? Promise.resolve(Entity) : Entity.load();
            this.setAttribute('icon', isGroup ? 'fa fa-group' : 'fa fa-user');

            return loaded.then(() => {
                if (this.$destroyed || version !== this.$refreshVersion) {
                    return;
                }

                if (!isGroup && !Entity.getId() && id !== 'u0') {
                    throw Entity;
                }

                if (isGroup && id.substring(1) === '1') {
                    this.$Destroy.style.display = 'none';
                }

                this.$Text.textContent = Entity.getName();
                this.$Text.removeAttribute('role');
                this.getElm().removeAttribute('aria-invalid');
                this.getElm().removeAttribute('title');
            }).catch((reason) => {
                if (this.$destroyed || version !== this.$refreshVersion) {
                    return;
                }

                let error = reason;

                if (!isGroup && reason === Entity && !Entity.getId()) {
                    const userId = id.substring(1);
                    error = new Error(QUILocale.get('quiqqer/core', 'exception.lib.user.not.found') + ' (' + userId + ')');
                    error.userId = userId;
                }

                const message = error?.message || String(error);
                this.setAttribute('icon', 'fa fa-exclamation-triangle');
                this.$Text.textContent = message;
                this.$Text.setAttribute('role', 'alert');
                this.getElm().setAttribute('aria-invalid', 'true');
                this.getElm().title = message;
                console.error(error);
            });
        }
    });
});
