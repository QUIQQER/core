/**
 * Shared confirmation for permanently deleting users or clearing their data.
 */
define('controls/users/DeleteWindow', [
    'qui/controls/windows/Confirm',
    'Users',
    'Locale',
    'css!controls/users/DeleteWindow.css'
], function (Confirm, Users, Locale) {
    'use strict';

    const lg = 'quiqqer/core';

    return new Class({
        Extends: Confirm,
        Type: 'controls/users/DeleteWindow',

        initialize: function (options) {
            options = options || {};
            const wipe = options.mode === 'wipe';
            const prefix = 'users.panel.' + (wipe ? 'wipe' : 'delete') + '.window.';

            this.$users = options.users || [];
            this.$wipe = wipe;
            this.$pending = false;
            this.$prefix = prefix;

            this.parent(Object.assign({}, options, {
                name: wipe ? 'WipeUsers' : 'DeleteUsers',
                title: Locale.get(lg, prefix + 'title'),
                icon: wipe ? 'fa fa-eraser' : 'fa fa-trash-o',
                texticon: false,
                text: false,
                information: false,
                maxWidth: 560,
                maxHeight: false,
                autoresize: true,
                autoclose: false,
                ok_button: {
                    text: Locale.get(lg, wipe ? 'users.user.btn.wipe' :
                        prefix + 'submit.' + (this.$users.length === 1 ? 'user' : 'users')),
                    textimage: wipe ? 'fa fa-eraser' : 'fa fa-trash-o'
                }
            }));

            this.addEvents({
                onOpen: () => this.$render(),
                onSubmit: () => this.$submitUsers(),
                onClose: () => {
                    if (this.$listObserver) {
                        this.$listObserver.disconnect();
                    }
                }
            });
        },

        $render: function () {
            this.getElm().addClass('qui-user-delete-window');
            const Content = this.getContent();
            Content.empty();
            Content.setStyle('padding', 0);

            const Body = new Element('div', {'class': 'qui-user-delete-window-body'}).inject(Content);
            new Element('h2', {text: Locale.get(lg, this.$prefix + 'text')}).inject(Body);
            new Element('p', {text: Locale.get(lg, this.$prefix + 'description')}).inject(Body);

            const List = new Element('ul', {'class': 'qui-user-delete-window-users'}).inject(Body);
            this.$users.forEach((user) => {
                const Item = new Element('li').inject(List);
                new Element('span', {
                    'class': 'fa fa-user-o', 'aria-hidden': 'true'
                }).inject(Item);
                const Details = new Element('div').inject(Item);
                new Element('strong', {text: user.name || user.id}).inject(Details);
                new Element('span', {text: user.id}).inject(Details);
            });

            if (this.$users.length > 3) {
                List.set('tabindex', 0);
                const visibleRows = Array.from(List.children).slice(0, 3);
                const resizeList = () => {
                    const height = visibleRows.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0);
                    List.setStyle('max-height', Math.ceil(height + List.clientTop * 2));
                };

                // Measure real rows so wrapped names and narrower windows still show three entries.
                this.$listObserver = new ResizeObserver(resizeList);
                visibleRows.forEach(row => this.$listObserver.observe(row));
                resizeList();
            }

            const Warning = new Element('div', {
                'class': 'q-message q-message-warning', role: 'note'
            }).inject(Content);
            new Element('span', {
                'class': 'fa fa-exclamation-triangle', 'aria-hidden': 'true'
            }).inject(Warning);
            new Element('span', {
                text: Locale.get(lg, this.$prefix + 'information')
            }).inject(Warning);

            this.getButton('submit').getElm().removeClass('btn-success').addClass('btn-danger');

            // Confirm focuses its first button after opening; prefer the non-destructive action.
            setTimeout(() => {
                const Cancel = this.getButton('cancel');
                if (Cancel && Cancel.getElm().isConnected) {
                    Cancel.getElm().focus();
                }
            }, 250);
        },

        $submitUsers: function () {
            if (this.$pending || !this.$users.length) {
                return;
            }

            this.$pending = true;
            this.getButton('submit').disable();
            this.Loader.show();

            const ids = this.$users.map(user => user.id);
            const request = this.$wipe ? Users.wipeUsers(ids) : Users.deleteUsers(ids);

            request.then(() => {
                this.fireEvent('complete', [this]);
                this.close();
            }).catch(() => {
                // Ajax displays the server's error. Keep the selection available for retry.
                this.$pending = false;
                this.Loader.hide();
                this.getButton('submit').enable();
            });
        }
    });
});
