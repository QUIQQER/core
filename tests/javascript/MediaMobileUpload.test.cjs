const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    const state = {enabled: 1, folder: 7, visible: true, created: [], refreshed: [], closed: 0};
    let create;
    const Item = {
        getElm() {},
        show: () => { state.visible = true; },
        hide: () => { state.visible = false; }
    };
    const Ajax = {
        get: (endpoint, callback, params) => {
            assert.equal(params.project, 'selected-project');
            assert.equal(params.param, 'media_allowQrUpload');
            callback(state.enabled);
        },
        post: (endpoint, callback, params) => {
            state.created.push({endpoint, project: params.project, parentid: params.parentid});
            if (state.defer) { state.respond = () => callback({id: 'session'}); }
            else { callback({id: 'session'}); }
        }
    };
    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/projects/project/media/MobileUpload.js'), 'utf8'), {
        define: (name, dependencies, factory) => { create = factory(Ajax, {}); },
        require: (dependencies, callback) => callback((session, onUpload) => {
            state.onUpload = onUpload;
            return {
                addEvent: (event, listener) => { state.onClose = listener; },
                close: () => { state.closed++; state.onClose(); }
            };
        })
    });
    const control = create({
        getCurrentFile: () => ({getId: () => state.folder}),
        getProject: () => ({
            getName: () => 'selected-project',
            encode: () => JSON.stringify({name: 'selected-project', lang: 'de', template: false})
        }),
        openID: id => { state.refreshed.push(id); }
    }, Item);
    return {state, control};
}

test('hides disabled project uploads and refuses session creation', async () => {
    const {state, control} = fixture();
    assert.equal(state.visible, false);
    await control.refresh();
    assert.equal(state.visible, true);
    state.enabled = 0;
    await control.open();
    assert.equal(state.visible, false);
    assert.equal(state.created.length, 0);
});

test('binds to selected folder, prevents duplicate dialogs and refreshes only that folder', async () => {
    const {state, control} = fixture();
    await Promise.all([control.open(), control.open()]);
    assert.deepEqual(state.created, [{
        endpoint: 'ajax_media_createMobileUpload', project: 'selected-project', parentid: 7
    }]);
    await state.onUpload();
    state.folder = 8;
    await state.onUpload();
    assert.deepEqual(state.refreshed, [7]);
    state.enabled = '0';
    await control.refresh();
    assert.equal(state.closed, 1);
});

test('revokes a session returned after the media panel was destroyed', async () => {
    const {state, control} = fixture();
    state.defer = true;
    const opening = control.open();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.created.length, 1);
    control.destroy();
    state.respond();
    await opening;
    assert.equal(state.closed, 1);
    await control.open();
    assert.equal(state.created.length, 1);
});
