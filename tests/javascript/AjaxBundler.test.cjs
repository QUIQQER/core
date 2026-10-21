const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    let definition;
    let request;
    const events = [];
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/classes/request/Bundler.js'), 'utf8'), {
        Class: function (value) { definition = value; return value; },
        define(name, dependencies, factory) { factory({}, {}); },
        JSON, window: {}, URL_DIR: '/',
        Object: {hasOwn: Object.hasOwn, toQueryString: JSON.stringify},
        Request: class {
            constructor(options) { this.options = options; request = this; }
            send() {}
        }
    });
    const bundler = {
        ...definition,
        getAttribute: name => name === 'maxRequests' ? 20 : 1000,
        fireEvent: name => events.push(name),
        $parseStackForSend: stack => stack.map(({rid}) => ({rid}))
    };
    const params = ['first', 'second'].map(rid => {
        let resolve;
        const promise = new Promise(callback => { resolve = callback; });
        return {rid, resolve, promise};
    });
    return {bundler, params, events, get request() { return request; }};
}

test('bundle results ignore metadata and unknown IDs while resolving every queued call', async () => {
    const {bundler, params} = fixture();
    bundler.$parseResponse(JSON.stringify({
        first: {value: 1}, second: {value: 2}, Jwt: 'renewed-token', unknown: {value: 3}
    }), params, []);
    assert.deepEqual(await Promise.all(params.map(entry => entry.promise)), [{value: 1}, {value: 2}]);
});

test('a global bundle exception is delivered to every queued request', async () => {
    const {bundler, params} = fixture();
    const error = {Exception: {message: 'Invalid JWT Token', code: 401, type: 'QUI\\FRS\\Exceptions\\InvalidJwtToken'}};
    bundler.$parseResponse(JSON.stringify(error), params, []);
    const results = await Promise.all(params.map(entry => entry.promise));
    assert.deepEqual(JSON.parse(JSON.stringify(results)), [error, error]);
});

for (const responseText of ['', '<html>Service unavailable</html>']) {
    test(`HTTP 503 with ${responseText ? 'HTML' : 'an empty body'} settles all callbacks and fires failure events`, async () => {
        const state = fixture();
        state.bundler.$request('post', state.params);
        state.request.options.onFailure({responseText, status: 503, statusText: 'Service Unavailable'});
        const results = await Promise.all(state.params.map(entry => entry.promise));
        assert.equal(results.length, 2);
        assert.equal(results[0].Exception.code, 503);
        assert.equal(results[1].Exception.message, 'Service Unavailable');
        assert.deepEqual(state.events, ['requestFailure', 'requestCancel']);
    });
}

test('an incomplete response preserves successful calls and reports the missing result', async () => {
    const {bundler, params} = fixture();
    bundler.$parseResponse('{"first":{"value":1}}', params, []);
    const results = await Promise.all(params.map(entry => entry.promise));
    assert.deepEqual(results[0], {value: 1});
    assert.equal(results[1].Exception.code, 502);
});

test('a malformed success response reports an error instead of leaving requests pending', async () => {
    const {bundler, params} = fixture();
    bundler.$parseResponse('not JSON', params, []);
    const results = await Promise.all(params.map(entry => entry.promise));
    assert.equal(results[0].Exception.code, 502);
    assert.equal(results[1].Exception.code, 502);
});
