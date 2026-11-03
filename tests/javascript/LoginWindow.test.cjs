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

function sizingFixture({height = 1000, loaded = true, dialogHeight = 320, contentHeight = 300} = {}) {
    const {create, window} = fixture({height});
    const {control, events} = create();
    const frames = new Map();
    const observers = [];
    const animations = [];
    let frameId = 0;
    const classes = new Set();
    const listeners = new Map();
    const Login = {scrollHeight: contentHeight};
    const Header = {dataset: {name: 'login-header'}, offsetHeight: 200};
    const Body = {
        dataset: {name: 'login-body'}, firstElementChild: Login,
        computedStyle: {paddingTop: '40', paddingBottom: '40'},
        classList: {add: name => classes.add(name), remove: name => classes.delete(name)},
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: name => listeners.delete(name),
        dispatchEvent: event => listeners.get(event.type)?.(event)
    };

    window.requestAnimationFrame = callback => {
        frames.set(++frameId, callback);
        return frameId;
    };
    window.cancelAnimationFrame = id => frames.delete(id);
    window.ResizeObserver = class {
        constructor(callback) {
            this.callback = callback;
            this.targets = [];
            observers.push(this);
        }
        observe(target) { this.targets.push(target); }
        disconnect() { this.targets = []; }
    };
    control.getAttribute = () => false;
    control.getElm = () => ({getBoundingClientRect: () => ({height: dialogHeight})});
    control.getContent = () => ({children: [Header, Body]});
    control.$Body = Body;
    control.$Login = {getElm: () => Login, destroy() {}};
    control.$opened = true;
    control.$contentLoaded = loaded;
    control.$contentSizingActive = true;
    control.parent = () => {
        const target = control.getOpeningHeight();
        return new Promise(resolve => animations.push({
            target,
            finish() {
                dialogHeight = target;
                resolve();
            }
        }));
    };
    control.$startContentSizing();

    return {
        control, events, animations, observers, frames, Login, Header, Body,
        scrolling: () => classes.has('quiqqer-loginWindow-body--ready'),
        flushFrame() {
            const pending = [...frames.values()];
            frames.clear();
            pending.forEach(callback => callback());
        },
        async finishResize(index = animations.length - 1) {
            animations[index].finish();
            await Promise.resolve();
            await Promise.resolve();
        }
    };
}

test('initial content stays non-scrollable until loading and resizing have finished', async () => {
    const state = sizingFixture({loaded: false});
    state.flushFrame();
    assert.equal(state.scrolling(), false);
    state.Login.scrollHeight = 400;
    state.observers[0].callback();
    state.flushFrame();
    assert.equal(state.animations.length, 0);

    state.control.$contentLoaded = true;
    state.control.$queueContentResize();
    state.flushFrame();
    assert.equal(state.animations.length, 1);
    assert.equal(state.animations[0].target, 680);
    assert.equal(state.scrolling(), false);
    await state.finishResize();
    state.flushFrame();
    assert.equal(state.scrolling(), true);
});

test('independent content growth during resizing keeps scrolling disabled until the final height', async () => {
    const state = sizingFixture({dialogHeight: 580});
    state.flushFrame();
    assert.equal(state.scrolling(), true);
    assert.deepEqual(state.observers[0].targets, [state.Header, state.Login]);

    state.Login.scrollHeight = 400;
    state.observers[0].callback();
    assert.equal(state.scrolling(), false);
    state.flushFrame();
    assert.equal(state.animations[0].target, 680);

    state.Login.scrollHeight = 450;
    state.observers[0].callback();
    assert.equal(state.animations.length, 1);
    await state.finishResize();
    state.flushFrame();
    assert.equal(state.scrolling(), false);
    assert.equal(state.animations[1].target, 730);
    await state.finishResize();
    state.flushFrame();
    assert.equal(state.scrolling(), true);
});

for (const [view, initialHeight, intermediateHeight, finalHeight] of [
    ['password reset', 300, 360, 420],
    ['login', 420, 350, 300]
]) {
    test(`${view} transition resizes once after its animation and final layout cleanup`, async () => {
        const state = sizingFixture({dialogHeight: initialHeight + 280, contentHeight: initialHeight});
        state.flushFrame();
        state.Body.dispatchEvent({type: 'quiqqer-login-view-change', detail: {pending: true}});
        assert.equal(state.scrolling(), false);

        state.Login.scrollHeight = intermediateHeight;
        state.observers[0].callback();
        state.flushFrame();
        assert.equal(state.animations.length, 0);

        state.Login.scrollHeight = finalHeight;
        state.observers[0].callback();
        state.Body.dispatchEvent({type: 'quiqqer-login-view-change', detail: {pending: false}});
        state.flushFrame();
        assert.equal(state.animations.length, 1);
        assert.equal(state.animations[0].target, finalHeight + 280);
        assert.equal(state.scrolling(), false);
        await state.finishResize();
        state.flushFrame();
        assert.equal(state.animations.length, 1);
        assert.equal(state.scrolling(), true);
    });
}

