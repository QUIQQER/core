const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture(state) {
    const fields = {};
    const State = {value: ''};
    const saves = [];
    const root = {
        set() {},
        getElement(selector) { return fields[selector] ??= {value: '', checked: false}; },
        querySelector(selector) {
            assert.equal(selector, '[data-name="state"]');
            return State;
        }
    };
    const dependencies = {
        Ajax: {
            get(names, callback) { callback('', {city: 'Montréal', country: 'CA', state, mail: '[]', phone: '[]'}); },
            post(name, callback, params) { saves.push(JSON.parse(params.data)); callback(); }
        },
        Locale: {get: (group, key) => key},
        'controls/grid/Grid': class {
            addEvents() {}
            setData(value) { this.data = value.data; }
            getData() { return this.data; }
        }
    };
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/users/Address.js'), 'utf8'), {
        JSON: {...JSON, decode: JSON.parse, encode: JSON.stringify},
        Class: function (value) { definition = value; return value; },
        define(name, names, factory) { factory(...names.map(name => dependencies[name] ?? {})); }
    });
    const control = {
        ...definition,
        Loader: {show() {}, hide() {}},
        getElm: () => root,
        getAttribute: () => 'test',
        fireEvent() {}
    };
    control.$onInject();
    return {State, saves, control};
}

test('the address editor loads, updates and clears the optional state', () => {
    const {State, saves, control} = fixture('Québec');
    assert.equal(State.value, 'Québec');
    State.value = 'Ontario';
    control.save();
    assert.equal(saves[0].state, 'Ontario');
    assert.equal(saves[0].city, 'Montréal');
    assert.equal(saves[0].country, 'CA');
    State.value = '';
    control.save();
    assert.equal(saves[1].state, '');
});

test('addresses without state data show an empty optional input', () => {
    assert.equal(fixture(undefined).State.value, '');
    assert.equal(fixture(null).State.value, '');
});
