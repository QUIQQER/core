define('controls/users/SelectItem', [

    'qui/QUI',
    'qui/controls/elements/SelectItem',
    'Ajax',
    'Locale',
    'Users'

], function(QUI, QUIElementSelectItem, QUIAjax, QUILocale, Users) {
    'use strict';

    return new Class({

        Extends: QUIElementSelectItem,
        Type: 'controls/users/SelectItem',

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
            this.setAttribute('icon', 'fa fa-user');
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

            const id = this.getAttribute('id');
            const version = ++this.$refreshVersion;
            const User = Users.get(id);
            const loaded = User.isLoaded() ? Promise.resolve(User) : User.load();
            this.setAttribute('icon', 'fa fa-user');

            return loaded.then(() => {
                if (this.$destroyed || version !== this.$refreshVersion) {
                    return;
                }

                if (!User.getId() && String(id) !== '0') {
                    throw User;
                }

                this.$Text.textContent = User.getAttribute('displayName') || User.getName();
                this.$Text.removeAttribute('role');
                this.getElm().removeAttribute('aria-invalid');
                this.getElm().removeAttribute('title');
            }).catch((reason) => {
                if (this.$destroyed || version !== this.$refreshVersion) {
                    return;
                }

                let error = reason;

                if (reason === User && !User.getId()) {
                    error = new Error(QUILocale.get('quiqqer/core', 'exception.lib.user.not.found') + ' (' + id + ')');
                    error.userId = id;
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
