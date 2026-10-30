const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const flush = () => new Promise(resolve => setImmediate(resolve));

function fixture(storeUrl = 'https://store.quiqqer.com/Modules') {
    const state = {requests: [], replies: [], listeners: [], frames: [], deferred: false, error: null};
    const modules = {};
    const window = {
        location: {href: 'https://admin.example.invalid/admin/'},
        addEventListener(type, listener) {
            assert.equal(type, 'message');
            state.listeners.push(listener);
        }
    };
    const request = (method, name, callback, options) => {
        state.requests.push({method, name, options, resolve: callback});

        if (state.error) {
            options.onError(state.error);
        } else if (!state.deferred) {
            callback('synthetic-result');
        }
    };
    const Ajax = {
        get: (...args) => request('GET', ...args),
        post: (...args) => request('POST', ...args)
    };
    class Store {
        inject() {
            this.frame = {
                src: storeUrl,
                isConnected: true,
                contentWindow: {
                    postMessage(data, origin) {
                        state.replies.push({data, origin});
                    }
                }
            };
            state.frames.push(this.frame);
            return this;
        }
        getFrame() { return this.frame; }
        destroy() { this.frame.isConnected = false; }
    }
    const dependencies = {
        'qui/QUI': {},
        'qui/classes/DOM': {},
        'qui/controls/Control': {},
        'qui/controls/loader/Loader': class {},
        'Ajax': Ajax
    };
    const sandbox = {
        window,
        URL,
        QUIQQER_VERSION: '2.0.0-test',
        JSON: {encode: JSON.stringify},
        Class: function (definition) {
            function Control(...args) {
                this.parent = () => {};
                this.addEvents = () => {};
                definition.initialize?.apply(this, args);
            }
            Control.prototype = definition;
            return Control;
        },
        define(name, names, factory) {
            modules[name] = factory(...names.map(name => modules[name] ?? dependencies[name]));
        },
        require(names, callback) {
            assert.deepEqual(Array.from(names), ['controls/packages/store/Store']);
            callback(Store);
        }
    };
    const load = name => vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/' + name + '.js'), 'utf8'), sandbox);

    load('classes/packages/Manager');
    modules.Packages = new modules['classes/packages/Manager']();
    load('classes/packages/StoreApi');
    load('controls/packages/Search');

    const Control = new modules['controls/packages/Search']();
    Control.$Container = {set() {}};
    for (const name of ['$StoreButton', '$PackageButton', '$SearchButton']) {
        Control[name] = {setNormal() {}, setActive() {}};
    }
    Control.openStore();

    const message = (data, overrides = {}) => ({
        origin: new URL(storeUrl, window.location.href).origin,
        source: window.$Store.getFrame().contentWindow,
        data,
        ...overrides
    });
    const dispatch = event => state.listeners[0].call(window, event);
    const send = async (data, overrides) => {
        dispatch(message(data, overrides));
        await flush();
    };

    return {state, window, Control, message, dispatch, send};
}

const install = {func: 'installPackage', params: ['synthetic/audit', 'dev-audit']};

test('the reported attacker-origin and foreign-source install request never reaches AJAX', async () => {
    const {state, send} = fixture();
    await send(install, {origin: 'https://attacker.invalid', source: {}});
    assert.equal(state.requests.length, 0);
    assert.equal(state.replies.length, 0);
});

for (const origin of ['https://attacker.invalid', 'https://store.quiqqer.com.attacker.invalid',
    'http://store.quiqqer.com', 'https://store.quiqqer.com:8443', 'https://store.quiqqer.de', 'null', '']) {
    test(`rejects the wrong origin even with the correct frame source: ${origin}`, async () => {
        const {state, send} = fixture();
        await send(install, {origin});
        assert.equal(state.requests.length, 0);
        assert.equal(state.replies.length, 0);
    });
}

for (const source of [{}, null]) {
    test(`rejects a foreign source on the configured store origin: ${source}`, async () => {
        const {state, send} = fixture();
        await send(install, {source});
        assert.equal(state.requests.length, 0);
        assert.equal(state.replies.length, 0);
    });
}

