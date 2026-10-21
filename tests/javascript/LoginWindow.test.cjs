const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture({height = 900} = {}) {
    const windows = [];
    const document = {activeElement: {isConnected: true, focus() { this.restored = true; }}};
    const window = {QUIQQER: {inAdministration: true}, URL_SYS_DIR: '/admin/'};
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/users/LoginWindow.js'), 'utf8'), {
        window, document,
        getComputedStyle: element => ({
            position: 'static', paddingTop: '0', paddingBottom: '0', marginTop: '0', marginBottom: '0',
            ...element.computedStyle
        }),
        Class: function (value) { definition = value; return value; },
        define(name, dependencies, factory) {
            assert.equal(dependencies[1], 'qui/controls/windows/SimpleWindow');
            factory({getWindowSize: () => ({y: height}), Controls: {getByType: () => windows}}, {}, {
                get: (group, key) => key
            }, {});
        }
    });

    function create() {
        const events = {};
        const calls = [];
        const control = {
            ...definition,
            Background: {
                getElm: () => ({classList: {add() {}}, style: {removeProperty() {}}}),
                setAttribute() {}
            },
            parent() {},
            addEvents(value) { Object.assign(events, value); },
            destroy() { calls.push('destroy'); },
            $render() {
                assert(control.content, 'SimpleWindow must create its content before rendering');
                calls.push('render');
            },
            $focusForm() { calls.push('focus'); }
        };
        control.initialize({});
        windows.push(control);
        return {control, events, calls};
    }
    return {create, window, document};
}

test('login content is rendered after SimpleWindow creates and opens the window', async () => {
    const {create} = fixture();
    const {control, events, calls} = create();
    control.parent = () => {
        control.content = {};
        events.onOpen();
        return Promise.resolve();
    };
    await control.open();
    assert.deepEqual(calls, ['render', 'focus']);
    assert.equal(control.$opened, true);
});

test('a complete long login grows beyond the former fixed height when the viewport allows it', () => {
    const {create} = fixture({height: 1000});
    const {control} = create();
    control.getAttribute = () => false;
    control.getContent = () => ({children: [
        {dataset: {name: 'login-close'}, computedStyle: {position: 'absolute'}, offsetHeight: 40},
        {dataset: {name: 'login-header'}, offsetHeight: 200},
        {
            dataset: {name: 'login-body'}, firstElementChild: {scrollHeight: 500},
            computedStyle: {paddingTop: '40', paddingBottom: '40'}
        }
    ]});
    assert.equal(control.getOpeningHeight(), 780);
});

test('a login taller than the viewport keeps room around the window', () => {
    const {create} = fixture({height: 700});
    const {control} = create();
    control.getAttribute = () => false;
    control.getContent = () => ({children: [
        {dataset: {name: 'login-header'}, offsetHeight: 200},
        {dataset: {name: 'login-body'}, firstElementChild: {scrollHeight: 600}}
    ]});
    assert.equal(control.getOpeningHeight(), 676);
});

test('a concurrent login popup cannot replace the active popup', async () => {
    const {create} = fixture();
    const first = create();
    first.control.parent = () => Promise.resolve();
    await first.control.open();
    const second = create();
    second.control.open();
    assert.deepEqual(second.calls, ['destroy']);
    assert.equal(first.control.$opened, true);
});

test('closing disposes pending authentication and restores the triggering element', () => {
    const {create, document} = fixture();
    const {control, events, calls} = create();
    const ReturnFocus = document.activeElement;
    control.open();
    control.$Login = {destroy() { calls.push('destroy-login'); }};
    control.close();
    events.onClose();
    assert.equal(control.$opened, false);
    assert.equal(control.$Login, null);
    assert.deepEqual(calls, ['destroy-login']);
    assert.equal(ReturnFocus.restored, true);
});

test('cancelling retains the backend redirect without redirecting frontend consumers', () => {
    const {create, window} = fixture();
    const {events} = create();
    window.QUIQQER.inAdministration = false;
    events.onCancel();
    assert.equal(window.location, undefined);
    window.QUIQQER.inAdministration = true;
    events.onCancel();
    assert.equal(window.location, '/admin/');
    assert.equal(window.onbeforeunload, null);
});
