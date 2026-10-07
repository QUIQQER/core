const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function createPanel() {
    let definition;

    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/desktop/panels/XML.js'), 'utf8'), {
        Class: function (value) {
            definition = value;
            return value;
        },
        define: (name, dependencies, factory) => factory({}, {})
    });

    return {
        ...definition,
        $config: null,
        $file: 'packages/example/module/settings.xml',
        attributes: {name: 'settings', height: 600},
        getAttributes() {
            return this.attributes;
        },
        setAttributes(attributes) {
            this.attributes = attributes;
        },
        getType() {
            return this.Type;
        }
    };
}

test('workspace retains panel identity and layout without configuration secrets', () => {
    const panel = createPanel();
    panel.$config = {
        google: {oauth_client_secret: 'test-only-oauth-secret'},
        mail: {password: 'test-only-mail-password'}
    };

    const persisted = JSON.parse(JSON.stringify(panel.serialize()));

    assert.deepEqual(persisted, {
        attributes: panel.attributes,
        type: 'controls/desktop/panels/XML',
        file: panel.$file
    });
    assert.equal(panel.$config.google.oauth_client_secret, 'test-only-oauth-secret');
    assert.equal(panel.getConfig(), panel.$config);
});

test('category controls can read settings before and after they have loaded', () => {
    const panel = createPanel();

    assert.equal(Object.keys(panel.getConfig()).length, 0);

    panel.$config = {general: {cacheType: 'redis'}};
    assert.equal(panel.getConfig().general.cacheType, 'redis');

    panel.$config = {general: {cacheType: 'filesystem'}};
    assert.equal(panel.getConfig().general.cacheType, 'filesystem');
    assert.equal(Object.hasOwn(panel.serialize(), 'config'), false);
});

test('restoring and saving a legacy workspace drops its stored configuration', () => {
    const panel = createPanel();
    const legacyWorkspace = {
        attributes: {name: 'legacy-settings', height: 800},
        type: 'controls/desktop/panels/XML',
        file: 'packages/example/legacy/settings.xml',
        config: {google: {oauth_client_secret: 'test-only-legacy-secret'}}
    };

    panel.unserialize(legacyWorkspace);

    assert.equal(panel.$config, null);
    assert.deepEqual(JSON.parse(JSON.stringify(panel.serialize())), {
        attributes: legacyWorkspace.attributes,
        type: legacyWorkspace.type,
        file: legacyWorkspace.file
    });
});
