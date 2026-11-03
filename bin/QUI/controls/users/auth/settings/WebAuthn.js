define('controls/users/auth/settings/WebAuthn', [

    'qui/QUI',
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'controls/users/auth/WebAuthnUtils'

], function (QUI, QUIControl, QUIAjax, QUILocale, WebAuthnUtils) {
    'use strict';

    const lg = 'quiqqer/core';

    return new Class({
        Extends: QUIControl,
        Type: 'controls/users/auth/settings/WebAuthn',

        Binds: [
            '$onImport',
            'showActivationNoticeIfReady',
            '$createPasskey',
            '$deletePasskey',
            '$activateExistingPasskeys',
            '$refresh',
            '$refreshPanel',
            '$getUserUuid',
            '$showActivationHint',
            '$showDeleteHint',
            '$setLoading',
            '$getWindow'
        ],

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onImport: this.$onImport
            });
        },

        $onImport: function () {
            const button = this.getElm().querySelector('[data-name="create-passkey"]');
            const message = this.getElm().querySelector('[data-name="message"]');

            if (!WebAuthnUtils.isSupported()) {
                if (button) {
                    button.disabled = true;
                }

                if (message) {
                    message.innerHTML = QUILocale.get(lg, 'quiqqer.webauthn.error.unsupported');
                }

                return;
            }

            if (button) {
                button.addEventListener('click', this.$createPasskey);
            }

            Array.from(this.getElm().querySelectorAll('[name="delete-passkey"]')).forEach((deleteButton) => {
                deleteButton.addEventListener('click', this.$deletePasskey);
            });
        },

        showActivationNoticeIfReady: function () {
            const settings = this.getElm().querySelector('[data-name="quiqqer-webauthn-settings"]');

            if (!settings) {
                return false;
            }

            if (settings.getAttribute('data-activated-existing-credentials') !== '1') {
                return false;
            }

            this.$showActivationHint();
            return true;
        },

        $createPasskey: function (event) {
            event.preventDefault();
            event.stopPropagation();

            const userUuid = this.$getUserUuid();
            const button = this.getElm().querySelector('[data-name="create-passkey"]');
            const name = this.getElm().querySelector('[data-name="credential-name"]');
            const message = this.getElm().querySelector('[data-name="message"]');
            const credentialName = name ? name.value : '';

            if (message) {
                message.innerHTML = '';
            }

            this.$setLoading(true);
            button.disabled = true;

            new Promise((resolve, reject) => {
                QUIAjax.get('ajax_users_authenticator_webauthn_beginRegistration', resolve, {
                    userUuid: userUuid,
                    name: credentialName,
                    onError: reject
                });
            }).then((options) => {
                return navigator.credentials.create(
                    WebAuthnUtils.prepareCreateOptions(options)
                );
            }).then((credential) => {
                return new Promise((resolve, reject) => {
                    QUIAjax.post('ajax_users_authenticator_webauthn_finishRegistration', resolve, {
                        userUuid: userUuid,
                        name: credentialName,
                        attestation: JSON.encode(WebAuthnUtils.serializeAttestation(credential)),
                        onError: reject
                    });
                });
            }).then(() => {
                button.disabled = false;
                return this.$refresh();
            }).then(() => {
                this.$refreshPanel();
                this.fireEvent('completed');
            }).catch((err) => {
                button.disabled = false;
                this.$setLoading(false);

                if (message) {
                    message.textContent = typeof err.getMessage === 'function'
                        ? err.getMessage()
                        : QUILocale.get(lg, WebAuthnUtils.getErrorLocaleKey(err));

                    if (err.getCode?.() === 401) {
                        const retry = document.createElement('button');
                        retry.type = 'button';
                        retry.dataset.name = 'restart-login';
                        retry.className = 'btn btn-secondary';
                        retry.textContent = QUILocale.get(lg, 'login.title');
                        retry.addEventListener('click', () => {
                            retry.disabled = true;
                            QUIAjax.post('ajax_session_destroy', () => window.location.reload(), {
                                onError: () => {
                                    retry.disabled = false;
                                }
                            });
                        });
                        message.appendChild(retry);
                    }
                }

                if (window.console) {
                    console.error(err);
                }
            });
        },

        $deletePasskey: function (event) {
            event.preventDefault();
            event.stopPropagation();

            const userUuid = this.$getUserUuid();
            const button = event.target.nodeName === 'BUTTON' ? event.target : event.target.closest('button');
            this.$setLoading(true);
            button.disabled = true;

            new Promise((resolve, reject) => {
                QUIAjax.post('ajax_users_authenticator_webauthn_deleteCredential', resolve, {
                    userUuid: userUuid,
                    id: button.getAttribute('data-id'),
                    onError: reject
                });
            }).then((result) => {
                if (result && result.hasCredentials === false) {
                    return this.$refreshPanel().then(() => {
                        const Win = this.$getWindow();

                        this.$showDeleteHint(true);

                        if (Win && typeof Win.close === 'function') {
                            Win.close();
                        }

                        return false;
                    });
                }

                return this.$refresh().then((Control) => {
                    Control.$showDeleteHint(false);
                    return true;
                });
            }).then((refreshPanel) => {
                if (!refreshPanel) {
                    return;
                }

                return this.$refreshPanel();
            }).catch((err) => {
                button.disabled = false;
                this.$setLoading(false);

                if (window.console) {
                    console.error(err);
                }
            });
        },

        $activateExistingPasskeys: function () {
            const authenticator = this.getAttribute('authenticator') || 'QUI\\Users\\Auth\\WebAuthn';

            this.$setLoading(true);

            new Promise((resolve, reject) => {
                QUIAjax.post('ajax_users_authenticator_enableByUser', resolve, {
                    authenticator: authenticator,
                    onError: reject
                });
            }).then(() => {
                return this.$refreshPanel();
            }).then(() => {
                this.$setLoading(false);
                this.fireEvent('completed');
            }).catch((err) => {
                this.$setLoading(false);

                if (window.console) {
                    console.error(err);
                }
            });
        },

        $refresh: function () {
            const container = this.getElm().parentNode;
            const User = this.getAttribute('User');
            const authenticator = this.getAttribute('authenticator') || 'QUI\\Users\\Auth\\WebAuthn';
            const userUuid = this.$getUserUuid();
            const Win = this.$getWindow();

            if (Win && Win.Loader) {
                Win.Loader.show();
            }

            return new Promise((resolve, reject) => {
                if (!userUuid) {
                    if (Win && Win.Loader) {
                        Win.Loader.hide();
                    }

                    reject();
                    return;
                }

                QUIAjax.get('ajax_users_authenticator_webauthn_settings', (html) => {
                    container.innerHTML = html;
                    QUI.parse(container).then(() => {
                        let refreshedControl = null;

                        Array.from(QUI.Controls.getControlsInElement(container)).forEach((Control) => {
                            Control.setAttribute('uid', userUuid);
                            Control.setAttribute('authenticator', authenticator);
                            Control.setAttribute('User', User);
                            Control.setAttribute('Panel', this.getAttribute('Panel'));

                            if (Control.getType && Control.getType() === this.getType()) {
                                refreshedControl = Control;
                            }
                        });

                        if (Win && Win.Loader) {
                            Win.Loader.hide();
                        }

                        resolve(refreshedControl || this);
                    }).catch((err) => {
                        if (Win && Win.Loader) {
                            Win.Loader.hide();
                        }

                        reject(err);
                    });
                }, {
                    userUuid: userUuid,
                    onError: (err) => {
                        if (Win && Win.Loader) {
                            Win.Loader.hide();
                        }

                        reject(err);
                    }
                });
            });
        },

        $refreshPanel: function () {
            const Panel = this.getAttribute('Panel');
            const authenticator = this.getAttribute('authenticator') || 'QUI\\Users\\Auth\\WebAuthn';

            if (Panel && typeof Panel.$refreshAuthenticator === 'function') {
                return Panel.$refreshAuthenticator(authenticator);
            }

            return Promise.resolve();
        },

        $getUserUuid: function () {
            const User = this.getAttribute('User');

            return User?.getAttribute?.('uuid')
                || this.getElm().querySelector('[data-name="quiqqer-webauthn-settings"]')?.dataset.userUuid
                || '';
        },

        $showActivationHint: function () {
            const message = this.getElm().querySelector('[data-name="message"]');

            if (!message) {
                return;
            }

            message.innerHTML = '<div class="messages-message message-success quiqqer-webauthn-settings-message-entry">'
                + QUILocale.get(lg, 'quiqqer.webauthn.settings.activated_existing.hint')
                + '</div>';

            const entry = message.querySelector('.quiqqer-webauthn-settings-message-entry');

            setTimeout(() => {
                entry.classList.add('is-visible');
            }, 20);

            setTimeout(() => {
                entry.classList.remove('is-visible');

                setTimeout(() => {
                    if (entry.parentNode) {
                        entry.parentNode.removeChild(entry);
                    }
                }, 250);
            }, 4000);
        },

        $showDeleteHint: function (globalMessage) {
            const hint = QUILocale.get(lg, 'quiqqer.webauthn.settings.delete.hint');

            if (globalMessage) {
                QUI.getMessageHandler().then((MessageHandler) => {
                    MessageHandler.addInformation(hint);
                });

                return;
            }

            const message = this.getElm().querySelector('[data-name="message"]');

            if (message) {
                message.innerHTML = '<div class="messages-message message-information">' + hint + '</div>';
            }
        },

        $setLoading: function (loading) {
            const Win = this.$getWindow();

            if (!Win || !Win.Loader) {
                return;
            }

            if (loading) {
                Win.Loader.show();
                return;
            }

            Win.Loader.hide();
        },

        $getWindow: function () {
            const popup = this.getElm().closest('.qui-window-popup');

            if (!popup) {
                return null;
            }

            const quiId = popup.getAttribute('data-quiid');

            if (!quiId) {
                return null;
            }

            return QUI.Controls.getById(quiId);
        }
    });
});