test('does not acknowledge init messages from untrusted senders', async () => {
    const {state, send} = fixture();
    await send({func: 'init'}, {origin: 'https://attacker.invalid'});
    await send({func: 'init'}, {source: {}});
    assert.equal(state.replies.length, 0);
});

for (const func of ['constructor', '__proto__', 'toString', 'hasOwnProperty', 'initialize',
    'install', 'setup', 'destroy', 'futureMethod']) {
    test(`only explicitly allowed methods can be invoked: ${func}`, async () => {
        const {state, window, send} = fixture();
        window.$StoreApi[func] = () => assert.fail('An unlisted method must not be called');
        await send({func, params: []});
        assert.equal(state.requests.length, 0);
        assert.equal(state.replies.length, 0);
    });
}

const invalidMessages = [
    null, undefined, false, 123, 'installPackage', [], {}, {func: 123}, {func: ['installPackage']},
    {func: 'installPackage'}, {func: 'installPackage', params: null},
    {func: 'installPackage', params: 'synthetic/audit'}, {func: 'installPackage', params: {}},
    {func: 'installPackage', params: []}, {func: 'installPackage', params: ['synthetic/audit']},
    {func: 'installPackage', params: ['synthetic/audit', 'dev-audit', 'extra']},
    {func: 'installPackage', params: [null, 'dev-audit']},
    {func: 'installPackage', params: ['synthetic/audit', {}]},
    {func: 'installPackage', params: ['', 'dev-audit']},
    {func: 'installPackage', params: ['synthetic/audit', '  ']},
    {func: 'installPackage', params: new Array(2)},
    {func: 'init', params: ['unexpected']}, {func: 'getLicenseAuthData', params: ['unexpected']}
];

invalidMessages.forEach((data, index) => {
    test(`rejects malformed trusted messages without dispatch or reply: ${index}`, async () => {
        const {state, send} = fixture();
        await send(data);
        assert.equal(state.requests.length, 0);
        assert.equal(state.replies.length, 0);
    });
});

for (const storeUrl of ['https://store.quiqqer.com/Modules', 'https://store.quiqqer.de/Module',
    'https://custom-store.example.invalid:8443/packages', '/custom-store']) {
    test(`valid store installation keeps the existing AJAX request and targets the exact origin: ${storeUrl}`,
        async () => {
            const {state, send} = fixture(storeUrl);
            await send(install);
            assert.equal(state.requests.length, 1);
            const Request = state.requests[0];
            assert.equal(Request.method, 'POST');
            assert.equal(Request.name, 'ajax_system_packages_installPackage');
            assert.equal(Request.options.packageName, 'synthetic/audit');
            assert.equal(Request.options.packageVersion, 'dev-audit');
            assert.equal(Request.options.server, 'false');
            assert.equal(state.replies.length, 1);
            assert.equal(state.replies[0].origin, new URL(storeUrl, 'https://admin.example.invalid/admin/').origin);
            assert.equal(state.replies[0].data.identifier, 'installPackagesynthetic/audit-dev-audit');
            assert.equal(state.replies[0].data.result, 'synthetic-result');
        });
}

test('the existing stable-version normalization is preserved', async () => {
    const {state, send} = fixture();
    await send({func: 'installPackage', params: ['synthetic/audit', '2.3.4']});
    assert.equal(state.requests[0].options.packageVersion, '2.*');
});

for (const func of ['init', 'getInstalledPackages', 'getLicenseAuthData', 'getMaxExecutionTime', 'getQuiqqerVersion']) {
    for (const params of [undefined, []]) {
        test(`supports the existing no-argument store method: ${func} (${params === undefined ? 'omitted' : 'array'})`,
            async () => {
                const {state, send} = fixture();
                await send({func, params});
                assert.equal(state.replies.length, 1);
                assert.equal(state.replies[0].origin, 'https://store.quiqqer.com');
                assert.equal(state.replies[0].data.identifier, func);
                const expected = func === 'init' ? true : (func === 'getQuiqqerVersion' ? '2.0.0-test' : 'synthetic-result');
                assert.equal(state.replies[0].data.result, expected);
            });
    }
}

