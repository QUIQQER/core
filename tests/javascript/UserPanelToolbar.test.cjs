const assert = require('node:assert/strict');
const {test} = require('node:test');
const {fixture, Node} = require('./fixtures/ManagerPanelToolbar.cjs');

test('initializes the toolbar with two usable controls and no empty placeholder', () => {
    const {root, controls, button, input, submit, panel, toolbarAttributes} = fixture();
    assert.equal(toolbarAttributes.width, '100%');
    assert.equal(controls.length, 2);
    assert.equal(button.textContent, 'Filter');
    assert.equal(submit.getAttribute('aria-label'), 'Suchen');
    assert.equal(input.getAttribute('aria-label'), 'Suchen');
    assert.equal(submit.getAttribute('tabindex'), '0');
    assert.equal(button.getAttribute('tabindex'), '0');
    assert.equal(button.getAttribute('aria-controls'), panel.$filterContainer.id);
    assert.ok(root.contains(button) && root.contains(submit));
    assert.ok(controls.every(control => control.getElm().textContent || control.getElm().getAttribute('aria-label')));
});

test('submits searches through the input, Enter and the embedded search button', () => {
    const {input, submit, searches} = fixture();
    input.emit('change');
    input.emit('keyup', {key: 'Enter'});
    input.emit('keyup', {key: 'a'});
    submit.emit('click');
    assert.equal(searches(), 3);
});

test('toggle closes on the second click, including clicks on the icon', () => {
    const {button, document, panel, searches} = fixture();
    button.emit('click');
    assert.equal(button.getAttribute('aria-expanded'), 'true');
    assert.equal(panel.$filterContainer.style.left, '400px');
    assert.equal(panel.$filterContainer.style.top, '88px');
    document.body.emit('mouseup', {target: button.children[0]});
    assert.equal(searches(), 0);
    button.emit('click', {target: button.children[0]});
    assert.equal(button.getAttribute('aria-expanded'), 'false');
    assert.equal(panel.$filterContainer.style.display, 'none');
    assert.equal(searches(), 1);
});

test('outside click applies filters while Escape also restores button focus', () => {
    const {button, document, panel, searches} = fixture();
    button.emit('click');
    document.body.emit('mouseup', {target: panel.$filterContainer});
    assert.equal(searches(), 0);
    document.body.emit('mouseup');
    assert.equal(button.getAttribute('aria-expanded'), 'false');
    button.emit('click');
    document.emit('keydown', {key: 'Escape'});
    assert.equal(button.getAttribute('aria-expanded'), 'false');
    assert.equal(button.focused, true);
    assert.equal(searches(), 2);
    document.body.emit('mouseup');
    assert.equal(searches(), 2, 'closing detaches the outside-click listener');
});

test('destroy removes the popup and global listeners without starting a search', () => {
    const {button, document, events, searches} = fixture();
    button.emit('click');
    events.onDestroy();
    assert.equal(document.body.children.length, 0);
    document.body.emit('mouseup');
    document.emit('keydown', {key: 'Escape'});
    assert.equal(searches(), 0);
});

test('closing the default filter does not activate search mode', () => {
    const {panel, button, attributes, runSearch} = fixture();
    panel.search = runSearch;
    button.emit('click');
    button.emit('click');
    assert.equal(attributes.search, false);
    assert.equal(attributes.searchSettings.userSearchString, '');
});

for (const [field, value] of [
    ['filter_status', '0'], ['filter_status', '1'], ['filter_status', '-1'],
    ['filter_group', 'customer-group'],
    ['filter_regdate_first', '2026-11-01'], ['filter_regdate_last', '2026-11-24']
]) {
    test('only a real filter activates search: ' + field + '=' + value, () => {
        const {panel, runSearch, attributes} = fixture();
        const input = panel.$filterContainer.form.elements[field];
        input.value = value;
        runSearch();
        assert.equal(attributes.search, true);
        input.value = field === 'filter_status' ? 'all' : '';
        runSearch();
        assert.equal(attributes.search, false, 'clearing the last filter ends search mode');
    });
}

test('text search activates search mode while empty and whitespace-only input clear it', () => {
    const {input, runSearch, attributes} = fixture();
    for (const [value, expected] of [['Andy', true], ['0', true], ['', false], ['  ', false]]) {
        input.value = value;
        runSearch();
        assert.equal(attributes.search, expected);
    }
});

test('grid dimensions follow actual padding and the height of a wrapped search notice', () => {
    const {panel, root} = fixture();
    root.clientWidth = 1000;
    root.clientHeight = 700;
    assert.deepEqual({...panel.$getGridSize()}, {width: 980, height: 680});
    const notice = new Node('div');
    panel.$SearchNotice = {getElm: () => notice};
    assert.deepEqual({...panel.$getGridSize()}, {width: 980, height: 616});
    assert.equal(notice.style.width, '980px');
    root.clientWidth = 320;
    notice.height = 86;
    assert.deepEqual({...panel.$getGridSize()}, {width: 300, height: 584});
    root.clientHeight = 50;
    assert.equal(panel.$getGridSize().height, 0);
});
