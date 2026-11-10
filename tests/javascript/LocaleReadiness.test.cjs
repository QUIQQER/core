const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

const core = resolve(__dirname, '../..');
const requireJs = readFileSync(resolve(core, '../../bin/quiqqer-asset/requirejs/requirejs/require.js'), 'utf8');

function fixture(header, {language = 'de', userLanguage = 'en', frontend = true} = {}) {
    const bundle = 'locale/readiness/bundle/' + language;
    const pending = new Map();
    const reads = [];
    const errors = [];
    const context = {
        console,
        setTimeout,
        clearTimeout,
        Intl,
        URL_DIR: '/',
        URL_OPT_DIR: '/packages/',
        URL_BIN_DIR: '/core/',
        URL_VAR_DIR: '/var/',
        URL_TEMPLATE_DIR: '/template/',
        QUIQQER_USER: {lang: userLanguage},
        QUIQQER_PROJECT: {lang: language},
        QUIQQER_LOCALE: [bundle],
        location: {
            search: '',
            toString: () => 'https://example.test/'
        },
        Class: function (definition) {
            return definition;
        }
    };

    if (frontend) {
        context.QUIQQER_FRONTEND = 1;
    }

    context.window = context;
    vm.createContext(context);
    vm.runInContext(requireJs, context);

    const scripts = [...readFileSync(resolve(core, 'src/templates', header), 'utf8')
        .matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)];
    const configuration = scripts.find(match => match[1].includes('require.config('))[1]
        .replaceAll('{$loaderSettings.type|json_encode}', '"default"')
        .replaceAll(/\{\$[^}]+\}/g, 'test');
    vm.runInContext(configuration, context);

    class Locale {
        constructor() {
            this.current = 'en';
            this.groups = {en: {'quiqqer/core': {'login.title': 'English login'}}};
        }
        setCurrent(language) {
            this.current = language;
        }

        getCurrent() {
            return this.current;
        }

        set(language, group, values) {
            this.groups[language] ??= {};
            this.groups[language][group] = {...this.groups[language][group], ...values};
        }
        get(group, key) {
            reads.push({language: this.current, key});
            return this.groups[this.current]?.[group]?.[key] ?? '[' + group + '] ' + key;
        }
    }

    context.define('classes/Locale', [], () => Locale);
    for (const dependency of ['qui/QUI', 'qui/controls/windows/SimpleWindow', 'controls/users/Login']) {
        context.define(dependency, [], () => ({}));
    }
    const cssPlugin = context.requirejs.s.contexts._.config.map['*'].css;
    context.define(cssPlugin, [], () => ({load(name, require, onload) { onload(); }}));
    context.define('independent', [], () => 'ready');

    const sources = {
        Locale: 'bin/QUI/Locale.js',
        'classes/LocaleInstance': 'bin/QUI/classes/LocaleInstance.js',
        'controls/users/LoginWindow': 'bin/QUI/controls/users/LoginWindow.js'
    };
    context.requirejs.load = (loader, moduleId) => {
        if (moduleId === bundle) {
            pending.set(moduleId, loader);
            return;
        }

        queueMicrotask(() => {
            try {
                assert.ok(sources[moduleId], 'Unexpected module: ' + moduleId);
                vm.runInContext(readFileSync(resolve(core, sources[moduleId]), 'utf8'), context);
                loader.completeLoad(moduleId);
            } catch (error) {
                error.requireModules = [moduleId];
                loader.onError(error);
            }
        });
    };
    context.requirejs.onError = error => errors.push(error);

    return {
        bundle,
        pending,
        reads,
        errors,
        import(modules) {
            return new Promise((resolve, reject) => context.requirejs(modules, (...values) => resolve(values), reject));
        },
        publish() {
            assert.ok(pending.has(bundle), 'The initial language bundle must be requested.');
            const common = 'locale/readiness/common/' + language;
            const frontendModule = 'locale/readiness/frontend/' + language;
            vm.runInContext(`
                define('${common}', ['Locale'], function(Locale) {
                    Locale.set('${language}', 'quiqqer/core', {'login.title': '${language} login'});
                });
                define('${frontendModule}', ['Locale', '${common}'], function(Locale) {
                    Locale.set('${language}', 'quiqqer/core', {'loginBox.header.text': '${language} description'});
                });
                define('${bundle}', ['${common}', '${frontendModule}'], function() {return {language: '${language}'};});
            `, context);
            pending.get(bundle).completeLoad(bundle);
            pending.delete(bundle);
        },
        fail() {
            const error = new Error('Initial language bundle failed.');
            error.requireModules = [bundle];
            error.requireType = 'scripterror';
            pending.get(bundle).onError(error);
        }
    };
}

const settle = () => new Promise(resolve => setTimeout(resolve, 30));

for (const header of ['header.html', 'headerNoConflict.html']) {
    for (const [language, userLanguage] of [['de', 'de'], ['de', 'en'], ['en', 'de']]) {
        test(`${header}: early LoginWindow evaluation waits for ${language} despite user language ${userLanguage}`, async () => {
            const state = fixture(header, {language, userLanguage});
            const pending = state.import(['controls/users/LoginWindow']);
            await settle();
            assert.equal(state.pending.size, 1);
            assert.deepEqual(state.reads, [], 'Static control options must not read translations prematurely.');
            assert.deepEqual(await state.import(['independent']), ['ready'], 'Unrelated modules can load in parallel.');

            state.publish();
            const [Control] = await pending;
            assert.equal(Control.options.title, language + ' login');
            assert.equal(Control.options.description, language + ' description');
            assert.ok(state.reads.every(read => read.language === language));
            assert.deepEqual(state.errors, []);
        });
    }

    test(`${header}: header dependencies and published modules share the ready Locale instance`, async () => {
        const state = fixture(header);
        const headerRequest = state.import(['Locale', state.bundle]);
        const controlRequest = state.import(['Locale']);
        await settle();
        state.publish();

        const [Locale] = await headerRequest;
        const [ControlLocale] = await controlRequest;
        const [InternalLocale] = await state.import(['classes/LocaleInstance']);
        assert.equal(Locale, ControlLocale);
        assert.equal(Locale, InternalLocale);
        assert.equal(Locale.getCurrent(), 'de');
        assert.equal(Locale.get('quiqqer/core', 'login.title'), 'de login');
        assert.deepEqual(state.errors, []);
    });

    test(`${header}: a failed language bundle rejects control loading without rendering fallback text`, async () => {
        const state = fixture(header);
        const pending = state.import(['controls/users/LoginWindow']);
        const rejected = assert.rejects(pending, /Initial language bundle failed/);
        await settle();
        state.fail();
        await rejected;
        assert.deepEqual(state.reads, []);
    });
}

test('backend Locale remains available without requesting frontend translations', async () => {
    const state = fixture('header.html', {frontend: false});
    const [Locale] = await state.import(['Locale']);
    assert.equal(state.pending.size, 0);
    assert.equal(Locale.getCurrent(), 'en');
    assert.equal(Locale.get('quiqqer/core', 'login.title'), 'English login');
    assert.deepEqual(state.errors, []);
});
