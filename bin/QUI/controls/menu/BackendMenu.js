/**
 * Backend navigation: app tiles and expanded administration/settings menus.
 * The existing menu items remain the source of actions and module integrations.
 */
define('controls/menu/BackendMenu', [
    'qui/QUI',
    'qui/controls/Control',
    'qui/classes/utils/DragDrop',
    'Locale',
    'Mustache',
    'text!controls/menu/BackendMenu.Apps.html',
    'text!controls/menu/BackendMenu.Categories.html',
    'css!controls/menu/BackendMenu.css'
], function (QUI, QUIControl, DragDrop, Locale, Mustache, appsTemplate, categoriesTemplate) {
    'use strict';

    return new Class({
        Extends: QUIControl,
        Type: 'controls/menu/BackendMenu',

        initialize: function (MenuBar, options) {
            this.parent(options);

            this.$Bar = MenuBar;
            this.$active = null;
            this.$roots = new Map();
            this.$actions = new Map();
            this.$items = [];
            this.$outside = (event) => {
                if (!this.getElm().contains(event.target)) {
                    this.close();
                }
            };
            this.$escape = (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.close(true);
                }
            };
            this.$resize = () => this.$position();

            this.addEvent('destroy', () => {
                this.close();
                this.$roots.forEach(({Item, update}) => {
                    Item.removeEvent('append', update);
                    Item.getElm().classList.remove('qui-backend-menu-legacy');
                });
            });
        },

        create: function () {
            if (this.$Elm) {
                return this.$Elm;
            }

            this.$Elm = document.createElement('div');
            this.$Elm.className = 'qui-backend-menu';

            this.$Popup = document.createElement('nav');
            this.$Popup.className = 'qui-backend-menu-popup';
            this.$Popup.id = 'qui-backend-menu-' + this.getId();
            this.$Popup.hidden = true;
            for (const name of ['quiqqer', 'apps', 'extras', 'settings']) {
                const Item = this.$Bar.getChildren(name);

                if (!Item) {
                    continue;
                }

                const Button = document.createElement('button');
                Button.type = 'button';
                Button.className = 'qui-backend-menu-trigger';
                Button.dataset.name = name;

                if (name === 'quiqqer') {
                    Button.classList.add('qui-backend-menu-trigger--logo');
                    Button.setAttribute('aria-label', 'QUIQQER');
                    const Logo = document.createElement('img');
                    Logo.src = window.URL_BIN_DIR + 'quiqqer_logo.svg';
                    Logo.alt = '';
                    Button.append(Logo);
                } else {
                    Button.textContent = Item.getAttribute('text');
                }
                Button.setAttribute('aria-controls', this.$Popup.id);
                Button.setAttribute('aria-expanded', 'false');
                Button.addEventListener('click', () => {
                    if (this.$active === name) {
                        this.close();
                    } else {
                        this.open(name);
                    }
                });
                Button.addEventListener('keydown', (event) => {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        this.open(name);
                        this.$Search.focus();
                    }
                });

                const update = () => {
                    Button.hidden = !Item.getChildren().length;
                };

                Item.addEvent('append', update);
                Item.getElm().classList.add('qui-backend-menu-legacy');
                update();
                this.$roots.set(name, {Item, Button, update});
                this.$Elm.append(Button);
            }

            const Header = document.createElement('header');
            Header.className = 'qui-backend-menu-header';
            const Title = document.createElement('h2');
            Title.id = this.$Popup.id + '-title';
            this.$Title = Title;
            this.$Popup.setAttribute('aria-labelledby', Title.id);

            this.$Search = document.createElement('input');
            this.$Search.type = 'search';
            this.$Search.dataset.name = 'search';
            this.$Search.placeholder = Locale.get('quiqqer/core', 'search');
            this.$Search.setAttribute('aria-label', this.$Search.placeholder);
            this.$Search.addEventListener('input', () => this.$render());

            const Close = document.createElement('button');
            Close.type = 'button';
            Close.className = 'qui-backend-menu-close';
            Close.dataset.name = 'close';
            Close.setAttribute('aria-label', Locale.get('quiqqer/core', 'close'));
            const Icon = document.createElement('span');
            Icon.className = 'fa fa-times';
            Icon.setAttribute('aria-hidden', 'true');
            Close.append(Icon);
            Close.addEventListener('click', () => this.close(true));
            Header.append(Title, this.$Search, Close);

            this.$Content = document.createElement('div');
            this.$Content.className = 'qui-backend-menu-content';
            this.$Content.addEventListener('click', (event) => {
                const Button = event.target.closest('[data-name="action"]');

                if (!Button || !this.$Content.contains(Button) || Button.disabled) {
                    return;
                }

                const Item = this.$actions.get(Button.dataset.id);

                if (Item) {
                    this.close(true);
                    Item.click();
                }
            });

            this.$Empty = document.createElement('p');
            this.$Empty.className = 'qui-backend-menu-empty';
            this.$Empty.setAttribute('role', 'status');
            this.$Empty.hidden = true;
            this.$Empty.textContent = Locale.get('quiqqer/core', 'no.results');

            this.$Popup.append(Header, this.$Content, this.$Empty);
            this.$Elm.append(this.$Popup);
            return this.$Elm;
        },

        open: function (name) {
            const Root = this.$roots.get(name);

            if (!Root || Root.Button.hidden) {
                return;
            }

            this.close();
            this.$Bar.getChildren().forEach((Item) => Item.hide());
            this.$active = name;
            this.$Popup.classList.toggle('qui-backend-menu-popup--apps', ['quiqqer', 'apps'].includes(name));
            Root.Button.setAttribute('aria-expanded', 'true');
            this.$Title.textContent = name === 'quiqqer' ? 'QUIQQER' : Root.Item.getAttribute('text');
            this.$Search.value = '';
            this.$actions.clear();
            this.$items = this.$readItems(Root.Item);
            this.$render();
            this.$Popup.hidden = false;
            this.$position();
            this.$Content.scrollTop = 0;

            document.addEventListener('pointerdown', this.$outside, true);
            document.addEventListener('focusin', this.$outside);
            document.addEventListener('keydown', this.$escape);
            window.addEventListener('resize', this.$resize);
        },

        close: function (restoreFocus) {
            const Root = this.$roots.get(this.$active);

            if (!Root) {
                return;
            }

            this.$active = null;
            this.$Popup.hidden = true;
            Root.Button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('pointerdown', this.$outside, true);
            document.removeEventListener('focusin', this.$outside);
            document.removeEventListener('keydown', this.$escape);
            window.removeEventListener('resize', this.$resize);

            if (restoreFocus) {
                Root.Button.focus();
            }
        },

        /**
         * Re-read the live tree on opening, including entries appended by modules.
         */
        $readItems: function (Parent, parentDisabled, ancestors = []) {
            return Parent.getChildren().flatMap((Item) => {
                if (Item.getType() === 'qui/controls/contextmenu/Separator') {
                    return [{separator: true}];
                }

                const disabled = parentDisabled || Item.isDisabled();
                const text = String(Item.getAttribute('text') || '');

                if (!text || Item.getAttribute('hidden') === true) {
                    return [];
                }

                const children = this.$readItems(Item, disabled, [...ancestors, text]);
                const onClick = Item.getAttribute('onClick');
                const action = !children.length || Boolean(
                    Item.getAttribute('require') || Item.getAttribute('exec') ||
                    Item.getAttribute('qui-xml-file') ||
                    (onClick && onClick !== 'QUI.Menu.menuClick')
                );
                const id = String(this.$actions.size);

                if (action) {
                    this.$actions.set(id, Item);
                }

                let icon = Item.getAttribute('originalIcon') || Item.getAttribute('icon') || 'fa fa-circle-o';
                let image = '';

                if (!/^(fa[srbl]?\s|fa-)/.test(icon)) {
                    try {
                        const url = new URL(icon, document.baseURI);

                        if (url.protocol === 'https:' || url.protocol === 'http:') {
                            image = url.href;
                        }
                    } catch (e) {
                        // Missing or invalid icons use the same fallback as an empty icon.
                    }

                    icon = 'fa fa-circle-o';
                }

                return [{
                    id: id,
                    text: text,
                    description: Item.getAttribute('description') || '',
                    icon: icon,
                    image: image,
                    action: action,
                    disabled: Boolean(disabled),
                    group: children.length > 0,
                    nested: children.length > 0 && ancestors.length > 0,
                    breadcrumb: ancestors.length ? ancestors.join(' › ') + ' › ' : '',
                    children: {items: children}
                }];
            });
        },

        $filter: function (items, query) {
            return items.flatMap((item) => {
                if (item.separator) {
                    return query ? [] : [item];
                }

                if (!query || (item.text + ' ' + item.description).toLocaleLowerCase().includes(query)) {
                    return [item];
                }

                const children = this.$filter(item.children.items, query);
                return children.length ? [{...item, children: {items: children}}] : [];
            });
        },

        /**
         * Long groups get their own multi-column area instead of stretching a column.
         */
        $categoryView: function (items) {
            const groups = [];
            const links = [];
            const wide = [];

            for (const item of items) {
                if (item.separator) {
                    continue;
                }

                if (!item.group) {
                    links.push(item);
                } else if (item.children.items.filter((child) => !child.separator).length > 8) {
                    wide.push({overview: false, items: [item]});
                } else {
                    groups.push(item);
                }
            }

            return {
                overview: true,
                singleSection: !groups.length || !links.length,
                groups: groups.length ? {overview: false, items: groups} : false,
                links: links.length ? {overview: false, items: links, rows: Math.ceil(links.length / 2)} : false,
                wide: wide
            };
        },

        $render: function () {
            const items = this.$filter(this.$items, this.$Search.value.trim().toLocaleLowerCase());
            const tiles = ['quiqqer', 'apps'].includes(this.$active);
            const template = tiles ? appsTemplate : categoriesTemplate;
            const data = tiles ? {items: items} : this.$categoryView(items);

            this.$Content.innerHTML = Mustache.render(template, data, {entries: template});
            this.$Empty.hidden = items.length > 0;

            // Keep the existing bookmark drop targets and their menu-item contract.
            this.$Content.querySelectorAll('[data-name="action"]').forEach((Button) => {
                const Item = this.$actions.get(Button.dataset.id);

                if (Button.disabled || !Item.getAttribute('dragable')) {
                    return;
                }

                // Drag.Move excludes button elements; use the wrapper as its handle.
                new DragDrop(Button.parentElement, {
                    dropables: '.qui-contextitem-dropable',
                    events: {
                        onStart: () => this.close(),
                        onEnter: (Element, Dragable, Droppable) => {
                            const Target = QUI.Controls.getById(Droppable?.getAttribute('data-quiid'));

                            if (Target) {
                                Target.highlight();
                            }
                        },
                        onLeave: (Element, Dragable, Droppable) => {
                            const Target = QUI.Controls.getById(Droppable?.getAttribute('data-quiid'));

                            if (Target) {
                                Target.normalize();
                            }
                        },
                        onDrop: (Element, Dragable, Droppable) => {
                            const Target = QUI.Controls.getById(Droppable?.getAttribute('data-quiid'));

                            if (Target) {
                                Target.normalize();
                                Target.appendChild(Item);
                            }
                        }
                    }
                });
            });
        },

        $position: function () {
            const top = Math.max(8, this.getElm().getBoundingClientRect().bottom + 8);
            this.$Popup.style.top = top + 'px';
            this.$Popup.style.maxHeight = Math.max(0, window.innerHeight - top - 12) + 'px';

            const Root = this.$roots.get(this.$active);

            if (Root) {
                const trigger = Root.Button.getBoundingClientRect();
                const width = this.$Popup.getBoundingClientRect().width;
                const popupLeft = Math.max(12, Math.min((window.innerWidth - width) / 2, trigger.left - 24));
                this.$Popup.style.left = popupLeft + 'px';
                const popup = this.$Popup.getBoundingClientRect();
                const left = Math.max(16, Math.min(popup.width - 16, trigger.left + trigger.width / 2 - popup.left));
                this.$Popup.style.setProperty('--_pointer-left', left + 'px');
            }
        }
    });
});
