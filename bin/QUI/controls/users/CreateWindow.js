/**
 * Create user dialog
 *
 * @event onCreateSubmit [this, username]
 * @event onInvite [this]
 */
define('controls/users/CreateWindow', [

    'qui/QUI',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'Users',
    'Locale',
    'Permissions',

    'css!controls/users/CreateWindow.css'

], (QUI, Confirm, QUIButton, Users, QUILocale, Permissions) => {
    'use strict';

    const lg = 'quiqqer/core';

    return new Class({
        Extends: Confirm,
        Type: 'controls/users/CreateWindow',

        initialize: function (options) {
            this.parent(Object.merge({
                name: 'CreateUser',
                title: QUILocale.get(lg, 'users.panel.create.window.title'),
                icon: 'fa fa-user',
                titleicon: false,
                autoclose: false,
                maxWidth: 600,
                maxHeight: 400,
                ok_button: {
                    textimage: 'fa fa-plus',
                    text: QUILocale.get(lg, 'users.panel.create.window.submit')
                },
                cancel_button: {
                    text: QUILocale.get(lg, 'cancel'),
                    textimage: false
                }
            }, options));

            this.$UsernameInput = null;

            this.addEvents({
                onOpen: this.$onOpen.bind(this),
                onSubmit: this.$onSubmit.bind(this)
            });
        },

        $onOpen: function (Win) {
            const Content = Win.getContent();
            const WindowElm = Win.getElm();
            const ButtonBar = WindowElm.querySelector('.qui-window-popup-buttons');

            WindowElm.classList.add('quiqqer-users-create-window-frame');
            Content.classList.add('quiqqer-users-create-window');
            Content.innerHTML = '' +
                '<div class="quiqqer-users-create-window-title">' +
                    QUILocale.get(lg, 'users.panel.create.window.title') +
                '</div>' +
                '<div class="quiqqer-users-create-window-information">' +
                    QUILocale.get(lg, 'users.panel.create.window.information') +
                '</div>' +
                '<div class="quiqqer-users-create-window-field">' +
                    '<input type="text" name="username" required />' +
                '</div>';

            this.$UsernameInput = Content.querySelector('input[name="username"]');

            if (this.$UsernameInput) {
                this.$UsernameInput.placeholder = QUILocale.get(lg, 'users.panel.create.window.placeholder');
                this.$UsernameInput.setAttribute(
                    'aria-label',
                    QUILocale.get(lg, 'users.panel.create.window.placeholder')
                );
                window.setTimeout(() => {
                    this.$UsernameInput.focus();
                }, 200);
            }

            if (ButtonBar) {
                let InviteButton = ButtonBar.querySelector('.quiqqer-users-create-window-invite-button');

                if (!InviteButton) {
                    InviteButton = new QUIButton({
                        textimage: 'fa fa-envelope',
                        text: QUILocale.get(lg, 'users.panel.create.window.invite.button'),
                        name: 'invite',
                        styles: {
                            display: 'inline-flex',
                            'float': 'none',
                            width: 'auto'
                        },
                        events: {
                            onClick: () => {
                                Win.fireEvent('invite', [Win]);
                            }
                        }
                    }).create();

                    InviteButton.classList.add('quiqqer-users-create-window-invite-button');
                }

                const PrimaryButton = ButtonBar.querySelector('[name="submit"]') || null;
                const CancelButton = ButtonBar.querySelector('[name="cancel"]') || null;

                ButtonBar.innerHTML = '';
                ButtonBar.appendChild(InviteButton);

                if (CancelButton) {
                    ButtonBar.appendChild(CancelButton);
                }

                if (PrimaryButton) {
                    ButtonBar.appendChild(PrimaryButton);
                }

                ButtonBar.querySelectorAll('button').forEach((Button) => {
                    Button.style.alignItems = 'center';
                    Button.style.display = 'inline-flex';
                    Button.style.float = 'none';
                    Button.style.justifyContent = 'center';
                    Button.style.width = 'auto';
                });
            }

            Win.Loader.show();

            Permissions.hasPermission('quiqqer.admin.users.create').then((hasPermission) => {
                if (!hasPermission) {
                    QUI.getMessageHandler().then((MH) => {
                        MH.addError(
                            QUILocale.get('quiqqer/core', 'exception.no.permission')
                        );
                    });

                    Win.close();
                }

                Win.Loader.hide();
            });
        },

        $onSubmit: function (Win) {
            const username = this.$UsernameInput ? this.$UsernameInput.value.trim() : '';

            if (!username) {
                if (this.$UsernameInput) {
                    this.$UsernameInput.focus();
                    this.$UsernameInput.reportValidity();
                }

                return;
            }

            Win.Loader.show();

            Users.existsUsername(username, (exists) => {
                if (exists === true) {
                    QUI.getMessageHandler((MH) => {
                        MH.addAttention(
                            QUILocale.get(lg, 'exception.create.user.exists')
                        );
                    });

                    Win.Loader.hide();
                    return;
                }

                Win.fireEvent('createSubmit', [Win, username]);
            }, {
                onError: () => {
                    Win.Loader.hide();
                }
            });
        }

    });
});
