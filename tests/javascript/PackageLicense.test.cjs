const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const source = name => readFileSync(resolve(__dirname, '../../bin/QUI/' + name), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function exception(code = 1599, attributes = {}) {
    return {
        getCode: () => code,
        getMessage: () => attributes.message || 'License required',
        getAttribute: name => ({type: 'QUI\\Package\\PackageNotLicensedException', ...attributes})[name]
    };
}

function ajaxFixture({backend = true, loadFailure = false} = {}) {
    const state = {messages: [], dialogs: [], triggered: [], requests: []};
    let Ajax;
    let nextId = 0;
    const Handler = {addException: error => state.messages.push(error)};
    const QUI = {
        getMessageHandler(callback) {
            callback?.(Handler);
            return Promise.resolve(Handler);
        },
        triggerError: (...args) => state.triggered.push(args)
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
    const window = {QUIQQER: {inAdministration: backend}};
    window.parent = window;
    const StringFactory = Object.assign(value => String(value), {uniqueID: () => String(++nextId)});

    vm.runInNewContext(source('Ajax.js'), {
        window,
        QUIQQER: window.QUIQQER,
        String: StringFactory,
        require(names, success, failure) {
            assert.deepEqual(Array.from(names), ['controls/licenseKey/RequiredWindow']);
            if (loadFailure) {
                failure();
            } else {
                success(error => state.dialogs.push(error));
            }
        },
        define(name, names, factory) { Ajax = factory(...names.map(name => dependencies[name])); }
    });

    return {
        state,
        fail(error, {sync = false, ...options} = {}) {
            const Request = sync
                ? Ajax.syncRequest('test', 'post', options)
                : Ajax.post('test', () => assert.fail('Errors must not invoke success'), {bundle: false, ...options});
            const result = Request.options.events.onError(error, Request);
            return {Request, result};
        }
    };
}

for (const sync of [false, true]) {
    test(`license errors open a backend dialog and preserve onError (sync=${sync})`, async () => {
        const {state, fail} = ajaxFixture();
        const error = exception();
        const callbacks = [];
        const {Request, result} = fail(error, {
            sync,
            onError: (...args) => { callbacks.push(args); return 'handled'; }
        });
        await flush();
        assert.deepEqual(state.dialogs, [error]);
        assert.deepEqual(state.messages, []);
        assert.deepEqual(callbacks, [[error, Request]]);
        assert.equal(result, 'handled');
        assert.equal(state.triggered.length, 0);
    });

    test(`showError=false suppresses the license dialog while notifying callers (sync=${sync})`, async () => {
        const {state, fail} = ajaxFixture();
        const error = exception();
        const {Request} = fail(error, {sync, showError: false});
        await flush();
        assert.equal(state.dialogs.length, 0);
        assert.equal(state.messages.length, 0);
        assert.deepEqual(state.triggered, [[error, Request]]);
    });

    test(`ordinary errors retain their existing notification (sync=${sync})`, async () => {
        const {state, fail} = ajaxFixture();
        const error = exception(403);
        const {Request} = fail(error, {sync});
        await flush();
        assert.deepEqual(state.messages, [error]);
        assert.equal(state.dialogs.length, 0);
        assert.deepEqual(state.triggered, [[error, Request]]);
    });

    test(`frontend license errors retain ordinary handling (sync=${sync})`, async () => {
        const {state, fail} = ajaxFixture({backend: false});
        const error = exception();
        fail(error, {sync});
        await flush();
        assert.deepEqual(state.messages, [error]);
        assert.equal(state.dialogs.length, 0);
    });
}

test('failed dialog loading falls back to the ordinary notification', async () => {
    const {state, fail} = ajaxFixture({loadFailure: true});
    const error = exception('1599');
    fail(error);
    await flush();
    assert.deepEqual(state.messages, [error]);
    assert.equal(state.dialogs.length, 0);
});

function windowFixture() {
    const state = {windows: [], parsed: []};
    const document = {};
    class Element extends EventTarget {
        constructor(tagName) {
            super();
            this.tagName = tagName;
            this.dataset = {};
            this.attributes = {};
            this.children = [];
            this.isConnected = true;
        }
        setAttribute(name, value) { this.attributes[name] = value; }
        appendChild(child) { this.children.push(child); }
        append(...children) { this.children.push(...children); }
        focus() { document.activeElement = this; }
        set innerHTML(value) { assert.fail('Server HTML must not reach the live DOM'); }
    }
    document.createElement = tag => new Element(tag);
    const ReturnFocus = new Element('button');
    document.activeElement = ReturnFocus;
    class SimpleWindow {
        constructor(options) {
            this.options = options;
            this.root = new Element('div');
            this.content = new Element('div');
            this.closed = false;
            state.windows.push(this);
        }
        getElm() { return this.root; }
        getContent() { return this.content; }
        open() { this.options.events.onOpen(); }
        close() { this.closed = true; this.options.events.onClose(); }
    }
    let openWindow;
    vm.runInNewContext(source('controls/licenseKey/RequiredWindow.js'), {
        document,
        URL,
        DOMParser: class {
            parseFromString(message, type) {
                state.parsed.push({message, type});
                return {body: {textContent: '50 redirects reached.'}};
            }
        },
        define(name, dependencies, factory) {
            assert.equal(dependencies[0], 'qui/controls/windows/SimpleWindow');
            openWindow = factory(SimpleWindow, {get: (group, key) => key});
        }
    });
    return {state, document, ReturnFocus, openWindow};
}

function nodes(Window) {
    const [Body, Actions] = Window.content.children[0].children;
    const [Symbol, Heading, Message, Package] = Body.children;
    const Close = Actions.children[0];
    return {Symbol, Heading, Package, Message, Actions, Close};
}

test('shows package and module text, safe store link and an accessible keyboard dialog', () => {
    const {state, document, ReturnFocus, openWindow} = windowFixture();
    const Window = openWindow(exception(1599, {
        package: 'quiqqer/redirect',
        message: '50 <b>redirects</b> reached.',
        url: 'https://store.quiqqer.com/Modules/Redirect'
    }));
    const {Symbol, Package, Message, Actions, Close} = nodes(Window);
    const Store = Actions.children[1];
    assert.equal(Symbol.attributes['aria-hidden'], 'true');
    assert.equal(Package.textContent, 'quiqqer/redirect');
    assert.equal(Message.textContent, '50 redirects reached.');
    assert.equal(state.parsed[0].message, '50 <b>redirects</b> reached.');
    assert.equal(Store.href, 'https://store.quiqqer.com/Modules/Redirect');
    assert.equal(Store.target, '_blank');
    assert.equal(Store.rel, 'noopener noreferrer');
    assert.equal(Window.root.attributes.role, 'dialog');
    assert.equal(Window.root.attributes['aria-modal'], 'true');
    assert.equal(Window.root.attributes['aria-describedby'], Message.id);
    assert.equal(document.activeElement, Store);

    const tab = new Event('keydown', {cancelable: true});
    Object.assign(tab, {key: 'Tab', shiftKey: false});
    Window.root.dispatchEvent(tab);
    assert.equal(tab.defaultPrevented, true);
    assert.equal(document.activeElement, Close);
    const backTab = new Event('keydown', {cancelable: true});
    Object.assign(backTab, {key: 'Tab', shiftKey: true});
    Window.root.dispatchEvent(backTab);
    assert.equal(document.activeElement, Store);

    const escape = new Event('keydown', {cancelable: true});
    Object.assign(escape, {key: 'Escape'});
    Window.root.dispatchEvent(escape);
    assert.equal(Window.closed, true);
    assert.equal(document.activeElement, ReturnFocus);
});

for (const url of [undefined, '', 'javascript:alert(1)', 'data:text/html,attack', '/relative', '//example.test',
    'https://user:password@example.test/']) {
    test(`omits absent or unsafe store URL: ${url}`, () => {
        const {openWindow} = windowFixture();
        const Window = openWindow(exception(1599, {package: 'quiqqer/redirect', url}));
        const {Actions, Close} = nodes(Window);
        assert.equal(Actions.children.length, 1);
        assert.equal(Close.tagName, 'button');
        Close.dispatchEvent(new Event('click'));
        assert.equal(Window.closed, true);
    });
}

test('deduplicates concurrent notices for a package and permits reopening after close', () => {
    const {state, openWindow} = windowFixture();
    const error = exception(1599, {package: '<img src=x onerror=alert(1)>'});
    const First = openWindow(error);
    assert.equal(nodes(First).Package.textContent, '<img src=x onerror=alert(1)>');
    assert.equal(openWindow(error), First);
    assert.equal(state.windows.length, 1);
    First.close();
    assert.notEqual(openWindow(error), First);
    assert.equal(state.windows.length, 2);
});
