define('controls/users/auth/EnableSecondaryAuthenticatorWindow', [

    'qui/QUI',
    'qui/controls/windows/Popup',
    'Ajax',
    'Locale',

    'css!controls/users/auth/EnableSecondaryAuthenticatorWindow.css'

], function (QUI, QUIPopup, QUIAjax, QUILocale) {
    "use strict";

    const lg = 'quiqqer/core';

    return new Class({

        Extends: QUIPopup,
        Type: 'controls/users/auth/EnableSecondaryAuthenticatorWindow',

        options: {
            authenticator: false, // enable a specific authenticator
            authenticators: false, // optional list of methods selected in the settings
            message: false // optional warning text
        },

        initialize: function (options) {
            this.parent(options);

            this.setAttributes({
                maxHeight: 800,
                maxWidth: 600,
                buttons: false,
                closeable: false,
                backgroundClosable: false,
                autoclose: false
            });

            if (this.getAttribute('authenticator')) {
                this.setAttribute('backgroundClosable', true);
                this.setAttribute('closable', true);
            }

            this.addEvents({
                onOpen: this.$onOpen
            });
        },

        $onOpen: function () {
            this.Loader.show();
            const container = this.getContent();

            container.classList.add('default-content');
            container.innerHTML = `
                <section data-name="authenticator-list">
                    <div style="text-align: center">
                        <span class="fa fa-shield-alt" style="margin: 2rem 0; font-size: 4rem;"></span>    
                        <h1 style="font-size: 2rem">
                            ${QUILocale.get(lg, 'quiqqer.window.enable.2fa.title')}
                        </h1>
                        ${QUILocale.get(lg, 'quiqqer.window.enable.2fa.description')}
                    </div>
                    <div data-name="enable-secondary-authenticators"></div>
                </section>
                <section data-name="authenticator-settings" style="opacity: 0; display: none;"></section>
            `;

            const message = this.getAttribute('message');

            if (typeof message === 'string' && message !== '') {
                const Warning = document.createElement('div');
                Warning.dataset.name = 'setup-message';
                Warning.className = 'quiqqer-message quiqqer-message-attention';
                Warning.setAttribute('role', 'alert');
                Warning.textContent = message;
                container.prepend(Warning);
            }

            if (this.getAttribute('authenticator')) {
                this.$showAuthenticatorSettings(this.getAttribute('authenticator')).then((closeWindow) => {
                    if (closeWindow === false) {
                        return;
                    }

                    this.Loader.hide();
                    this.close();
                    this.fireEvent('completed');
                });

                return;
            }

            const showAuthenticators = (list) => {
                list.forEach((authenticator) => {
                    const authNode = document.createElement('div')

                    authNode.classList.add('enable-secondary-authenticators-authenticator');
                    authNode.setAttribute('data-name', 'enable-secondary-authenticators-authenticator');

                    authNode.innerHTML = `
                        <h2>${authenticator.frontend.title}</h2>
                        <p>${authenticator.frontend.description}</p>
                        <button class="btn btn-primary">
                            ${QUILocale.get(lg, 'activate')}
                        </button>
                    `;

                    const button = authNode.querySelector('button');
                    button.setAttribute('data-authenticator', authenticator.authenticator)

                    if (authenticator.hasSettings) {
                        button.addEventListener('click', (e) => {
                            const button = e.target.nodeName === 'BUTTON' ? e.target : e.target.parentNode;

                            this.$showAuthenticatorSettings(
                                button.getAttribute('data-authenticator')
                            ).then((closeWindow) => {
                                if (closeWindow === false) {
                                    return;
                                }

                                this.close();
                                this.fireEvent('completed');
                            });
                        });
                    } else {
                        button.addEventListener('click', (e) => {
                            const button = e.target.nodeName === 'BUTTON' ? e.target : e.target.parentNode;

                            this.$enableAuthenticator(
                                button.getAttribute('data-authenticator')
                            ).then(() => {
                                this.close();
                                this.fireEvent('completed');
                            });
                        });
                    }

                    container
                        .querySelector('[data-name="enable-secondary-authenticators"]')
                        .appendChild(authNode);
                });

                this.Loader.hide();
            };

            const authenticators = this.getAttribute('authenticators');

            if (Array.isArray(authenticators)) {
                showAuthenticators(authenticators);
            } else {
                QUIAjax.get('ajax_users_authenticator_getSecondaryAuthenticators', showAuthenticators);
            }
        },

        $showAuthenticatorSettings: function (authenticator) {
            const container = this.getContent();
            const list = container.querySelector('[data-name="authenticator-list"]');
            const settings = container.querySelector('[data-name="authenticator-settings"]');

            settings.innerHTML = '';

            settings.style.opacity = 0;
            settings.style.position = 'absolute';
            settings.style.left = '-20px';
            settings.style.top = '0';
            settings.style.width = '100%';
            settings.style.height = '100%';
            settings.style.display = '';

            if (typeof container.scrollTo === 'function') {
                container.scrollTo(0, 0);
            } else if (typeof container.scrollTop !== 'undefined') {
                container.scrollTop = 0;
            }

            // back button
            let backButton = null;

            if (!this.getAttribute('authenticator')) {
                backButton = document.createElement('button');
                backButton.type = 'button';
                backButton.style.marginBottom = '1rem';
                backButton.innerHTML = `
                    <span class="fa fa-arrow-left"></span>
                    <span>${QUILocale.get(lg, 'quiqqer.window.enable.2fa.backButton')}</span>
                `;

                backButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    moofx(list).animate({
                        opacity: 1,
                        left: 0
                    }, {
                        duration: 250
                    });

                    moofx(settings).animate({
                        left: -20,
                        opacity: 0
                    }, {
                        duration: 250,
                        callback: () => {
                            settings.innerHTML = '';
                            settings.style.display = 'none';
                        }
                    });
                });
            }


            this.Loader.show();

            return new Promise((resolve) => {
                QUIAjax.get('ajax_users_authenticator_secondarySettings', (settingHtml) => {
                    settings.innerHTML = settingHtml;

                    // insert back button
                    if (backButton) {
                        settings.insertBefore(backButton, settings.firstChild);
                    }

                    QUI.parse(settings).then(() => {
                        const settingsNode = settings.querySelector('[data-qui]');

                        if (!settingsNode) {
                            return resolve();
                        }

                        const settingsInstance = QUI.Controls.getById(settingsNode.getAttribute('data-quiid'));

                        if (!settingsInstance) {
                            return resolve();
                        }

                        settingsInstance.addEvents({
                            completed: () => {
                                moofx(settings).animate({
                                    left: -10,
                                    opacity: 0
                                }, {
                                    duration: 250,
                                    callback: () => {
                                        settings.style.display = 'none';
                                        settings.parentNode.removeChild(settings);
                                        resolve();
                                    }
                                });
                            }
                        });

                        if (
                            typeof settingsInstance.showActivationNoticeIfReady === 'function'
                            && settingsInstance.showActivationNoticeIfReady()
                        ) {
                            this.Loader.hide();
                            this.fireEvent('completed');
                        }

                        moofx(list).animate({
                            opacity: 0,
                            left: -20
                        }, {
                            duration: 250
                        });

                        moofx(settings).animate({
                            left: 0,
                            opacity: 1
                        }, {
                            duration: 250,
                            callback: () => {
                                this.Loader.hide();
                            }
                        });
                    });
                }, {
                    authenticator: authenticator,
                    onError: (err) => {
                        console.error(err);
                    }
                });
            });
        },

        $enableAuthenticator: function (authenticator) {
            return new Promise((resolve, reject) => {
                QUIAjax.get('ajax_users_authenticator_enableTwoFactorBySession', resolve, {
                    authenticator: authenticator,
                    onError: reject
                });
            });
        }
    });
});