test('overlapping view transitions wait until all animations have finished', () => {
    const state = sizingFixture({dialogHeight: 580});
    state.flushFrame();
    const dispatch = pending => state.Body.dispatchEvent({
        type: 'quiqqer-login-view-change', detail: {pending}
    });
    dispatch(true);
    dispatch(true);
    state.Login.scrollHeight = 400;
    dispatch(false);
    state.flushFrame();
    assert.equal(state.animations.length, 0);
    dispatch(false);
    state.flushFrame();
    assert.equal(state.animations.length, 1);
});

test('viewport-limited content remains scrollable without toggling its scrollbar on observer callbacks', () => {
    const state = sizingFixture({height: 700, dialogHeight: 676, contentHeight: 600});
    state.flushFrame();
    assert.equal(state.scrolling(), true);
    state.Login.scrollHeight = 650;
    state.observers[0].callback();
    assert.equal(state.scrolling(), true);
    state.flushFrame();
    assert.equal(state.animations.length, 0);
});

test('completion of an older resize cannot restore scrolling during a newer resize', async () => {
    const state = sizingFixture({dialogHeight: 580});
    state.flushFrame();
    state.control.resize();
    state.control.resize();
    await state.finishResize(0);
    state.flushFrame();
    assert.equal(state.scrolling(), false);
    await state.finishResize(1);
    state.flushFrame();
    assert.equal(state.scrolling(), true);
});

test('an already queued size check waits for an explicitly started animation', async () => {
    const state = sizingFixture({dialogHeight: 580});
    state.control.resize();
    state.flushFrame();
    assert.equal(state.scrolling(), false);
    assert.equal(state.animations.length, 1);
    await state.finishResize();
    state.flushFrame();
    assert.equal(state.scrolling(), true);
});

test('closing releases size observers and pending frames and ignores late resize completions', async () => {
    const state = sizingFixture();
    state.flushFrame();
    state.events.onCloseBegin();
    await state.finishResize();
    state.observers[0].callback();
    assert.equal(state.frames.size, 0);
    assert.equal(state.scrolling(), false);
    assert.deepEqual(state.observers[0].targets, []);

    const pending = sizingFixture();
    pending.events.onDestroy();
    assert.equal(pending.frames.size, 0);
});

for (const method of ['$showPasswordReset', '$showPassword']) {
    test(`${method} reports completion only after clearing the temporary content height`, async () => {
        const animations = [];
        const events = [];
        const input = {focus() {}};
        const view = () => ({
            style: {},
            setStyle(name, value) { this.style[name] = value; },
            getSize: () => ({y: 400}),
            getElement: () => input
        });
        const Login = view();
        const Reset = view();
        const Container = {
            style: {}, offsetHeight: 300,
            getElement: selector => selector === '.quiqqer-auth-login-container' ? Login : Reset,
            dispatchEvent(event) {
                events.push({
                    type: event.type, pending: event.detail.pending, bubbles: event.bubbles,
                    height: this.style.height, overflow: this.style.overflow
                });
            }
        };
        let definition;
        vm.runInNewContext(readFileSync(resolve(
            __dirname, '../../bin/QUI/controls/users/auth/QUIQQERLogin.js'
        ), 'utf8'), {
            Class: function (value) { definition = value; return value; },
            define(name, dependencies, factory) { factory({}, {}, {}, {}, {}); },
            CustomEvent: class {
                constructor(type, options) { this.type = type; Object.assign(this, options); }
            },
            moofx: target => ({animate(properties, options) {
                animations.push(() => {
                    Object.assign(target.style, properties);
                    options.callback();
                });
            }})
        });

        const completion = definition[method].call({getElm: () => Container, fireEvent() {}});
        assert.deepEqual(events, [{
            type: 'quiqqer-login-view-change', pending: true, bubbles: true,
            height: '300px', overflow: 'hidden'
        }]);
        animations.shift()();
        assert.equal(events.length, 1);
        animations.shift()();
        animations.shift()();
        await completion;
        assert.deepEqual(events[1], {
            type: 'quiqqer-login-view-change', pending: false, bubbles: true,
            height: null, overflow: null
        });
    });
}
