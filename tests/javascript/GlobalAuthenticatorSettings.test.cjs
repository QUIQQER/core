const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const mail = 'QUI\\Users\\Auth\\VerifiedMail2FA';
const passkey = 'QUI\\Users\\Auth\\WebAuthn';
const setupRequired = {getCode: () => 409};

function fixture({
    frontend = [mail],
    backend = [mail],
    saveErrors = [setupRequired],
    moduleError = false
} = {}) {
    const windows = [];
    const calls = [];
    const saved = [];
    const input = {nodeName: 'INPUT', value: ''};
    const selects = {
        secondary_frontend: {dataset: {name: 'secondary_frontend'}, value: '0'},
        secondary_backend: {dataset: {name: 'secondary_backend'}, value: '2'}
    };
    const methods = values => ({
        closest: () => ({style: {}}),
        querySelectorAll: () => values.map(value => ({value}))
    });
    const nodes = {
        ...selects,
        secondary_frontend_authenticators: methods(frontend),
        secondary_backend_authenticators: methods(backend)
    };
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/users/auth/GlobalAuthenticatorSettings.js'), 'utf8'), {
        JSON: {parse: JSON.parse, encode: JSON.stringify},
        setTimeout,
        Class: function (value) { definition = value; return value; },
        define(name, dependencies, factory) {
            factory(...dependencies.map(dependency => ({
                Locale: {get: (group, key) => `${group}:${key}`},
                Ajax: {post(call, callback, params) {
                    const error = saveErrors[calls.length];
                    calls.push({call, params});
                    if (error) {
                        params.onError(error);
                    } else {
                        saved.push(JSON.parse(params.authenticators));
                        callback(true);
                    }
                }}
            })[dependency] || {}));
        },
        require(dependencies, callback, failure) {
            assert.deepEqual(Array.from(dependencies), ['controls/users/auth/EnableSecondaryAuthenticatorWindow']);
            if (moduleError) return failure(new Error('module unavailable'));
            callback(class {
                constructor(options) { this.options = options; windows.push(this); }
                setAttribute() {}
                open() { this.opened = true; }
            });
        }
    });
    const control = {
        ...definition,
        $secondaryModes: {frontend: 0, backend: 2},
        $availableAuthenticators: [mail, passkey].map(authenticator => ({
            authenticator,
            isSecondaryAuthentication: true,
            frontend: {title: authenticator, description: ''},
            hasSettings: true
        })),
        $setupPromise: null,
        getElm: () => input,
        $primaryTable: {getElements: () => []},
        $secondaryTable: {querySelector: selector => nodes[selector.match(/data-name="([^"]+)"/)[1]]}
    };
    control.$onChange();
    return {control, input, selects, windows, calls, saved};
}

const settle = () => new Promise(resolve => setTimeout(resolve, 5));

for (const name of ['secondary_frontend', 'secondary_backend']) {
    test(`mandatory ${name} opens setup only on save, using the selected methods`, async () => {
        const {control, selects, windows, calls, saved} = fixture();
        assert.equal(windows.length, 0);
        selects[name].value = '1';
        control.$onChange({target: selects[name]});
        assert.equal(windows.length, 0, 'changing the setting must not open the popup');
        assert.equal(calls.length, 0);
        const saving = control.save();
        const rejected = assert.rejects(saving, /cancelled/);
        await settle();
        assert.equal(windows.length, 1);
        assert.equal(windows[0].opened, true);
        assert.equal(windows[0].options.message, 'quiqqer/core:exception.auth.require_own_second_factor');
        assert.deepEqual(Array.from(windows[0].options.authenticators, item => item.authenticator), [mail]);
        windows[0].options.events.onCancel();
        await rejected;
        assert.equal(saved.length, 0, 'cancelling must not persist the mandatory policy');
        assert.equal(selects[name].value, name === 'secondary_frontend' ? '0' : '2');
    });
}

test('no selected method rejects saving without opening an empty popup', async () => {
    const {control, selects, windows, saved} = fixture({backend: []});
    selects.secondary_backend.value = '1';
    control.$onChange();
    await assert.rejects(control.save(), error => error === setupRequired);
    assert.equal(windows.length, 0);
    assert.equal(saved.length, 0);
});

test('each mandatory area must have a selected method before setup opens', async () => {
    const {control, selects, windows} = fixture({frontend: []});
    selects.secondary_frontend.value = '1';
    selects.secondary_backend.value = '1';
    control.$onChange();
    await assert.rejects(control.save(), error => error === setupRequired);
    assert.equal(windows.length, 0);
});

