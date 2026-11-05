/**
 * QUIQQER Authentication Settings via email code
 */
define('controls/users/auth/settings/VerifiedMail2FA', [

    'qui/QUI',
    'qui/controls/Control',
    'Locale',
    'Ajax'

], function (QUI, QUIControl, QUILocale, QUIAjax) {
    'use strict';

    return new Class({

        Extends: QUIControl,
        Type: 'controls/users/auth/settings/VerifiedMail2FA',

        Binds: [
            '$onImport',
            '$sendCode'
        ],

        /**
         * construct
         * @param {Object} options
         */
        initialize: function (options) {
            this.parent(options);

            this.Loader = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * event : on import
         */
        $onImport: function () {
            const content = this.getElm();
            const codeForm = content.querySelector('[data-name="quiqqer-mail2fa-auth-settings-code"]');
            const codeSendButton = content.querySelector('[data-name="send-code"]');
            const verifyButton = content.querySelector('[data-name="verify-mail"]');
            const enableButton = content.querySelector('[data-name="enable"]');

            // mail code send
            if (codeSendButton) {
                codeSendButton.addEventListener('click', this.$sendCode);
            }

            if (enableButton) {
                enableButton.addEventListener('click', () => {
                    enableButton.disabled = true;

                    this.$enable().then((enabled) => {
                        if (enabled === true) {
                            this.fireEvent('completed');
                        } else {
                            this.$showError(QUILocale.get('quiqqer/core', 'exception.login.fail'));
                        }
                    }).catch((error) => {
                        this.$showError(error.getMessage?.() || QUILocale.get('quiqqer/core', 'exception.login.fail'), error);
                    }).finally(() => {
                        enableButton.disabled = false;
                    });
                });
            }

            if (codeForm) {
                const inputs = codeForm.querySelectorAll('input');
                this.$initInputEvents(inputs);

                codeForm.addEventListener('submit', (e) => {
                    e.stopPropagation();
                    e.preventDefault();

                    if (verifyButton) verifyButton.disabled = true;
                    if (codeSendButton) codeSendButton.disabled = true;

                    const code = Array.from(codeForm.querySelectorAll('input'))
                        .map((input) => input.value)
                        .join('');

                    this.$verifyMail(code).then((verified) => {
                        if (verified !== true) {
                            this.$showError(QUILocale.get('quiqqer/core', 'exception.login.fail'));
                            return;
                        }

                        content.querySelector('[data-name="mail-verification"]').hidden = true;
                        content.querySelector('[data-name="mail-activation"]').hidden = false;
                        content.querySelector('[data-name="message"]').textContent = '';
                        enableButton?.focus();
                    }).catch((error) => {
                        this.$showError(error.getMessage?.() || QUILocale.get('quiqqer/core', 'exception.login.fail'), error);
                    }).finally(() => {
                        if (verifyButton) verifyButton.disabled = false;
                        if (codeSendButton) codeSendButton.disabled = false;
                    });
                });
            }
        },

        $sendCode: function (e) {
            const button = this.getElm().querySelector('[data-name="send-code"]');
            const icon = button.querySelector('[data-name="send-code-icon"]');
            let envelopeIcon = 'fa-envelope-o';

            if (icon.classList.contains('fa-envelope')) {
                envelopeIcon = 'fa-envelope';
            }

            icon.classList.add('fa-circle-o-notch', 'fa-spin');
            icon.classList.remove(envelopeIcon);
            button.disabled = true;

            QUIAjax.post('ajax_users_authenticator_mail2fa_sendEnableMail', () => {
                icon.classList.remove('fa-circle-o-notch', 'fa-spin');
                icon.classList.add(envelopeIcon);
                button.disabled = false;
            }, {
                showLogin: false,
                onError: (error) => {
                    icon.classList.remove('fa-circle-o-notch', 'fa-spin');
                    icon.classList.add(envelopeIcon);
                    button.disabled = false;
                    this.$showError(error.getMessage?.() || QUILocale.get('quiqqer/core', 'exception.login.fail'), error);
                }
            });
        },

        $verifyMail: function (code) {
            return new Promise((resolve, reject) => {
                QUIAjax.post('ajax_users_authenticator_mail2fa_verifyMail', resolve, {
                    showLogin: false,
                    code: code,
                    onError: reject
                });
            });
        },

        $enable: function () {
            return new Promise((resolve, reject) => {
                QUIAjax.post('ajax_users_authenticator_mail2fa_enableByUser', resolve, {
                    showLogin: false,
                    onError: reject
                });
            });
        },

        $showError: function (message, error = null) {
            const messageNode = this.getElm().querySelector('[data-name="message"]');
            messageNode.textContent = message;

            if (error?.getCode?.() !== 401) {
                return;
            }

            const retry = document.createElement('button');
            retry.type = 'button';
            retry.dataset.name = 'restart-login';
            retry.className = 'btn btn-secondary';
            retry.textContent = QUILocale.get('quiqqer/core', 'login.title');
            retry.addEventListener('click', () => {
                retry.disabled = true;
                QUIAjax.post('ajax_session_destroy', () => window.location.reload(), {
                    onError: () => {
                        retry.disabled = false;
                    }
                });
            });
            messageNode.appendChild(retry);
        },

        $initInputEvents: function (inputs) {
            // Focus handling for code inputs
            inputs.forEach((input, idx) => {
                input.addEventListener('input', (e) => {
                    const val = input.value;
                    // If user pasted more than one character, handle paste
                    if (val.length > 1) {
                        // Paste scenario: distribute chars
                        for (let i = 0; i < inputs.length; i++) {
                            inputs[i].value = val[i] || '';
                        }
                        if ([...inputs].every(inp => inp.value.length === 1)) {
                            input.form && input.form.querySelector('[type="submit"]').click();
                        } else {
                            // Focus next empty
                            for (let i = 0; i < inputs.length; i++) {
                                if (!inputs[i].value) {
                                    inputs[i].focus();
                                    break;
                                }
                            }
                        }
                        return;
                    }
                    // Normal input: jump to next if filled
                    if (val.length === 1 && idx < inputs.length - 1) {
                        inputs[idx + 1].focus();
                    }
                    // Submit if all filled
                    if ([...inputs].every(inp => inp.value.length === 1)) {
                        input.form && input.form.querySelector('[type="submit"]').click();
                    }
                });

                input.addEventListener('keydown', (e) => {
                    // Backspace or left arrow: go to previous if empty
                    if ((e.key === 'Backspace' || e.key === 'ArrowLeft') && !input.value && idx > 0) {
                        inputs[idx - 1].focus();
                        e.preventDefault();
                    }
                    // Ctrl+Backspace: clear current and go to previous
                    if ((e.key === 'Backspace' && e.ctrlKey) && idx > 0) {
                        input.value = '';
                        inputs[idx - 1].focus();
                        e.preventDefault();
                    }
                    // Right arrow: go to next
                    if (e.key === 'ArrowRight' && idx < inputs.length - 1) {
                        inputs[idx + 1].focus();
                        e.preventDefault();
                    }
                    // Ctrl+V (paste) is handled by input event
                });

                // Paste event for mouse paste
                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const paste = (e.clipboardData || window.clipboardData).getData('text');
                    for (let i = 0; i < inputs.length; i++) {
                        inputs[i].value = paste[i] || '';
                    }
                    if ([...inputs].every(inp => inp.value.length === 1)) {
                        input.form && input.form.querySelector('[type="submit"]').click();
                    } else {
                        // Focus next empty
                        for (let i = 0; i < inputs.length; i++) {
                            if (!inputs[i].value) {
                                inputs[i].focus();
                                break;
                            }
                        }
                    }
                });
            });
        }
    });
});