test('API rejections reply only to the verified origin with the existing error format', async () => {
    const {state, send} = fixture();
    state.error = {getMessage: () => 'Synthetic installation failure'};
    await send(install);
    assert.equal(state.replies.length, 1);
    assert.equal(state.replies[0].origin, 'https://store.quiqqer.com');
    assert.equal(state.replies[0].data.result, null);
    assert.equal(state.replies[0].data.errorMessage, 'Synthetic installation failure');
});

for (const error of [new Error('Synthetic failure'), null]) {
    test(`synchronous API errors cannot break the message listener: ${error}`, async () => {
        const {state, window, send} = fixture();
        window.$StoreApi.installPackage = () => { throw error; };
        await send(install);
        assert.equal(state.replies.length, 1);
        assert.equal(state.replies[0].origin, 'https://store.quiqqer.com');
        assert.equal(state.replies[0].data.result, null);
        assert.equal(state.replies[0].data.errorMessage, error?.message ?? '');
    });
}

test('a missing API method replies without dispatch and without a wildcard origin', async () => {
    const {state, window, send} = fixture();
    window.$StoreApi = {};
    await send(install);
    assert.equal(state.requests.length, 0);
    assert.equal(state.replies.length, 1);
    assert.equal(state.replies[0].origin, 'https://store.quiqqer.com');
    assert.equal(state.replies[0].data.result, null);
});

for (const change of ['removed', 'disconnected', 'missing-frame', 'missing-window']) {
    test(`ignores messages when the active store frame is unavailable: ${change}`, async () => {
        const {state, window, message, dispatch} = fixture();
        const event = message(install);
        const Frame = window.$Store.getFrame();
        if (change === 'removed') window.$Store = null;
        if (change === 'disconnected') Frame.isConnected = false;
        if (change === 'missing-frame') window.$Store.getFrame = () => null;
        if (change === 'missing-window') Frame.contentWindow = null;
        dispatch(event);
        await flush();
        assert.equal(state.requests.length, 0);
        assert.equal(state.replies.length, 0);
    });
}

for (const src of ['http://[invalid', 'about:blank', 'data:text/html,untrusted', 'javascript:void(0)']) {
    test(`rejects invalid or opaque store origins: ${src}`, async () => {
        const {state, window, message, dispatch} = fixture();
        const event = message(install);
        window.$Store.getFrame().src = src;
        event.origin = 'null';
        dispatch(event);
        await flush();
        assert.equal(state.requests.length, 0);
        assert.equal(state.replies.length, 0);
    });
}

test('reopening the store registers one listener and rejects messages from the old frame', async () => {
    const {state, Control, message, dispatch, send} = fixture();
    const oldEvent = message(install);
    Control.openStore();
    assert.equal(state.listeners.length, 1);
    dispatch(oldEvent);
    await flush();
    assert.equal(state.requests.length, 0);
    await send(install);
    assert.equal(state.requests.length, 1);
});

for (const change of ['replace', 'disconnect', 'navigate']) {
    test(`pending replies remain bound to their verified frame and origin: ${change}`, async () => {
        const {state, window, Control, send} = fixture();
        state.deferred = true;
        await send({func: 'getLicenseAuthData'});
        assert.equal(state.requests.length, 1);

        if (change === 'replace') Control.openStore();
        if (change === 'disconnect') window.$Store.getFrame().isConnected = false;
        if (change === 'navigate') window.$Store.getFrame().src = 'https://attacker.invalid';
        state.requests[0].resolve('synthetic-authentication-data');
        await flush();

        if (change === 'navigate') {
            assert.equal(state.replies.length, 1);
            assert.equal(state.replies[0].origin, 'https://store.quiqqer.com');
        } else {
            assert.equal(state.replies.length, 0);
        }
    });
}
