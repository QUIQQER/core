const assert = require('node:assert/strict');
const {test} = require('node:test');
const {fixture: managerFixture, Node} = require('./fixtures/ManagerPanelToolbar.cjs');

const fixture = () => managerFixture('groups');

test('group toolbar registers real search and filter buttons without a placeholder', () => {
    const {root, controls, button, input, submit, panel, toolbarAttributes} = fixture();
    assert.equal(toolbarAttributes.width, '100%');
    assert.equal(controls.length, 2);
    assert.equal(button.textContent, 'Filter');
    assert.equal(button.getAttribute('aria-controls'), panel.$filterContainer.id);
    assert.equal(button.getAttribute('tabindex'), '0');
    assert.equal(submit.getAttribute('tabindex'), '0');
    assert.equal(input.getAttribute('aria-label'), 'Suchen');
    assert.equal(submit.getAttribute('aria-label'), 'Suchen');
    assert.ok(root.contains(input) && root.contains(submit) && root.contains(button));
});

test('group search supports Enter, input changes and its search button', () => {
    const {input, submit, searches} = fixture();
    input.emit('change');
    input.emit('keyup', {key: 'Enter'});
    input.emit('keyup', {key: 'a'});
    submit.emit('click');
    assert.equal(searches(), 3);
});

test('opening and closing the group filter without a search term leaves the search inactive', () => {
    const {panel, button, document, attributes, runSearch} = fixture();
    panel.search = runSearch;
    button.emit('click');
    assert.equal(button.getAttribute('aria-expanded'), 'true');
    document.body.emit('mouseup', {target: button.children[0]});
    assert.equal(button.getAttribute('aria-expanded'), 'true');
    button.emit('click');
    assert.equal(button.getAttribute('aria-expanded'), 'false');
    assert.equal(attributes.search, '');
    button.emit('click');
    document.body.emit('mouseup', {target: panel.$filterContainer});
    assert.equal(button.getAttribute('aria-expanded'), 'true');
    document.body.emit('mouseup');
    assert.equal(button.getAttribute('aria-expanded'), 'false');
});

test('Escape closes the group filter and restores keyboard focus', () => {
    const {button, document, panel} = fixture();
    button.emit('click');
    assert.equal(panel.$filterContainer.style.left, '400px');
    assert.equal(panel.$filterContainer.style.top, '88px');
    document.emit('keydown', {key: 'Escape'});
    assert.equal(button.getAttribute('aria-expanded'), 'false');
    assert.equal(button.focused, true);
});

test('destroying the group panel removes its popup and global event handlers', () => {
    const {button, document, events, searches} = fixture();
    button.emit('click');
    events.onDestroy();
    assert.equal(document.body.children.length, 0);
    document.body.emit('mouseup');
    document.emit('keydown', {key: 'Escape'});
    assert.equal(searches(), 0);
});

for (const [gid, name, fields] of [
    [true, true, ['id', 'uuid', 'name']],
    [true, false, ['id', 'uuid']],
    [false, true, ['name']],
    [false, false, ['id', 'uuid', 'name']]
]) {
    test('group search preserves selected fields: ID=' + gid + ', name=' + name, () => {
        const {input, panel, runSearch, attributes} = fixture();
        panel.$filterContainer.form.elements.gid.checked = gid;
        panel.$filterContainer.form.elements.name.checked = name;
        input.value = 'Customers';
        runSearch();
        assert.equal(attributes.search, 'Customers');
        assert.deepEqual(Array.from(attributes.searchfields), fields);
        if (!gid && !name) {
            assert.equal(panel.$filterContainer.form.elements.gid.checked, true);
            assert.equal(panel.$filterContainer.form.elements.name.checked, true);
        }
    });
}

test('clearing or entering whitespace ends group search while zero stays searchable', () => {
    const {input, runSearch, attributes} = fixture();
    for (const [value, expected] of [['  Admin  ', 'Admin'], ['0', '0'], ['', ''], ['  ', '']]) {
        input.value = value;
        runSearch();
        assert.equal(attributes.search, expected);
    }
});

test('group grid measures padding and wrapped notices rather than reserving a fixed area', () => {
    const {panel, root} = fixture();
    root.clientWidth = 1000;
    root.clientHeight = 700;
    assert.deepEqual({...panel.$getGridSize()}, {width: 980, height: 680});
    const notice = new Node('div');
    panel.$SearchNotice = {getElm: () => notice};
    assert.deepEqual({...panel.$getGridSize()}, {width: 980, height: 616});
    root.clientWidth = 320;
    notice.height = 86;
    assert.deepEqual({...panel.$getGridSize()}, {width: 300, height: 584});
    root.clientHeight = 30;
    assert.equal(panel.$getGridSize().height, 0);
});
