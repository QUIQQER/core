const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

class Node {
    constructor(tagName, text = '', attributes = {}) {
        this.tagName = tagName;
        this.textContent = text;
        this.attributes = new Map(Object.entries(attributes));
        this.children = [];
        this.listeners = new Map();
    }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    hasAttribute(name) { return this.attributes.has(name); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    removeAttribute(name) { this.attributes.delete(name); }
    contains(Node) { return Node === this || this.children.includes(Node); }
    getElementsByTagName(name) { return this.children.filter(Node => Node.tagName === name.toUpperCase()); }
    getBoundingClientRect() { return {x: 10, y: 20, width: 100, height: 34}; }
    addEventListener(name, callback) {
        if (!this.listeners.has(name)) this.listeners.set(name, new Set());
        this.listeners.get(name).add(callback);
    }
    removeEventListener(name, callback) { this.listeners.get(name)?.delete(callback); }
    emit(name, Target, values = {}) {
        for (const callback of this.listeners.get(name) || []) {
            callback({type: name, composedPath: () => [Target, this], ...values});
        }
    }
}

function fixture() {
    const Root = new Node('DIV');
    const Button = new Node('BUTTON', '', {title: 'Speichern'});
    Button.parentElement = Root;
    Root.children.push(Button);
    const observers = [];
    const tooltips = [];
    const callbacks = {};
    const document = {activeElement: null};
    const Panel = {
        $Buttons: Root,
        addEvent(name, callback) {
            const previous = callbacks[name];
            callbacks[name] = () => { previous?.(); callback(); };
        }
    };
    let attach;
    vm.runInNewContext(readFileSync(join(__dirname, '../../bin/QUI/utils/PanelTooltips.js'), 'utf8'), {
        URL_OPT_DIR: '/opt/',
        document,
        window: {getComputedStyle: Node => ({zIndex: Node.zIndex ?? 'auto'})},
        MutationObserver: class {
            constructor(callback) { this.callback = callback; observers.push(this); }
            observe() {}
            disconnect() { this.disconnected = true; }
        },
        define(name, dependencies, factory) {
            attach = factory({get: () => 'Schließen'}, (Reference, options) => {
                const Tooltip = {
                    props: {zIndex: 9999, ...options},
                    reference: Reference,
                    popper: {id: 'tip-' + tooltips.length},
                    popperInstance: {update() {}},
                    show() { this.props.onShow?.(this); this.visible = true; },
                    hide() { this.visible = false; },
                    setContent(content) { this.props.content = content; },
                    setProps(props) { Object.assign(this.props, props); },
                    destroy() { this.destroyed = true; delete Reference._tippy; }
                };
                Reference._tippy = Tooltip;
                tooltips.push(Tooltip);
                return Tooltip;
            });
        }
    });
    return {Root, Button, Panel, document, observers, tooltips, callbacks, attach};
}

test('one delegated toolbar tooltip leaves custom button tooltips intact', () => {
    const {Root, Button, Panel, observers, tooltips, attach} = fixture();
    const Custom = new Node('BUTTON', '', {title: 'Eigener Tooltip'});
    Custom._tippy = {};
    Root.children.push(Custom);
    attach(Panel);
    attach(Panel);
    assert.equal(tooltips.length, 1);
    assert.equal(observers.length, 1);
    assert.equal(Button.getAttribute('title'), null);
    assert.equal(Button.getAttribute('aria-label'), 'Speichern');
    assert.equal(Custom.getAttribute('title'), 'Eigener Tooltip');
});

test('disabled hover uses an enabled toolbar reference and renders titles as plain text', () => {
    const {Root, Button, Panel, tooltips, attach} = fixture();
    Button.disabled = true;
    Button.setAttribute('title', '<img src=x onerror=alert(1)>');
    attach(Panel);
    Root.emit('pointerover', Button);
    assert.equal(tooltips[0].reference, Root);
    assert.equal(Button.disabled, true);
    assert.equal(tooltips[0].props.allowHTML, false);
    assert.equal(tooltips[0].props.delay, 0);
    assert.equal(tooltips[0].props.content, '<img src=x onerror=alert(1)>');
    assert.equal(tooltips[0].visible, true);
});

test('late title updates refresh an open tooltip and its generated accessible name', () => {
    const {Root, Button, Panel, observers, tooltips, attach} = fixture();
    attach(Panel);
    Root.emit('focusin', Button);
    Button.setAttribute('title', 'Jetzt speichern');
    observers[0].callback();
    assert.equal(Button.getAttribute('title'), null);
    assert.equal(Button.getAttribute('aria-label'), 'Jetzt speichern');
    assert.equal(tooltips[0].props.content, 'Jetzt speichern');
});

test('keyboard focus and Escape preserve existing accessible descriptions', () => {
    const {Root, Button, Panel, tooltips, attach} = fixture();
    Button.setAttribute('aria-describedby', 'existing-help');
    attach(Panel);
    Root.emit('focusin', Button);
    assert.equal(Button.getAttribute('aria-describedby'), 'existing-help tip-0');
    Root.emit('keydown', Button, {key: 'Escape'});
    assert.equal(tooltips[0].visible, false);
    assert.equal(Button.getAttribute('aria-describedby'), 'existing-help');
});

test('late buttons are observed and removing the hovered button hides its tooltip', () => {
    const {Root, Panel, observers, tooltips, attach} = fixture();
    attach(Panel);
    const Late = new Node('BUTTON', 'Neu', {title: 'Später hinzugefügt'});
    Root.children.push(Late);
    observers[0].callback();
    Root.emit('pointerover', Late);
    assert.equal(tooltips[0].props.content, 'Später hinzugefügt');
    Root.children.pop();
    observers[0].callback();
    assert.equal(tooltips[0].visible, false);
    assert.equal(Late.getAttribute('title'), 'Später hinzugefügt');
    assert.equal(Late.getAttribute('aria-describedby'), null);
});

test('destroy removes handlers, observer and tooltip while restoring original title semantics', () => {
    const {Root, Button, Panel, observers, tooltips, callbacks, attach} = fixture();
    attach(Panel);
    Root.emit('pointerover', Button);
    callbacks.onDestroy();
    assert.equal(observers[0].disconnected, true);
    assert.equal(tooltips[0].destroyed, true);
    assert.equal(Button.getAttribute('title'), 'Speichern');
    assert.equal(Button.getAttribute('aria-label'), null);
    assert.equal(Button.getAttribute('aria-describedby'), null);
    assert.ok([...Root.listeners.values()].every(callbacks => callbacks.size === 0));
});

test('a toolbar in a stacked window places its tooltip just above its highest ancestor', () => {
    const {Root, Button, Panel, tooltips, attach} = fixture();
    Root.parentElement = {zIndex: 20000, parentElement: {zIndex: 23000}};
    attach(Panel);
    Root.emit('pointerover', Button);
    assert.equal(tooltips[0].props.zIndex, 23001);
});

test('header close buttons use the translated action while panel content stays outside the scope', () => {
    const {Root, Panel, tooltips, observers, callbacks, attach} = fixture();
    const Header = new Node('DIV');
    const Close = new Node('BUTTON', '', {name: 'close'});
    Close.parentElement = Header;
    Header.children.push(Close);
    const Content = new Node('DIV');
    const ContentButton = new Node('BUTTON', '', {title: 'Content title'});
    Content.children.push(ContentButton);
    Panel.$Header = Header;
    Panel.$Content = Content;
    attach(Panel);
    attach(Panel);
    assert.equal(tooltips.length, 2);
    assert.equal(observers.length, 2);
    Header.emit('focusin', Close);
    assert.equal(tooltips[1].visible, true);
    assert.equal(tooltips[1].props.content, 'Schließen');
    assert.equal(Close.getAttribute('aria-label'), 'Schließen');
    assert.equal(ContentButton.getAttribute('title'), 'Content title');
    callbacks.onDestroy();
    assert.ok(tooltips.every(Tooltip => Tooltip.destroyed));
    assert.ok(observers.every(Observer => Observer.disconnected));
    assert.ok([...Header.listeners.values(), ...Root.listeners.values()].every(callbacks => callbacks.size === 0));
});
