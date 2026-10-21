/**
 * Edit a user's address in a separate confirmation window.
 *
 * @event onSaved [self]
 */
define('controls/users/address/EditWindow', [
    'qui/controls/windows/Confirm',
    'controls/users/Address',
    'Locale'
], function (Confirm, Address, Locale) {
    'use strict';

    const lg = 'quiqqer/core';

    return new Class({
        Extends: Confirm,
        Type: 'controls/users/address/EditWindow',

        options: {
            uid: false,
            addressId: false
        },

        initialize: function (options) {
            this.parent({
                title: Locale.get(lg, 'users.user.address.edit'),
                icon: 'fa fa-edit',
                texticon: false,
                text: false,
                information: false,
                maxWidth: 1000,
                maxHeight: 900,
                ok_button: {
                    text: Locale.get(lg, 'save'),
                    textimage: 'fa fa-save'
                },
                cancel_button: {
                    text: Locale.get(lg, 'cancel'),
                    textimage: false
                },
                ...options,
                autoclose: false
            });

            this.$Address = null;
            this.$loaded = false;
            this.$saving = false;
            this.$closed = false;
            this.$focusTimer = null;
            this.$ReturnFocus = document.activeElement;

            this.addEvents({
                onOpen: this.$onOpen.bind(this),
                onSubmit: this.$onSubmit.bind(this),
                onCloseBegin: () => {
                    this.$closed = true;
                    window.clearTimeout(this.$focusTimer);

                    if (!this.$saving) {
                        this.$disposeAddress();
                    }
                },
                onClose: () => {
                    if (this.$ReturnFocus?.isConnected) {
                        this.$ReturnFocus.focus();
                    }
                }
            });
        },

        $onOpen: function () {
            const Elm = this.getElm();
            const Content = this.getContent();
            const openedAt = Date.now();

            Elm.setAttribute('role', 'dialog');
            Elm.setAttribute('aria-modal', 'true');
            Elm.setAttribute('aria-label', this.getAttribute('title'));
            Content.style.padding = '0';
            Content.setAttribute('aria-busy', 'true');

            for (const name of ['cancel', 'submit']) {
                this.getButton(name).getElm().tabIndex = 0;
            }

            this.getButton('submit').disable();

            this.$Address = new Address({
                uid: this.getAttribute('uid'),
                addressId: this.getAttribute('addressId'),
                events: {
                    onLoaded: (Editor) => {
                        if (this.$closed) {
                            return;
                        }

                        this.$loaded = true;
                        Content.setAttribute('aria-busy', 'false');
                        this.getButton('submit').enable();

                        const Form = Editor.getElm().querySelector('[data-name="address-form"]');

                        Form.addEventListener('submit', (event) => {
                            event.preventDefault();
                            this.submit();
                        });

                        // Confirm initially focuses Cancel after its opening animation.
                        this.$focusTimer = window.setTimeout(() => {
                            if (!this.$closed) {
                                Editor.getElm().querySelector('[data-name="company"]').focus();
                            }
                        }, Math.max(0, 250 - (Date.now() - openedAt)));
                    },
                    onLoadError: () => {
                        Content.setAttribute('aria-busy', 'false');
                    },
                    onSaved: () => {
                        this.$saving = false;
                        this.Loader.hide();
                        this.fireEvent('saved', [this]);

                        if (this.$closed) {
                            this.$disposeAddress();
                        } else {
                            this.close();
                        }
                    },
                    onSaveError: () => {
                        this.$saving = false;
                        this.Loader.hide();

                        if (this.$closed) {
                            this.$disposeAddress();
                        } else {
                            Content.setAttribute('aria-busy', 'false');
                            this.getButton('submit').enable();
                        }
                    }
                }
            });

            this.$Address.inject(Content);
        },

        $onSubmit: function () {
            if (!this.$loaded || this.$saving || this.$closed) {
                return;
            }

            this.$saving = true;
            this.getButton('submit').disable();
            this.getContent().setAttribute('aria-busy', 'true');
            this.Loader.show();
            this.$Address.save();
        },

        $disposeAddress: function () {
            this.$Address?.destroy();
            this.$Address = null;
        }
    });
});
