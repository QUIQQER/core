const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const crypto = require('node:crypto');
let Provider;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../bin/QUI/controls/projects/project/ColorProvider.js'), 'utf8'), {
    define: (_name, _deps, factory) => {Provider = factory();},
    crypto
});
const plain = (object) => JSON.parse(JSON.stringify(object));

test('prototype draft migration matches stable IDs and leaves the supplied input unchanged', () => {
    const saved = {version: 1, colors: {primary: {light: '#AbCdEf'}}, favorites: ['#123456', '#123456', '#ABCDEF']};
    const before = JSON.stringify(saved);
    const normalized = Provider.normalize(saved, {text: {light: '#000', dark: '#fff'}});
    assert.equal(normalized.version, 2);
    assert.deepEqual(plain(normalized.colors.primary), {value: '#AbCdEf'});
    assert.deepEqual(plain(normalized.colors['custom-legacy-abcdef']), {value: '#ABCDEF', name: '#ABCDEF'});
    assert.equal(Object.keys(normalized.colors).length, 4);
    assert.equal(JSON.stringify(saved), before);
    assert.deepEqual(plain(Provider.normalize(normalized, {})), plain(normalized));
});

test('named additions keep returned palettes isolated from the form draft', async () => {
    const palette = {version: 2, colors: {primary: {value: '#123456'}}};
    const provider = Provider.draft(palette, () => {});
    const {id, palette: result} = await provider.addColor(' Campaign ', {light: '#1234', dark: '#abcd'});
    assert.equal(result.colors[id].name, 'Campaign');
    assert.equal(palette.colors[id].dark, '#abcd');
    result.colors[id].name = 'Outside mutation';
    assert.equal((await provider.load()).colors[id].name, 'Campaign');
    const loaded = await provider.load();
    loaded.colors[id].dark = '#000';
    assert.equal(palette.colors[id].dark, '#abcd');
    await assert.rejects(provider.addColor(' ', {value: '#000'}));
});

test('project instances expose the generic color provider contract without eagerly loading it', async () => {
    let prototype;
    const requests = [];
    let createdFor;
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../bin/QUI/classes/projects/Project.js'), 'utf8'), {
        Class: function(definition) {return definition;},
        define: (_name, _deps, factory) => {prototype = factory();},
        require: (dependencies, resolve) => {
            requests.push(...dependencies);
            resolve({create: project => {createdFor = project; return {load: () => {throw new Error('not during setup');}};}});
        }
    });
    assert.equal(requests.length, 0);
    const project = Object.create(prototype);
    const provider = await project.getColorProvider();
    assert.equal(createdFor, project);
    assert.equal(typeof provider.load, 'function');
    assert.deepEqual(requests, ['controls/projects/project/ColorProvider']);
});

test('project provider carries labels and binds data requests and subscriptions to its own project', async () => {
    let factory;
    let requestedProject;
    let savedListener;
    let removed = 0;
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../bin/QUI/controls/projects/project/ColorProvider.js'), 'utf8'), {
        define: (_name, _deps, create) => {factory = create;}
    });
    const providerFactory = factory(
        {get: (_url, done, options) => {requestedProject = options.project; done({colors: {primary: {value: '#123456'}}});}},
        {get: (_group, key) => key},
        {addEvent: (_name, listener) => {savedListener = listener;}, removeEvent: (_name, listener) => {assert.equal(listener, savedListener); removed++;}}
    );
    const provider = providerFactory.create({getName: () => 'project-a'});
    assert.equal(requestedProject, undefined);
    assert.equal(provider.labels.apply, 'projects.colors.apply');
    assert.equal((await provider.load()).colors.primary.value, '#123456');
    assert.equal(requestedProject, 'project-a');
    let changes = 0;
    const unsubscribe = provider.subscribe(() => changes++);
    savedListener({getName: () => 'project-b'});
    assert.equal(changes, 0);
    savedListener({getName: () => 'project-a'});
    assert.equal(changes, 1);
    unsubscribe();
    assert.equal(removed, 1);
});

test('failure to load the Core provider rejects the project context promise', async () => {
    let prototype;
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../bin/QUI/classes/projects/Project.js'), 'utf8'), {
        Class: function(definition) {return definition;},
        define: (_name, _deps, factory) => {prototype = factory();},
        require: (_dependencies, _resolve, reject) => reject(new Error('module unavailable'))
    });
    await assert.rejects(prototype.getColorProvider(), /module unavailable/);
});