test('optional 2FA never opens setup during save', async () => {
    const {control, windows, saved} = fixture({saveErrors: []});
    assert.equal(await control.save(), true);
    assert.equal(windows.length, 0);
    assert.equal(saved.length, 1);
});

test('a user already confirmed by the server can save mandatory 2FA without setup', async () => {
    const {control, selects, windows, saved} = fixture({saveErrors: []});
    selects.secondary_backend.value = '1';
    control.$onChange();
    assert.equal(await control.save(), true);
    assert.equal(windows.length, 0);
    assert.equal(saved.length, 1);
});

test('completion retries saving once, including when close fires first', async () => {
    const {control, selects, windows, calls, saved} = fixture();
    selects.secondary_backend.value = '1';
    control.$onChange();
    const saving = control.save();
    assert.equal(control.save(), saving, 'repeated clicks share the pending save');
    await settle();
    assert.equal(saved.length, 0);
    windows[0].options.events.onClose();
    windows[0].options.events.onCompleted();
    assert.equal(await saving, true);
    await settle();
    assert.equal(calls.length, 2);
    assert.equal(saved.length, 1);
    assert.equal(saved[0].secondary_backend, 1);
});

test('closing without completion restores the saved mode without persisting the policy', async () => {
    const {control, input, selects, windows, saved} = fixture();
    selects.secondary_backend.value = '1';
    control.$onChange();
    const rejected = assert.rejects(control.save(), /cancelled/);
    await settle();
    windows[0].options.events.onClose();
    await rejected;
    assert.equal(saved.length, 0);
    assert.equal(selects.secondary_backend.value, '2');
    assert.equal(JSON.parse(input.value).secondary_backend, 2);
});

test('permission denials do not open setup or retry authorization', async () => {
    const denial = {getCode: () => 403};
    const {control, selects, windows, calls} = fixture({saveErrors: [denial]});
    selects.secondary_backend.value = '1';
    control.$onChange();
    await assert.rejects(control.save(), error => error === denial);
    assert.equal(windows.length, 0);
    assert.equal(calls.length, 1);
});

test('a server rejection after setup cannot bypass second factor validation', async () => {
    const {control, selects, windows, calls, saved} = fixture({saveErrors: [setupRequired, setupRequired]});
    selects.secondary_backend.value = '1';
    control.$onChange();
    const rejected = assert.rejects(control.save(), error => error === setupRequired);
    await settle();
    windows[0].options.events.onCompleted();
    await rejected;
    assert.equal(calls.length, 2);
    assert.equal(saved.length, 0);
});

test('failure to load the popup never persists the policy', async () => {
    const {control, selects, saved} = fixture({moduleError: true});
    selects.secondary_backend.value = '1';
    control.$onChange();
    await assert.rejects(control.save(), /cancelled/);
    assert.equal(saved.length, 0);
});

test('only methods from mandatory areas appear, with duplicates removed', async () => {
    const {control, selects, windows} = fixture({frontend: [passkey], backend: [mail]});
    selects.secondary_backend.value = '1';
    selects.secondary_frontend.value = '2';
    control.$onChange();
    let rejected = assert.rejects(control.save(), /cancelled/);
    await settle();
    assert.deepEqual(Array.from(windows[0].options.authenticators, item => item.authenticator), [mail]);
    windows[0].options.events.onCancel();
    await rejected;

    selects.secondary_frontend.value = '1';
    selects.secondary_backend.value = '1';
    control.$onChange();
    control.$saveAuthenticators = () => Promise.reject(setupRequired);
    rejected = assert.rejects(control.save(), /cancelled/);
    await settle();
    assert.deepEqual(Array.from(windows[1].options.authenticators, item => item.authenticator), [mail, passkey]);
    windows[1].options.events.onCancel();
    await rejected;
});

test('cancel restores the most recently saved mode, including after an earlier successful save', async () => {
    const {control, input, selects, windows} = fixture({saveErrors: [null, setupRequired]});
    selects.secondary_backend.value = '0';
    control.$onChange();
    await control.save();
    selects.secondary_backend.value = '1';
    control.$onChange();
    const rejected = assert.rejects(control.save(), /cancelled/);
    await settle();
    windows[0].options.events.onCancel();
    await rejected;
    assert.equal(selects.secondary_backend.value, '0');
    assert.equal(JSON.parse(input.value).secondary_backend, 0);
});
