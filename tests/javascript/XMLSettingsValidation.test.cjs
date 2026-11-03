const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture(save) {
    const calls = [];
    const control = {getId: () => 'auth-settings', save};
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/desktop/panels/XML.js'), 'utf8'), {
        JSON: {...JSON, encode: JSON.stringify},
        Class: function (value) { definition = value; return value; },
        define(name, dependencies, factory) {
            factory(...dependencies.map(dependency => ({
                'qui/QUI': {Controls: {getControlsInElement: () => [control]}},
                Ajax: {post: (call, callback) => { calls.push(call); callback(); }}
            })[dependency] || {}));
        }
    });
    const panel = {
        ...definition,
        $file: ['conf.xml'],
        $config: {},
        getBody: () => ({}),
        unloadCategory() {},
        getButtonBar: () => ({getElement: () => ({setAttribute() {}})}),
        Loader: {
            visible: false,
            show() { this.visible = true; },
            hide() { this.visible = false; }
        }
    };
    return {panel, calls};
}

test('a rejected authenticator setting keeps the general config unsaved and restores the settings panel', async () => {
    const {panel, calls} = fixture(() => Promise.reject(new Error('Confirm your own 2FA first.')));
    panel.save();
    assert.equal(panel.Loader.visible, true);
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls, []);
    assert.equal(panel.Loader.visible, false);
});

test('general settings are saved only after the authenticator control succeeds', async () => {
    let complete;
    const {panel, calls} = fixture(() => new Promise(resolve => { complete = resolve; }));
    panel.save();
    assert.deepEqual(calls, []);
    complete();
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls, ['ajax_settings_save']);
    assert.equal(panel.Loader.visible, false);
});
