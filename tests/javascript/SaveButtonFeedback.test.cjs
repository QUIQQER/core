const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');
const {Node} = require('./fixtures/ManagerPanelToolbar.cjs');

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
}

class Element extends Node {
    get firstElementChild() { return this.children[0]; }
    removeAttribute(name) { delete this.attributes[name]; }
}

function fixture(disabled = false) {
    let api;
    let now = 0;
    let timerId = 0;
    const timers = new Map();
    const events = {};
    const element = new Element('button');
    element.appendChild(new Element('span'));
    const options = {text: 'Save', textimage: 'fa fa-floppy-o'};
    const changes = [];
    const Button = {
        getElm: () => element,
        getAttribute: name => options[name],
        setAttribute(name, value) { options[name] = value; changes.push([name, value]); },
        addEvent(name, callback) { events[name] = callback; },
        isDisabled: () => disabled,
        disable() { disabled = true; element.disabled = true; },
        enable() { disabled = false; element.disabled = false; }
    };
    const messages = {'panel.save.pending': 'Saving…', 'panel.save.success': 'Saved', 'panel.save.error': 'Saving failed'};
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/utils/SaveButtonFeedback.js'), 'utf8'), {
        define: (name, dependencies, factory) => { api = factory({get: (group, key) => messages[key]}, {}); },
        document: {createElement: tag => new Element(tag)},
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, {callback, at: now + delay}); return id; },
        clearTimeout: id => timers.delete(id)
    });
    const tick = elapsed => {
        now += elapsed;
        for (const [id, timer] of Array.from(timers)) {
            if (timer.at <= now) { timers.delete(id); timer.callback(); }
        }
    };
    return {saveFeedback: api, Button, element, options, timers, tick, events, changes};
}

test('save feedback coalesces requests, shows a spinner, then a check, then restores the original icon', async () => {
    const f = fixture();
    const request = deferred();
    let calls = 0;
    const operation = () => { calls++; return request.promise; };
    const saving = f.saveFeedback(f.Button, operation);
    assert.equal(f.saveFeedback(f.Button, operation), saving);
    assert.equal(f.options.textimage, 'fa fa-circle-o-notch fa-spin');
    assert.equal(f.element.disabled, true);
    assert.equal(f.element.getAttribute('aria-busy'), 'true');
    assert.equal(f.element.children[1].getAttribute('role'), 'status');
    assert.equal(f.element.children[1].textContent, 'Saving…');
    await Promise.resolve();
    assert.equal(calls, 1);
    request.resolve('saved-data');
    assert.equal(await saving, 'saved-data');
    assert.equal(f.options.textimage, 'fa fa-check');
    assert.equal(f.element.disabled, false);
    assert.equal(f.element.getAttribute('data-save-state'), 'success');
    assert.equal(f.element.children[1].textContent, 'Saved');
    f.tick(2499);
    assert.equal(f.options.textimage, 'fa fa-check');
    f.tick(1);
    assert.equal(f.options.textimage, 'fa fa-floppy-o');
    assert.equal(f.options.text, 'Save');
    assert.equal(f.element.getAttribute('title'), 'Save');
    assert.equal(f.element.getAttribute('aria-label'), null);
    assert.equal(f.element.getAttribute('aria-busy'), null);
    assert.equal(f.element.children[1].textContent, '');
});

test('a failed save preserves its rejection, shows a bolt and allows retry', async () => {
    const f = fixture();
    const failure = new Error('Save denied');
    await assert.rejects(f.saveFeedback(f.Button, () => Promise.reject(failure)), error => error === failure);
    assert.equal(f.options.textimage, 'fa fa-bolt');
    assert.equal(f.element.getAttribute('data-save-state'), 'error');
    assert.equal(f.element.getAttribute('title'), 'Saving failed');
    assert.equal(f.element.disabled, false);
    f.tick(2500);
    assert.equal(f.options.textimage, 'fa fa-floppy-o');
});

