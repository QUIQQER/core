const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const core = resolve(__dirname, '../..');
const requireJs = readFileSync(resolve(core, '../../bin/quiqqer-asset/requirejs/requirejs/require.js'), 'utf8');
const source = readFileSync(resolve(core, 'bin/QUI/classes/Locale.js'), 'utf8');
const token = name => 'c' + Buffer.from(name).toString('hex');

async function fixture({format = 2, payload = value => value} = {}) {
    const requests = [];
    const context = {
        console, setTimeout, clearTimeout, Intl,
        QUIQQER_LOCALE_CONTEXTS: {
            format, version: 'page-version',
            contexts: ['frontend', 'administration', 'dashboard'], languages: ['de', 'en']
        },
        Class: function (definition) {
            class Locale extends definition.Extends {}
            Object.assign(Locale.prototype, definition);
            return Locale;
        }
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(requireJs, context);
    vm.runInContext(`
        define('qui/classes/Locale', [], function () {
            return class {
                constructor() { this.language = 'de'; this.groups = {}; }
                getCurrent() { return this.language; }
                set(language, group, values) {
                    this.groups[language] ??= {};
                    this.groups[language][group] = {...this.groups[language][group], ...values};
                }
            };
        });
    `, context);
    context.requirejs.load = (loader, id) => {
        requests.push(id);
        queueMicrotask(() => {
            try {
                const segments = id.split('/');
                const language = segments.at(-1);
                const name = segments.at(-2);
                const scope = name === 'common' ? name : Buffer.from(name.slice(1), 'hex').toString();
                const result = payload({
                    language, context: scope,
                    version: format === 2 ? 'newer-version' : 'page-version',
                    groups: {'test/context': {[scope]: language + ' ' + scope}}
                });
                vm.runInContext(`define(${JSON.stringify(id)}, [], function () {return ${JSON.stringify(result)};});`, context);
                loader.completeLoad(id);
            } catch (error) {
                error.requireModules = [id];
                loader.onError(error);
            }
        });
    };
    vm.runInContext(source, context);
    const Locale = await new Promise((resolve, reject) => context.requirejs(['classes/Locale'], resolve, reject));
    return {locale: new Locale(), requests, context};
}

test('stable custom paths accept newer files and coalesce concurrent loads', async () => {
    const {locale, requests} = await fixture();
    const first = locale.loadContext('dashboard');
    assert.equal(first, locale.loadContext('dashboard'));
    await first;
    await locale.loadContext('dashboard');
    assert.deepEqual(requests.sort(), [
        'locale/_contexts/common/de', 'locale/_contexts/' + token('dashboard') + '/de'
    ].sort());
    assert.equal(locale.groups.de['test/context'].dashboard, 'de dashboard');
});

test('standard context loading requests a bundle, not a missing standalone module', async () => {
    const {locale, requests} = await fixture();
    await locale.loadContext('administration');
    assert.deepEqual(requests, ['locale/_contexts/b' + Buffer.from('administration').toString('hex') + '/de']);
    assert.equal(locale.groups.de['test/context'].administration, 'de administration');
});

test('a language switch keeps in-flight results in their requested language', async () => {
    const {locale, requests} = await fixture();
    const german = locale.loadContext('dashboard');
    locale.language = 'en';
    const english = locale.loadContext('dashboard');
    await Promise.all([german, english]);
    assert.equal(locale.groups.de['test/context'].dashboard, 'de dashboard');
    assert.equal(locale.groups.en['test/context'].dashboard, 'en dashboard');
    assert.equal(requests.length, 4);
});

test('old manifests continue to load versioned paths', async () => {
    const legacy = await fixture({format: 1});
    delete legacy.context.QUIQQER_LOCALE_CONTEXTS.format;
    await legacy.locale.loadContext('dashboard');
    assert.ok(legacy.requests.every(id => id.startsWith('locale/_contexts/page-version/')));
    assert.equal(legacy.locale.groups.de['test/context'].dashboard, 'de dashboard');
});

test('legacy loading still rejects a different publication', async () => {
    const {locale} = await fixture({format: 1, payload: value => ({...value, version: 'different'})});
    await assert.rejects(locale.loadContext('dashboard'), /Invalid published locale module/);
});

for (const [name, corrupt] of [
    ['language', value => ({...value, language: 'fr'})],
    ['context', value => ({...value, context: 'wrong'})],
    ['groups', value => ({...value, groups: []})],
    ['translation', value => ({...value, groups: {group: {key: 123}}})]
]) {
    test(`invalid ${name} rejects and a corrected module can be retried`, async () => {
        let broken = true;
        const {locale} = await fixture({payload: value => broken && value.context === 'dashboard' ? corrupt(value) : value});
        await assert.rejects(locale.loadContext('dashboard'), /Invalid published locale module/);
        broken = false;
        await locale.loadContext('dashboard');
        assert.equal(locale.groups.de['test/context'].dashboard, 'de dashboard');
    });
}

test('failed network loads can be retried', async () => {
    let broken = true;
    const {locale} = await fixture({payload: value => {
        if (broken && value.context === 'dashboard') {
            throw new Error('Network unavailable');
        }
        return value;
    }});
    await assert.rejects(locale.loadContext('dashboard'), /Network unavailable/);
    broken = false;
    await locale.loadContext('dashboard');
    assert.equal(locale.groups.de['test/context'].dashboard, 'de dashboard');
});

test('numeric locale keys remain valid when PHP encodes them as a JSON array', async () => {
    const {locale} = await fixture({payload: value => ({...value, groups: {'test/numbers': ['zero', 'one']}})});
    await locale.loadContext('dashboard');
    assert.equal(locale.groups.de['test/numbers']['0'], 'zero');
    assert.equal(locale.groups.de['test/numbers']['1'], 'one');
});
