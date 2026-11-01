/** Core adapters: persisted read-only palette and form-owned editable drafts. */
define('controls/projects/project/ColorProvider', [
    'Ajax', 'Locale', 'Projects'
], function(Ajax, Locale, Projects) {
    'use strict';
    const prefix = 'projects.colors.';
    const clone = (value) => JSON.parse(JSON.stringify(value));

    return {
        text: function(key) {return Locale.get('quiqqer/core', prefix + key);},

        labels: function() {
            const keys = ['color', 'own', 'linked', 'copy', 'light', 'dark', 'different', 'clear', 'reset',
                'add', 'remove', 'name', 'builtin', 'custom', 'archived', 'removeHelp', 'confirmRemove',
                'invalid', 'error', 'empty', 'loading', 'apply', 'cancel', 'back', 'unlink', 'chooseVariant',
                'merge', 'hue', 'saturation', 'brightness', 'alpha', 'surfaceHelp', 'more', 'advanced',
                'single', 'pair', 'projectColor', 'individual', 'resetHelp', 'clearHelp',
                'copyValue', 'copyLight', 'copyDark', 'copied', 'copyError', 'linkedHelp',
                'mergeTitle', 'mergeHelp', 'keepLight', 'keepDark', 'keepPair',
                'format', 'showNames', 'brand', 'status', 'neutral', 'content', 'other'];
            return Object.fromEntries(keys.map((key) => [key, this.text(key)]));
        },

        names: function(colors) {
            return Object.fromEntries(Object.entries(colors).map(([id, entry]) => [id, entry.name || this.text('name.' + id)]));
        },

        normalize: function(saved, defaults) {
            const colors = clone(defaults);
            if (![1, 2].includes(saved.version) || !saved.colors) {throw new Error('Invalid palette');}
            Object.entries(saved.colors).forEach(([id, color]) => {
                colors[id] = saved.version === 1 && !Object.hasOwn(color, 'dark') ? {value: color.light} : clone(color);
            });
            if (saved.version === 1) {
                saved.favorites.forEach((value) => {
                    const id = 'custom-legacy-' + value.slice(1).toLowerCase();
                    if (!colors[id]) {colors[id] = {value: value, name: value};}
                });
            }
            const palette = {version: 2, colors: colors};
            if (saved.source) {palette.source = clone(saved.source);}
            return palette;
        },

        create: function(Project) {
            const project = Project.getName();
            return {
                labels: this.labels(),
                load: () => new Promise((resolve, reject) => {
                    Ajax.get('ajax_project_colors_get', (palette) => {
                        palette.names = this.names(palette.colors);
                        resolve(palette);
                    }, {project: project, onError: reject});
                }),
                subscribe: (listener) => {
                    const saved = (ChangedProject) => {
                        if (ChangedProject.getName() === project) {listener();}
                    };
                    Projects.addEvent('projectSave', saved);
                    return () => Projects.removeEvent('projectSave', saved);
                }
            };
        },

        /** The owner writes this draft through its existing Save action; no Ajax writes here. */
        draft: function(palette, onChange) {
            const listeners = new Set();
            return {
                load: () => Promise.resolve(clone(palette)),
                subscribe: (listener) => {listeners.add(listener); return () => listeners.delete(listener);},
                changed: () => {
                    onChange(palette);
                    listeners.forEach((listener) => listener());
                },
                addColor: function(name, color) {
                    if (!name.trim() || name.length > 100 || Object.keys(palette.colors).length >= 512) {
                        return Promise.reject(new Error('Invalid project color'));
                    }
                    const id = 'custom-' + crypto.randomUUID();
                    palette.colors[id] = Object.assign({}, color, {name: name.trim()});
                    this.changed();
                    return Promise.resolve({id: id, palette: clone(palette)});
                }
            };
        }
    };
});
