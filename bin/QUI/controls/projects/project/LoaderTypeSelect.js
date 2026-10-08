define('controls/projects/project/LoaderTypeSelect', [
    'qui/controls/Control',
    'qui/controls/loader/Types',
    'Locale',
    'css!qui/controls/loader/Loader.css',
    'css!controls/projects/project/LoaderTypeSelect.css'
], function (QUIControl, LoaderTypes, QUILocale) {
    "use strict";

    const lg = 'quiqqer/core';
    const localePrefix = 'projects.frontend.loader.';

    return new Class({
        Extends: QUIControl,
        Type: 'controls/projects/project/LoaderTypeSelect',

        initialize: function (options) {
            this.parent(options);
            this.$types = LoaderTypes.getTypes();
            this.$Container = null;
            this.$Input = null;

            this.addEvents({
                onImport: this.$onImport.bind(this),
                onDestroy: this.$onDestroy.bind(this)
            });
        },

        $onImport: function () {
            this.$Input = this.getElm();

            // A label around multiple buttons forwards clicks and hover to the first card.
            const Label = this.$Input.closest('label');

            if (Label) {
                const Field = document.createElement('div');

                for (const Attribute of Label.attributes) {
                    if (Attribute.name !== 'for') {
                        Field.setAttribute(Attribute.name, Attribute.value);
                    }
                }

                Field.append(...Label.childNodes);
                Label.replaceWith(Field);
            }

            this.$Container = document.createElement('div');
            this.$Container.className = 'quiqqer-core-loaderSelect';
            this.$Input.after(this.$Container);

            const Grid = document.createElement('div');
            Grid.className = 'quiqqer-core-loaderSelect-grid';
            Grid.setAttribute('role', 'group');
            Grid.setAttribute('aria-label', QUILocale.get(lg, localePrefix + 'type'));
            this.$Container.appendChild(Grid);

            ['', ...Object.keys(this.$types)].forEach((type) => {
                const descriptor = this.$types[type || 'standard'];
                const Card = document.createElement('button');
                Card.type = 'button';
                Card.className = 'quiqqer-core-loaderSelect-card';
                Card.dataset.name = 'type';
                Card.dataset.type = type;
                Card.disabled = this.$Input.disabled;
                Card.addEventListener('click', () => {
                    this.$Input.value = type;
                    this.$refresh();
                    this.$Input.dispatchEvent(new Event('change', {bubbles: true}));
                    this.fireEvent('change', [this, type]);
                });

                const Preview = document.createElement('div');
                Preview.className = 'quiqqer-core-loaderSelect-preview';
                Preview.setAttribute('aria-hidden', 'true');
                Card.appendChild(Preview);

                const Title = document.createElement('span');
                Title.className = 'quiqqer-core-loaderSelect-title';
                Title.textContent = type ? descriptor.title : QUILocale.get(lg, localePrefix + 'automatic');
                Card.setAttribute('aria-label', Title.textContent);
                Card.appendChild(Title);
                Grid.appendChild(Card);

                require(descriptor.files, () => {
                    if (!this.$Container) {
                        return;
                    }

                    const Loader = document.createElement('div');
                    Loader.className = 'qui-loader';
                    const Inner = document.createElement('div');
                    Inner.className = 'qui-loader-inner-' + (type || 'standard');

                    if (descriptor.icon) {
                        const Icon = document.createElement('span');
                        Icon.className = 'fas fa ' + descriptor.icon + ' fa-spin';
                        Inner.appendChild(Icon);
                    }

                    for (let i = 0; i < descriptor.children; i++) {
                        const Child = document.createElement('div');
                        Child.className = 'control-background';
                        Inner.appendChild(Child);
                    }

                    Loader.appendChild(Inner);
                    Preview.appendChild(Loader);
                });
            });

            const Description = document.createElement('p');
            Description.className = 'quiqqer-core-loaderSelect-description';
            Description.textContent = QUILocale.get(lg, localePrefix + 'automatic.description');
            this.$Container.appendChild(Description);

            this.$Notice = document.createElement('p');
            this.$Notice.className = 'quiqqer-core-loaderSelect-notice';
            this.$Notice.setAttribute('role', 'status');
            this.$Container.appendChild(this.$Notice);
            this.$refresh();
        },

        $refresh: function () {
            const value = this.$Input.value;
            const available = Object.prototype.hasOwnProperty.call(this.$types, value);
            const selected = available ? value : '';

            this.$Container.querySelectorAll('[data-name="type"]').forEach((Card) => {
                Card.setAttribute('aria-pressed', String(Card.dataset.type === selected));
            });

            let notice = '';

            if (value && !available) {
                notice = QUILocale.get(lg, localePrefix + 'unavailable');
            } else if (available && !this.$types[value].supportsColor) {
                notice = QUILocale.get(lg, localePrefix + 'ownColors');
            }

            this.$Notice.textContent = notice;
        },

        $onDestroy: function () {
            if (this.$Container) {
                this.$Container.remove();
                this.$Container = null;
            }
        }
    });
});
