/**
 * Present a mobile upload link in a QUI Simple Window.
 * Closing the window revokes access before removing the dialog.
 */
define('controls/upload/mobileUpload/Window', [
    'qui/controls/windows/SimpleWindow',
    'Ajax',
    'css!controls/upload/mobileUpload/Window.css'
], function (SimpleWindow, Ajax) {
    'use strict';

    const element = (tag, name, text) => {
        const Element = document.createElement(tag);
        Element.dataset.name = name;

        if (text !== undefined) {
            Element.textContent = text;
        }

        return Element;
    };

    const request = (session, action) => new Promise((resolve, reject) => {
        Ajax.post(session.api, resolve, {
            action,
            id: session.id,
            generation: session.generation,
            onError: (Exception) => {
                const error = new Error(session.labels.error);
                error.status = Number(Exception.getAttribute?.('code') || Exception.code);
                reject(error);
            }
        });
    });

    const openWindow = function (session, onUpload) {
        const labels = session.labels;
        const ReturnFocus = document.activeElement;
        let timer;
        let copyResetTimer;
        let disposed = false;
        let count = 0;
        let polling = false;
        let active = true;
        let closing = false;

        const Content = element('section', 'mobile-upload-window');
        Content.className = 'quiqqer-mobile-upload-window';

        const Heading = element('h2', 'heading', labels.title);
        const Intro = element('p', 'intro', labels.intro);
        const QR = element('img', 'qr');
        QR.src = session.qr;
        QR.alt = labels.qr;
        QR.width = 340;
        QR.height = 340;

        const Link = element('a', 'link', labels.open);
        Link.href = session.url;
        Link.target = '_blank';
        Link.rel = 'noopener noreferrer';
        Link.title = session.url;
        Link.setAttribute('aria-label', labels.open);

        const Status = element('p', 'status');
        Status.setAttribute('aria-live', 'polite');

        const Countdown = element('p', 'countdown');
        const KeepOpen = element('p', 'keep-open', labels.keepOpen);
        const Actions = element('div', 'actions');

        const button = (name, label, icon, action) => {
            const Button = element('button', name);
            Button.type = 'button';
            Button.className = 'btn btn-light';

            const Icon = document.createElement('span');
            Icon.dataset.name = 'icon';
            Icon.className = 'fa fa-' + icon;
            Icon.setAttribute('aria-hidden', 'true');

            const Text = document.createElement('span');
            Text.dataset.name = 'label';
            Text.textContent = label;
            Button.append(Icon, Text);

            Button.addEventListener('click', action);
            Actions.appendChild(Button);

            return Button;
        };

        const Copy = button('copy', labels.copy, 'copy', async () => {
            try {
                await navigator.clipboard.writeText(session.url);

                if (disposed) {
                    return;
                }

                const Label = Copy.querySelector('[data-name="label"]');
                const Icon = Copy.querySelector('[data-name="icon"]');

                window.clearTimeout(copyResetTimer);
                Label.textContent = labels.copied;
                Icon.className = 'fa fa-check';
                Copy.classList.add('is-copied');

                if (Status.textContent === labels.copyError) {
                    Status.textContent = '';
                }

                copyResetTimer = window.setTimeout(() => {
                    Label.textContent = labels.copy;
                    Icon.className = 'fa fa-copy';
                    Copy.classList.remove('is-copied');
                }, 2000);
            } catch (error) {
                Status.textContent = labels.copyError;

                const selection = window.getSelection();
                const range = document.createRange();
                range.selectNodeContents(Link);
                selection?.removeAllRanges();
                selection?.addRange(range);
                Link.focus();
            }
        });

        Copy.setAttribute('aria-live', 'polite');

        const deactivate = () => {
            active = false;
            window.clearInterval(timer);

            QR.hidden = true;
            Link.removeAttribute('href');
            Copy.disabled = true;
            Countdown.textContent = labels.inactive;
        };

        const tick = async () => {
            if (disposed || !active || closing) {
                return;
            }

            const remaining = Math.max(0, session.expiresAt - Math.floor(Date.now() / 1000));
            const minutes = Math.floor(remaining / 60);
            const seconds = String(remaining % 60).padStart(2, '0');
            Countdown.textContent = labels.expires + ' ' + minutes + ':' + seconds;

            if (!remaining) {
                deactivate();
            }

            if (polling || document.hidden) {
                return;
            }

            polling = true;

            try {
                const state = await request(session, 'status');

                if (disposed) {
                    return;
                }

                if (state.count > count) {
                    await onUpload?.();
                    count = state.count;
                    Status.textContent = labels.arrived + ': ' + count;
                }

                if (!state.active) {
                    deactivate();
                }
            } catch (error) {
                if (!disposed) {
                    Status.textContent = labels.error;

                    if ([403, 410].includes(error.status)) {
                        deactivate();
                    }
                }
            } finally {
                polling = false;
            }
        };

        const Window = new SimpleWindow({
            maxWidth: 540,
            maxHeight: 800,
            autoresize: true,
            resizable: false,
            'class': 'quiqqer-mobile-upload-dialog',
            backgroundClosable: false,
            events: {
                onOpen: () => {
                    Window.getContent().appendChild(Content);
                    Window.getElm().setAttribute('role', 'dialog');
                    Window.getElm().setAttribute('aria-modal', 'true');
                    Window.getElm().setAttribute('aria-label', labels.title);
                    Window.getElm().focus();

                    timer = window.setInterval(tick, 3000);
                    tick();
                },
                onClose: () => {
                    Window.stopMonitoring();

                    if (ReturnFocus?.isConnected) {
                        ReturnFocus.focus();
                    }
                }
            }
        });

        Window.stopMonitoring = () => {
            disposed = true;
            window.clearInterval(timer);
            window.clearTimeout(copyResetTimer);
        };

        const Close = button('close', labels.endUpload, 'ban', () => Window.close());

        const closeDialog = Window.close.bind(Window);

        Window.close = async () => {
            if (closing) {
                return;
            }

            closing = true;
            Close.disabled = true;

            try {
                if (active) {
                    const state = await request(session, 'revoke');
                    deactivate();

                    if (!disposed && state.count > count) {
                        await onUpload?.();
                        count = state.count;
                    }
                }

                await closeDialog();
            } catch (error) {
                Status.textContent = labels.closeError;
            } finally {
                closing = false;
                Close.disabled = false;
            }
        };

        Content.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                Window.close();
            }

            if (event.key === 'Tab') {
                const first = active ? Link : Close;

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    Close.focus();
                } else if (!event.shiftKey && document.activeElement === Close) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        Content.append(Heading, Intro, QR, Link, Countdown, KeepOpen, Status, Actions);
        Window.open();

        return Window;
    };

    openWindow.revoke = (session) => request(session, 'revoke');

    return openWindow;
});
