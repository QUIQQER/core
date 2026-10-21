const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {webcrypto} = require('node:crypto');
const {test} = require('node:test');
const vm = require('node:vm');

function serviceFixture() {
    const cookies = new Map();
    const calls = [];
    const timers = new Map();
    const state = {blocked: false, throws: false, validSession: true, networkError: false, stalled: false};
    const document = {
        get cookie() {
            if (state.throws) throw new Error('access denied');
            return Array.from(cookies, ([key, value]) => key + '=' + value).join('; ');
        },
        set cookie(value) {
            if (state.throws) throw new Error('access denied');
            if (state.blocked) return;
            const [name, content] = value.split(';')[0].split('=');
            if (value.includes('Max-Age=0')) cookies.delete(name);
            else cookies.set(name, content);
        }
    };
    let service;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/utils/LoginCheck.js'), 'utf8'), {
        document, crypto: webcrypto, Uint32Array, location: {protocol: 'https:'},
        setTimeout: callback => { const id = {}; timers.set(id, callback); return id; },
        clearTimeout: id => timers.delete(id),
        define(name, dependencies, factory) {
            service = factory({get(call, callback, params) {
                calls.push({call, params});
                if (state.stalled) return;
                queueMicrotask(() => {
                    if (state.networkError) params.onError(new Error('offline'));
                    else callback(params.token === 'start' ? 'a'.repeat(64) : state.validSession);
                });
            }});
        }
    });
    return {service, cookies, calls, state, timers};
}

test('checks the actual cookie store and confirms the session in two sequential requests', async () => {
    const {service, cookies, calls, timers} = serviceFixture();
    await service.check();
    assert.equal(cookies.size, 0);
    assert.equal(calls.length, 2);
    assert.equal(calls[0].params.token, 'start');
    assert.equal(calls[1].params.token, 'a'.repeat(64));
    assert.equal(calls[0].params.showError, false);
    assert.equal(calls[0].params.showLogin, false);
    assert.equal(calls[0].params.bundle, false);
    assert.equal(timers.size, 0);
});

test('blocked cookies stop before any server request and can be checked again', async () => {
    const {service, calls, state} = serviceFixture();
    state.blocked = true;
    await assert.rejects(service.check(), error => error.loginCheck === 'cookies');
    assert.equal(calls.length, 0);
    state.blocked = false;
    await service.check();
    assert.equal(calls.length, 2);
});

test('cookie access exceptions produce a cookie message', async () => {
    const {service, calls, state} = serviceFixture();
    state.throws = true;
    await assert.rejects(service.check(), error => error.loginCheck === 'cookies');
    assert.equal(calls.length, 0);
});

test('a session that does not persist is distinguished from blocked JavaScript cookies', async () => {
    const {service, state, cookies} = serviceFixture();
    state.validSession = false;
    await assert.rejects(service.check(), error => error.loginCheck === 'session');
    assert.equal(cookies.size, 0);
});

test('network errors are distinguished from session errors and are retryable', async () => {
    const {service, state} = serviceFixture();
    state.networkError = true;
    await assert.rejects(service.check(), error => error.loginCheck === 'connection');
    state.networkError = false;
    await service.check();
});

test('a stalled request times out and cannot leave all future checks pending', async () => {
    const {service, state, timers} = serviceFixture();
    state.stalled = true;
    const pending = service.check();
    const rejection = assert.rejects(pending, error => error.loginCheck === 'connection');
    await new Promise(resolve => setImmediate(resolve));
    for (const callback of timers.values()) callback();
    await rejection;
    state.stalled = false;
    await service.check();
});

test('concurrent controls share the running check, but later submissions get a fresh check', async () => {
    const {service, calls} = serviceFixture();
    const first = service.check();
    assert.equal(service.check(), first);
    await first;
    assert.equal(calls.length, 2);
    await service.check();
    assert.equal(calls.length, 4);
});

