const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    const state = {requests: [], logins: [], errors: []};
    let Ajax;
    let id = 0;
    const QUI = {
        $events: {},
        getMessageHandler: () => Promise.resolve({addException: error => state.errors.push(error)}),
        triggerError: error => state.errors.push(error)
    };
    class Request {
        constructor(options) {
            this.options = options;
            this.attributes = {...options};
            state.requests.push(this);
        }
        getAttribute(name) { return this.attributes[name]; }
        setAttribute(name, value) { this.attributes[name] = value; }
        send() {}
    }
    const dependencies = {
        'qui/QUI': QUI,
        'qui/classes/request/Ajax': Request,
        'classes/request/Bundler': class {},
        'qui/utils/Object': {combine: (first, second) => ({...first, ...second})},
        Locale: {getCurrent: () => 'en'}
    };
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/Ajax.js'), 'utf8'), {
        window: {QUIQQER: {inAdministration: true}},
        QUIQQER: {inAdministration: true},
        String: Object.assign(value => String(value), {uniqueID: () => String(++id)}),
        require(names, callback) {
            assert.equal(Array.from(names).join(','), 'controls/users/LoginWindow');
            callback(class {
                constructor(options) { this.options = options; state.logins.push(this); }
                open() {}
            });
        },
        define(name, names, factory) { Ajax = factory(...names.map(name => dependencies[name])); }
    });
    const error = (type, code = 401, attributes = {}) => ({
        getCode: () => code,
        getMessage: () => 'Authentication required',
        getAttribute: name => name === 'type' ? type : (attributes[name] ?? false)
    });
    return {state, Ajax, error};
}

for (const {type, attributes} of [
    {type: 'QUI\\Users\\Exception', attributes: {}},
    {type: 'Example\\Security\\ExpiredToken', attributes: {authenticationRequired: true}}
]) {
    test(`${type} opens login and retries the original call after success`, async () => {
        const {state, Ajax, error} = fixture();
        const callback = () => {};
        const Request = Ajax.get('ajax_site_categories_get', callback, {bundle: false, id: 2});
        Request.options.events.onError(error(type, 401, attributes), Request);
        assert.equal(state.logins.length, 1);
        assert.equal(Request.getAttribute('logout'), true);
        assert.equal(state.requests.length, 1, 'Do not retry before authentication');
        state.logins[0].options.events.onSuccess();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(state.requests.length, 2);
        assert.equal(state.requests[1].getAttribute('_rf'), 'ajax_site_categories_get');
        assert.equal(state.requests[1].getAttribute('id'), 2);
        assert.equal(state.errors.length, 0);
    });
}

test('concurrent authentication failures share one login and resume all calls', async () => {
    const {state, Ajax, error} = fixture();
    for (const call of ['ajax_site_categories_get', 'ajax_site_buttons_get']) {
        const Request = Ajax.get(call, () => {}, {bundle: false});
        Request.options.events.onError(error('Example\\Security\\ExpiredToken', 401, {
            authenticationRequired: true
        }), Request);
    }
    assert.equal(state.logins.length, 1);
    state.logins[0].options.events.onSuccess();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.requests.length, 4);
    assert.deepEqual(state.requests.slice(2).map(request => request.getAttribute('_rf')), [
        'ajax_site_categories_get', 'ajax_site_buttons_get'
    ]);
});

test('closing login leaves rejected calls unsent and permits another login attempt', async () => {
    const {state, Ajax, error} = fixture();
    const fail = () => {
        const Request = Ajax.get('protected', () => {}, {bundle: false});
        Request.options.events.onError(error('Example\\Security\\ExpiredToken', 401, {
            authenticationRequired: true
        }), Request);
    };
    fail();
    state.logins[0].options.events.onClose();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.requests.length, 1);
    fail();
    assert.equal(state.logins.length, 2);
});

test('background probes with showLogin=false do not open a login window', () => {
    const {state, Ajax, error} = fixture();
    let failed = false;
    const Request = Ajax.get('probe', () => {}, {
        bundle: false, showError: false, showLogin: false, onError: () => { failed = true; }
    });
    Request.options.events.onError(error('Example\\Security\\ExpiredToken', 401, {
        authenticationRequired: true
    }), Request);
    assert.equal(state.logins.length, 0);
    assert.equal(failed, true);
});

test('permission errors and unrelated 401 errors do not trigger reauthentication', () => {
    const {state, Ajax, error} = fixture();
    for (const [type, code, attributes] of [
        ['QUI\\Permissions\\Exception', 403, {}],
        ['Example\\Security\\ExpiredToken', 403, {authenticationRequired: true}],
        ['Example\\Security\\ExpiredToken', 401, {}],
        ['Example\\Security\\ExpiredToken', 401, {authenticationRequired: 'true'}],
        ['OtherException', 401, {}]
    ]) {
        const Request = Ajax.get('protected', () => {}, {bundle: false, showError: false, onError() {}});
        Request.options.events.onError(error(type, code, attributes), Request);
    }
    assert.equal(state.logins.length, 0);
});
