/**
 * Display the lifecycle of a save operation without changing the button label.
 */
define('utils/SaveButtonFeedback', [
    'Locale',
    'css!utils/SaveButtonFeedback.css'
], function (QUILocale) {
    'use strict';

    const lg = 'quiqqer/core';
    const saves = new WeakMap();

    return function (Button, operation, options = {}) {
        if (!Button) {
            return Promise.resolve().then(operation);
        }

        let state = saves.get(Button);

        if (!state) {
            const element = Button.getElm();
            const announcement = document.createElement('span');
            announcement.className = 'quiqqer-save-feedback-announcement';
            announcement.setAttribute('role', 'status');
            announcement.setAttribute('aria-live', 'polite');
            element.appendChild(announcement);
            element.classList.add('quiqqer-save-feedback-button');
            state = {
                element: element,
                announcement: announcement,
                icon: Button.getAttribute('textimage') || 'fa fa-save',
                title: Button.getAttribute('title') || Button.getAttribute('text'),
                label: element.getAttribute('aria-label'),
                pending: null,
                timer: null,
                destroyed: false
            };
            saves.set(Button, state);
            Button.addEvent('destroy', () => {
                state.destroyed = true;
                clearTimeout(state.timer);
            });
        }

        if (state.pending) {
            return state.pending;
        }

        clearTimeout(state.timer);
        const wasDisabled = Button.isDisabled();
        const show = (status, icon) => {
            if (state.destroyed) {
                return;
            }

            const message = QUILocale.get(lg, 'panel.save.' + status);
            Button.setAttribute('textimage', icon);
            state.element.firstElementChild.setAttribute('aria-hidden', 'true');
            state.element.setAttribute('data-save-state', status);
            state.element.setAttribute('title', message);
            state.element.setAttribute('aria-label', message);
            state.announcement.textContent = message;
    };

    Button.disable();
    state.element.setAttribute('aria-busy', 'true');
    show('pending', 'fa fa-circle-o-notch fa-spin');

    state.pending = Promise.resolve().then(operation).then(result => {
        show('success', 'fa fa-check');
        return result;
    }, error => {
        show('error', 'fa fa-bolt');
        throw error;
    }).finally(() => {
        state.pending = null;

        if (state.destroyed) {
            return;
        }

        state.element.setAttribute('aria-busy', 'false');

        if (!wasDisabled && (!options.canEnable || options.canEnable())) {
            Button.enable();
        }

        state.timer = setTimeout(() => {
            if (state.destroyed) {
                return;
            }

            Button.setAttribute('textimage', state.icon);
            state.element.setAttribute('title', state.title);
            state.element.removeAttribute('data-save-state');
            state.element.removeAttribute('aria-busy');
            state.announcement.textContent = '';

            if (state.label === null) {
                state.element.removeAttribute('aria-label');
            } else {
                state.element.setAttribute('aria-label', state.label);
            }
        }, 2500);
    });

    return state.pending;
    };
});