function loginFixture(check) {
    class Element extends EventTarget {
        constructor(tagName) {
            super();
            this.tagName = tagName;
            this.dataset = {};
            this.children = [];
            this.attributes = {};
            this.style = {};
        }
        setAttribute(name, value) { this.attributes[name] = value; }
        removeAttribute(name) { delete this.attributes[name]; }
        append(...children) { children.forEach(child => { child.parentNode = this; this.children.push(child); }); }
        appendChild(child) { this.append(child); return child; }
        prepend(child) { child.parentNode = this; this.children.unshift(child); }
        remove() { this.parentNode.children = this.parentNode.children.filter(child => child !== this); }
        querySelector(selector) {
            const name = selector.match(/data-name="([^"]+)"/)[1];
            for (const child of this.children) {
                if (child.dataset.name === name) return child;
                const nested = child.querySelector(selector);
                if (nested) return nested;
            }
            return null;
        }
    }
    const root = new Element('div');
    const container = new Element('div');
    container.dataset.name = 'quiqqer-users-login-container';
    root.append(container);
    const calls = [];
    const events = [];
    let definition;
    let succeeded = 0;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/users/Login.js'), 'utf8'), {
        document: {createElement: name => new Element(name)}, window: {}, JSON: {...JSON, encode: JSON.stringify},
        Class: function (value) { definition = value; return value; },
        define(name, dependencies, factory) {
            factory(...dependencies.map(dependency => ({
                'qui/QUI': {fireEvent: name => events.push(name)},
                'qui/utils/Form': {getFormData: () => ({password: 'secret'})},
                'utils/LoginCheck': {check},
                'Locale': {get: (group, key) => key},
                'Ajax': {post(call, callback, params) { calls.push({call, params}); callback({loggedIn: true, user: {}}); }}
            })[dependency] || {}));
        }
    });
    const login = {
        ...definition, $forms: [], $authStep: 'primary',
        getElm: () => root,
        getAttribute: name => name === 'onSuccess' ? () => succeeded++ : name === 'showLoader',
        Loader: {visible: false, show() { this.visible = true; }, hide() { this.visible = false; }},
        fireEvent: name => events.push(name),
        $handleLoginResponse: response => Promise.resolve(response)
    };
    const form = {get: () => 'QUI\\Users\\Auth\\QUIQQER'};
    return {login, form, root, container, calls, events, succeeded: () => succeeded};
}

test('failed preflight never sends credentials and keeps a visible retry outside the inert form', async () => {
    let blocked = true;
    const fixture = loginFixture(() => blocked
        ? Promise.reject(Object.assign(new Error('cookies'), {loginCheck: 'cookies'}))
        : Promise.resolve());
    const {login, form, root, container, calls} = fixture;
    await assert.rejects(login.auth(form));
    assert.equal(calls.length, 0);
    assert.equal(container.inert, true);
    assert.equal(root.dataset.loginCheck, 'failed');
    assert.equal(login.Loader.visible, false);
    const notice = root.querySelector('[data-name="login-check-notice"]');
    const message = notice.querySelector('[data-name="login-check-message"]');
    assert.equal(message.attributes.role, 'alert');
    assert.equal(message.textContent, 'login.check.cookies');
    const retry = notice.querySelector('[data-name="login-check-retry"]');
    assert.equal(retry.type, 'button');
    blocked = false;
    retry.dispatchEvent(new Event('click'));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(root.querySelector('[data-name="login-check-notice"]'), null);
    assert.equal(container.inert, false);
    assert.equal(root.dataset.loginCheck, undefined);
    assert.equal(fixture.events.includes('loginCheckSuccess'), true);
    assert.equal(calls.length, 0, 'retry must not automatically submit credentials');
    await login.auth(form);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].call, 'ajax_users_login');
    assert.equal(fixture.succeeded(), 1);
});

test('authentication waits for successful preflight and preserves the authentication step', async () => {
    let confirm;
    const {login, form, container, calls} = loginFixture(() => new Promise(resolve => { confirm = resolve; }));
    login.$authStep = 'secondary';
    const pending = login.auth(form);
    assert.equal(calls.length, 0);
    assert.equal(container.inert, true);
    confirm();
    await pending;
    assert.equal(calls[0].params.authStep, 'secondary');
    assert.deepEqual(JSON.parse(calls[0].params.params), {password: 'secret'});
});

test('destroying a control during preflight prevents a late credential submission', async () => {
    let confirm;
    const {login, form, calls} = loginFixture(() => new Promise(resolve => { confirm = resolve; }));
    const pending = login.auth(form);
    const rejected = assert.rejects(pending, error => error.loginCheck === 'cancelled');
    login.$loginCheckDestroyed = true;
    confirm();
    await rejected;
    assert.equal(calls.length, 0);
});

test('browser diagnostics are not forwarded as credential errors to frontend consumers', async () => {
    const {login, events} = loginFixture(() => Promise.reject(Object.assign(new Error('cookies'), {loginCheck: 'cookies'})));
    const form = new EventTarget();
    login.$forms = [form];
    login.$refreshForm();
    form.dispatchEvent(new Event('submit', {cancelable: true}));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(events.includes('userLoginError'), false);
    assert.equal(events.includes('quiqqerUserAuthLoginUserLoginError'), false);
});