test('a new save cancels the previous reset timer and keeps its spinner until the request settles', async () => {
    const f = fixture();
    await f.saveFeedback(f.Button, () => true);
    f.tick(1000);
    const second = deferred();
    const saving = f.saveFeedback(f.Button, () => second.promise);
    f.tick(3000);
    assert.equal(f.options.textimage, 'fa fa-circle-o-notch fa-spin');
    assert.equal(f.element.disabled, true);
    second.resolve();
    await saving;
    f.tick(2499);
    assert.equal(f.options.textimage, 'fa fa-check');
    f.tick(1);
    assert.equal(f.options.textimage, 'fa fa-floppy-o');
});

test('synchronous preparation errors also show failure and release the button', async () => {
    const f = fixture();
    const failure = new Error('Invalid form');
    await assert.rejects(f.saveFeedback(f.Button, () => { throw failure; }), error => error === failure);
    assert.equal(f.options.textimage, 'fa fa-bolt');
    assert.equal(f.element.disabled, false);
});

test('closing the panel during a save prevents late DOM updates without cancelling the request', async () => {
    const f = fixture();
    const request = deferred();
    const saving = f.saveFeedback(f.Button, () => request.promise);
    f.events.destroy();
    const changes = f.changes.length;
    request.resolve('saved-after-close');
    assert.equal(await saving, 'saved-after-close');
    assert.equal(f.changes.length, changes);
    assert.equal(f.timers.size, 0);
});

test('destroy clears the feedback timer and an already disabled button stays disabled', async () => {
    const f = fixture(true);
    await f.saveFeedback(f.Button, () => true);
    assert.equal(f.element.disabled, true);
    assert.equal(f.timers.size, 1);
    f.events.destroy();
    assert.equal(f.timers.size, 0);
});

test('saving without a mounted button still returns the operation result', async () => {
    const f = fixture();
    assert.equal(await f.saveFeedback(null, () => 42), 42);
});

test('a save cannot re-enable a button after its editing permission has been lost', async () => {
    const f = fixture();
    let permitted = true;
    const request = deferred();
    const pending = f.saveFeedback(f.Button, () => request.promise, {canEnable: () => permitted});
    permitted = false;
    request.resolve();
    await pending;
    assert.equal(f.element.disabled, true);
});

function panelFixture(entity, {security = false, preparationError} = {}) {
    const f = fixture();
    let definition;
    const password = deferred();
    const request = deferred();
    const calls = [];
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/' + entity + 's/' +
        (entity === 'user' ? 'User' : 'Group') + '.js'), 'utf8'), {
        Class: function(value) { return value; },
        define: (name, dependencies, factory) => {
            definition = factory(...dependencies.map(name => name === 'utils/SaveButtonFeedback' ? f.saveFeedback : {}));
        }
    });
    const model = {save() { calls.push('save'); return request.promise; }};
    const prepare = () => { calls.push('prepare'); if (preparationError) throw preparationError; };
    const panel = {
        ...definition,
        $savePromise: null,
        getButtons: () => f.Button,
        getActiveCategory: () => ({getAttribute: () => security ? 'security' : 'details'}),
        $onButtonNormal: prepare,
        $onCategoryUnload: prepare,
        savePassword() { calls.push('password'); return password.promise; },
        getUser: () => model,
        getGroup: () => model,
        Loader: {show: () => calls.push('show'), hide: () => calls.push('hide')}
    };
    return {...f, panel, calls, password, request, save: () => entity === 'user' ? panel.$onClickSave() : panel.save()};
}

for (const entity of ['user', 'group']) {
    test(entity + ' panel shares pending saves and displays feedback for the actual model result', async () => {
        const f = panelFixture(entity);
        const pending = f.save();
        assert.equal(f.save(), pending);
        assert.equal(f.options.textimage, 'fa fa-circle-o-notch fa-spin');
        await Promise.resolve();
        await Promise.resolve();
        assert.equal(f.calls.filter(call => call === 'save').length, 1);
        f.request.resolve(true);
        assert.equal(await pending, true);
        assert.equal(f.panel.$savePromise, null);
        assert.equal(f.options.textimage, 'fa fa-check');
        if (entity === 'user') assert.equal(f.calls.at(-1), 'hide');
    });

    test(entity + ' panel catches preparation failures before contacting the server', async () => {
        const failure = new Error('Invalid input');
        const f = panelFixture(entity, {preparationError: failure});
        await assert.rejects(f.save(), error => error === failure);
        assert.ok(!f.calls.includes('save'));
        assert.equal(f.options.textimage, 'fa fa-bolt');
        assert.equal(f.panel.$savePromise, null);
    });
}

