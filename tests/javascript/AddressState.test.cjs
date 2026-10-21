const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture(state, {deferLoad = false, failSave = false} = {}) {
    const fields = {};
    const State = {value: ''};
    const saves = [];
    const events = [];
    let load;
    let failLoad;
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
            get(names, callback, params) {
                load = () => callback('', {city: 'Montréal', country: 'CA', state, mail: '[]', phone: '[]'});
                failLoad = params.onError;
                if (!deferLoad) {
                    load();
                }
            },
            post(name, callback, params) {
                saves.push(JSON.parse(params.data));
                if (failSave) {
                    params.onError('save failed');
                } else {
                    callback();
                }
            }
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
        fireEvent(name, args) { events.push({name, args}); }
    };
    control.$onInject();
    return {State, saves, control, events, load, failLoad};
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

test('the editor reports load and save errors to its containing window', () => {
    const failedLoad = fixture('Québec', {deferLoad: true});
    failedLoad.failLoad('load failed');
    assert.equal(failedLoad.events[0].name, 'loadError');
    assert.equal(failedLoad.events[0].args[1], 'load failed');
    const failedSave = fixture('Québec', {failSave: true});
    assert.equal(failedSave.events[0].name, 'loaded');
    failedSave.control.save();
    assert.equal(failedSave.events[1].name, 'saveError');
    assert.equal(failedSave.events[1].args[1], 'save failed');
});

test('a late load response does not rebuild a destroyed editor', () => {
    const {control, load, failLoad, events} = fixture('Québec', {deferLoad: true});
    control.$addressDestroyed = true;
    load();
    failLoad('late failure');
    assert.equal(events.length, 0);
    assert.equal(control.$State, undefined);
});
