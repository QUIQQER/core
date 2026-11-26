const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const vm = require('node:vm');

class Node {
    constructor(tagName) {
        this.tagName = tagName;
        this.children = [];
        this.attributes = {};
        this.style = {};
        this.listeners = new Map();
        this.offsetWidth = 500;
        this.value = '';
        this.classList = {add: () => {}};
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    appendChild(child) {
        child.remove();
        child.parentElement = this;
        this.children.push(child);
        return child;
    }
    prepend(child) {
        this.appendChild(child);
        this.children.unshift(this.children.pop());
    }
    remove() {
        if (this.parentElement) {
            this.parentElement.children = this.parentElement.children.filter(child => child !== this);
            this.parentElement = null;
        }
    }
    contains(target) { return target === this || this.children.some(child => child.contains(target)); }
    querySelector(selector) {
        if (selector === 'form') return this.form;
        const name = selector.match(/\[data-name="([^"]+)"\]/)[1];
        for (const child of this.children) {
            if (child.getAttribute('data-name') === name) return child;
            const nested = child.querySelector(selector);
            if (nested) return nested;
        }
        return null;
    }
    addEventListener(name, callback) {
        if (!this.listeners.has(name)) this.listeners.set(name, new Set());
        this.listeners.get(name).add(callback);
    }
    removeEventListener(name, callback) { this.listeners.get(name)?.delete(callback); }
    emit(name, values = {}) {
        const event = {target: this, preventDefault() {}, stopPropagation() {}, ...values};
        for (const callback of this.listeners.get(name) || []) callback(event);
    }
    getBoundingClientRect() { return {right: 900, bottom: 60, height: this.height ?? 54}; }
    focus() { this.focused = true; }
}

function fixture(manager = 'users') {
    const entity = manager === 'users' ? 'user' : 'group';
    const renderFilterName = manager === 'users' ? 'renderFilter' : '$renderFilter';
    const root = new Node('div');
    const document = new Node('document');
    document.body = new Node('body');
    document.createElement = tagName => new Node(tagName);
    const controls = [];
    const attributes = {};
    const toolbarAttributes = {};
    const events = {};
    let definition;
    let searches = 0;
    let initialized = false;
    class Button {
        constructor(options) {
            this.element = new Node('button');
            this.element.textContent = options.text || '';
            if (options.events?.onClick) this.element.addEventListener('click', options.events.onClick);
        }
        getElm() { return this.element; }
    }
    const modules = {
        'qui/controls/buttons/Button': Button,
        Locale: {get: (group, name) => name === 'search' ? 'Suchen' : 'Filter:'}
    };
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../../bin/QUI/controls/' + manager + '/Panel.js'), 'utf8'), {
        document,
        window: {
            scrollX: 0,
            scrollY: 20,
            getComputedStyle: element => ({
                paddingLeft: '10px', paddingRight: '10px', paddingTop: '10px', paddingBottom: '10px',
                marginTop: '0px', marginBottom: '10px', ...element.computedStyle
            })
        },
        Class: function(value) { return value; },
        define: (name, dependencies, factory) => {
            definition = factory(...dependencies.map(name => modules[name] || {}));
        }
    });
    const panel = {
        ...definition,
        $uid: 'test-panel',
        getButtonBar() {
            initialized = true;
            return {setAttribute: (name, value) => { toolbarAttributes[name] = value; }};
        },
        addButton(value) {
            assert.equal(initialized, true);
            if (value instanceof Button) controls.push(value);
            else assert.ok(value instanceof Node, 'only real controls and DOM elements belong in the toolbar');
            root.appendChild(value instanceof Button ? value.getElm() : value);
        },
        [renderFilterName]() {
            this.$filterContainer.form = {
                elements: {
                    gid: {checked: true}, name: {checked: true},
                    uid: {checked: true}, username: {checked: true}, email: {checked: true},
                    firstname: {checked: true}, lastname: {checked: true},
                    filter_status: {value: 'all'}, filter_group: {value: ''},
                    filter_regdate_first: {value: ''}, filter_regdate_last: {value: ''}
                }
            };
        },
        addEvent(name, callback) { events[name] = callback; },
        search() { searches++; },
        setAttribute(name, value) { attributes[name] = value; },
        getAttribute(name) { return attributes[name]; },
        getElm: () => root,
        getBody: () => root,
        $loadUsers() { searches++; },
        load() { searches++; }
    };
    panel.$createSearchToolbar();
    return {
        panel, root, document, controls, events, attributes, toolbarAttributes,
        runSearch: () => definition.search.call(panel),
        searches: () => searches,
        button: root.querySelector('[data-name="' + entity + '-filter"]'),
        input: root.querySelector('[data-name="' + entity + '-search"]'),
        submit: root.querySelector('[data-name="' + entity + '-search-submit"]')
    };
}

module.exports = {fixture, Node};
