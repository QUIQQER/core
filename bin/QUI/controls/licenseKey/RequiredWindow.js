/**
 * Explain package license requirements reported by the server.
 */
define('controls/licenseKey/RequiredWindow', [
    'qui/controls/windows/SimpleWindow',
    'Locale',
    'css!controls/licenseKey/RequiredWindow.css'
], function (SimpleWindow, Locale) {
    'use strict';

    const windows = new Map();
    const lg = 'quiqqer/core';
    let nextId = 0;

    const element = function (tag, name, text) {
        const Element = document.createElement(tag);
        Element.dataset.name = name;

        if (typeof text === 'string') {
            Element.textContent = text;
        }

        return Element;
    };

    const getStoreUrl = function (value) {
        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }

        try {
            const StoreUrl = new URL(value);

            if (
                ['https:', 'http:'].includes(StoreUrl.protocol)
                && StoreUrl.username === ''
                && StoreUrl.password === ''
            ) {
                return StoreUrl.href;
            }
        } catch (error) {
            // A missing or invalid store URL leaves the license notice usable.
        }

        return null;
    };

    return function (Exception) {
        const packageName = String(Exception.getAttribute('package') || '');

        if (windows.has(packageName)) {
            const ExistingWindow = windows.get(packageName);
            ExistingWindow.getElm().focus();
            return ExistingWindow;
        }

        const ReturnFocus = document.activeElement;
        const title = Locale.get(lg, 'package.licenseRequired.title');
        const Content = element('section', 'license-required');
        Content.className = 'quiqqer-license-required';

        const Body = element('div', 'body');
        Body.className = 'quiqqer-license-required-body';
        const Symbol = element('div', 'symbol');
        Symbol.className = 'quiqqer-license-required-symbol';
        Symbol.setAttribute('aria-hidden', 'true');
        const Icon = element('span', 'icon');
        Icon.className = 'fa fa-key';
        Symbol.appendChild(Icon);
        const Heading = element('h2', 'heading', title);
        const Package = element('p', 'package', packageName);
        Package.className = 'quiqqer-license-required-package';
        const Message = element('p', 'message');
        Message.className = 'quiqqer-license-required-message';
        Message.id = 'quiqqer-license-required-message-' + (++nextId);

        // Existing module messages may contain HTML. Extract text in an inert
        // document and never insert server-provided markup into the live DOM.
        const ParsedMessage = new DOMParser().parseFromString(String(Exception.getMessage() || ''), 'text/html');
        Message.textContent = ParsedMessage.body.textContent.trim();

        const Actions = element('footer', 'actions');
        Actions.className = 'quiqqer-license-required-actions';
        const Close = element('button', 'close', Locale.get(lg, 'package.licenseRequired.later'));
        Close.type = 'button';
        Close.className = 'btn btn-link-body';
        Actions.appendChild(Close);
        const storeUrl = getStoreUrl(Exception.getAttribute('url'));
        let StoreLink = null;

        if (storeUrl !== null) {
            StoreLink = element('a', 'store', Locale.get(lg, 'package.licenseRequired.store'));
            StoreLink.className = 'btn btn-primary';
            StoreLink.href = storeUrl;
            StoreLink.target = '_blank';
            StoreLink.rel = 'noopener noreferrer';
            const Arrow = element('span', 'store-icon');
            Arrow.className = 'fa fa-arrow-right';
            Arrow.setAttribute('aria-hidden', 'true');
            StoreLink.appendChild(Arrow);
            Actions.appendChild(StoreLink);
        }

        Body.append(Symbol, Heading, Message, Package);
        Content.append(Body, Actions);

        const Window = new SimpleWindow({
            maxWidth: 560,
            maxHeight: 440,
            'class': 'quiqqer-license-required-dialog',
            resizable: false,
            events: {
                onOpen: function () {
                    Window.getContent().appendChild(Content);
                    const Dialog = Window.getElm();
                    Dialog.setAttribute('role', 'dialog');
                    Dialog.setAttribute('aria-modal', 'true');
                    Dialog.setAttribute('aria-label', title);
                    Dialog.setAttribute('aria-describedby', Message.id);
                    (StoreLink || Close).focus();
                },
                onClose: function () {
                    windows.delete(packageName);

                    if (ReturnFocus?.isConnected) {
                        ReturnFocus.focus();
                    }
                }
            }
        });

        Close.addEventListener('click', function () {
            Window.close();
        });

        Window.getElm().addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                Window.close();
                return;
            }

            if (event.key === 'Tab') {
                const Last = StoreLink || Close;

                if (document.activeElement === Window.getElm()) {
                    event.preventDefault();
                    (event.shiftKey ? Last : Close).focus();
                } else if (event.shiftKey && document.activeElement === Close) {
                    event.preventDefault();
                    Last.focus();
                } else if (!event.shiftKey && document.activeElement === Last) {
                    event.preventDefault();
                    Close.focus();
                }
            }
        });

        windows.set(packageName, Window);
        Window.open();
        return Window;
    };
});
