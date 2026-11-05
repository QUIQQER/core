const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function loadControl(path, dependencies, globals = {}) {
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../', path), 'utf8'), {
        console: {error() {}},
        JSON: {...JSON, encode: JSON.stringify},
        Class: function (value) { definition = value; return value; },
        define(name, names, factory) {
            factory(...names.map(name => dependencies[name] || {}));
        },
        ...globals
    });
    return definition;
}

function element() {
    return {
        disabled: false,
        dataset: {},
        style: {},
        events: {},
        children: [],
        classList: {add() {}, remove() {}, contains() { return false; }},
        addEventListener(name, callback) { this.events[name] = callback; },
        focus() {},
        appendChild(child) { this.children.push(child); }
    };
}

async function settle() {
    await new Promise(resolve => setImmediate(resolve));
    await new Promise(resolve => setImmediate(resolve));
}

function mailFixture(result = true, error = null) {
    const form = element();
    const send = element();
    const enable = element();
    const verify = element();
    const verification = element();
    const activation = {...element(), hidden: true};
    const message = element();
    const icon = element();
    const inputs = Array.from({length: 6}, () => ({...element(), value: '1'}));
    form.querySelectorAll = () => inputs;
    send.querySelector = () => icon;
    const nodes = {
        '[data-name="quiqqer-mail2fa-auth-settings-code"]': form,
        '[data-name="send-code"]': send,
        '[data-name="enable"]': enable,
        '[data-name="verify-mail"]': verify,
        '[data-name="mail-verification"]': verification,
        '[data-name="mail-activation"]': activation,
        '[data-name="message"]': message
    };
    let completed = 0;
    let reloads = 0;
    const calls = [];
    const definition = loadControl('bin/QUI/controls/users/auth/settings/VerifiedMail2FA.js', {
        Locale: {get: (group, key) => key},
        Ajax: {post(call, callback, params) {
            calls.push(call);
            if (error && call !== 'ajax_session_destroy') params.onError(error);
            else callback(result);
        }}
    }, {
        document: {createElement: () => element()},
        window: {location: {reload: () => reloads++}}
    });
    const control = {
        ...definition,
        getElm: () => ({querySelector: selector => nodes[selector]}),
        fireEvent: () => completed++
    };
    control.$onImport();
    return {form, send, enable, verify, verification, activation, message, control, calls,
        completed: () => completed, reloads: () => reloads};
}

const event = {preventDefault() {}, stopPropagation() {}};

for (const message of [false, '', 'Bitte zuerst 2FA einrichten. <img src=x onerror=alert(1)>']) {
    test(`setup popup renders an optional warning as plain text: ${JSON.stringify(message)}`, () => {
        const root = {...element(), prepend(node) { this.children.unshift(node); }};
        const definition = loadControl('bin/QUI/controls/users/auth/EnableSecondaryAuthenticatorWindow.js', {
            Ajax: {get: (call, callback) => callback([])},
            Locale: {get: () => ''}
        }, {
            document: {createElement: () => ({dataset: {}, attributes: {},
                setAttribute(name, value) { this.attributes[name] = value; }})}
        });
        definition.$onOpen.call({
            Loader: {show() {}, hide() {}},
            getContent: () => root,
            getAttribute: name => name === 'message' ? message : false
        });
        assert.equal(root.children.length, message ? 1 : 0);
        if (message) {
            const warning = root.children[0];
            assert.equal(warning.textContent, message);
            assert.equal(warning.attributes.role, 'alert');
            assert.equal(warning.className, 'quiqqer-message quiqqer-message-attention');
        }
    });
}

for (const useSelectedMethods of [true, false]) {
    test(`setup popup uses ${useSelectedMethods ? 'unsaved selected' : 'saved global'} methods`, () => {
        const button = element();
        button.setAttribute = (name, value) => { button[name] = value; };
        const list = element();
        const root = {...element(), querySelector: () => list};
        const method = {
            authenticator: 'QUI\\Users\\Auth\\VerifiedMail2FA',
            frontend: {title: 'Mail', description: 'Mail setup'},
            hasSettings: true
        };
        const calls = [];
        const definition = loadControl('bin/QUI/controls/users/auth/EnableSecondaryAuthenticatorWindow.js', {
            Ajax: {get(call, callback) { calls.push(call); callback([method]); }},
            Locale: {get: () => ''}
        }, {
            document: {createElement: () => ({
                ...element(),
                setAttribute() {},
                querySelector: () => button
            })}
        });
        definition.$onOpen.call({
            Loader: {show() {}, hide() {}},
            getContent: () => root,
            getAttribute: name => name === 'authenticators' && useSelectedMethods ? [method] : false
        });
        assert.equal(list.children.length, 1);
        assert.equal(button['data-authenticator'], method.authenticator);
        assert.equal(typeof button.events.click, 'function');
        assert.deepEqual(calls, useSelectedMethods ? [] : ['ajax_users_authenticator_getSecondaryAuthenticators']);
    });
}

test('incorrect mail codes keep setup open and restore both buttons', async () => {
    const fixture = mailFixture(false);
    fixture.form.events.submit(event);
    await settle();
    assert.equal(fixture.completed(), 0);
    assert.equal(fixture.enable.disabled, false);
    assert.equal(fixture.send.disabled, false);
    assert.equal(fixture.message.textContent, 'exception.login.fail');
});

