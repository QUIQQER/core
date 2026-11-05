const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function definition(file, globals = {}) {
    let result;
    vm.runInNewContext(readFileSync(path.join(__dirname, '../../bin/QUI/controls/editors', file), 'utf8'), {
        Class: function(value) {return value;},
        define: (_name, dependencies, factory) => {result = factory(...dependencies.map(() => ({})));},
        ...globals
    });
    return result;
}

function preview() {
    const links = [];
    const document = {
        head: {appendChild: link => links.push(link)},
        createElement: () => ({remove() {links.splice(links.indexOf(this), 1);}})
    };
    const control = Object.assign(Object.create(definition('Preview.js')), {
        $projectCSSFiles: [],
        $projectCSSLinks: [],
        $loaded: false,
        $Elm: {contentWindow: {document}},
        fireEvent: () => {}
    });
    return {control, links};
}

test('the final project styles are installed when the preview document loads', () => {
    const {control, links} = preview();
    control.setCSSFiles(['palette-a.css']);
    control.setCSSFiles(['palette-b.css', 'template.css', 'palette-b.css']);
    assert.equal(links.length, 0);
    control.$onLoad();
    assert.deepEqual(links.map(link => link.href), ['palette-b.css', 'template.css']);
});

test('project changes replace old palette definitions and preserve unrelated assets', () => {
    const {control, links} = preview();
    const external = {href: 'external.css'};
    links.push(external);
    control.$onLoad();
    control.setCSSFiles(['palette-a.css', 'template-a.css']);
    control.setCSSFiles(['palette-b.css']);
    assert.deepEqual(links.map(link => link.href), ['external.css', 'palette-b.css']);
    control.setCSSFiles([]);
    assert.deepEqual(links, [external]);
});

test('late project settings cannot restore colors from an earlier project', async () => {
    const requests = [];
    const styles = [];
    const Editors = {
        getProjectFiles: project => new Promise(resolve => requests.push({project, resolve}))
    };
    const control = Object.assign(Object.create(definition('Input.js', {
        require: (_dependencies, callback) => callback(Editors)
    })), {
        $projectFilesRequest: 0,
        $Editor: null,
        $Preview: {setCSSFiles: files => styles.push(Array.from(files))},
        $Project: {name: 'a'}
    });
    control.$setProjectToEditor();
    control.$Project = {name: 'b'};
    control.$setProjectToEditor();
    requests[1].resolve({cssFiles: ['palette-b.css']});
    await Promise.resolve();
    requests[0].resolve({cssFiles: ['palette-a.css']});
    await Promise.resolve();
    assert.deepEqual(styles, [[], [], ['palette-b.css']]);

    control.$Project = null;
    control.$setProjectToEditor();
    assert.deepEqual(styles.at(-1), []);
    assert.equal(requests.length, 2);
});
