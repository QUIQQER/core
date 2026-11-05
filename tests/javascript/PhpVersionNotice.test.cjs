const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture(information) {
    let definition;
    const nodes = {
        '[data-name="phpVersion"]': {textContent: ''},
        '[data-name="phpVersionNotice"]': {hidden: true},
        '[data-name="phpVersionNoticeText"]': {textContent: ''}
    };

    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/packages/System.js'), 'utf8'), {
        Class: function (value) {
            definition = value;
            return value;
        },
        define: (name, dependencies, factory) => factory(),
        require: (names, callback) => callback({getInformation: () => Promise.resolve(information)})
    });

    let loaded;
    const ready = new Promise(resolve => {
        loaded = resolve;
    });
    const control = {
        ...definition,
        getElm: () => ({
            querySelector: selector => nodes[selector] ?? {set() {}, style: {}}
        }),
        refreshLastUpdateCheckDate() {},
        loadCachedUpdates() {},
        refreshActiveRun() {},
        loadRunHistory() {},
        startUpdateRunMonitor() {},
        fireEvent: loaded
    };

    return {control, nodes, ready};
}

test('loading the update panel shows the server PHP warning next to its version', async () => {
    const warning = 'PHP 8.2.99 is outdated. Future QUIQQER updates require PHP 8.3.';
    const {control, nodes, ready} = fixture({
        version: '2.0',
        php_version: '8.2.99',
        php_update_warning: warning,
        database: {version: '1', type: 'sqlite'},
        hash: ''
    });

    control.$onInject();
    await ready;

    assert.equal(nodes['[data-name="phpVersion"]'].textContent, '8.2.99');
    assert.equal(nodes['[data-name="phpVersionNoticeText"]'].textContent, warning);
    assert.equal(nodes['[data-name="phpVersionNotice"]'].hidden, false);
});

test('a supported PHP version hides and clears an earlier warning', () => {
    const {control, nodes} = fixture();
    control.$renderPhpVersion('8.2.0', 'Upgrade PHP to 8.3 or higher.');
    control.$renderPhpVersion('8.3.0', null);

    assert.equal(nodes['[data-name="phpVersion"]'].textContent, '8.3.0');
    assert.equal(nodes['[data-name="phpVersionNoticeText"]'].textContent, '');
    assert.equal(nodes['[data-name="phpVersionNotice"]'].hidden, true);
});

test('an older system info response leaves the notice hidden', () => {
    const {control, nodes} = fixture();
    control.$renderPhpVersion('8.5.0', undefined);

    assert.equal(nodes['[data-name="phpVersionNotice"]'].hidden, true);
    assert.equal(nodes['[data-name="phpVersionNoticeText"]'].textContent, '');
});
