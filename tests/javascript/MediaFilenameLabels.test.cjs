const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    class Element {
        constructor(tag, attributes = {}) {
            this.children = [];
            this.attributes = {...attributes};
        }
        appendChild(child) { this.children.push(child); }
        setAttribute(name, value) { this.attributes[name] = value; }
        getAttribute(name) { return this.attributes[name]; }
        get(name) { return this.getAttribute(name); }
        set(attributes) { Object.assign(this.attributes, attributes); }
        setStyle() {}
        setStyles() {}
        addClass() {}
        removeClass() {}
        inject(parent) { parent.appendChild(this); return this; }
        querySelector(selector) {
            assert.equal(selector, '[data-name="title"]');
            return this.children.find(child => child.getAttribute('data-name') === 'title');
        }
        set innerHTML(value) { assert.fail('File names must remain plain text'); }
    }
    let definition;
    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/projects/project/media/Panel.js'), 'utf8'), {
        URL_DIR: '/',
        Element,
        document: {createElement: tag => new Element(tag)},
        typeOf: value => Array.isArray(value) ? 'array' : typeof value,
        Class: function (value) { definition = value; return value; },
        define(name, dependencies, factory) { factory(...dependencies.map(() => ({}))); }
    });
    const Project = {getName: () => 'test'};
    const panel = {
        ...definition,
        $createMissingMediaMetaBadges: () => null,
        $Filter: {setStyle() {}},
        $Media: {getProject: () => Project},
        $PanelContextMenu: {show() {}}
    };
    return {Element, panel};
}

for (const view of ['$viewSymbols', '$viewPreview']) {
    test(`${view} displays extensions on files and preserves folder names`, () => {
        const {Element, panel} = fixture();
        const data = [
            {id: 1, name: '..', extension: '', type: 'folder'},
            {id: 2, name: 'Header-Image', extension: 'png', type: 'image'},
            {id: 3, name: 'Design-dots', extension: 'svg', type: 'image'},
            {id: 4, name: 'Terms', extension: 'pdf', type: 'file'},
            {id: 5, name: 'README', extension: '', type: 'file'},
            {id: 6, name: 'archive.v2', extension: '', type: 'folder'},
            {id: 7, name: '<img src=x onerror=alert(1)>', extension: 'png', type: 'image'}
        ];
        const items = panel[view]({data}, new Element('div'));
        assert.deepEqual(Array.from(items, item => item.querySelector('[data-name="title"]').textContent), [
            'Header-Image.png', 'Design-dots.svg', 'Terms.pdf', 'README', 'archive.v2',
            '<img src=x onerror=alert(1)>.png'
        ]);
    });
}

test('renaming a file keeps its extension in the visible label and tooltip', () => {
    const {Element, panel} = fixture();
    const [Node] = panel.$viewSymbols({data: [
        {id: 2, name: 'Header-Image', extension: 'png', type: 'image'}
    ]}, new Element('div'));
    panel.getContent = () => ({querySelector: () => Node});
    panel.$itemEvent(panel.$Media, {
        getId: () => 2,
        getAttribute: name => name === 'name' ? 'Renamed' : '',
        isActive: () => true
    });
    assert.equal(Node.querySelector('[data-name="title"]').textContent, 'Renamed.png');
    assert.equal(Node.getAttribute('title'), 'Renamed.png');
});
