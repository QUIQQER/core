const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    let definition;
    let response;
    const footer = {children: [], prepend(node) { this.children.unshift(node); }};
    const form = {clientHeight: 600, clientWidth: 800, classList: {add() {}}};
    const container = {
        closest: () => form,
        querySelector: selector => selector === '.pDiv2' ? footer : footer.children[0] || null
    };
    const dependencies = {
        Locale: {get: (group, key, values) => values ?
            values.count + (key.endsWith('.one') ? ' Adresse' : ' Adressen') : key},
        Ajax: {get: (url, callback) => { response = callback; }},
        'controls/grid/Grid': function (element, options) {
            this.container = element;
            this.options = options;
            this.addEvents = () => {};
            this.refresh = () => options.onrefresh();
            this.setData = data => { this.data = data; };
        }
    };
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/users/User.js'), 'utf8'), {
        console,
        document: {createElement: () => ({dataset: {}, setAttribute() {}})},
        Element: function () {},
        Class: function (value) { definition = value; return value; },
        define: (name, names, factory) => factory(...names.map(name => dependencies[name] || {}))
    });
    const panel = {
        ...definition,
        getContent: () => ({querySelector: () => container}),
        getUser: () => ({getId: () => 'test-user'})
    };
    panel.$createAddressTable();
    return {panel, footer, respond: rows => response(rows)};
}

test('address toolbar matches user manager and enables the native export', () => {
    const {panel} = fixture();
    const options = panel.$AddressGrid.options;
    assert.equal(options.exportData, true);
    assert.equal(options['button-reload'], true);
    assert.deepEqual(Array.from(options.buttons, button => button.name), ['add', 'edit', 'delete']);
    const remove = options.buttons[2];
    assert.equal(remove.position, 'right');
    assert.equal(remove.icon, 'fa fa-trash-o');
    assert.equal(remove.text, undefined);
    assert.equal(remove.textimage, undefined);
    assert.equal(remove.disabled, true);
    assert.equal(remove.title, 'users.user.address.table.btn.delete');
    assert.equal(options.columnModel.find(column => column.dataIndex === 'default').export, false);
});

test('address count stays at the start of the footer and updates without duplicates', () => {
    const {panel, footer, respond} = fixture();
    for (const count of [0, 1, 3, 1]) {
        panel.$AddressGrid.refresh();
        respond(Array.from({length: count}, () => ({default: false})));
        assert.equal(footer.children.length, 1);
        assert.equal(footer.children[0].dataset.name, 'address-count');
        assert.equal(footer.children[0].textContent, count + (count === 1 ? ' Adresse' : ' Adressen'));
        assert.equal(panel.$AddressGrid.data.data.length, count);
    }
});
