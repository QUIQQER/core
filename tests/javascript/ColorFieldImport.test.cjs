const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function loadControls(imports, loadError) {
    let controls;
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../bin/QUI/utils/Controls.js'), 'utf8'), {
        define: (_name, _dependencies, factory) => {controls = factory();},
        require: (dependencies, success, failure) => {
            assert.equal(dependencies[0], 'qui/controls/elements/ColorField');
            if (loadError) {
                failure(loadError);
                return;
            }
            success(class {
                imports(input) {
                    imports.push({input, value: input.value, type: input.type});
                    input.type = 'hidden';
                }
            });
        }
    });
    return controls;
}

// Model a native color input that only accepts six-digit hex, including value sanitization.
function colorInput(value, attributes = {}) {
    let current = '#000000';
    const input = {
        type: 'color',
        getAttribute: name => attributes[name] ?? null,
        get value() {return current;},
        set value(value) {
            current = this.type === 'color' && !/^#[a-f\d]{6}$/i.test(value) ? '#000000' : value;
        }
    };
    input.value = value;
    return input;
}

function container(inputs) {
    return {
        querySelectorAll(selector) {
            assert.equal(selector, 'input[type="color"]');
            return inputs.filter(input => input.type === 'color');
        }
    };
}

test('delegates the original color input and its preserved value to ColorField', async () => {
    const value = '{"type":"color","light":"#123456","dark":"#abcdef80"}';
    const input = colorInput(value, {
        'data-realvalue': value,
        value: '#ffffff',
        name: 'color',
        'data-qui-options-references': '1',
        'data-qui-options-dark': '1'
    });
    const imports = [];
    await loadControls(imports).parseColor(container([input]));
    // Restoration belongs to ColorField, including imports outside the Core parser.
    assert.deepEqual(imports, [{input, value: '#000000', type: 'color'}]);
    assert.equal(input.getAttribute('data-realvalue'), value);
    assert.equal(input.getAttribute('value'), '#ffffff');
    assert.equal(input.getAttribute('name'), 'color');
    assert.equal(input.getAttribute('data-qui-options-references'), '1');
    assert.equal(input.getAttribute('data-qui-options-dark'), '1');
});

test('leaves registered controls untouched and does not import converted inputs twice', async () => {
    const registered = colorInput('#123456', {'data-quiid': 'existing'});
    const input = colorInput('#abcdef');
    const imports = [];
    const controls = loadControls(imports);
    const parent = container([registered, input]);
    await controls.parseColor(parent);
    await controls.parseColor(parent);
    assert.equal(imports.length, 1);
    assert.equal(imports[0].input, input);
    assert.equal(registered.type, 'color');
    assert.equal(registered.value, '#123456');
});

test('a module loading failure rejects parsing without changing the input', async () => {
    const input = colorInput('#abcdef');
    const error = new Error('ColorField unavailable');
    await assert.rejects(loadControls([], error).parseColor(container([input])), error);
    assert.equal(input.type, 'color');
    assert.equal(input.value, '#abcdef');
});
