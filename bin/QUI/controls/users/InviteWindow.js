/**
 * Invite user dialog
 *
 * @event onInviteSubmit [this, email, groups]
 */
define('controls/users/InviteWindow', [

    'qui/QUI',
    'qui/controls/windows/Confirm',
    'controls/groups/Select',
    'Users',
    'Locale',

    'css!controls/users/InviteWindow.css'

], (QUI, QUIConfirm, GroupSelect, Users, QUILocale) => {
    'use strict';

    const lg = 'quiqqer/core';

    return new Class({
        Extends: QUIConfirm,
        Type: 'controls/users/InviteWindow',

        initialize: function (options) {
            this.parent(Object.merge({
                name: 'InviteUser',
                title: QUILocale.get(lg, 'users.panel.invite.window.title'),
                icon: 'fa fa-envelope',
                titleicon: false,
                autoclose: false,
                maxWidth: 760,
                maxHeight: 620,
                ok_button: {
                    textimage: 'fa fa-envelope',
                    text: QUILocale.get(lg, 'users.panel.invite.window.submit')
                },
                cancel_button: {
                    textimage: 'fa fa-close',
                    text: QUILocale.get(lg, 'cancel')
                }
            }, options));

            this.$EmailInput = null;
            this.$GroupSelect = null;

            this.addEvents({
                onOpen: this.$onOpen.bind(this),
                onSubmit: this.$onSubmit.bind(this)
            });
        },

        $onOpen: function () {
            const Content = this.getContent();
            const WindowElm = this.getElm();
            const ButtonBar = WindowElm.querySelector('.qui-window-popup-buttons');

            WindowElm.classList.add('quiqqer-users-invite-window-frame');
            Content.classList.add('quiqqer-users-create-window', 'quiqqer-users-invite-window');
            Content.innerHTML = '' +
                '<div class="quiqqer-users-create-window-title">' +
                    QUILocale.get(lg, 'users.panel.invite.window.headline') +
                '</div>' +
                '<div class="quiqqer-users-create-window-information">' +
                    QUILocale.get(lg, 'users.panel.invite.window.information') +
                '</div>' +
                '<div class="quiqqer-users-invite-window-section">' +
                    '<div class="quiqqer-users-invite-window-step">1</div>' +
                    '<div class="quiqqer-users-invite-window-section-body">' +
                        '<div class="quiqqer-users-invite-window-section-title">' +
                            QUILocale.get(lg, 'users.panel.invite.window.email.title') +
                        '</div>' +
                        '<div class="quiqqer-users-invite-window-section-description">' +
                            QUILocale.get(lg, 'users.panel.invite.window.email.description') +
                        '</div>' +
                        '<div class="quiqqer-users-create-window-field">' +
                            '<input type="email" name="email" required />' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="quiqqer-users-invite-window-section">' +
                    '<div class="quiqqer-users-invite-window-step">2</div>' +
                    '<div class="quiqqer-users-invite-window-section-body">' +
                        '<div class="quiqqer-users-invite-window-section-title">' +
                            QUILocale.get(lg, 'users.panel.invite.window.groups.title') +
                        '</div>' +
                        '<div class="quiqqer-users-invite-window-section-description">' +
                            QUILocale.get(lg, 'users.panel.invite.window.groups.description') +
                        '</div>' +
                        '<div class="quiqqer-users-create-window-groups-select"></div>' +
                    '</div>' +
                '</div>';

            this.$EmailInput = Content.querySelector('input[name="email"]');
            this.$GroupSelect = new GroupSelect({
                multiple: true,
                styles: {
                    height: '126px',
                    minHeight: '126px',
                    width: '100%'
                }
            }).inject(Content.querySelector('.quiqqer-users-create-window-groups-select'));

            if (this.$EmailInput) {
                this.$EmailInput.placeholder = QUILocale.get(lg, 'email');
                this.$EmailInput.setAttribute('aria-label', QUILocale.get(lg, 'email'));
                window.setTimeout(() => {
                    this.$EmailInput.focus();
                }, 200);
            }

            if (ButtonBar) {
                const SubmitButton = ButtonBar.querySelector('[name="submit"]');
                const CancelButton = ButtonBar.querySelector('[name="cancel"]');

                ButtonBar.innerHTML = '';

                if (CancelButton) {
                    ButtonBar.appendChild(CancelButton);
                }

                if (SubmitButton) {
                    ButtonBar.appendChild(SubmitButton);
                }

                ButtonBar.querySelectorAll('button').forEach((Button) => {
                    Button.style.alignItems = 'center';
                    Button.style.display = 'inline-flex';
                    Button.style.float = 'none';
                    Button.style.justifyContent = 'center';
                    Button.style.width = 'auto';
                });
            }
        },

        $onSubmit: function (Win) {
            const email = this.$EmailInput ? this.$EmailInput.value.trim() : '';
            const groupValue = this.$GroupSelect ? this.$GroupSelect.getValue() : '';
            const groups = groupValue ? String(groupValue).split(',').filter(Boolean) : [];

            if (!email) {
                if (this.$EmailInput) {
                    this.$EmailInput.focus();
                    this.$EmailInput.reportValidity();
                }

                return;
            }

            Win.Loader.show();

            Users.existsUsername(email, (exists) => {
                if (exists === true) {
                    QUI.getMessageHandler((MH) => {
                        MH.addAttention(
                            QUILocale.get(lg, 'exception.create.user.exists')
                        );
                    });

                    Win.Loader.hide();
                    return;
                }

                Win.fireEvent('inviteSubmit', [Win, email, groups]);
            }, {
                onError: () => {
                    Win.Loader.hide();
                }
            });
        }
    });
});
