/** Compact palette draft; only the enclosing project settings Save action persists it. */
define('controls/projects/project/settings/Colors', [
    'qui/controls/Control',
    'qui/controls/elements/ColorPopup',
    'qui/controls/elements/ColorValue',
    'controls/projects/project/ColorProvider',
    'css!controls/projects/project/settings/Colors.css'
], function(QUIControl, ColorPopup, ColorValue, ColorProvider) {
    'use strict';

    return new Class({
        Extends: QUIControl,
        Type: 'controls/projects/project/settings/Colors',

        initialize: function(options) {
            this.parent(options);
            this.$editing = false;
            this.$previewMode = 'light';
            this.$generation = 0;
            this.addEvents({
                onImport: () => {
                    this.$Input = this.getElm();
                    const Label = this.$Input.closest('label');
                    if (Label) {
                        const Container = document.createElement('div');
                        Container.className = Label.className;
                        Container.classList.add('quiqqer-projectColors-field');
                        Container.append(...Label.childNodes);
                        Label.replaceWith(Container);
                    }
                    this.$Container = document.createElement('div');
                    this.$Container.className = 'quiqqer-projectColors';
                    this.$Input.after(this.$Container);
                    this.$Input.addEventListener('palette-saved', () => this.setProject(this.$Project));
                },
                onDestroy: () => {
                    this.$generation++;
                    this.$Popup?.destroy();
                    this.$Container?.remove();
                }
            });
        },

        setProject: function(Project) {
            this.$Popup?.destroy();
            this.$Popup = null;
            this.$Project = Project;
            const generation = ++this.$generation;
            this.$Container.textContent = ColorProvider.text('loading');
            return ColorProvider.create(Project).load().then((data) => {
                if (generation !== this.$generation) {return;}
                this.$defaults = data.defaults;
                this.$groups = data.groups;
                this.$sourceTitles = {};
                if (data.source && data.sourceTitle) {
                    this.$sourceTitles[data.source.provider] = data.sourceTitle;
                }
                let saved = data;
                try {saved = this.$Input.value ? JSON.parse(this.$Input.value) : data;} catch (error) {
                    if (!data.damaged) {throw error;}
                }
                this.$repair = false;
                if (saved.palette !== undefined) {
                    this.$repair = saved.repair === true;
                    saved = JSON.parse(saved.palette);
                }
                if (data.damaged && !this.$repair) {
                    this.$Container.textContent = ColorProvider.text('damaged');
                    this.$button(this.$Container, ColorProvider.text('repair'), () => {
                        this.$repair = true;
                        this.$palette = {
                            version: 2,
                            colors: JSON.parse(JSON.stringify(data.defaults)),
                            source: data.defaultSource
                        };
                        this.$attachDraft(); this.$render(); this.$provider.changed();
                    });
                    return;
                }
                this.$palette = ColorProvider.normalize(saved, data.defaults);
                this.$attachDraft();
                this.$render();
            }).catch(() => {
                if (generation === this.$generation) {this.$Container.textContent = ColorProvider.text('error');}
            });
        },

        $attachDraft: function() {
            this.$provider = ColorProvider.draft(this.$palette, () => {
                const request = {palette: JSON.stringify(this.$palette)};
                if (this.$repair) {request.repair = true;}
                this.$Input.value = JSON.stringify(request);
                this.$Input.dispatchEvent(new Event('change', {bubbles: true}));
                this.$refresh();
            });
        },

        $element: function(parent, tag, className, text) {
            const element = document.createElement(tag);
            if (className) {element.className = className;}
            if (text !== undefined) {element.textContent = text;}
            parent.appendChild(element);
            return element;
        },

        $button: function(parent, text, action, name) {
            const button = this.$element(parent, 'button', 'qui-colorField-button', text);
            button.type = 'button';
            if (name) {button.dataset.name = name;}
            button.addEventListener('click', action);
            return button;
        },

        $entries: function(group) {
            return Object.entries(this.$palette.colors).filter(([id, color]) => {
                if (color.archived) {return group === 'archived';}
                if (id.startsWith('custom-')) {return group === 'custom';}
                return group === (this.$groups[id] || color.displayGroup || 'other');
            });
        },

        $swatches: function(parent, color) {
            const samples = this.$element(parent, 'span', 'quiqqer-projectColors-swatches');
            const modes = color.dark === undefined ? ['both'] : ['light', 'dark'];
            modes.forEach((mode) => {
                const sample = this.$element(samples, 'span', 'quiqqer-projectColors-sample');
                const swatch = this.$element(sample, 'span', 'qui-colorField-swatch');
                swatch.style.setProperty('--sample-color', color.value ?? color[mode]);
                swatch.setAttribute('aria-hidden', 'true');
                if (modes.length === 2) {
                    sample.title = ColorProvider.text(mode);
                    this.$element(sample, 'small', '', ColorProvider.text(mode));
                }
            });
        },

        $render: function() {
            this.$Popup?.destroy();
            this.$Popup = null;
            this.$Container.replaceChildren();
            this.$Status = null;
            this.$Container.classList.add('qui-colorField');
            this.$Overview = this.$element(this.$Container, 'section', 'quiqqer-projectColors-overview');
            this.$Overview.dataset.name = 'overview';
            const header = this.$element(this.$Overview, 'header', 'quiqqer-projectColors-header');
            const intro = this.$element(header, 'div');
            this.$element(intro, 'h2', '', ColorProvider.text('title'));
            this.$element(intro, 'p', 'quiqqer-projectColors-muted', ColorProvider.text('description'));
            const source = this.$palette.source;
            let sourceName = ColorProvider.text('source.core');
            if (source && !(source.provider === 'quiqqer/core' && source.palette === 'standard')) {
                sourceName = this.$sourceTitles[source.provider] || source.provider;
            }
            const sourceInfo = this.$element(intro, 'p', '', ColorProvider.text('source') + ': ' + sourceName);
            sourceInfo.dataset.name = 'palette-source';
            this.$Edit = this.$button(header, ColorProvider.text('editPalette'), () => this.$showEditor(true), 'edit-palette');
            this.$Edit.classList.add('quiqqer-projectColors-primary');
            this.$element(this.$Edit, 'span', 'fa fa-pencil').setAttribute('aria-hidden', 'true');
            this.$OverviewColors = this.$element(this.$Overview, 'div', 'quiqqer-projectColors-overviewGroups');

            this.$Management = this.$element(this.$Container, 'section', 'quiqqer-projectColors-management');
            this.$Management.dataset.name = 'management';
            const editHeader = this.$element(this.$Management, 'header', 'quiqqer-projectColors-header');
            this.$Back = this.$button(editHeader, ColorProvider.text('backOverview'), () => this.$showEditor(false), 'back-overview');
            this.$Back.setAttribute('aria-label', ColorProvider.text('backOverview'));
            this.$Back.title = ColorProvider.text('backOverview');
            this.$Back.textContent = '';
            this.$element(this.$Back, 'span', 'fa fa-angle-left').setAttribute('aria-hidden', 'true');
            const editIntro = this.$element(editHeader, 'div');
            this.$element(editIntro, 'h2', '', ColorProvider.text('editPalette'));
            this.$element(editIntro, 'p', 'quiqqer-projectColors-muted', ColorProvider.text('editHelp'));
            const layout = this.$element(this.$Management, 'div', 'quiqqer-projectColors-layout');
            this.$Colors = this.$element(layout, 'div', 'quiqqer-projectColors-groups');
            const preview = this.$element(layout, 'aside', 'quiqqer-projectColors-preview');
            const previewHeader = this.$element(preview, 'div', 'quiqqer-projectColors-previewHeader');
            this.$element(previewHeader, 'h3', '', ColorProvider.text('sample.title'));
            const modes = this.$element(previewHeader, 'div', 'quiqqer-projectColors-modes');
            modes.setAttribute('role', 'group');
            modes.setAttribute('aria-label', ColorProvider.text('sample.mode'));
            this.$modeButtons = ['light', 'dark'].map((mode) => {
                const button = this.$button(modes, ColorProvider.text(mode), () => {
                    this.$previewMode = mode;
                    this.$updatePreview();
                }, 'preview-' + mode);
                return button;
            });
            this.$Preview = this.$element(preview, 'div');
            this.$Preview.dataset.name = 'preview';
            this.$element(preview, 'p', 'quiqqer-projectColors-muted', ColorProvider.text('sample.help'));
            this.$refresh();
            this.$showEditor(this.$editing, false);
        },

        $showEditor: function(editing, focus = true) {
            this.$Popup?.close(false);
            this.$editing = editing;
            this.$Overview.hidden = editing;
            this.$Management.hidden = !editing;
            if (focus) {(editing ? this.$Back : this.$Edit).focus();}
        },

        $refresh: function() {
            if (!this.$Colors) {return;}
            this.$renderOverview();
            this.$renderColors();
            this.$updatePreview();
        },

        $renderOverview: function() {
            this.$OverviewColors.replaceChildren();
            const names = ColorProvider.names(this.$palette.colors);
            ['brand', 'status', 'neutral', 'content', 'other', 'custom'].forEach((group) => {
                const entries = this.$entries(group);
                if (!entries.length) {return;}
                const section = this.$element(this.$OverviewColors, 'section');
                section.dataset.group = group;
                this.$element(section, 'h3', '', ColorProvider.text(group));
                if (group === 'content') {
                    const samples = this.$element(section, 'div', 'quiqqer-projectColors-contentSamples');
                    ['light', 'dark'].forEach((mode) => {
                        const example = this.$element(samples, 'div');
                        this.$element(example, 'h4', 'quiqqer-projectColors-modeLabel', ColorProvider.text(mode));
                        this.$sample(example, mode, false);
                    });
                    return;
                }
                const list = this.$element(section, 'ul', 'quiqqer-projectColors-summary');
                entries.forEach(([id, color]) => {
                    const item = this.$element(list, 'li');
                    this.$swatches(item, color);
                    this.$element(item, 'span', 'quiqqer-projectColors-name', names[id]);
                });
            });
        },

        $sample: function(parent, mode, full) {
            const colors = this.$palette.colors;
            const value = (id) => colors[id]?.value ?? colors[id]?.[mode];
            const sample = this.$element(parent, 'div', 'quiqqer-projectColors-example');
            sample.dataset.mode = mode;
            // These values belong only to the example, never to the backend or template.
            sample.style.backgroundColor = value('background');
            sample.style.color = value('text');
            const heading = this.$element(sample, full ? 'h4' : 'h5', '', ColorProvider.text('sample.heading'));
            heading.style.color = value('heading');
            this.$element(sample, 'p', '', ColorProvider.text('sample.text'));
            const links = this.$element(sample, 'p', 'quiqqer-projectColors-exampleLinks');
            ['link', 'link-hover'].forEach((role) => {
                const link = this.$element(links, 'a', '', ColorProvider.text('sample.' + role));
                link.href = '#';
                link.style.color = value(role);
                link.addEventListener('click', (event) => event.preventDefault());
            });
            if (!full) {return;}
            const buttons = this.$element(sample, 'div', 'quiqqer-projectColors-exampleActions');
            ['primary', 'secondary', 'tertiary'].forEach((role) => {
                const button = this.$element(buttons, 'button', 'quiqqer-projectColors-exampleButton', ColorProvider.text('name.' + role));
                button.type = 'button';
                this.$sampleColor(button, value(role), value('background'));
            });
            const statuses = this.$element(sample, 'div', 'quiqqer-projectColors-exampleActions');
            ['success', 'danger', 'warning', 'info'].forEach((role) => {
                const status = this.$element(statuses, 'span', 'quiqqer-projectColors-exampleStatus', ColorProvider.text('name.' + role));
                this.$sampleColor(status, value(role), value('background'));
                if (['success', 'danger', 'info'].includes(role)) {
                    status.style.setProperty('--_example-text', '#fff');
                }
            });
            this.$entries('custom').forEach(([id, color]) => {
                const text = this.$element(sample, 'p', '', color.name + ': ' + ColorProvider.text('sample.custom'));
                text.style.color = value(id);
            });
        },

        $sampleColor: function(element, color, background) {
            element.style.setProperty('--_example-color', color);
            const foreground = ColorValue.rgba(color);
            const backdrop = ColorValue.rgba(background);
            // Choose example text against the composited fill, including transparent palette values.
            const channels = foreground.slice(0, 3).map((channel, index) => {
                const base = backdrop[index] * backdrop[3] + 255 * (1 - backdrop[3]);
                const value = (channel * foreground[3] + base * (1 - foreground[3])) / 255;
                return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
            });
            const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
            element.style.setProperty('--_example-text', luminance > 0.179 ? '#000000' : '#ffffff');
        },

        $updatePreview: function() {
            this.$Preview.replaceChildren();
            this.$sample(this.$Preview, this.$previewMode, true);
            this.$modeButtons.forEach((button, index) => {
                button.setAttribute('aria-pressed', String(this.$previewMode === (index ? 'dark' : 'light')));
            });
        },

        $renderColors: function() {
            const focus = document.activeElement;
            const focusId = focus?.dataset.colorId;
            const focusName = focus?.dataset.name;
            const archiveOpen = this.$Colors.querySelector('[data-name="archive"]')?.open;
            this.$Colors.replaceChildren();
            const names = ColorProvider.names(this.$palette.colors);
            ['brand', 'status', 'neutral', 'content', 'other', 'custom', 'archived'].forEach((group) => {
                const entries = this.$entries(group);
                if (!entries.length && group !== 'custom') {return;}
                const archived = group === 'archived';
                const section = this.$element(this.$Colors, archived ? 'details' : 'section');
                section.dataset.name = archived ? 'archive' : 'color-group';
                section.dataset.group = group;
                if (archived) {section.open = Boolean(archiveOpen);}
                const header = this.$element(section, archived ? 'summary' : 'div', 'quiqqer-projectColors-groupHeader');
                this.$element(header, archived ? 'span' : 'h3', '', ColorProvider.text(group));
                if (!archived) {
                    this.$element(header, 'p', 'quiqqer-projectColors-muted', ColorProvider.text('usage.' + group));
                }
                const list = this.$element(section, 'ul', 'quiqqer-projectColors-rows');
                entries.forEach(([id, color]) => {
                    const item = this.$element(list, 'li', 'quiqqer-projectColors-item');
                    const row = this.$button(item, '', () => this.$editColor(id, row), 'edit-color');
                    row.className = 'quiqqer-projectColors-row';
                    row.dataset.colorId = id;
                    row.setAttribute('aria-haspopup', 'dialog');
                    row.setAttribute('aria-expanded', 'false');
                    this.$swatches(row, color);
                    this.$element(row, 'span', 'quiqqer-projectColors-name', names[id]);
                    const usage = Object.hasOwn(this.$defaults, id) ? id : 'custom';
                    this.$element(row, 'span', 'quiqqer-projectColors-usage', ColorProvider.text('use.' + usage));
                    const values = this.$element(row, 'span', 'quiqqer-projectColors-values');
                    (color.dark === undefined ? ['both'] : ['light', 'dark']).forEach((mode) => {
                        this.$element(values, 'span', 'quiqqer-projectColors-muted', ColorProvider.text(mode));
                        this.$element(values, 'span', '', color.value ?? color[mode]);
                    });
                    const icon = this.$element(row, 'span', 'fa fa-pencil');
                    icon.setAttribute('aria-hidden', 'true');
                    if (archived) {
                        const restore = this.$button(item, ColorProvider.text('restore'), () => {
                            delete this.$palette.colors[id].archived;
                            this.$provider.changed();
                            this.$focusColor(id);
                        }, 'restore-color');
                        restore.dataset.colorId = id;
                    }
                });
                if (group === 'custom') {
                    const item = this.$element(list, 'li');
                    const add = this.$button(item, ColorProvider.text('addOwn'), () => this.$editColor(null, add), 'add-color');
                    add.classList.add('quiqqer-projectColors-add');
                    add.disabled = Object.keys(this.$palette.colors).length >= 512;
                    const limit = this.$element(section, 'p', 'quiqqer-projectColors-muted', ColorProvider.text('limit'));
                    limit.hidden = !add.disabled;
                }
            });
            if (focusId) {this.$focusColor(focusId, focusName);}
            else if (focusName === 'add-color') {this.$Colors.querySelector('[data-name="add-color"]')?.focus();}
        },

        $focusColor: function(id, name = 'edit-color') {
            const row = Array.from(this.$Colors.querySelectorAll('[data-name="' + name + '"]'))
                .find((node) => node.dataset.colorId === id);
            if (row?.getClientRects().length) {row.focus();}
        },

        $editColor: function(id, anchor) {
            this.$Popup?.destroy();
            const color = id ? this.$palette.colors[id] : {value: '#000000'};
            const named = !id || !Object.hasOwn(this.$defaults, id);
            const generation = this.$generation;
            this.$Popup = new ColorPopup({
                labels: ColorProvider.labels(),
                label: id ? ColorProvider.names(this.$palette.colors)[id] : ColorProvider.text('addOwn'),
                value: ColorValue.field(color),
                dark: true,
                clearable: false,
                editableName: named,
                colorName: color.name || '',
                removable: Boolean(id && named && !color.archived),
                defaultcolor: named ? false : ColorValue.field(this.$defaults[id]),
                events: {
                    onChange: (Popup, value, name) => {
                        if (generation !== this.$generation) {return;}
                        const entry = ColorValue.entry(value);
                        if (!id) {
                            this.$provider.addColor(name, entry).then((result) => {
                                if (generation === this.$generation) {this.$focusColor(result.id);}
                            }).catch(() => {
                                if (generation === this.$generation) {this.$showError();}
                            });
                            return;
                        }
                        const next = Object.assign({}, this.$palette.colors[id]);
                        delete next.value;
                        delete next.light;
                        delete next.dark;
                        Object.assign(next, entry);
                        if (named) {next.name = name;}
                        this.$palette.colors[id] = next;
                        this.$provider.changed();
                    },
                    onRemove: () => {
                        if (generation !== this.$generation) {return;}
                        this.$palette.colors[id].archived = true;
                        this.$provider.changed();
                        this.$Colors.querySelector('[data-name="add-color"]')?.focus();
                    }
                }
            });
            this.$Popup.open(anchor);
        },

        $showError: function() {
            if (!this.$Status) {
                this.$Status = this.$element(this.$Management, 'p');
                this.$Status.setAttribute('role', 'alert');
            }
            this.$Status.textContent = ColorProvider.text('error');
        }
    });
});
