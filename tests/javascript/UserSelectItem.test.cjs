const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
}

function element() {
    return {
        style: {}, attributes: {}, textContent: '',
        setAttribute(key, value) { this.attributes[key] = value; },
        removeAttribute(key) { delete this.attributes[key]; }
    };
}

// Exercise the actual legacy model: missing users reject with the model,
// change their ID to zero, and remain marked as loaded in the manager's cache.
function userModel(response) {
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/classes/users/User.js'), 'utf8'), {
        Class: function(value) { return value; },
        define(name, dependencies, factory) {
            definition = factory({}, {}, {get(name, callback) { callback(response); }}, {});
        },
        require(names, callback) { callback({onRefreshUser() {}}); }
    });
    const User = {
        ...definition,
        setAttributes(values) { Object.assign(this.attributes, values); },
        getAttribute(key) { return this.attributes[key]; },
        fireEvent() {}, updateOriginalAttributes() {}
    };
    User.initialize(42);
    return User;
}

function fixture(Entity, mixed = false, selectedId = mixed ? 'u42' : 42) {
    let definition;
    const errors = [];
    const events = {};
    const elm = element();
    const attributes = {id: selectedId};
    const modules = {
        Users: {get: () => Entity}, Groups: {get: () => Entity},
        Locale: {get: () => 'Requested user was not found'}
    };
    const file = mixed ? 'usersAndGroups' : 'users';
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/' + file + '/SelectItem.js'), 'utf8'), {
        Class: function(value) { return value; },
        console: {error: error => errors.push(error)},
        define(name, dependencies, factory) { definition = factory(...dependencies.map(name => modules[name] || {})); }
    });
    let destroyed = false;
    const Item = {
        ...definition,
        parent() {}, addEvent(name, callback) { events[name] = callback; },
        getAttribute: key => attributes[key],
        setAttribute(key, value) { attributes[key] = value; },
        getElm: () => elm,
        destroy() { destroyed = true; },
        $Text: element(), $Destroy: element()
    };
    Item.initialize({});
    return {Item, elm, attributes, errors, events, wasDestroyed: () => destroyed};
}

for (const mixed of [false, true]) {
    test((mixed ? 'mixed' : 'user') + ' selection retains a missing reference and explains the actual model rejection', async () => {
        const User = userModel({id: 0});
        const {Item, errors, elm, attributes, wasDestroyed} = fixture(User, mixed);
        await Item.refresh();
        assert.equal(User.isLoaded(), true);
        assert.equal(User.getId(), 0);
        assert.equal(wasDestroyed(), false);
        assert.equal(attributes.id, mixed ? 'u42' : 42);
        assert.match(Item.$Text.textContent, /Requested user was not found \(42\)/);
        assert.equal(Item.$Text.attributes.role, 'alert');
        assert.equal(elm.attributes['aria-invalid'], 'true');
        assert.equal(errors[0].name, 'Error');
        assert.equal(String(errors[0].userId), '42');
        assert.notEqual(errors[0], User);
        await Item.refresh();
        assert.equal(errors.length, 2, 'cached invalid model is still an error');
        assert.match(Item.$Text.textContent, /\(42\)/);
    });

    test((mixed ? 'mixed' : 'user') + ' selection ignores late completion after destruction', async () => {
        const pending = deferred();
        const User = {isLoaded: () => false, load: () => pending.promise, getId: () => 0};
        const {Item, events, errors} = fixture(User, mixed);
        const loading = Item.refresh();
        events.onDestroy();
        pending.reject(User);
        await loading;
        assert.equal(Item.$Text.textContent, '');
        assert.equal(errors.length, 0);
    });

    test((mixed ? 'mixed' : 'user') + ' selection reports real request errors unchanged without clearing the selection', async () => {
        const error = new Error('Permission denied');
        const User = {isLoaded: () => false, load: () => Promise.reject(error), getId: () => 42};
        const {Item, errors, wasDestroyed} = fixture(User, mixed);
        await Item.refresh();
        assert.equal(errors[0], error);
        assert.equal(Item.$Text.textContent, 'Permission denied');
        assert.equal(wasDestroyed(), false);
    });

    test((mixed ? 'mixed' : 'user') + ' selection renders valid names as text and supports the explicit Nobody user', async () => {
        const User = {
            isLoaded: () => true, getId: () => 0,
            getAttribute: () => '<Nobody>', getName: () => '<Nobody>'
        };
        const {Item, errors} = fixture(User, mixed, mixed ? 'u0' : 0);
        await Item.refresh();
        assert.equal(Item.$Text.textContent, '<Nobody>');
        assert.equal(errors.length, 0);
    });
}

test('mixed selection retains normal group rendering', async () => {
    const Group = {isLoaded: () => true, getName: () => 'Staff'};
    const {Item, errors, attributes} = fixture(Group, true, 'g23');
    await Item.refresh();
    assert.equal(Item.$Text.textContent, 'Staff');
    assert.equal(attributes.icon, 'fa fa-group');
    assert.equal(errors.length, 0);
});

test('an older refresh cannot replace the current name', async () => {
    const first = deferred();
    const second = deferred();
    let count = 0;
    let name = 'current';
    const User = {
        isLoaded: () => false, load: () => ++count === 1 ? first.promise : second.promise,
        getId: () => 42, getName: () => name, getAttribute: () => name
    };
    const {Item} = fixture(User);
    const older = Item.refresh();
    const latest = Item.refresh();
    second.resolve();
    await latest;
    name = 'obsolete';
    first.resolve();
    await older;
    assert.equal(Item.$Text.textContent, 'current');
});
