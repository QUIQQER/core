const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    let definition;
    let request;
    const events = [];
    const dependencies = {
        Ajax: {post: (endpoint, callback, params) => { request = {endpoint, callback, params}; }},
        'qui/utils/Object': {combine: (options, required) => Object.assign({}, options, required)}
    };
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/classes/users/Manager.js'), 'utf8'), {
        Class: function(value) { definition = value; return value; },
        define: (name, names, factory) => factory(...names.map(name => dependencies[name] || {}))
    });
    const user = {getId: () => 42, getAttribute: key => ({id:42, uuid:'target-uuid'})[key]};
    const untouched = {getId: () => 43, getAttribute: key => ({id:43, uuid:'other-uuid'})[key]};
    const manager = {...definition, $users: {42:user, 'target-uuid':user, 43:untouched},
        fireEvent: (...args) => events.push(args)};
    return {manager, events, request: () => request};
}

for (const [method, endpoint] of [['wipeUsers','ajax_users_wipe'], ['deleteUsers','ajax_users_delete']]) {
    test(method + ' routes correctly and discards both ID and UUID caches after success', async () => {
        const f = fixture();
        const pending = f.manager[method](['target-uuid']);
        assert.equal(f.request().endpoint, endpoint);
        assert.deepEqual(JSON.parse(f.request().params.uid), ['target-uuid']);
        f.request().callback(true);
        assert.equal(await pending, true);
        assert.deepEqual(Object.keys(f.manager.$users), ['43']);
        assert.equal(f.events[0][0], 'delete');
        assert.deepEqual(Array.from(f.events[0][1][1]).sort(), ['42','target-uuid']);
    });

    test(method + ' rejects errors without closing panels or clearing cached accounts', async () => {
        const f = fixture();
        const pending = f.manager[method](['target-uuid']);
        const failure = new Error('Permission denied');
        f.request().params.onError(failure);
        await assert.rejects(pending, error => error === failure);
        assert.equal(f.events.length, 0);
        assert.equal(Object.keys(f.manager.$users).length, 3);
    });
}
