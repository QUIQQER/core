const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

class Element {
    constructor(tagName, name) {
        this.tagName = tagName.toUpperCase();
        this.dataset = {name};
        this.children = [];
        this.attributes = {};
        this.style = {};
        this.events = {};
        this.offsetWidth = 104;
        const classes = new Set();
        this.classList = {
            add: (...names) => names.forEach(name => classes.add(name)),
            contains: name => classes.has(name),
            toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name)
        };
    }
    appendChild(child) { child.parentNode = this; this.children.push(child); }
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name]; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, callback) { this.events[name] = callback; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    querySelectorAll(selector) {
        const match = selector.match(/^\[data-name="([^"]+)"\]$/);
        assert(match, `expected a data-name selector, received ${selector}`);
        return this.children.flatMap(child => [
            ...(child.dataset.name === match[1] ? [child] : []), ...child.querySelectorAll(selector)
        ]);
    }
    closest(selector) {
        assert.equal(selector, '[data-name="authenticator"]');
        return this.dataset.name === 'authenticator' ? this : this.parentNode?.closest(selector);
    }
}

function fixture({enabled = false, settings = true, authenticator = 'ExampleAuth'} = {}) {
    const root = new Element('ul');
    const entry = new Element('li', 'authenticator');
    entry.dataset.authenticator = authenticator;
    entry.dataset.settings = settings ? '1' : '';
    entry.classList.toggle('authenticator-enabled', enabled);
    entry.appendChild(new Element('div', 'authenticator-actions'));
    root.appendChild(entry);
    const calls = [];
    const state = {enabled};
    const user = {
        enableAuthenticator: async auth => { calls.push(['enable', auth]); state.enabled = true; },
        disableAuthenticator: async auth => { calls.push(['disable', auth]); state.enabled = false; },
        hasAuthenticator: async auth => { calls.push(['has', auth]); return state.enabled; }
    };
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/users/User.js'), 'utf8'), {
        document: {createElement: tag => new Element(tag)}, console,
        Class: function (value) { definition = value; return value; },
        define(name, dependencies, factory) {
            factory(...dependencies.map(name => name === 'Locale' ? {get: (group, key) => key} : {}));
        }
    });
    const panel = {
        ...definition,
        getBody: () => root,
        getUser: () => user,
        $openAuthSettings: button => calls.push(['settings', button.closest('[data-name="authenticator"]').dataset.authenticator])
    };
    panel.$createAuthenticatorButtons(entry);
    return {
        panel, user, calls, state, entry,
        status: entry.querySelector('[data-name="authenticator-status"]'),
        settings: entry.querySelector('[data-name="authenticator-settings"]')
    };
}

test('plain list entries get status and accessible settings buttons', () => {
    const {entry, status, settings} = fixture();
    assert.equal(entry.tagName, 'LI');
    assert.equal(status.getAttribute('aria-pressed'), 'false');
    assert.equal(status.textContent, 'isDeactivate');
    assert.equal(settings.disabled, true);
    assert.equal(settings.getAttribute('aria-label'), 'user.settings.authenticators.additionalSettings');
    assert.equal(settings.children[0].getAttribute('aria-hidden'), 'true');
    assert.equal(fixture({settings: false}).settings, null);
});

test('enable and disable target the list entry and synchronize both buttons', async () => {
    const {panel, calls, status, settings, entry} = fixture();
    const enabling = panel.$toggleAuthentication(status);
    assert.equal(status.disabled, true);
    assert.equal(status.getAttribute('aria-busy'), 'true');
    await enabling;
    assert.deepEqual(calls, [['enable', 'ExampleAuth'], ['has', 'ExampleAuth']]);
    assert.equal(entry.classList.contains('authenticator-enabled'), true);
    assert.equal(status.getAttribute('aria-pressed'), 'true');
    assert.equal(status.disabled, false);
    assert.equal(status.getAttribute('aria-busy'), undefined);
    assert.equal(settings.disabled, false);
    await panel.$toggleAuthentication(status);
    assert.deepEqual(calls.slice(2), [['disable', 'ExampleAuth'], ['has', 'ExampleAuth']]);
    assert.equal(status.getAttribute('aria-pressed'), 'false');
    assert.equal(settings.disabled, true);
});

test('WebAuthn opens registration settings after enabling a list entry', async () => {
    const {panel, calls, status} = fixture({authenticator: 'QUI\\Users\\Auth\\WebAuthn'});
    await panel.$toggleAuthentication(status);
    assert.deepEqual(calls.at(-1), ['settings', 'QUI\\Users\\Auth\\WebAuthn']);
});

test('refresh after closing settings updates a list entry without table selectors', async () => {
    const {panel, state, status, settings} = fixture({enabled: true});
    state.enabled = false;
    await panel.$refreshAuthenticator('ExampleAuth');
    assert.equal(status.textContent, 'isDeactivate');
    assert.equal(settings.disabled, true);
    await panel.$refreshAuthenticator('AbsentAuth');
});

test('failed toggles restore the prior state and keep the entry usable', async () => {
    const {panel, user, status, settings} = fixture({enabled: true});
    user.disableAuthenticator = async () => { throw new Error('denied'); };
    await assert.rejects(panel.$toggleAuthentication(status), /denied/);
    assert.equal(status.disabled, false);
    assert.equal(status.getAttribute('aria-pressed'), 'true');
    assert.equal(status.getAttribute('aria-busy'), undefined);
    assert.equal(settings.disabled, false);
});