test('user feedback waits for password saving and avoids a user save when the password fails', async () => {
    const f = panelFixture('user', {security: true});
    const pending = f.save();
    await Promise.resolve();
    assert.ok(f.calls.includes('password'));
    assert.ok(!f.calls.includes('save'));
    assert.equal(f.options.textimage, 'fa fa-circle-o-notch fa-spin');
    const failure = new Error('Password rejected');
    const rejection = assert.rejects(pending, error => error === failure);
    f.password.reject(failure);
    await rejection;
    assert.ok(!f.calls.includes('save'));
    assert.equal(f.options.textimage, 'fa fa-bolt');
    assert.equal(f.calls.at(-1), 'hide');
});

test('user feedback only reports success after both password and user data have saved', async () => {
    const f = panelFixture('user', {security: true});
    const pending = f.save();
    f.password.resolve();
    await Promise.resolve();
    await Promise.resolve();
    assert.ok(f.calls.includes('save'));
    assert.equal(f.options.textimage, 'fa fa-circle-o-notch fa-spin');
    f.request.resolve();
    await pending;
    assert.equal(f.options.textimage, 'fa fa-check');
});

function groupModelFixture() {
    let definition;
    let request;
    const events = [];
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/classes/groups/Group.js'), 'utf8'), {
        JSON: {encode: JSON.stringify},
        Class: function(value) { return value; },
        require: (dependencies, callback) => callback({refreshGroup() {}}),
        define: (name, dependencies, factory) => {
            definition = factory({}, {combine: (first, second) => Object.assign(first || {}, second)}, {
                post: (endpoint, callback, params) => { request = {endpoint, callback, params}; }
            });
        }
    });
    const model = {...definition, getId: () => 42, getAttributes: () => ({name: 'Example'}),
        fireEvent: name => events.push(name)};
    return {model, events, request: () => request};
}

test('group model rejects Ajax failures and preserves the supplied error callback', async () => {
    const f = groupModelFixture();
    let reported;
    const saving = f.model.save(undefined, {onError: error => { reported = error; }});
    const failure = new Error('Permission denied');
    const rejected = assert.rejects(saving, error => error === failure);
    f.request().params.onError(failure);
    await rejected;
    assert.equal(reported, failure);
    assert.deepEqual(f.events, []);
});

test('group model retains its successful save callback and refresh event', async () => {
    const f = groupModelFixture();
    let saved;
    const saving = f.model.save(model => { saved = model; });
    assert.equal(f.request().endpoint, 'ajax_groups_save');
    assert.equal(f.request().params.gid, 42);
    f.request().callback();
    assert.equal(await saving, f.model);
    assert.equal(saved, f.model);
    assert.deepEqual(f.events, ['refresh']);
});

