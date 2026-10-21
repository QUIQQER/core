/**
 * Login popup shared by backend reauthentication and frontend integrations.
 *
 * @event onSuccess [self]
 */
define('controls/users/LoginWindow', [
    'qui/QUI',
    'qui/controls/windows/SimpleWindow',
    'Locale',
    'controls/users/Login',

    'css!controls/users/LoginWindow.css'
], function (QUI, SimpleWindow, Locale, Login) {
    'use strict';

    const lg = 'quiqqer/core';

    const element = function (tag, name, text) {
        const Element = document.createElement(tag);
        Element.dataset.name = name;

        if (typeof text === 'string') {
            Element.textContent = text;
        }

        return Element;
    };

    return new Class({
        Extends: SimpleWindow,
        Type: 'controls/users/LoginWindow',

        options: {
            title: Locale.get(lg, 'login.title'),
            maxHeight: false,
            maxWidth: 480,
            resizable: false,
            backgroundClosable: false,
            description: Locale.get(lg, 'loginBox.header.text'),
            logo: false,
            message: false
        },

        initialize: function (options) {
            this.parent(options);
            this.$opened = false;
            this.$Login = null;
            this.$ReturnFocus = null;

            this.addEvents({
                onOpen: () => {
                    this.$render();
                    this.$focusForm();
                },
                onCancel: () => {
                    window.onbeforeunload = null;

                    if (window.QUIQQER?.inAdministration) {
                        window.location = window.URL_SYS_DIR;
                    }
                },
                onClose: () => {
                    if (this.$ReturnFocus?.isConnected) {
                        this.$ReturnFocus.focus();
                    }
                }
            });
        },

        open: function () {
            const logins = QUI.Controls.getByType(this.Type);

            if (logins.some((LoginWindow) => LoginWindow !== this && LoginWindow.$opened)) {
                this.destroy();
                return;
            }

            if (this.$opened) {
                return;
            }

            this.$opened = true;
            this.$ReturnFocus = document.activeElement;
            const Backdrop = this.Background.getElm();
            Backdrop.classList.add('quiqqer-loginWindow-backdrop');
            Backdrop.style.removeProperty('background-color');
            this.Background.setAttribute('opacity', 1);
            return this.parent();
        },

        $render: function () {
            const Dialog = this.getElm();
            const Content = this.getContent();
            Content.classList.add('quiqqer-loginWindow-content');

            Dialog.classList.add('quiqqer-loginWindow');
            Dialog.setAttribute('role', 'dialog');
            Dialog.setAttribute('aria-modal', 'true');

            const Close = element('button', 'login-close');
            Close.type = 'button';
            Close.className = 'btn btn-close quiqqer-loginWindow-close';
            Close.setAttribute('aria-label', Locale.get(lg, 'close'));
            const CloseIcon = element('span', 'close-icon');
            CloseIcon.className = 'fa fa-close';
            CloseIcon.setAttribute('aria-hidden', 'true');
            Close.appendChild(CloseIcon);
            Close.addEventListener('click', () => this.cancel());
            Content.appendChild(Close);

            const Header = element('header', 'login-header');
            Header.className = 'quiqqer-loginWindow-header';
            const branding = window.QUIQQER?.loginBranding || {
                logo: window.QUIQQER_PROJECT?.logo,
                alt: window.QUIQQER_PROJECT?.name
            };
            const fallbackLogo = (window.URL_BIN_DIR || '') + 'quiqqer_logo.svg';
            let logoUrl = this.getAttribute('logo') || branding.logo || fallbackLogo;

            try {
                const LogoUrl = new URL(logoUrl, document.baseURI);

                if (!['https:', 'http:'].includes(LogoUrl.protocol)) {
                    logoUrl = fallbackLogo;
                }
            } catch (error) {
                logoUrl = fallbackLogo;
            }

            const Logo = element('img', 'login-logo');
            Logo.className = 'quiqqer-loginWindow-logo';
            Logo.src = logoUrl;
            Logo.alt = this.getAttribute('logo') ? '' : (branding.alt || 'QUIQQER');
            Logo.addEventListener('error', () => {
                if (Logo.getAttribute('src') !== fallbackLogo) {
                    Logo.src = fallbackLogo;
                    Logo.alt = 'QUIQQER';
                }
            }, {once: true});
            const Heading = element('h2', 'login-title', this.getAttribute('title'));
            Heading.id = 'quiqqer-login-window-title-' + this.getId();
            Dialog.setAttribute('aria-labelledby', Heading.id);
            const Intro = element('p', 'login-intro', this.getAttribute('description'));
            Intro.className = 'quiqqer-loginWindow-intro';
            Intro.hidden = !this.getAttribute('description');
            Header.append(Logo, Heading, Intro);
            Content.appendChild(Header);

            if (this.getAttribute('message')) {
                const Message = element('p', 'login-message');
                Message.className = 'quiqqer-loginWindow-message';
                Message.textContent = new DOMParser().parseFromString(
                    String(this.getAttribute('message')),
                    'text/html'
                ).body.textContent;
                Content.appendChild(Message);
            }

            const Body = element('div', 'login-body');
            Body.className = 'quiqqer-loginWindow-body';
            Content.appendChild(Body);

            this.$Login = new Login({
                showLoader: false,
                onSuccess: () => {
                    this.close();
                    this.fireEvent('success', [this]);
                },
                events: {
                    onLoad: () => {
                        this.resize();
                        this.$focusForm();
                    },
                    onLoginCheckSuccess: () => {
                        Heading.textContent = this.getAttribute('title');
                        Intro.hidden = !this.getAttribute('description');
                        this.resize();
                    },
                    onLoginCheckError: () => {
                        Heading.textContent = Locale.get(lg, 'login.check.title');
                        Intro.hidden = true;
                        this.resize();
                        requestAnimationFrame(() => this.$focusForm());
                    },
                    onAuthBegin: () => this.Loader.show(),
                    onAuthNext: () => this.Loader.hide()
                }
            }).inject(Body);

            Dialog.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    this.cancel();
                    return;
                }

                if (event.key !== 'Tab') {
                    return;
                }

                const elements = this.$focusableElements();
                const First = elements[0];
                const Last = elements[elements.length - 1];

                if (!First) {
                    event.preventDefault();
                    Dialog.focus();
                } else if (event.shiftKey && (document.activeElement === First || document.activeElement === Dialog)) {
                    event.preventDefault();
                    Last.focus();
                } else if (!event.shiftKey && document.activeElement === Last) {
                    event.preventDefault();
                    First.focus();
                }
            });
        },

        getOpeningHeight: function () {
            if (
                this.getAttribute('mobileMode') === 'fullScreen'
                && window.matchMedia('(max-width: 767px)').matches
            ) {
                return QUI.getWindowSize().y;
            }

            const availableHeight = Math.max(QUI.getWindowSize().y - 24, 0);
            const configuredHeight = Number(this.getAttribute('maxHeight'));
            const maximumHeight = configuredHeight > 0
                ? Math.min(availableHeight, configuredHeight)
                : availableHeight;
            const Content = this.getContent();

            if (!Content?.children.length) {
                return Math.min(maximumHeight, 320);
            }

            let height = 0;

            for (const Child of Content.children) {
                const style = getComputedStyle(Child);

                if (['absolute', 'fixed'].includes(style.position)) {
                    continue;
                }

                if (Child.dataset.name === 'login-body' && Child.firstElementChild) {
                    height += Child.firstElementChild.scrollHeight
                        + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
                } else {
                    height += Child.offsetHeight;
                }

                height += parseFloat(style.marginTop) + parseFloat(style.marginBottom);
            }

            return Math.min(maximumHeight, Math.ceil(height));
        },

        $focusableElements: function () {
            const elements = [];
            const Walker = document.createTreeWalker(this.getContent(), NodeFilter.SHOW_ELEMENT);
            let Element = Walker.nextNode();

            while (Element) {
                if (
                    Element.tabIndex >= 0
                    && !Element.disabled
                    && !Element.closest('[inert]')
                    && Element.getClientRects().length
                ) {
                    elements.push(Element);
                }

                Element = Walker.nextNode();
            }

            return elements;
        },

        $focusForm: function () {
            if (!this.$opened) {
                return;
            }

            const elements = this.$focusableElements();
            const First = elements.find((Element) => Element.dataset.name !== 'login-close');

            (First || this.getElm()).focus();
        },

        close: function () {
            this.$opened = false;

            if (this.$Login) {
                this.$Login.destroy();
                this.$Login = null;
            }

            return this.parent();
        },

        // Kept for integrations that previously called the confirmation-window API.
        submit: function () {}
    });
});
