const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const MAX_AGE = 28 * 24 * 60 * 60 * 1000;
const NOW = Date.UTC(2026, 9, 17, 12);
const KEY = 'site-test-de-13';

function fixture(raw) {
    let now = NOW;
    class Clock extends Date {
        constructor(...arguments_) {
            super(...(arguments_.length ? arguments_ : [now]));
        }
        static now() { return now; }
    }

    const entries = new Map([['unrelated-draft', 'unchanged']]);
    if (raw !== undefined) entries.set(KEY, raw);
    const storage = {
        get: key => entries.get(key) ?? null,
        set: (key, value) => entries.set(key, value),
        remove: key => entries.delete(key)
    };
    function definition(path, dependencies = {}) {
        let result;
        vm.runInNewContext(readFileSync(resolve(__dirname, '../..', path), 'utf8'), {
            define(name, names, factory) {
                factory(...names.map(name => dependencies[name] || {}));
            },
            Class: function (value) { result = value; return value; },
            Date: Clock,
            JSON: {parse: JSON.parse, stringify: JSON.stringify, decode: JSON.parse, encode: JSON.stringify},
            typeOf: value => typeof value,
            Promise,
            document: {addEventListener() {}}
        });
        return result;
    }

    const Site = definition('bin/QUI/classes/projects/project/Site.js', {'qui/QUI': {Storage: storage}});
    const server = {title: 'Saved title', content: 'Saved content', e_user: 7, e_date: '2026-10-16T12:00:00Z'};
    const site = {
        ...Site,
        $loaded: true,
        options: {attributes: {...server}},
        getWorkingStorageId: () => KEY,
        isLoaded() { return this.$loaded; },
        getProject: () => ({addEvent() {}}),
        getId: () => 13,
        addEvents() {},
        load(callback) {
            this.loads = (this.loads || 0) + 1;
            this.options.attributes = {...server};
            this.$loaded = true;
            if (callback) callback();
        }
    };

    function panel(loadUser = () => Promise.resolve()) {
        const buttons = [];
        const sheets = [];
        const Panel = definition('bin/QUI/controls/projects/project/site/Panel.js', {
            Locale: {get: (group, key) => key},
            Users: {get: () => ({
                isLoaded: () => false,
                load: loadUser,
                getName: () => 'Editor',
                getUsername: () => 'editor'
            })},
            Mustache: {render: () => 'Restore draft'}
        });
        const control = {
            ...Panel,
            getSite: () => site,
            Loader: {show() {}, hide() {}},
            $buildPanel: () => Promise.resolve(),
            $isDeleted: () => false,
            load() { this.loads = (this.loads || 0) + 1; },
            createSheet() {
                const sheet = {
                    getContent: () => ({set() {}}),
                    clearButtons() {},
                    addButton: button => buttons.push(button),
                    show: callback => callback(),
                    hide: callback => callback(),
                    destroy() {}
                };
                sheets.push(sheet);
                return sheet;
            }
        };
        control.$onInject();
        return {control, buttons, sheets};
    }

    return {site, entries, panel, setNow: value => { now = value; }};
}

function draft(timestamp = NOW) {
    return JSON.stringify({title: 'Draft title', content: 'Unsaved content', __storageTime: timestamp});
}

for (const age of [0, MAX_AGE - 1]) {
    test(`a draft aged ${age} milliseconds remains restorable`, async () => {
        const raw = draft(NOW - age);
        const {site, entries} = fixture(raw);
        assert.equal(site.hasWorkingStorage(), true);
        assert.equal(site.getWorkingStorage().content, 'Unsaved content');
        assert.equal((await site.hasWorkingStorageChanges()).title, 'Draft title');
        assert.equal(entries.get(KEY), raw);
        site.restoreWorkingStorage();
        assert.equal(site.getAttribute('title'), 'Draft title');
    });
}

for (const age of [MAX_AGE, MAX_AGE + 1, 5 * 365 * 24 * 60 * 60 * 1000]) {
    test(`a draft aged ${age} milliseconds is removed and never restored`, async () => {
        const {site, entries} = fixture(draft(NOW - age));
        assert.equal(site.hasWorkingStorage(), false);
        assert.equal(site.getWorkingStorage(), false);
        assert.equal(await site.hasWorkingStorageChanges(), false);
        site.restoreWorkingStorage();
        assert.equal(site.getAttribute('title'), 'Saved title');
        assert.equal(site.loads, undefined);
        assert.equal(entries.has(KEY), false);
        assert.equal(entries.get('unrelated-draft'), 'unchanged');
    });
}

