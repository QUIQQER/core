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
        'panel.group.deactivate': 'Gruppe deaktivieren',
        'panel.site.activate': 'Seite aktivieren',
        'panel.site.deactivate': 'Seite deaktivieren',
        isActivate: 'Ist aktiviert',
        isDeactivate: 'Ist deaktiviert'
    };
    let buttons;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/utils/EntityPanelButtons.js'), 'utf8'), {
        define: (name, dependencies, factory) => { buttons = factory({get: (group, key) => translations[key]}, Button); },

    });
    const panel = {getButtonBar: () => ({setAttribute: (key, value) => { toolbarAttributes[key] = value; }})};
    return {buttons, panel, toolbar, toolbarAttributes};
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
    ['group', 'Gruppe aktivieren', 'Gruppe deaktivieren'],
    ['site', 'Seite aktivieren', 'Seite deaktivieren']
]) {
    test(entity + ' status shows its current state and describes the opposite action in the tooltip', () => {
        const {buttons} = fixture();
        const element = new Element('button');
        const icon = new Element('span');
        element.appendChild(icon);
        element.firstElementChild = icon;
        let attributes = {};
        const Status = {
            getElm: () => element,
            setAttributes: value => { attributes = value; element.textContent = value.text; }
        };
        buttons.setStatus(Status, entity, true);
        assert.equal(attributes.text, 'Ist aktiviert');
        assert.equal(attributes.textimage, 'fa fa-check');
        assert.equal(attributes.title, deactivate);
        assert.equal(element.getAttribute('aria-pressed'), 'true');
        assert.equal(element.getAttribute('aria-label'), 'Ist aktiviert. ' + deactivate);
        assert.equal(icon.getAttribute('aria-hidden'), 'true');
        assert.equal(element.tabIndex, 0);
        assert.equal(element.type, 'button');
        element.emit('mouseenter');
        assert.equal(element.textContent, 'Ist aktiviert');
        buttons.setStatus(Status, entity, false);
        assert.equal(attributes.text, 'Ist deaktiviert');
        assert.equal(attributes.textimage, 'fa fa-times');
        assert.equal(attributes.title, activate);
        assert.equal(element.getAttribute('aria-pressed'), 'false');
        assert.equal(element.getAttribute('aria-label'), 'Ist deaktiviert. ' + activate);
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
            Locale: {get: (group, key) => key},
            Users: {addEvent() {}},
            Ajax: {get() {}},
            'utils/EntityPanelButtons': {
                createActions: () => actions,
                setStatus: (Status, type) => decorations.push({Status, type})
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
        assert.equal(decorations[0].Status.options.events.onClick, panel.$onStatusButtonChange);
        assert.ok(added.includes(actions));
    });
}

function activationFixture(entity, initial, failure = false) {
    let definition;
    let complete;
    let active = initial;
    let disabled = false;
    const calls = [];
    const pending = new Promise(resolve => { complete = resolve; });
    const modules = {'utils/EntityPanelButtons': {
        setStatus: (Button, type, status) => calls.push(['status', type, status])
    }};
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/' + entity + 's/' +
        (entity === 'user' ? 'User' : 'Group') + '.js'), 'utf8'), {
        Class: function(value) { return value; },
        define: (name, dependencies, factory) => {
            definition = factory(...dependencies.map(name => modules[name] || {}));
        }
    });
    const change = async value => {
        calls.push(['change', value]);
        await pending;
        if (failure) throw new Error('Request failed or cancelled');
        active = value;
    };
    const model = {isActive: () => active, activate: () => change(1), deactivate: () => change(0)};
    const Button = {isDisabled: () => disabled, disable: () => { disabled = true; }, enable: () => { disabled = false; }};
    const panel = {
        ...definition,
        getUser: () => model,
        getGroup: () => model,
        userDeactivation() { calls.push(['confirm']); return change(0); },
        Loader: {show: () => calls.push(['show']), hide: () => calls.push(['hide'])}
    };
    return {panel, Button, calls, complete, isDisabled: () => disabled, isActive: () => active};
}

