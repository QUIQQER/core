const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture(attributes = {}, options = {}, importInput = false) {
    const popups = [];
    const requests = [];
    const events = [];
    const lifecycle = {};
    let definition;

    class Element {
        constructor(tag, attrs = {}) {
            this.attributes = {...attrs};
            this.value = '';
            this.children = [];
            this.dataset = {};
            this.listeners = {};
            this.isConnected = true;
            this.style = {removeProperty() {}};
            this.classList = {add() {}};
        }
        getAttribute(name) { return this.attributes[name] ?? null; }
        setAttribute(name, value) { this.attributes[name] = value; }
        removeAttribute(name) { delete this.attributes[name]; }
        set(name, value) { this.setAttribute(name, value); }
        setStyles() {}
        setStyle() {}
        inject(parent) { parent.children.push(this); return this; }
        wraps(input) { this.children.push(input); }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        dispatchEvent(event) { events.push(event); }
        focus() { this.focused = true; }
    }

    class Button {
        constructor(config) { this.config = config; this.element = new Element('button'); }
        inject(parent) { this.element.inject(parent); return this; }
        getElm() { return this.element; }
        disable() { this.element.disabled = true; }
        enable() { this.element.disabled = false; }
        click() { this.config.events.onClick(); }
    }

    class Popup {
        constructor(config) { this.config = config; popups.push(this); }
        open() { this.opened = true; }
        close() { this.closed = true; this.config.events.onClose(); }
        submit(data) { this.close(); this.config.events.onSubmit(this, data); }
    }

    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/projects/project/site/Input.js'), 'utf8'), {
        define: (_name, _deps, factory) => {
            definition = factory({}, Button, Popup, {
                getUrlParams: value => Object.fromEntries(new URLSearchParams(value.split('?')[1]))
            }, {get: (_group, key) => key});
        },
        Class: function (config) { return config; },
        Element,
        Event,
        MutationObserver: class {
            constructor(callback) { this.callback = callback; }
            observe() {}
            disconnect() { this.disconnected = true; }
        },
        require: (_dependencies, success, failure) => requests.push({success, failure}),
        console: {error() {}}
    });

    const input = new Element('input', attributes);
    const control = Object.create(definition);
    control.attributes = {...definition.options, ...options};
    control.parent = () => {};
    control.getAttribute = key => control.attributes[key];
    control.setAttribute = (key, value) => { control.attributes[key] = value; };
    control.getId = () => 'site-input-test';
    control.addEvents = callbacks => Object.assign(lifecycle, callbacks);
    control.fireEvent = (name, args) => events.push({type: name, args});
    control.initialize(options, input);

    if (importInput) {
        control.$Elm = input;
        control.$onImport();
    } else {
        control.create();
    }

    return {control, input, events, popups, requests, lifecycle, Popup};
}

test('existing site inputs have no media button and keep focus selection', () => {
    const {control, input, popups} = fixture();
    assert.equal(control.$MediaButton, undefined);
    input.listeners.focus();
    assert.equal(popups.length, 1);
});

test('class parser and data-qui imports both honor media and external flags', () => {
    for (const imported of [false, true]) {
        for (const value of ['0', '1', 'false', 'true']) {
            const enabled = value === '1' || value === 'true';
            const {control, input} = fixture({
                'data-qui-options-media': value,
                'data-qui-options-external': value
            }, {}, imported);
            assert.equal(!!control.$MediaButton, enabled);
            assert.equal(!!input.listeners.focus, !enabled);
            assert.equal(control.$Input, input);
        }
    }
});

test('media selection uses the current project, preserves references and emits events once', () => {
    const {control, input, requests, popups, events, Popup} = fixture({'data-qui-options-media': '1'});
    control.setProject({getName: () => 'edited-project', getLang: () => 'de'});
    control.$MediaButton.click();
    control.$MediaButton.click();
    assert.equal(requests.length, 1);
    requests[0].success(Popup);
    assert.equal(popups[0].config.project, 'edited-project');
    assert.equal(popups[0].config.lang, 'de');
    const url = 'image.php?project=edited-project&id=42';
    popups[0].submit({url, type: 'file'});
    assert.equal(input.value, url);
    assert.deepEqual(events.map(event => event.type), ['select', 'input', 'change']);
    assert.equal(events[0].args[0], url);
    assert.equal(events[2].bubbles, false);
    assert.equal(control.$MediaButton.getElm().focused, true);
});

test('reopening reads the current reference, not a stale value or the wrong language', () => {
    const {control, input, popups, requests, Popup} = fixture({}, {media: true});
    control.setProject({getName: () => 'default', getLang: () => 'de'});
    input.value = 'index.php?project=other&lang=fr&id=10';
    control.$SiteButton.click();
    assert.equal(popups[0].config.project, 'other');
    assert.equal(popups[0].config.lang, 'fr');
    popups[0].close();
    input.value = 'image.php?project=media-project&id=55';
    control.$MediaButton.click();
    requests[0].success(Popup);
    assert.equal(popups[1].config.project, 'media-project');
    assert.equal(popups[1].config.fileid, '55');
});

test('cancel preserves the value and clearing uses the existing remove event', () => {
    const {control, input, events, popups} = fixture();
    input.value = 'https://example.com/document.pdf';
    control.$SiteButton.click();
    popups[0].close();
    assert.equal(input.value, 'https://example.com/document.pdf');
    assert.equal(events.length, 0);
    control.$ClearButton.click();
    assert.equal(input.value, '');
    assert.deepEqual(events.map(event => event.type), ['remove', 'input', 'change']);
});

test('disabled and readonly inputs cannot select or clear values', () => {
    for (const property of ['disabled', 'readOnly']) {
        const {control, input, popups, requests} = fixture({}, {media: true});
        input.value = 'unchanged';
        input[property] = true;
        control.$stateObserver.callback();
        assert.equal(control.$MediaButton.getElm().disabled, true);
        control.$SiteButton.click();
        control.$MediaButton.click();
        control.$ClearButton.click();
        assert.equal(popups.length + requests.length, 0);
        assert.equal(input.value, 'unchanged');
    }
});

test('pending module loads and popup replies cannot update destroyed or changed inputs', () => {
    for (const mutation of ['destroy', 'detach', 'change', 'readonly']) {
        for (const loaded of [false, true]) {
            const {control, input, requests, popups, lifecycle, Popup} = fixture({}, {media: true});
            input.value = 'old';
            control.$MediaButton.click();
            if (loaded) { requests[0].success(Popup); }
            if (mutation === 'destroy') { lifecycle.onDestroy(); }
            if (mutation === 'detach') { input.isConnected = false; }
            if (mutation === 'change') { input.value = 'edited'; }
            if (mutation === 'readonly') { input.readOnly = true; }
            if (loaded) { popups[0].submit({url: 'image.php?project=test&id=1'}); }
            else { requests[0].success(Popup); }
            assert.equal(input.value, mutation === 'change' ? 'edited' : 'old');
            if (!loaded) { assert.equal(popups.length, 0); }
        }
    }
});

test('failed media module loads allow a retry', () => {
    const {control, requests} = fixture({}, {media: true});
    control.$MediaButton.click();
    requests[0].failure(new Error('load failed'));
    control.$MediaButton.click();
    assert.equal(requests.length, 2);
});
