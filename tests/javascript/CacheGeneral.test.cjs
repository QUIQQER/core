const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function importCacheControl(config, initialType = 'filesystem') {
    const tables = {};
    const inputs = {};

    for (const name of ['general.redis', 'apc.namespace', 'memcache.servers', 'mongo.host', 'filesystem.path']) {
        const table = {
            display: '',
            setStyle(name, value) {
                this[name] = value;
            },
            querySelector() {
                return null;
            },
            getElement() {
                return {};
            }
        };
        tables[name] = table;
        inputs[`[name="${name}"]`] = {getParent: () => table};
    }

    const select = {
        value: initialType,
        addEventListener(name, listener) {
            this[name] = listener;
        }
    };
    inputs['[name="general.cacheType"]'] = select;

    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/cache/General.js'), 'utf8'), {
        Class: function (value) {
            definition = value;
            return value;
        },
        define: (name, dependencies, factory) => factory({}, {}, class {
            inject() {
                return this;
            }
        }, {}, {get: () => 'Redis check'})
    });

    const control = {
        ...definition,
        $Settings: {
            getConfig: () => config,
            serialize() {
                assert.fail('Settings must not be read through workspace serialization');
            }
        },
        getElm: () => ({
            querySelector: selector => inputs[selector],
            querySelectorAll: () => Object.values(tables)
        })
    };
    control.$onTypeChange = control.$onTypeChange.bind(control);
    control.$onImport();

    return {control, select, tables};
}

test('cache import reads live configuration and gives the selected cache type priority', () => {
    const config = {
        general: {cacheType: 'redis'},
        handlers: {filesystem: 1, redis: 0}
    };
    const {select, tables, control} = importCacheControl(config);

    assert.equal(select.value, 'redis');
    assert.deepEqual(config.handlers, {filesystem: 0, redis: 1});
    assert.equal(tables['general.redis'].display, null);
    assert.equal(tables['filesystem.path'].display, 'none');
    assert.ok(control.$RedisCheck);

    select.value = 'filesystem';
    select.change();
    assert.equal(tables['general.redis'].display, 'none');
    assert.equal(tables['filesystem.path'].display, null);
});

test('legacy handlers work without a general settings section', () => {
    const {select, tables} = importCacheControl({handlers: {filesystem: '0', redis: '1'}});

    assert.equal(select.value, 'redis');
    assert.equal(tables['general.redis'].display, null);
});

for (const config of [{}, {general: {}}, {general: null, handlers: null}, {general: {cacheType: 'redis'}}]) {
    test(`missing cache sections keep the form selection: ${JSON.stringify(config)}`, () => {
        const {select, tables} = importCacheControl(config, 'redis');

        assert.equal(select.value, 'redis');
        assert.equal(tables['general.redis'].display, null);
    });
}