test('mail verification and 2FA activation are separate server-confirmed steps', async () => {
    const fixture = mailFixture(true);
    fixture.form.events.submit(event);
    await settle();
    assert.equal(fixture.completed(), 0);
    assert.equal(fixture.verification.hidden, true);
    assert.equal(fixture.activation.hidden, false);
    assert.deepEqual(fixture.calls, ['ajax_users_authenticator_mail2fa_verifyMail']);
    fixture.enable.events.click();
    await settle();
    assert.equal(fixture.completed(), 1);
    assert.equal(fixture.calls[1], 'ajax_users_authenticator_mail2fa_enableByUser');
    assert.equal(fixture.enable.disabled, false);
});

test('failed mail requests leave setup retryable and render errors as text', async () => {
    const error = {getMessage: () => '<script>untrusted</script>'};
    const fixture = mailFixture(null, error);
    fixture.form.events.submit(event);
    await settle();
    assert.equal(fixture.completed(), 0);
    assert.equal(fixture.enable.disabled, false);
    assert.equal(fixture.message.textContent, '<script>untrusted</script>');
    fixture.control.$sendCode(event);
    assert.equal(fixture.send.disabled, false);
});

test('mail setup offers a fresh sign-in after the authentication policy changes', async () => {
    const fixture = mailFixture(null, {getMessage: () => 'Sign in again', getCode: () => 401});
    fixture.enable.events.click();
    await settle();
    assert.equal(fixture.completed(), 0);
    assert.equal(fixture.message.textContent, 'Sign in again');
    const retry = fixture.message.children[0];
    assert.equal(retry.type, 'button');
    retry.events.click();
    assert.equal(fixture.calls.at(-1), 'ajax_session_destroy');
    assert.equal(fixture.reloads(), 1);
});

test('a real permission denial cannot be used to restart mail setup automatically', async () => {
    const fixture = mailFixture(null, {getMessage: () => 'Denied', getCode: () => 403});
    fixture.enable.events.click();
    await settle();
    assert.equal(fixture.completed(), 0);
    assert.equal(fixture.message.children.length, 0);
    assert.equal(fixture.calls.length, 1);
});

function passkeyFixture(error = null) {
    const calls = [];
    const button = element();
    const message = element();
    const settings = {...element(), dataset: {userUuid: 'owner-uuid'}};
    const nodes = {
        '[data-name="create-passkey"]': button,
        '[data-name="credential-name"]': {value: 'My passkey'},
        '[data-name="message"]': message,
        '[data-name="quiqqer-webauthn-settings"]': settings
    };
    const container = element();
    const root = {...element(), parentNode: container, querySelector: selector => nodes[selector]};
    let reloads = 0;
    let completed = 0;
    const refreshed = {
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        getType: () => 'controls/users/auth/settings/WebAuthn'
    };
    const ajax = (call, callback, params) => {
        calls.push({call, params});
        if (error && call.endsWith('beginRegistration')) params.onError(error);
        else callback({publicKey: {}});
    };
    const definition = loadControl('bin/QUI/controls/users/auth/settings/WebAuthn.js', {
        Ajax: {get: ajax, post: ajax},
        Locale: {get: (group, key) => key},
        'qui/QUI': {
            parse: async () => {},
            Controls: {getControlsInElement: () => [refreshed]}
        },
        'controls/users/auth/WebAuthnUtils': {
            prepareCreateOptions: options => options,
            serializeAttestation: () => ({}),
            getErrorLocaleKey: () => 'error'
        }
    }, {
        navigator: {credentials: {create: async () => ({})}},
        document: {createElement: () => element()},
        window: {location: {reload: () => reloads++}}
    });
    const control = {
        ...definition,
        getAttribute: () => undefined,
        getElm: () => root,
        getType: () => 'controls/users/auth/settings/WebAuthn',
        $getWindow: () => null,
        fireEvent: () => completed++
    };
    return {control, calls, button, message, refreshed, completed: () => completed, reloads: () => reloads};
}

test('passkey setup in the login popup refreshes without a user panel object', async () => {
    const fixture = passkeyFixture();
    fixture.control.$createPasskey(event);
    await settle();
    assert.deepEqual(fixture.calls.map(call => call.call), [
        'ajax_users_authenticator_webauthn_beginRegistration',
        'ajax_users_authenticator_webauthn_finishRegistration',
        'ajax_users_authenticator_webauthn_settings'
    ]);
    assert(fixture.calls.every(call => call.params.userUuid === 'owner-uuid'));
    assert.equal(fixture.refreshed.attributes.uid, 'owner-uuid');
    assert.equal(fixture.completed(), 1);
});

test('expired enrollment offers explicit sign-in without renewing a grant', async () => {
    const fixture = passkeyFixture({getCode: () => 401, getMessage: () => 'Sign in again'});
    fixture.control.$createPasskey(event);
    await settle();
    assert.equal(fixture.completed(), 0);
    assert.equal(fixture.calls.length, 1);
    assert.equal(fixture.message.textContent, 'Sign in again');
    const retry = fixture.message.children[0];
    assert.equal(retry.type, 'button');
    retry.events.click();
    assert.equal(fixture.calls[1].call, 'ajax_session_destroy');
    assert.equal(fixture.reloads(), 1);
});

test('a permission denial cannot complete setup or offer an authorization retry', async () => {
    const fixture = passkeyFixture({getCode: () => 403, getMessage: () => 'Denied'});
    fixture.control.$createPasskey(event);
    await settle();
    assert.equal(fixture.completed(), 0);
    assert.equal(fixture.message.children.length, 0);
    assert.equal(fixture.calls.length, 1);
});
