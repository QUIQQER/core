/**
 * Link input: select a project site, optionally enter a URL or select a media file.
 * The historical module path and project-site parser remain supported.
 *
 * Enable media selection with data-qui-options-media="1" (default: false).
 * Enable free URL input with data-qui-options-external="1" or data-external="1".
 * Both attributes work with data-qui imports and the project-site class parser.
 * Media values remain internal image.php references, not absolute/cache URLs.
 * setProject(Project) supplies the default project and language for both selectors.
 * See the Core wiki: dev_input_types, "Linkeingabe".
 *
 * @event onSelect [url, this] - fires if the user selects a site or media file
 * @event onRemove [this] - fires if the user removes the selected site
 */
define('controls/projects/project/site/Input', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'controls/projects/Popup',
    'qui/utils/String',
    'Locale',

    'css!controls/projects/project/site/Input.css'

], function (QUIControl, QUIButton, ProjectPopup, StringUtils, QUILocale) {
    "use strict";

    /**
     * @class controls/projects/project/site/Input
     *
     * @param {Object} options
     * @param {HTMLElement} [Input] - (optional), if no input given, one would be created
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QUIControl,
        Type   : 'controls/projects/project/site/Input',

        Binds: [
            '$onCreate',
            '$onImport'
        ],

        options: {
            project : false,
            lang    : false,
            name    : '',
            styles  : false,
            external: false, // free URL input allowed?
            media   : false // media file selection enabled?
        },

        initialize: function (options, Input) {
            this.parent(options);

            this.$Input      = Input || null;
            this.$SiteButton = null;

            this.addEvents({
                onImport: this.$onImport,
                onDestroy: () => {
                    this.$destroyed = true;
                    this.$stateObserver?.disconnect();
                    this.$Popup?.close();
                }
            });
        },

        /**
         * Create the DOMNode
         *
         * @return {HTMLElement}
         */
        create: function () {
            this.$Elm = new Element('div', {
                'class'     : 'qui-controls-project-site-input box',
                'data-quiid': this.getId()
            });

            if (!this.$Input) {
                this.$Input = new Element('input', {
                    name: this.getAttribute('name')
                }).inject(this.$Elm);
            } else {
                this.$Elm.wraps(this.$Input);
            }

            // Read options here as the legacy class parser calls create(), not imports().
            for (const name of ['external', 'media']) {
                const value = this.$Input.getAttribute('data-qui-options-' + name)
                    ?? (name === 'external' ? this.$Input.getAttribute('data-external') : null)
                    ?? this.getAttribute(name);
                this.setAttribute(name, value === true || value === 1 || value === '1' || value === 'true');
            }

            if (this.getAttribute('styles')) {
                this.$Elm.setStyles(this.getAttribute('styles'));
            }

            this.$Input.set('data-quiid', this.getId());

            this.$Input.setStyles({
                'float': 'left'
            });

            if (!this.getAttribute('external')) {
                this.$Input.setStyle('cursor', 'pointer');
            }

            this.$SiteButton = this.$createButton('site', 'fa fa-file-o', () => {
                this.$openSelection(false);
            });

            if (this.getAttribute('media')) {
                this.$Elm.classList.add('qui-controls-project-site-input--media');
                this.$MediaButton = this.$createButton('media', 'fa fa-file-text-o', () => {
                    this.$openSelection(true);
                });
            }

            this.$ClearButton = this.$createButton('clear', 'fa fa-remove', () => {
                if (!this.$canEdit()) {
                    return;
                }

                this.$Input.value = '';
                this.fireEvent('remove', [this]);
                this.$notifyChange();
            });

            this.$stateObserver = new MutationObserver(() => this.$syncButtons());
            this.$stateObserver.observe(this.$Input, {
                attributes: true,
                attributeFilter: ['disabled', 'readonly']
            });
            this.$syncButtons();

            if (!this.getAttribute('external')) {
                this.$Input.addEventListener('focus', () => this.$openSelection(false));
            }

            return this.$Elm;
        },

        /**
         * event : on import
         */
        $onImport: function () {
            this.$Input = this.$Elm;

            const name = this.$Input.getAttribute('data-qui-options-name');

            if (name) {
                this.setAttribute('name', name);
            }

            this.create();
        },

        /** Set the default context; a stored internal reference takes precedence. */
        setProject: function (Project) {
            this.setAttribute('project', typeof Project === 'string' ? Project : Project.getName());

            if (typeof Project?.getLang === 'function') {
                this.setAttribute('lang', Project.getLang());
            }
        },

        $createButton: function (name, icon, onClick) {
            const label = QUILocale.get('quiqqer/core', 'projects.project.site.input.' + name);
            const Button = new QUIButton({
                icon: icon,
                title: label,
                events: {onClick: onClick}
            }).inject(this.$Elm);
            const element = Button.getElm();
            element.dataset.name = name;
            element.setAttribute('aria-label', label);
            element.removeAttribute('tabindex');
            element.style.removeProperty('outline');

            return Button;
        },

        $canEdit: function () {
            return !this.$destroyed && this.$Input.isConnected
                && !this.$Input.disabled && !this.$Input.readOnly;
        },

        $syncButtons: function () {
            const disabled = this.$Input.disabled || this.$Input.readOnly;

            for (const Button of [this.$SiteButton, this.$MediaButton, this.$ClearButton]) {
                if (Button) {
                    disabled ? Button.disable() : Button.enable();
                }
            }
        },

        $notifyChange: function () {
            this.$Input.dispatchEvent(new Event('input', {bubbles: true}));
            // Preserve the existing non-bubbling change event contract.
            this.$Input.dispatchEvent(new Event('change'));
        },

        $openSelection: function (media) {
            if (!this.$canEdit() || this.$Popup || this.$popupLoading) {
                return;
            }

            const value = this.$Input.value;
            const open = Popup => {
                this.$popupLoading = false;

                if (!this.$canEdit() || this.$Input.value !== value) {
                    return;
                }

                let project = this.getAttribute('project');
                let lang = this.getAttribute('lang');
                let fileid = false;

                // Only internal references may override the editor's project context.
                if (/^(index|image)\.php\?/.test(value)) {
                    const params = StringUtils.getUrlParams(value);
                    project = params.project || project;
                    lang = params.lang || lang;
                    fileid = media && value.startsWith('image.php?') ? params.id || false : false;
                }

                const Button = media ? this.$MediaButton : this.$SiteButton;
                this.$Popup = new Popup({
                    project: project,
                    lang: lang,
                    fileid: fileid,
                    events: {
                        onSubmit: (Win, data) => {
                            if (!this.$canEdit() || this.$Input.value !== value) {
                                return;
                            }

                            const url = media ? data.url : data.urls?.[0];

                            if (typeof url !== 'string' || !url) {
                                return;
                            }

                            this.$Input.value = url;
                            this.fireEvent('select', [url, this]);
                            this.$notifyChange();
                        },
                        onClose: () => {
                            this.$Popup = null;

                            if (this.$canEdit()) {
                                Button.getElm().focus();
                            }
                        }
                    }
                });
                this.$Popup.open();
            };

            if (media) {
                this.$popupLoading = true;
                require(['controls/projects/project/media/Popup'], open, error => {
                    this.$popupLoading = false;
                    console.error(error);
                });
            } else {
                open(ProjectPopup);
            }
        }
    });
});
