const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');
const {Node} = require('./fixtures/ManagerPanelToolbar.cjs');

class Element extends Node {
    constructor(tag) {
        super(tag);
        this.style.removeProperty = name => { delete this.style[name]; };
    }
}

class Events {
    constructor() { this.events = new Map(); }
    addEvent(name, callback) {
        if (!this.events.has(name)) this.events.set(name, new Set());
        this.events.get(name).add(callback);
        return this;
    }
    fire(name) { for (const callback of this.events.get(name) || []) callback(); }
}

function fixture() {
    const observers = [];
    const toolbar = new Element('div');
    const toolbarAttributes = {};
    class Button extends Events {
        constructor(options) {
            super();
            this.options = options;
            this.element = new Element('button');
            this.element.textContent = options.text;
            this.items = [];
            this.menu = new Events();
            this.menu.element = new Element('div');
            this.menu.getElm = () => this.menu.element;
            this.menu.getChildren = () => this.items;
            this.menu.hide = () => this.menu.fire('hide');
        }
        getElm() { return this.element; }
        getContextMenu(callback) { callback(this.menu); }
    }
    const translations = {
        'panel.actions': 'Aktionen',
        'panel.user.activate': 'Benutzer aktivieren',
        'panel.user.deactivate': 'Benutzer deaktivieren',
        'panel.group.activate': 'Gruppe aktivieren',
        'panel.group.deactivate': 'Gruppe deaktivieren'
    };
    let buttons;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/utils/EntityPanelButtons.js'), 'utf8'), {
        define: (name, dependencies, factory) => { buttons = factory({get: (group, key) => translations[key]}, Button); },
        MutationObserver: class {
            constructor(callback) { this.callback = callback; observers.push(this); }
            observe(target, options) { this.target = target; this.options = options; }
            disconnect() { this.disconnected = true; }
        }
    });
    const panel = {getButtonBar: () => ({setAttribute: (key, value) => { toolbarAttributes[key] = value; }})};
    return {buttons, panel, toolbar, toolbarAttributes, observers};
}

test('actions use a translated native button and standard right-aligned menu', () => {
    const {buttons, panel, toolbar, toolbarAttributes} = fixture();
    const Button = buttons.createActions(panel);
    toolbar.appendChild(Button.getElm());
    Button.fire('inject');
    assert.equal(Button.options.name, 'extra');
    assert.equal(Button.options.text, 'Aktionen');
    assert.equal(Button.options.menuCorner, 'topRight');
    assert.equal(Button.getElm().tabIndex, 0);
    assert.equal(Button.getElm().type, 'button');
    assert.equal(toolbarAttributes.width, '100%');
    assert.equal(Button.getElm().getAttribute('aria-haspopup'), 'menu');
    assert.equal(Button.menu.getElm().getAttribute('aria-label'), 'Aktionen');
    assert.equal(Button.getElm().getAttribute('aria-expanded'), 'false');
    Button.menu.fire('show');
    assert.equal(Button.getElm().getAttribute('aria-expanded'), 'true');
    Button.menu.hide();
    assert.equal(Button.getElm().getAttribute('aria-expanded'), 'false');
});

test('actions expose disabled menu entries and Escape closes the menu and restores focus', () => {
    const {buttons, panel, toolbar} = fixture();
    const Button = buttons.createActions(panel);
    toolbar.appendChild(Button.getElm());
    Button.fire('inject');
    const entry = new Element('div');
    Button.items.push({getElm: () => entry, isDisabled: () => true});
    Button.menu.fire('show');
    assert.equal(entry.getAttribute('role'), 'menuitem');
    assert.equal(entry.getAttribute('aria-disabled'), 'true');
    Button.menu.getElm().emit('keydown', {key: 'Escape'});
    assert.equal(Button.getElm().getAttribute('aria-expanded'), 'false');
    assert.equal(Button.getElm().focused, true);
    Button.fire('destroy');
    assert.equal(Button.menu.getElm().listeners.get('keydown').size, 0);
    assert.equal(Button.getElm().listeners.get('keydown').size, 0);
});

for (const [entity, activate, deactivate] of [
    ['user', 'Benutzer aktivieren', 'Benutzer deaktivieren'],
    ['group', 'Gruppe aktivieren', 'Gruppe deaktivieren']
]) {
    test(entity + ' status keeps its visible text and only changes the action tooltip', () => {
        const {buttons, observers} = fixture();
        const element = new Element('button');
        element.textContent = 'Ist aktiviert';
        const switchElement = new Element('div');
        let active = true;
        const Status = Object.assign(new Events(), {
            getElm: () => element,
            getStatus: () => active,
            $Switch: {getElm: () => switchElement}
        });
        buttons.decorateStatus(Status, entity);
        assert.equal(element.textContent, 'Ist aktiviert');
        assert.equal(element.getAttribute('title'), deactivate);
        assert.equal(element.getAttribute('aria-pressed'), 'true');
        assert.equal(switchElement.getAttribute('aria-hidden'), 'true');
        element.emit('mouseenter');
        assert.equal(element.textContent, 'Ist aktiviert');
        active = false;
        observers[0].callback();
        assert.equal(element.textContent, 'Ist aktiviert', 'model callbacks retain ownership of the visible status text');
        assert.equal(element.getAttribute('title'), activate);
        assert.equal(element.getAttribute('aria-label'), activate);
        assert.equal(element.getAttribute('aria-pressed'), 'false');
        active = true;
        Status.fire('change');
        assert.equal(element.getAttribute('title'), deactivate);
        Status.fire('destroy');
        assert.equal(observers[0].disconnected, true);
    });
}

for (const entity of ['user', 'group']) {
    test(entity + ' detail preserves its existing menu actions, handlers and permissions', () => {
        let definition;
        const children = [];
        const decorations = [];
        const added = [];
        const actions = {appendChild: item => children.push(item)};
        class Control {
            constructor(options) { this.options = options; }
            inject() { return this; }
        }
        const modules = {
            'qui/controls/buttons/Button': Control,
            'qui/controls/buttons/ButtonSwitch': Control,
            Locale: {get: (group, key) => key},
            Users: {addEvent() {}},
            Ajax: {get() {}},
            'utils/EntityPanelButtons': {
                createActions: () => actions,
                decorateStatus: (Status, type) => decorations.push({Status, type})
            }
        };
        vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/' + entity + 's/' +
            (entity === 'user' ? 'User' : 'Group') + '.js'), 'utf8'), {
            define: (name, dependencies, factory) => { definition = factory(...dependencies.map(name => modules[name] || {})); },
            Class: function(value) { return value; }
        });
        const panel = {
            ...definition,
            getElm: () => new Element('div'),
            getHeader: () => new Element('div'),
            getUser: () => ({getId: () => 42}),
            addButton: item => added.push(item),
            Loader: {show() {}}
        };
        if (entity === 'user') panel.$onCreate();
        else panel.$drawButtons();
        assert.deepEqual(children.map(item => item.name), entity === 'user' ?
            ['sendMail', 'userWipe', 'userDelete'] : ['sendMail', 'groupDelete']);
        assert.equal(children[0].disabled, true, 'mail remains disabled until permission checks finish');
        assert.equal(children[0].events.onClick, panel.$onClickSendMail);
        assert.equal(children.at(-1).events.onClick, entity === 'user' ? panel.$onClickDel : panel.del);
        assert.equal(decorations[0].type, entity);
        assert.equal(decorations[0].Status.options.name, 'status');
        assert.equal(decorations[0].Status.options.disabled, true);
        assert.ok(added.includes(actions));
    });
}
