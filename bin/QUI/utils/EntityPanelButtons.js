/**
 * Shared action and status buttons for user and group detail panels.
 */
define('utils/EntityPanelButtons', [
    'Locale',
    'qui/controls/buttons/Button',
    'css!utils/EntityPanelButtons.css'
], function (QUILocale, QUIButton) {
    'use strict';

    const lg = 'quiqqer/core';

    return {
        createActions: function (Panel) {
            const Button = new QUIButton({
                name: 'extra',
                text: QUILocale.get(lg, 'panel.actions'),
                menuCorner: 'topRight',
                'class': 'quiqqer-entity-actions-button'
            });

            Button.addEvent('inject', () => {
                const element = Button.getElm();
                element.parentElement.classList.add('quiqqer-entity-button-toolbar');
                Panel.getButtonBar().setAttribute('width', '100%');
                element.type = 'button';
                element.tabIndex = 0;
                element.style.removeProperty('outline');
                element.setAttribute('aria-haspopup', 'menu');
                element.setAttribute('aria-expanded', 'false');
            });

            Button.getContextMenu((Menu) => {
                const element = Button.getElm();
                const menu = Menu.getElm();
                menu.setAttribute('role', 'menu');
                menu.setAttribute('aria-label', QUILocale.get(lg, 'panel.actions'));

                Menu.addEvent('show', () => {
                    element.setAttribute('aria-expanded', 'true');
                    Menu.getChildren().forEach((Item) => {
                        Item.getElm().setAttribute('role', 'menuitem');
                        Item.getElm().setAttribute('aria-disabled', String(Item.isDisabled()));
                    });
                });
                Menu.addEvent('hide', () => element.setAttribute('aria-expanded', 'false'));

                const onEscape = (event) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        Menu.hide();
                        element.focus();
                    }
                };
                element.addEventListener('keydown', onEscape);
                menu.addEventListener('keydown', onEscape);
                Button.addEvent('destroy', () => {
                    element.removeEventListener('keydown', onEscape);
                    menu.removeEventListener('keydown', onEscape);
                });
            });

            return Button;
        },

        decorateStatus: function (Status, entity) {
            const element = Status.getElm();
            element.type = 'button';
            element.classList.add('quiqqer-entity-status-button');
            const switchElement = Status.$Switch.getElm();
            switchElement.setAttribute('aria-hidden', 'true');

            const refresh = () => {
                const active = Boolean(Status.getStatus());
                const key = 'panel.' + entity + (active ? '.deactivate' : '.activate');
                const action = QUILocale.get(lg, key);
                element.setAttribute('title', action);
                element.setAttribute('aria-label', action);
                element.setAttribute('aria-pressed', String(active));
            };
            const Observer = new MutationObserver(refresh);
            Observer.observe(switchElement, {attributes: true, attributeFilter: ['data-status']});
            Status.addEvent('inject', refresh);
            Status.addEvent('change', refresh);
            Status.addEvent('destroy', () => Observer.disconnect());
            refresh();
        }
    };
});