for (const entity of ['user', 'group']) {
    for (const active of [0, 1]) {
        test(entity + ' click toggles ' + active + ' only after confirmation and blocks repeated clicks', async () => {
            const f = activationFixture(entity, active);
            const pending = f.panel.$onStatusButtonChange(f.Button);
            assert.equal(f.isDisabled(), true);
            await f.panel.$onStatusButtonChange(f.Button);
            assert.equal(f.isActive(), active);
            assert.deepEqual(f.calls.filter(call => call[0] === 'status'), []);
            assert.deepEqual(f.calls.filter(call => call[0] === 'change'), [['change', active ? 0 : 1]]);
            if (entity === 'user' && active) assert.ok(f.calls.some(call => call[0] === 'confirm'));
            f.complete();
            await pending;
            assert.equal(f.isActive(), active ? 0 : 1);
            assert.equal(f.isDisabled(), false);
            assert.deepEqual(f.calls.slice(-2), [['status', entity, !active], ['hide']]);
        });
    }

    test(entity + ' rejected or cancelled activation keeps the current status and permits retry', async () => {
        const f = activationFixture(entity, 1, true);
        const pending = f.panel.$onStatusButtonChange(f.Button);
        f.complete();
        await pending;
        assert.equal(f.isActive(), 1);
        assert.equal(f.isDisabled(), false);
        assert.deepEqual(f.calls.slice(-2), [['status', entity, true], ['hide']]);
    });
}

test('deleted users cannot be activated', async () => {
    const f = activationFixture('user', -1);
    await f.panel.$onStatusButtonChange(f.Button);
    assert.deepEqual(f.calls, []);
});

function siteFixture() {
    let definition;
    let loaded = false;
    let active;
    const updates = [];
    const attributes = {_onclick: 'Panel.deactivate'};
    const Status = {
        getAttribute: name => attributes[name],
        setAttribute: (name, value) => { attributes[name] = value; }
    };
    const modules = {
        'utils/EntityPanelButtons': {setStatus: (Button, type, status) => updates.push([type, status])},
        Locale: {get() {}},
        'qui/QUI': {fireEvent() {}},
        Ajax: {get: (names, callback) => callback([], [], false)},
        'qui/controls/buttons/Button': class {hide() {} show() {}}
    };
    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/projects/project/site/Panel.js'), 'utf8'), {
        Class: function(value) { return value; },
        define: (name, dependencies, factory) => {
            definition = factory(...dependencies.map(name => modules[name] || {}));
        }
    });
    const model = {
        isLoaded: () => loaded,
        getAttribute: () => active,
        setAttribute() {},
        getProject: () => ({encode: () => 'example'}),
        getId: () => 1
    };
    const panel = {
        ...definition,
        $built: false,
        $acquireEditingLock: () => Promise.resolve(true),
        $lockDestroyed: false,
        $deletedState: false,
        getSite: () => model,
        getButtons: () => Status,
        getButtonBar: () => ({getChildren: () => null}),
        addButton() {},
        refresh() {},
        $isDeleted: () => false,
        getActiveCategory: () => ({}),
        $onCategoryEnter: () => Promise.resolve(),
        $ButtonOpenWebsite: {show() {}, hide() {}},
        getContent: () => ({getElement: () => null}),
        Loader: {hide() {}}
    };
    return {panel, attributes, updates,
        setLoaded: value => { loaded = true; active = value; }};
}

test('site toolbar uses server status while its model is unloaded, then synchronizes on load', async () => {
    for (const active of [false, true]) {
        const f = siteFixture();
        f.attributes._onclick = active ? 'Panel.deactivate' : 'Panel.activate';
        await f.panel.$buildPanel();
        assert.deepEqual(f.updates, [['site', active]], 'an unloaded model must not override server status');
        await f.panel.load();
        assert.equal(f.updates.length, 1);
        f.setLoaded(active ? '1' : '0');
        await f.panel.load();
        assert.deepEqual(f.updates.at(-1), ['site', active]);
        assert.equal(f.attributes._onclick, active ? 'Panel.deactivate' : 'Panel.activate');
    }
});

test('site activation events keep current status and subsequent action synchronized', () => {
    const f = siteFixture();
    f.panel.$onSiteActivate();
    assert.deepEqual(f.updates.at(-1), ['site', true]);
    assert.equal(f.attributes._onclick, 'Panel.deactivate');
    f.panel.$onSiteDeactivate();
    assert.deepEqual(f.updates.at(-1), ['site', false]);
    assert.equal(f.attributes._onclick, 'Panel.activate');
});
