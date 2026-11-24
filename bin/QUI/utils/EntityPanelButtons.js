/**
 * Shared action and status buttons for backend detail panels.
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

        setStatus: function (Status, entity, active) {
            const element = Status.getElm();
            const text = QUILocale.get(lg, active ? 'isActivate' : 'isDeactivate');
            const action = QUILocale.get(lg, 'panel.' + entity + (active ? '.deactivate' : '.activate'));

            Status.setAttributes({
                text: text,
                textimage: active ? 'fa fa-check' : 'fa fa-times',
                title: action
            });
            element.type = 'button';
            element.tabIndex = 0;
            element.style.removeProperty('outline');
            element.classList.add('quiqqer-entity-status-button');
            element.setAttribute('aria-label', text + '. ' + action);
            element.setAttribute('aria-pressed', String(Boolean(active)));
            element.firstElementChild.setAttribute('aria-hidden', 'true');
        }
    };
});