const invalid = [
    ['missing timestamp', '{}'],
    ['null timestamp', draft(null)],
    ['string timestamp', draft(String(NOW))],
    ['date string', draft('2026-10-17')],
    ['boolean timestamp', draft(true)],
    ['zero timestamp', draft(0)],
    ['negative timestamp', draft(-1)],
    ['future timestamp', draft(NOW + 1)],
    ['infinite timestamp', '{"__storageTime":1e999}'],
    ['malformed JSON', '{invalid'],
    ['empty storage', ''],
    ['null value', 'null'],
    ['array value', '[]'],
    ['string value', '"draft"'],
    ['boolean value', 'true'],
    ['numeric value', '13']
];

for (const [name, raw] of invalid) {
    test(`${name} is rejected and removed without changing saved attributes`, () => {
        const {site, entries} = fixture(raw);
        assert.doesNotThrow(() => site.restoreWorkingStorage());
        assert.equal(site.hasWorkingStorage(), false);
        assert.equal(entries.has(KEY), false);
        assert.equal(site.getAttribute('title'), 'Saved title');
        assert.equal(entries.get('unrelated-draft'), 'unchanged');
    });
}

test('missing storage is handled without changing attributes', async () => {
    const {site} = fixture();
    assert.equal(site.getWorkingStorage(), false);
    assert.equal(await site.hasWorkingStorageChanges(), false);
    site.restoreWorkingStorage();
    assert.equal(site.getAttribute('title'), 'Saved title');
});

test('a new edit timestamps the draft and starts a fresh validity period', () => {
    const {site, entries, setNow} = fixture();
    site.setAttribute('title', 'First edit');
    assert.equal(JSON.parse(entries.get(KEY)).__storageTime, NOW);
    setNow(NOW + MAX_AGE - 1);
    site.setAttribute('title', 'Second edit');
    setNow(NOW + MAX_AGE);
    assert.equal(site.getWorkingStorage().title, 'Second edit');
});

test('a draft expiring while server attributes load is not returned as a change', async () => {
    const {site, setNow, entries} = fixture(draft(NOW - MAX_AGE + 1));
    site.$loaded = false;
    site.load = callback => {
        setNow(NOW + 1);
        site.$loaded = true;
        callback();
    };
    assert.equal(await site.hasWorkingStorageChanges(), false);
    assert.equal(entries.has(KEY), false);
});

test('an expired draft opens the saved page without showing a restore sheet', async () => {
    const {site, panel} = fixture(draft(NOW - MAX_AGE));
    const {sheets} = panel();
    await new Promise(setImmediate);
    assert.equal(sheets.length, 0);
    assert.equal(site.loads, 1);
});

test('a current draft is offered and can be restored through the panel', async () => {
    const {site, panel, entries} = fixture(draft());
    const {control, sheets, buttons} = panel();
    await new Promise(setImmediate);
    assert.equal(sheets.length, 1);
    const restore = buttons.find(button => button.text === 'panel.site.restore.button.restore');
    assert.ok(restore);
    restore.events.onClick();
    assert.equal(site.getAttribute('title'), 'Draft title');
    assert.equal(entries.has(KEY), false);
    assert.equal(control.loads, 1);
});

test('a draft expiring while the editor user loads is not offered', async () => {
    const {site, panel, setNow} = fixture(draft(NOW - MAX_AGE + 1));
    const {sheets} = panel(() => {
        setNow(NOW + 1);
        return Promise.resolve();
    });
    await new Promise(setImmediate);
    assert.equal(sheets.length, 0);
    assert.equal(site.loads, 1);
});

test('an open restore sheet cannot restore a draft after it expires', async () => {
    const {site, panel, setNow, entries} = fixture(draft());
    const {control, buttons} = panel();
    await new Promise(setImmediate);
    setNow(NOW + MAX_AGE);
    buttons.find(button => button.text === 'panel.site.restore.button.restore').events.onClick();
    assert.equal(site.getAttribute('title'), 'Saved title');
    assert.equal(entries.has(KEY), false);
    assert.equal(control.loads, 1);
});
