const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

test('XML site tabs fill values and await automatic controls before attaching the project', async () => {
    const calls = [];
    const project = {};
    const attributes = {color: '{"type":"palette","id":"primary"}'};
    const site = {getAttributes: () => attributes, getProject: () => project};
    const form = {
        inject() {return this;},
        getElements() {return [{get: () => 'color-field'}];}
    };
    let finishControls;
    const controlsReady = new Promise(resolve => {finishControls = resolve;});
    let panel;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/controls/projects/project/site/Panel.js'), 'utf8'), {
        Class: function (definition) {return definition;},
        Element: function () {return form;},
        typeOf: value => typeof value,
        define(_name, _dependencies, factory) {
            panel = factory(
                {
                    parse: async value => {assert.equal(value, form); calls.push('declarative');},
                    Controls: {getById: () => ({
                        setProject(value) {assert.equal(value, project); calls.push('project');},
                        setAttribute(name, value) {assert.equal(name, 'Site'); assert.equal(value, site);}
                    })}
                }, {}, {}, {}, {}, {},
                {setDataToForm(value, target) {
                    assert.equal(value, attributes);
                    assert.equal(target, form);
                    calls.push('fill');
                }}, {},
                {parse: async target => {
                    assert.equal(target, form);
                    calls.push('automatic');
                    await controlsReady;
                }}
            );
        }
    });

    const category = {getAttribute: name => ({name: 'custom-colors', type: 'xml'})[name]};
    const instance = {
        $isDeleted: () => false,
        getActiveCategory: () => null,
        getAttribute: () => null,
        getSite: () => site,
        getBody: () => ({getElement: () => form}),
        Loader: {show() {}},
        $Container: {set() {}},
        $onCategoryLeaveHide: async () => {},
        $onCategoryLeave: async () => {},
        $getCategoryFromXml: async () => '<input type="color" name="color">',
        $categoryOnLoad: async () => {calls.push('loaded');},
        $onCategoryEntryShow: async () => {calls.push('show');}
    };
    const loading = panel.$onCategoryEnter.call(instance, category);
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls, ['fill', 'automatic']);
    finishControls();
    await loading;
    assert.deepEqual(calls, ['fill', 'automatic', 'declarative', 'project', 'loaded', 'show']);
});
