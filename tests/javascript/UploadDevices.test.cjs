const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    let definition;
    const calls = [];
    function Dialog(options) {
        this.options = options;
        this.open = () => {};
        this.close = () => options.events.onClose();
    }
    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/upload/mobileUpload/Devices.js'), 'utf8'), {
        Class: function (value) { definition = value; return value; },
        document: {createElement: () => ({})},
        define: (name, dependencies, factory) => factory({}, {
            post: (endpoint, callback, params) => {
                calls.push({endpoint, params});
                callback([]);
            }
        }, {get: (group, key) => key}, {}, {}, Dialog, Dialog)
    });
    const events = {};
    const control = {
        ...definition,
        parent() {},
        addEvent: (name, listener) => { events[name] = listener; },
        getAttribute: () => 'selected-user',
        $Status: {textContent: ''}
    };
    control.initialize({});
    return {control, events, calls};
}

for (const event of ['onImport', 'onInject']) {
    test(`initializes the device grid through ${event} and avoids duplicate initialization`, () => {
        const {control, events} = fixture();
        let grids = 0;
        let loads = 0;
        control.buildGrid = () => { grids++; control.$Grid = {}; };
        control.load = () => { loads++; };
        events[event]();
        events.onImport();
        events.onInject();
        assert.equal(grids, 1);
        assert.equal(loads, 1);
    });
}

test('lists devices for the selected user and sends that identity for changes', async () => {
    const {control, calls} = fixture();
    await control.request('list');
    await control.request('rename', 'device-id', 'Phone');
    await control.request('revoke', 'device-id');
    assert.equal(calls.length, 3);
    for (const call of calls) {
        assert.equal(call.endpoint, 'ajax_upload_mobileUpload_devices');
        assert.equal(call.params.uid, 'selected-user');
    }
    assert.equal(calls[1].params.name, 'Phone');
    assert.equal(calls[2].params.id, 'device-id');
});

test('pages and filters the entire device list, keeping cell content as text', () => {
    const {control} = fixture();
    let data;
    let page = 1;
    control.$Grid = {
        getAttribute: name => name === 'page' ? page : 20,
        setData: value => { data = value; },
        unselectAll() {},
        getSelectedData: () => [],
        getButtons: () => []
    };
    const devices = Array.from({length: 23}, (_, index) => ({
        id: String(index), name: 'Phone ' + index, description: '<img src=x>', createdAt: 1, lastUsedAt: 2
    }));
    control.render(devices);
    assert.equal(data.data.length, 20);
    assert.equal(data.total, 23);
    assert.equal(data.data[0].description.textContent, '<img src=x>');
    assert.equal(data.data[0].description.innerHTML, undefined);
    page = 2;
    control.render(devices);
    assert.equal(data.data.length, 3);
    assert.equal(data.data[0].id, '20');
    control.$filter = 'phone 22';
    control.render(devices);
    assert.equal(data.page, 1);
    assert.equal(data.total, 1);
    assert.equal(data.data[0].id, '22');
});

test('toolbar actions require one selection and removal waits for the QUI dialog submission', () => {
    const {control} = fixture();
    let selected = [];
    let disabled = true;
    const changes = [];
    control.$Grid = {
        getSelectedData: () => selected,
        getButtons: () => [{enable: () => { disabled = false; }, disable: () => { disabled = true; }}]
    };
    control.changeDevice = (...args) => changes.push(args);
    control.updateButtons();
    assert.equal(disabled, true);
    control.editSelected('revoke');
    assert.equal(control.$Dialog, null);
    selected = [{id: 'phone', deviceName: 'My phone'}];
    control.updateButtons();
    assert.equal(disabled, false);
    control.editSelected('revoke');
    assert.equal(disabled, true);
    assert.equal(changes.length, 0);
    control.$Dialog.close();
    assert.equal(changes.length, 0);
    control.editSelected('revoke');
    control.$Dialog.options.events.onSubmit();
    assert.deepEqual(changes, [['revoke', 'phone']]);
});

test('ignores pending responses after leaving the user category', async () => {
    const {control, events} = fixture();
    let respond;
    let renders = 0;
    control.request = () => new Promise(resolve => { respond = resolve; });
    control.render = () => { renders++; };
    const pending = control.load();
    events.onDestroy();
    respond([]);
    await pending;
    assert.equal(renders, 0);
});

test('grid ignores a queued scroll event after its DOM was removed', () => {
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/grid/Grid.js'), 'utf8'), {
        URL_OPT_DIR: '/',
        require() {},
        Class: function (value) { definition = value; return value; },
        define: (name, dependencies, factory) => factory(...dependencies.map(dependency =>
            dependency === 'Locale' ? {get: () => ''} : {}))
    });
    definition.onBodyScroll.call({container: {getElement: () => null}});
    let offset;
    let resized = false;
    definition.onBodyScroll.call({
        container: {getElement: selector => selector === '.hDivBox'
            ? {setStyle: (name, value) => { offset = value; }}
            : {getScroll: () => ({x: 30})}},
        rePosDrag: () => { resized = true; }
    });
    assert.equal(offset, -30);
    assert.equal(resized, true);
});
