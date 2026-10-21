const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    let definition;
    let editor;
    const events = {};
    const attributes = {};
    const content = {style: {}, setAttribute(name, value) { attributes[name] = value; }};
    const root = {setAttribute() {}};
    const form = {addEventListener(name, callback) { this[name] = callback; }};
    const focus = {isConnected: true, count: 0, focus() { this.count++; }};
    const timers = new Map();
    const buttons = Object.fromEntries(['cancel', 'submit'].map(name => [name, {
        element: {},
        disable() { this.disabled = true; },
        enable() { this.disabled = false; },
        getElm() { return this.element; }
    }]));
    const dependencies = {
        Locale: {get: (group, key) => key},
        'controls/users/Address': class {
            constructor(options) { this.options = options; this.saves = 0; this.destroyed = 0; editor = this; }
            inject() {}
            save() { this.saves++; }
            destroy() { this.destroyed++; }
            getElm() {
                return {querySelector: selector => selector === '[data-name="address-form"]' ? form : focus};
            }
        }
    };
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/users/address/EditWindow.js'), 'utf8'), {
        document: {activeElement: focus},
        window: {
            setTimeout(callback) { timers.set(1, callback); return 1; },
            clearTimeout(id) { timers.delete(id); }
        },
        Class: function (value) { definition = value; return value; },
        define(name, names, factory) { factory(...names.map(name => dependencies[name] ?? {})); }
    });
    const dialog = {
        ...definition,
        parent(options) { this.attributes = options; },
        addEvents(value) { Object.assign(events, value); },
        getAttribute(name) { return this.attributes[name]; },
        getElm: () => root,
        getContent: () => content,
        getButton: name => buttons[name],
        Loader: {show() {}, hide() {}},
        saved: 0,
        closed: 0,
        fireEvent(name) { if (name === 'saved') { this.saved++; } },
        close() { this.closed++; events.onCloseBegin(); events.onClose(); },
        submit() { events.onSubmit(); }
    };
    dialog.initialize({uid: 'user-uuid', addressId: 'address-uuid'});
    events.onOpen();
    return {
        dialog, editor, buttons, form, events, focus, attributes,
        loaded() { editor.options.events.onLoaded(editor); },
        flushFocus() { timers.get(1)?.(); }
    };
}

test('the standalone Confirm loads the selected address before allowing a save', () => {
    const {dialog, editor, buttons, attributes, loaded, flushFocus, focus} = fixture();
    assert.equal(dialog.attributes.autoclose, false);
    assert.equal(dialog.attributes.ok_button.text, 'save');
    assert.equal(editor.options.uid, 'user-uuid');
    assert.equal(editor.options.addressId, 'address-uuid');
    assert.equal(buttons.submit.disabled, true);
    assert.equal(buttons.cancel.element.tabIndex, 0);
    dialog.submit();
    assert.equal(editor.saves, 0);
    loaded();
    assert.equal(buttons.submit.disabled, false);
    assert.equal(attributes['aria-busy'], 'false');
    flushFocus();
    assert.equal(focus.count, 1);
});

test('failed saves keep edits open and allow retry; success closes only after saving', () => {
    const {dialog, editor, buttons, loaded} = fixture();
    loaded();
    dialog.submit();
    dialog.submit();
    assert.equal(editor.saves, 1);
    assert.equal(buttons.submit.disabled, true);
    assert.equal(dialog.closed, 0);
    editor.options.events.onSaveError();
    assert.equal(buttons.submit.disabled, false);
    assert.equal(dialog.closed, 0);
    assert.equal(dialog.saved, 0);
    dialog.submit();
    assert.equal(editor.saves, 2);
    editor.options.events.onSaved();
    assert.equal(dialog.saved, 1);
    assert.equal(dialog.closed, 1);
    assert.equal(editor.destroyed, 1);
});

test('submitting the address form saves without navigating', () => {
    const {dialog, editor, form, loaded} = fixture();
    loaded();
    let prevented = false;
    form.submit({preventDefault() { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(editor.saves, 1);
    assert.equal(dialog.closed, 0);
});

test('cancel disposes the editor, ignores a late load and restores focus', () => {
    const {dialog, editor, buttons, loaded, flushFocus, focus} = fixture();
    dialog.close();
    loaded();
    flushFocus();
    dialog.submit();
    assert.equal(editor.destroyed, 1);
    assert.equal(editor.saves, 0);
    assert.equal(buttons.submit.disabled, true);
    assert.equal(dialog.saved, 0);
    assert.equal(focus.count, 1);
});

test('closing during a save still reports the successful change to the address list', () => {
    const {dialog, editor, loaded} = fixture();
    loaded();
    dialog.submit();
    dialog.close();
    assert.equal(editor.destroyed, 0);
    editor.options.events.onSaved();
    assert.equal(editor.destroyed, 1);
    assert.equal(dialog.saved, 1);
    assert.equal(dialog.closed, 1);
});

test('a load failure leaves Save disabled and Cancel available', () => {
    const {dialog, editor, buttons, attributes} = fixture();
    editor.options.events.onLoadError();
    assert.equal(buttons.submit.disabled, true);
    assert.notEqual(buttons.cancel.disabled, true);
    assert.equal(attributes['aria-busy'], 'false');
    dialog.submit();
    assert.equal(editor.saves, 0);
});
