const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/menu/BackendMenu.js'), 'utf8'), {
        URL,
        document: {baseURI: 'https://example.test/admin/'},
        Class: function (value) { definition = value; return value; },
        define: (name, names, factory) => factory(...names.map(() => ({})))
    });
    return {...definition, $actions: new Map()};
}

function item(attributes, children = []) {
    return {
        getType: () => attributes.separator ? 'qui/controls/contextmenu/Separator' : 'qui/controls/contextmenu/Item',
        getAttribute: name => attributes[name],
        isDisabled: () => Boolean(attributes.disabled),
        getChildren: () => children
    };
}

test('retains original action objects and exposes every submenu, including actionable groups', () => {
    const menu = fixture();
    const leaf = item({text: 'Users', require: 'controls/users/Panel'});
    const group = item({text: 'Administration'}, [leaf]);
    const both = item({text: 'ERP', require: 'erp/Panel'}, [item({text: 'Orders'})]);
    const entries = menu.$readItems(item({}, [group, both]));
    assert.equal(entries[0].group, true);
    assert.equal(entries[0].action, false);
    assert.equal(menu.$actions.get(entries[0].children.items[0].id), leaf);
    assert.equal(entries[1].action, true);
    assert.equal(menu.$actions.get(entries[1].id), both);
    assert.equal(menu.$actions.size, 3);
});

test('inherits disabled state, omits hidden entries and accepts separators without item methods', () => {
    const menu = fixture();
    const separator = {getType: () => 'qui/controls/contextmenu/Separator'};
    const entries = menu.$readItems(item({}, [
        item({text: 'Hidden', hidden: true}),
        separator,
        item({text: 'Disabled', disabled: true}, [item({text: 'Child'})])
    ]));
    assert.equal(entries.length, 2);
    assert.equal(entries[0].separator, true);
    assert.equal(entries[1].children.items[0].disabled, true);
});

test('keeps custom group callbacks actionable without making the default dispatcher a group action', () => {
    const menu = fixture();
    const entries = menu.$readItems(item({}, [
        item({text: 'Default', onClick: 'QUI.Menu.menuClick'}, [item({text: 'Child'})]),
        item({text: 'Custom', onClick: () => {}}, [item({text: 'Child'})])
    ]));
    assert.equal(entries[0].action, false);
    assert.equal(entries[1].action, true);
});

test('search preserves ancestors and includes all children when their group matches', () => {
    const menu = fixture();
    const entries = menu.$readItems(item({}, [item({text: 'Security'}, [
        item({text: 'Users', description: 'Manage accounts'}),
        item({text: 'Permissions'})
    ]), item({text: 'Unrelated'})]));
    assert.equal(menu.$filter(entries, 'accounts')[0].children.items.length, 1);
    assert.equal(menu.$filter(entries, 'accounts')[0].children.items[0].text, 'Users');
    assert.equal(menu.$filter(entries, 'security')[0].children.items.length, 2);
    assert.equal(menu.$filter(entries, 'absent').length, 0);
    assert.equal(entries[0].children.items.length, 2);
});

test('reads dynamically appended actions and keeps safe image URLs or icon classes', () => {
    const menu = fixture();
    const children = [item({text: 'Icon', icon: 'fa fa-user'})];
    const root = item({}, children);
    assert.equal(menu.$readItems(root)[0].icon, 'fa fa-user');
    children.push(item({text: 'Image', icon: '/logo.svg'}));
    children.push(item({text: 'Unsafe', icon: 'javascript:alert(1)'}));
    const entries = menu.$readItems(root);
    assert.equal(entries.length, 3);
    assert.equal(entries[1].image, 'https://example.test/logo.svg');
    assert.equal(entries[2].image, '');
    assert.equal(entries[2].icon, 'fa fa-circle-o');
});

test('keeps long groups together in a wide section without losing compact groups or actions', () => {
    const menu = fixture();
    const shop = item({text: 'Shop'}, Array.from({length: 12}, (_, index) => item({text: 'Entry ' + index})));
    const entries = menu.$readItems(item({}, [
        item({text: 'Users'}, [item({text: 'Groups'})]), shop, item({text: 'Trash'})
    ]));
    const view = menu.$categoryView(entries);
    assert.equal(view.groups.items[0].text, 'Users');
    assert.equal(view.links.items[0].text, 'Trash');
    assert.equal(view.wide[0].items[0], entries[1]);
    assert.equal(view.wide[0].items[0].children.items.length, 12);
    assert.equal(view.singleSection, false);
    const filtered = menu.$categoryView(menu.$filter(entries, 'entry 11'));
    assert.equal(filtered.wide.length, 0);
    assert.equal(filtered.groups.items[0].children.items.length, 1);
    assert.equal(filtered.singleSection, true);
});

test('gives nested groups an ancestor path while keeping leaf captions unchanged', () => {
    const menu = fixture();
    const entries = menu.$readItems(item({}, [item({text: 'Shop'}, [
        item({text: 'Accounting'}, [item({text: 'Incoming payments'})])
    ])]));
    const shop = entries[0];
    const accounting = shop.children.items[0];
    assert.equal(shop.nested, false);
    assert.equal(accounting.nested, true);
    assert.equal(accounting.breadcrumb, 'Shop › ');
    assert.equal(accounting.children.items[0].nested, false);
    assert.equal(accounting.children.items[0].text, 'Incoming payments');
});