function sitePanelFixture() {
    const f = fixture();
    const validation = deferred();
    const request = deferred();
    const reload = deferred();
    const calls = [];
    const errors = [];
    const form = {};
    const attributes = {title: 'Home'};
    const category = {};
    let definition;
    const dependencies = {
        'utils/SaveButtonFeedback': f.saveFeedback,
        'qui/utils/Form': {setDataToForm: (data, target) => calls.push(['form', data, target])}
    };
    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/projects/project/site/Panel.js'), 'utf8'), {
        Class: function(value) { return value; },
        console: {error: error => errors.push(error)},
        define: (name, names, factory) => {
            definition = factory(...names.map(name => dependencies[name] || {}));
        }
    });
    const model = {
        save(callback, token) { calls.push(['save', callback, token]); return request.promise; },
        getAttributes: () => attributes
    };
    let deleted = false;
    const panel = {
        ...definition,
        $savePromise: null,
        $ownsEditingLock: true,
        $lockDestroyed: false,
        $lockToken: 'editing-lock-token',
        $isDeleted: () => deleted,
        getButtons: name => { assert.equal(name, 'save'); return f.Button; },
        getSite: () => model,
        getActiveCategory: () => category,
        $onCategoryLeave(active) { assert.equal(active, category); return validation.promise; },
        $Container: {getElement: () => form},
        load() { calls.push(['reload']); return reload.promise; },
        $refreshEditingLock: () => calls.push(['refresh-lock']),
        Loader: {hide: () => calls.push(['hide'])}
    };
    return {...f, panel, validation, request, reload, calls, errors, form, attributes,
        deleteSite: () => { deleted = true; }};
}

test('site save waits for category validation, model saving and reload while preserving its editing token', async () => {
    const f = sitePanelFixture();
    const pending = f.panel.save();
    assert.equal(f.panel.save(), pending);
    assert.equal(f.options.textimage, 'fa fa-circle-o-notch fa-spin');
    await Promise.resolve();
    assert.deepEqual(f.calls, []);
    f.validation.resolve();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(f.calls, [['save', null, 'editing-lock-token']]);
    f.request.resolve();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(f.calls, [['save', null, 'editing-lock-token'],
        ['form', f.attributes, f.form], ['reload']]);
    assert.equal(f.options.textimage, 'fa fa-circle-o-notch fa-spin');
    f.reload.resolve('reloaded');
    assert.equal(await pending, 'reloaded');
    assert.equal(f.options.textimage, 'fa fa-check');
    assert.equal(f.element.disabled, false);
    assert.equal(f.panel.$savePromise, null);
    f.tick(2500);
    assert.equal(f.options.textimage, 'fa fa-floppy-o');
});

for (const stage of ['validation', 'request', 'reload']) {
    test('site ' + stage + ' failure displays feedback and retains lock recovery', async () => {
        const f = sitePanelFixture();
        const failure = new Error(stage + ' failed');
        const pending = f.panel.save();
        f.validation.resolve();
        if (stage === 'validation') {
            // A synchronous category failure must use the same feedback path.
            f.panel.$onCategoryLeave = () => { throw failure; };
        } else {
            await Promise.resolve();
            await Promise.resolve();
            if (stage === 'request') f.request.reject(failure);
            else { f.request.resolve(); f.reload.reject(failure); }
        }
        assert.equal(await pending, undefined);
        assert.equal(f.options.textimage, 'fa fa-bolt');
        assert.deepEqual(f.errors, [failure]);
        assert.deepEqual(f.calls.slice(-2), [['refresh-lock'], ['hide']]);
        assert.equal(f.panel.$savePromise, null);
        if (stage === 'validation') assert.ok(!f.calls.some(call => call[0] === 'save'));
    });
}

test('deleted or locked sites do not start saving or display save feedback', async () => {
    for (const blocked of ['deleted', 'locked']) {
        const f = sitePanelFixture();
        if (blocked === 'deleted') f.deleteSite();
        else f.panel.$ownsEditingLock = false;
        await f.panel.save();
        assert.deepEqual(f.calls, []);
        assert.equal(f.options.textimage, 'fa fa-floppy-o');
        assert.equal(f.timers.size, 0);
    }
});

test('a site save cannot re-enable editing after lock loss, deletion or panel destruction', async () => {
    for (const lost of ['lock', 'deleted', 'destroyed']) {
        const f = sitePanelFixture();
        const pending = f.panel.save();
        f.validation.resolve();
        f.request.resolve();
        f.reload.resolve();
        if (lost === 'lock') f.panel.$ownsEditingLock = false;
        if (lost === 'deleted') f.deleteSite();
        if (lost === 'destroyed') f.panel.$lockDestroyed = true;
        await pending;
        assert.equal(f.element.disabled, true);
    }
});
