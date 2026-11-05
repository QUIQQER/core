const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const paletteKey = 'quiqqer.colors.palette';
const savedPalette = JSON.stringify({version: 2, colors: {primary: {value: '#123456'}}});

function createPanel({stored = savedPalette, visible = true, saveError, readError} = {}) {
    const calls = [];
    const events = [];
    const draft = JSON.stringify({palette: savedPalette, repair: true});
    const input = {value: draft, dispatchEvent: event => events.push(event)};
    const project = {
        getName: () => 'example',
        setConfig: async config => {
            calls.push(['save', config[paletteKey]]);
            if (saveError) {throw saveError;}
            return null;
        },
        getConfig: async () => {
            calls.push(['read']);
            if (readError) {throw readError;}
            return stored === null ? {} : {[paletteKey]: stored};
        }
    };
    let definition;
    vm.runInNewContext(readFileSync(path.join(__dirname,
        '../../bin/QUI/controls/projects/project/Settings.js'), 'utf8'), {
        Class: function(value) {return value;},
        CustomEvent: class {
            constructor(type, options) {
                this.type = type;
                this.detail = options.detail;
            }
        },
        define: (_name, dependencies, factory) => {
            definition = factory(...dependencies.map(name => name === 'Projects' ? {$projects: {}} : {}));
        }
    });
    const panel = Object.assign(Object.create(definition), {
        $config: {[paletteKey]: draft, unrelated: 'keep'},
        $onCategoryLeave: () => {},
        getProject: () => project,
        getContent: () => ({querySelector: () => visible ? input : null}),
        Loader: {show: () => calls.push(['show']), hide: () => calls.push(['hide'])}
    });
    return {panel, calls, events, input, draft};
}

test('a void save reloads the persisted palette and removes the repair request before saving again', async () => {
    const {panel, calls, events, input, draft} = createPanel();
    await panel.save();

    assert.deepEqual(calls, [['show'], ['save', draft], ['read'], ['hide']]);
    assert.equal(panel.$config[paletteKey], savedPalette);
    assert.equal(panel.$config.unrelated, 'keep');
    assert.equal(input.value, savedPalette);
    assert.equal(events.length, 1);
    assert.equal(events[0].type, 'palette-saved');
    assert.equal(events[0].detail, savedPalette);

    await panel.save();
    assert.equal(calls.filter(([name]) => name === 'save')[1][1], savedPalette);
});

test('the persisted palette updates the panel even when another settings category is visible', async () => {
    const {panel, events} = createPanel({visible: false});
    await panel.save();
    assert.equal(panel.$config[paletteKey], savedPalette);
    assert.equal(events.length, 0);
});

test('an absent stored palette becomes an empty field without a repair request', async () => {
    const {panel, input} = createPanel({stored: null});
    await panel.save();
    assert.equal(panel.$config[paletteKey], '');
    assert.equal(input.value, '');
});

test('a failed save keeps the draft and does not reload or announce a saved palette', async () => {
    const {panel, calls, events, input, draft} = createPanel({saveError: new Error('save failed')});
    await panel.save();
    assert.deepEqual(calls, [['show'], ['save', draft], ['hide']]);
    assert.equal(input.value, draft);
    assert.equal(events.length, 0);
});

test('a failed reload keeps the draft and does not announce a refreshed palette', async () => {
    const {panel, calls, events, input, draft} = createPanel({readError: new Error('read failed')});
    await panel.save();
    assert.deepEqual(calls, [['show'], ['save', draft], ['read'], ['hide']]);
    assert.equal(panel.$config[paletteKey], draft);
    assert.equal(input.value, draft);
    assert.equal(events.length, 0);
});
