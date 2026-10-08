/**
 * OmniGrid Table Object
 *
 * based on Author: Marko Šantić
 * Version: OmniGrid 1.2.6
 *
 * Complete rewrited by www.pcsg.de (Henning Leutz)
 *
 *
 * @fires onClick
 * @fires onDblClick
 * @fires onDblClickBegin [event, this]
 * @fires onContextMenu
 * @fires onBlur
 * @fires onFocus
 * @fires onMouseDown
 * @fires onMouseDown
 * @fires onEditComplete
 * @fires onLoadData
 * @fires onStart - on Sort Start
 * @fires onAutoColumModel
 * @fires onRefresh
 *
 * @fires onDragDropStart
 * @fires onDragDropComplete
 * @fires onDragDropEnter
 * @fires onDragDropLeave
 * @fires onDrop
 *
 * @licence: MIT licence
 */

define('controls/grid/Grid', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/buttons/Separator',
    'qui/controls/contextmenu/Menu',
    'qui/controls/contextmenu/Item',
    'qui/controls/windows/Confirm',
    'qui/utils/Controls',
    'Locale',

    'css!controls/grid/Grid.css'

], function (QUI, QUIControl, QUIButton, QUISeparator, QUIContextMenu,
             QUIContextItem, QUIConfirm, ControlUtils, QUILocale
) {
    'use strict';

    let Panel = null;
    const lg = 'quiqqer/core';

    const resizeMeInThePanel = function () {
        this.resize();

        if (Panel) {
            Panel.removeEvent('resize', resizeMeInThePanel);
        }
    };

    const getHash = function (str) {
        if (typeOf(str) !== 'string') {
            str = JSON.encode(str);
        }

        let hash = 0;
        let i, len, char;

        for (i = 0, len = str.length; i < len; i++) {
            char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }

        return hash;
    };

    // workaround for css loading
    require(['css!controls/grid/Grid.css']);

    /**
     * @class controls/grid/Grid
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QUIControl,
        Type: 'controls/grid/Grid',

        options: {
            title: '',
            titletagnode: '', // 'h3.omnigrid__caption-title' ist default | any valid css selector, e.g.:
            // 'div', 'h1.my_css_class', 'h2#id_attr.my_css_class.one_more_my_class'
            description: '', // html allowed
            name: false,
            alternaterows: true,
            showHeader: true,
            sortHeader: true,
            resizeColumns: true,
            selectable: true,
            serverSort: false,
            sortOn: null,
            sortBy: 'ASC',
            filterHide: true,
            filterHideCls: 'hide',
            tablesizing: 'normal', // 'normal', 'small'
            design: 'simple', // 'simple', 'clean'
            border: 'column', // 'none', 'column', 'row', 'all'
            // spacing from the last cell to the right border of the table, useful for resizing by dragging
            // if resizeColumns is set to false, lastCellRightSpacing is always set to 0
            lastCellRightSpacing: 40,
            emptyMessage: QUILocale.get('quiqqer/core', 'grid.is.empty'),

            storageKey: false, // if storage key is set, the grid settings (column model) are saved in the locale storage
            configurable: true, // table is configurable, user is able to dragdrop columns, storage key must be set

            filterSelectedCls: 'filter',
            multipleSelection: false,
            editable: false,   // Grid.addEvent('editcomplete', function(data) // selectable muss "true" sein!
            editondblclick: false,
            editType: 'input', // textarea | input
            resizeHeaderOnly: false,

            // accordion
            accordion: false,
            openAccordionOnClick: true,
            accordionRenderer: null,
            accordionLiveRenderer: null,
            autoSectionToggle: true, // if true just one section can be open/visible
            showtoggleicon: true,
            toggleiconTitle: 'Details',
            openAccordionOnDblClick: false,

            // buttons
            // [
            //   {
            //    ...
            //    position: 'left', // 'left' (default), 'center', 'right'
            //    order: 10         // number, 10 is default
            //   }
            // ]
            buttons: [],

            // pagination
            url: null,
            pagination: false,
            'button-reload': false,
            page: 1,
            perPageOptions: [
                5,
                10,
                20,
                50,
                75,
                100,
                150,
                200,
                250,
                500,
                750,
                1000,
                2500,
                5000
            ],
            perPage: 100,
            filterInput: true,
            // dataProvider
            dataProvider: null,

            //export
            exportName: false,
            exportData: false,
            exportCssFile: false,
            exportTypes: {
                pdf: true,
                csv: true,
                json: true,
                print: true
            }, // {print : 'Drucken', pdf : 'PDF', csv : 'CSV', json : 'JSON'},
            exportRenderer: null, // function(data){data.type data.data data.Grid}
            exportBinUrl: URL_OPT_DIR + 'quiqqer/core/src/QUI/Export/bin/export.php',

            // drag & Drop
            dragdrop: false,
            droppables: [],
            dragDropDataIndex: '',
            dragDropClass: false
        },

        Binds: [
            'openSortWindow',
            'highlightRow'
        ],

        $data: false,
        $columnModel: false,
        $refreshDelayID: null,

        initialize: function (container, options) {
            this.$gridHash = 0;

            this.tableSizing = 'normal';
            this.lastCellRightSpacing = 40;

            this.setAttribute('emptyMessage', QUILocale.get('quiqqer/core', 'grid.is.empty'));

            if (typeof options.lastCellRightSpacing !== 'undefined') {
                this.lastCellRightSpacing = parseInt(options.lastCellRightSpacing);
            }

            // column model
            if (typeof options.columnModel !== 'undefined') {
                this.$columnModel = options.columnModel;
                delete options.columnModel;
            } else {
                this.$columnModel = {};
            }

            this.addEvents({
                onSetAttribute: (key, value) => {
                    if (key === 'emptyMessage') {
                        if (this.container && this.container.querySelector('.data-empty-cell')) {
                            this.container.querySelector('.data-empty-cell').innerHTML = value;
                        }
                    }
                }
            });

            this.$originalColumns = this.$columnModel;
            // store initial columns model to reset the grid if needed with resetGrid() function
            // todo @michael.daniel it does not work as excepted
            this.$initialColumnsModel = Array.from(this.$columnModel);

            this.parent(options);

            if (!this.getAttribute('resizeColumns')) {
                this.lastCellRightSpacing = 0;
            }

            if (typeof options.storageKey !== 'undefined' && options.storageKey) {
                this.$gridHash = getHash(this.$columnModel);
                this.$loadFromStorage(options);
            }

            this.container = typeOf(container) === 'string' ? document.id(container) : container;

            this.container.setStyle('height', '100%');

            if (typeof options.tablesizing !== 'undefined' && options.tablesizing) {
                this.setAttribute('tablesizing', options.tablesizing);
            }

            if (this.getAttribute('tablesizing') === 'small') {
                this.tableSizing = 'small';
                this.container.style.setProperty('--_grid-sizingMultiplier', 0.5);
            }

            this.$disabled = false;

            this._stopdrag = false;
            this._dragtimer = false;
            this._mousedown = false;

            this.$data = [];
            this.$Menu = false;

            if (!this.container) {
                return;
            }

            //instanz name für element ids
            if (!this.getAttribute('name')) {
                this.setAttribute('name', this.getId());
            }

            this.container.set({
                'tabindex': '-1',
                styles: {
                    'MozOutline': 'none',
                    'outline': 0
                },
                events: {
                    focus: this.focus.bind(this),
                    blur: this.blur.bind(this),
                    mousedown: this.mousedown.bind(this),
                    mouseup: this.mouseup.bind(this)
                },
                'data-quiid': this.getId()
            });

            // Textmarkierung bei doppelclick aufheben
            this.container.addEventListener('dblclick', function (event) {
                event.preventDefault();

                if (window.getSelection) {
                    window.getSelection().removeAllRanges();
                } else {
                    if (document.selection) {
                        document.selection.empty();
                    }
                }
            });

            // Textmarkierung bei shift click aufheben
            this.container.addEventListener('click', function (event) {
                if (!event.shiftKey) {
                    return;
                }

                if (window.getSelection) {
                    window.getSelection().removeAllRanges();
                } else {
                    if (document.selection) {
                        document.selection.empty();
                    }
                }
            });


            this.draw();
            this.reset();
            this.resize();
            this.loadData();

            // this.resize.delay(250, this);
            // this.resize.delay(500, this);

            const PanelNode = this.container.getParent('.qui-panel');

            if (!PanelNode) {
                return;
            }

            Panel = QUI.Controls.getById(PanelNode.get('data-quiid'));
            if (!Panel) {
                return;
            }

            Panel.addEvent('resize', resizeMeInThePanel.bind(this));
            Panel.addEvent('show', resizeMeInThePanel.bind(this));

            (function () {
                Panel.removeEvent('resize', resizeMeInThePanel);
            }).delay(2000);
        },

        getElm: function () {
            return this.container;
        },

        destroy: function () {
            this.removeAll();

            this.container.empty();
            this.container.setStyles({
                width: '',
                height: ''
            });

            this.container.removeClass('omnigrid');

            const PanelNode = this.container.getParent('.qui-panel');

            if (!PanelNode) {
                return;
            }

            const Panel = QUI.Controls.getById(PanelNode.get('data-quiid'));

            if (!Panel) {
                return;
            }

            Panel.addEvent('resize', resizeMeInThePanel);
            Panel.addEvent('show', resizeMeInThePanel);
        },

        // API
        reset: function () {
            const t = this;

            t.renderData();

            t.$refreshDelayID = null;
            t.dragging = false;
            t.selected = [];

            t.elements = t.ulBody.getElements('li.tr');

            t.filtered = false;
            t.lastsection = null;

            if (t.getAttribute('alternaterows')) {
                t.altRow();
            }

            // Setup header
            t.container.getElements('.th').each(function (el, i) {
                const dataType = el.retrieve('dataType'),
                    columnModel = t.$columnModel[i] || {},
                    sortFunction = columnModel.sortFunction;

                if (!dataType && typeOf(sortFunction) !== 'function') {
                    return;
                }

                el.getdate = function (str) {
                    function fixYear(yr) {
                        yr = +yr;

                        if (yr < 50) {
                            yr += 2000;
                        } else {
                            if (yr < 100) {
                                yr += 1900;
                            }
                        }

                        return yr;
                    }

                    let ret, strtime;

                    if (str.length > 12) {
                        strtime = str.substring(str.lastIndexOf(' ') + 1);
                        strtime = strtime.substring(0, 2) + strtime.substr(-2);
                    } else {
                        strtime = '0000';
                    }

                    // YYYY-MM-DD
                    if ((ret = str.match(/(\d{2,4})-(\d{1,2})-(\d{1,2})/))) {
                        return (fixYear(ret[1]) * 10000) + (ret[2] * 100) + (+ret[3]) + strtime;
                    }

                    // DD/MM/YY[YY] or DD-MM-YY[YY]
                    if ((ret = str.match(/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/))) {
                        return (fixYear(ret[3]) * 10000) + (ret[2] * 100) + (+ret[1]) + strtime;
                    }

                    return 999999990000; // So non-parsed dates will be last, not first
                };

                el.findData = function (elem) {
                    const child = elem.getFirst();

                    if (child) {
                        return el.findData(child);
                    }

                    return elem.innerHTML.trim();
                };

                el.stripHTML = function (str) {
                    const tmp = str.replace(
                        /(<.*['"])([^'"]*)(['"]>)/g,
                        function (x, p1, p2, p3) {
                            return p1 + p3;
                        }
                    );

                    return tmp.replace(/<\/?[^>]+>/gi, '');
                };

                el.compare = function (a, b) {
                    if (typeOf(sortFunction) === 'function') {
                        return sortFunction(
                            t.$data[a.retrieve('row')],
                            t.$data[b.retrieve('row')],
                            el.sortBy || t.getAttribute('sortBy'),
                            columnModel,
                            t,
                            a,
                            b
                        );
                    }

                    // a i b su LI elementi
                    let var1 = a.getChildren()[i].innerHTML.trim(),
                        var2 = b.getChildren()[i].innerHTML.trim();

                    if (dataType === 'number' ||
                        dataType === 'integer' ||
                        dataType === 'int') {

                        var1 = parseFloat(el.stripHTML(var1));
                        var2 = parseFloat(el.stripHTML(var2));

                        if (el.sortBy === 'ASC') {
                            return var1 - var2;
                        }

                        return var2 - var1;
                    }

                    if (dataType === 'string' || dataType === 'text') {
                        var1 = var1.toUpperCase();
                        var2 = var2.toUpperCase();

                        if (var1 === var2) {
                            return 0;
                        }

                        if (el.sortBy === 'ASC') {
                            return var1 < var2 ? 1 : -1;
                        }

                        return var1 > var2 ? 1 : -1;
                    }

                    if (dataType === 'date') {
                        var1 = parseFloat(el.getdate(var1));
                        var2 = parseFloat(el.getdate(var2));

                        if (el.sortBy === 'ASC') {
                            return var1 - var2;
                        }

                        return var2 - var1;
                    }

                    if (dataType === 'currency') {
                        var1 = parseFloat(var1.substr(1).replace(',', ''));
                        var2 = parseFloat(var2.substr(1).replace(',', ''));

                        if (el.sortBy === 'ASC') {
                            return var1 - var2;
                        }

                        return var2 - var1;
                    }
                };

            }, t);

            t.altRow();
        },

        /**
         * Resize the grid
         */
        resize: function () {
            const self = 0,
                Container = this.container,
                width = Container.getSize().x,
                buttons = Container.getElements('.tDiv button'),
                separators = Container.getElements('.tDiv .qui-buttons-separator');

            buttons.setStyle('display', null);

            let sumWidth = buttons.map(function (Button) {
                if (self.$Menu === Button) {
                    return 0;
                }

                return Button.getComputedSize().totalWidth;
            }).sum();

            sumWidth = sumWidth + separators.map(function (Separator) {
                return Separator.getComputedSize().totalWidth;
            }).sum();

            if (sumWidth > width) {
                // hide buttons
                buttons.setStyle('display', 'none');
                separators.setStyle('display', 'none');

                if (this.$Menu) {
                    this.$Menu.enable();
                    this.$Menu.show();
                }
            } else {
                // show buttons
                buttons.setStyle('display', null);
                separators.setStyle('display', null);

                if (this.$Menu) {
                    this.$Menu.disable();
                    this.$Menu.hide();
                }
            }
        },

        /**
         * Return pagination data for ajax request
         *
         * @return {Object}
         */
        getPaginationData: function () {
            return {
                limit: this.getAttribute('perPage'),
                perPage: this.getAttribute('perPage'),

                page: this.getAttribute('page'),
                sortBy: this.getAttribute('sortBy'),
                sortOn: this.getAttribute('sortOn')
            };
        },

        // API
        // pretvara zadanu columnu u inline edit mode
        // options = {
        //        dataIndex:Number - column name || columnIndex:Number - column index
        //}
        edit: function (options) {
            let li;
            let t = this,
                sels = t.getSelectedIndices();

            if (!sels || sels.length === 0 || !t.getAttribute('editable')) {
                return;
            }

            if (options.li) {
                li = options.li;
            } else {
                li = t.elements[sels[0]];
            }

            t.finishEditing();

            // nadi index u columnModel
            let c = options.columnIndex || 0;
            let colmod, len;

            if (options.dataIndex) {
                for (len = this.$columnModel.length; c < len; c++) {
                    colmod = this.$columnModel[c];

                    if (colmod.hidden) {
                        continue;
                    }

                    if (colmod.dataIndex === options.dataIndex) {
                        break;
                    }
                }
            }

            if (c === this.$columnModel.length) {
                return;
            }

            colmod = this.$columnModel[c];

            if (!colmod.editable) {
                return;
            }

            const td = li.getElements('div.td')[c],
                data = this.$data[sels[0]],
                width = parseInt(td.getStyle('width')),
                html = data[colmod.dataIndex],
                editType = colmod.editType ? colmod.editType : this.getAttribute('editType');

            td.innerHTML = '';

            const input = new Element(editType, {
                'class': 'inline',
                style: 'width: ' + width + 'px; height: auto;',
                value: html,
                title: 'Doppelklick oder Enter um die Änderungen zu übernehmen', // #locale
                events: {
                    keyup: t.finishEditing.bind(this),
                    blur: t.finishEditing.bind(this),
                    dblclick: t.finishEditing.bind(this)
                }
            });

            if (this.getAttribute('editType') === 'textarea') {
                input.setAttribute('title', 'Doppelklick mit der linken Maustaste um die Änderungen zu übernehmen');
            }

            input.inject(td);
            input.focus();

            t.inlineEditSafe = {
                row: sels[0],
                columnModel: colmod,
                td: td,
                input: input,
                oldvalue: html
            };

            t.inlineeditmode = true;

            return t.inlineEditSafe;
        },

        finishEditing: function (evt) {
            const t = this;

            if (!t.inlineeditmode) {
                return;
            }

            if (evt &&
                evt.type === 'keyup' &&
                evt.key !== 'enter' &&
                evt.key !== 'esc' &&
                evt.type !== 'dblclick') {
                return;
            }

            const row = t.inlineEditSafe.row,
                data = this.$data[row],
                colmod = t.inlineEditSafe.columnModel,
                td = t.inlineEditSafe.td,
                editType = colmod.editType ? colmod.editType : this.getAttribute('editType');

            if (editType === 'textarea' &&
                evt &&
                evt.key !== 'esc' &&
                evt.type !== 'dblclick') {
                return;
            }

            t.inlineeditmode = false;

            if (editType === 'input') {
                if ((evt && ((evt.type === 'keyup' && evt.key === 'enter') || (evt.type === 'dblclick')))) {
                    data[colmod.dataIndex] = t.inlineEditSafe.input.value;
                } else {
                    data[colmod.dataIndex] = t.inlineEditSafe.oldvalue;
                }
            }

            if (editType === 'textarea') {
                if (evt && evt.type === 'dblclick') {
                    data[colmod.dataIndex] = t.inlineEditSafe.input.value;
                } else {
                    data[colmod.dataIndex] = t.inlineEditSafe.oldvalue;
                }
            }

            if (typeof data[colmod.dataIndex] === 'undefined' || !data[colmod.dataIndex]) {
                data[colmod.dataIndex] = '';
            }

            td.innerHTML = colmod.labelFunction ? colmod.labelFunction(data, row, colmod) : data[colmod.dataIndex];

            if (td.innerHTML.length === 0) {
                td.innerHTML = '&nbsp;';
            }

            // Key Events
            if (evt && evt.type === 'keyup' &&
                evt.key === 'enter' &&
                t.inlineEditSafe.oldvalue !== td.innerHTML) {
                t.inlineEditSafe.target = t;
                t.fireEvent('editComplete', t.inlineEditSafe);
            }

            // bei dbl click auch speichern ausführen
            if (evt &&
                evt.type === 'dblclick' &&
                t.inlineEditSafe.oldvalue !== td.innerHTML) {
                t.inlineEditSafe.target = t;
                t.fireEvent('editComplete', t.inlineEditSafe);
            }

            t.inlineEditSafe = null;
        },

        toggle: function (el) {
            if (typeof el.style === 'undefined') {
                return;
            }

            if (el.style.display === 'block') {
                el.style.display = 'none';
                return;
            }

            el.style.display = 'block';
        },

        // API
        getSection: function (row) {
            return this.ulBody.getElement('.section-' + row);
        },

        // API
        removeSections: function () {
            let i, len;
            const sections = this.ulBody.getElements('.section');

            if (this.getAttribute('showtoggleicon')) {
                this.ulBody.getElements('.toggleicon').setStyle('background-position', '0 0');
            }

            for (i = 0, len = sections.length; i < len; i++) {
                this.ulBody.removeChild(sections[i]);
            }
        },

        getLiParent: function (target) {
            if (!target) {
                return false;
            }

            if (target && !target.hasClass('td')) {
                target = this.getTdParent(target);
            }

            if (target) {
                return target.getParent();
            }
        },

        getTdParent: function (target) {
            if (!target) {
                return;
            }

            if (target && !target.hasClass('td')) {
                target = target.getParent('.td');
            }

            if (target) {
                return target;
            }
        },

        focus: function () {
            this.fireEvent('focus');
        },

        blur: function () {
            this.fireEvent('blur', [this]);
        },

        mousedown: function () {
            this.fireEvent('mouseDown', [this]);
        },

        mouseup: function () {
            this.fireEvent('mouseUp', [this]);
        },

        onRowMouseOver: function (evt) {
            let li = this.getLiParent(evt.target);

            if (!li) {
                return;
            }

            if (!this.dragging) {
                li.addClass('over');
            }

            if (!evt.target || typeof evt.target.getParent !== 'function') {
                return;
            }

            this.fireEvent('mouseOver', {
                target: this,
                row: li.retrieve('row'),
                element: li
            });
        },

        onRowMouseOut: function (evt) {
            let li = this.getLiParent(evt.target);

            if (!li) {
                return;
            }

            if (!this.dragging) {
                li.removeClass('over');
            }

            if (!evt.target || typeof evt.target.getParent !== 'function') {
                return;
            }

            this.fireEvent('mouseOut', {
                target: this,
                row: li.retrieve('row'),
                element: li
            });
        },

        onRowMouseDown: function (event) {
            if (this._mousedown) {
                return;
            }

            if (event.target.nodeName === 'INPUT') {
                return;
            }

            this._stopdrag = false;
            this._dragtimer = this.startDrag.delay(200, this, event);
        },

        onRowMouseUp: function (event) {
            if (event.target.nodeName === 'INPUT') {
                return;
            }

            // stop drag an drop
            if (!this.getAttribute('dragdrop')) {
                return;
            }

            // if dragdrop is start
            if (this.Drag) {
                this.Drag.fireEvent('mouseUp', event);
                return;
            }

            if (this._dragtimer) {
                clearTimeout(this._dragtimer);
            }

            this._dragtimer = false;
            this._stopdrag = true;
        },

        onRowClick: function (evt) {
            let i, len, row;

            let t = this,
                li = this.getLiParent(evt.target),
                onclick = false;

            if (evt.target.nodeName === 'INPUT') {
                return;
            }

            if (!li) {
                return;
            }

            if (typeof li.focus !== 'undefined') {
                li.focus();
            }

            row = li.retrieve('row');

            if (t.getAttribute('selectable')) {
                let selectedNum = t.selected.length,
                    dontselect = false;

                if ((!evt.control && !evt.shift && !evt.meta) || !t.getAttribute('multipleSelection')) {
                    for (i = 0, len = t.elements.length; i < len; i++) {
                        t.elements[i].removeClass('selected');
                    }

                    t.selected = [];
                }

                if (evt.control || evt.meta) {
                    for (i = 0; i < selectedNum; i++) {
                        if (row == t.selected[i]) {
                            t.elements[row].removeClass('selected');
                            t.selected.splice(i, 1);

                            dontselect = true;
                        }
                    }
                }

                if (evt.shift && t.getAttribute('multipleSelection')) {
                    let si = 0;

                    if (t.selected.length > 0) {
                        si = t.selected[selectedNum - 1];
                    }

                    let endindex = row;
                    let startindex = Math.min(si, endindex);

                    endindex = Math.max(si, endindex);

                    for (i = startindex; i <= endindex; i++) {
                        if (t.elements[i].hasClass('hide')) {
                            continue;
                        }

                        t.elements[i].addClass('selected');
                        t.selected.push(Number(i));
                    }
                }

                if (!dontselect) {
                    li.addClass('selected');
                    t.selected.push(Number(row));
                }

                t.selected = t.unique(t.selected, true);
            }

            if (t.getAttribute('accordion') &&
                t.getAttribute('openAccordionOnClick') && !t.getAttribute('openAccordionOnDblClick')) {
                t.accordianOpen(li);
            }

            if ((onclick = t.$data[row].onclick)) {
                if (typeof onclick === 'string') {
                    if (!eval(onclick + '(li, data, evt);')) {
                        return;
                    }
                } else {
                    onclick(li, t.$data[row], evt);
                }
            }

            t.fireEvent('click', [
                {
                    indices: t.selected,
                    target: t,
                    row: row,
                    element: li,
                    cell: t.getTdParent(evt.target),
                    evt: evt
                },
                this
            ]);
        },

        onRowDblClick: function (evt) {
            let li = this.getLiParent(evt.target);

            if (!li) {
                return;
            }

            this.fireEvent('dblClickBegin', [
                evt,
                this
            ]);

            let ondblclick;
            let target = evt.target,
                row = li.retrieve('row');

            if (!target.hasClass('td') && target.getParent('.td')) {
                target = target.getParent('.td');
            }

            if (this.getAttribute('editable') &&
                this.getAttribute('editondblclick') && target.hasClass('td')) {
                let i, len;
                const childs = li.getChildren();

                for (i = 0, len = childs.length; i < len; i++) {
                    if (childs[i] === target) {
                        break;
                    }
                }

                const obj = this.edit({
                    columnIndex: i,
                    li: li
                });

                if (obj) {
                    if (typeof obj.input.selectRange === 'function') {
                        obj.input.selectRange(0, obj.input.value.length);
                    }
                }
            }

            if (this.getAttribute('accordion') &&
                this.getAttribute('openAccordionOnDblClick')) {
                this.accordianOpen(li);
            }

            if ((ondblclick = this.$data[row].ondblclick)) {
                if (typeof ondblclick === 'string') {
                    if (!eval(ondblclick + '(li, t.$data[ row ]);')) {
                        return;
                    }
                } else {
                    ondblclick(li, this.$data[row]);
                }
            }

            const eventparams = {
                row: row,
                target: this,
                element: li,
                cell: this.getTdParent(evt.target)
            };

            this.fireEvent('dblClick', eventparams);
        },

        onRowContext: function (event) {
            const li = this.getLiParent(event.target);

            if (!li) {
                return;
            }

            if (this.getAttribute('selectable') && !li.hasClass('selected')) {
                event.control = true;
                this.onRowClick(event);
            }

            event.stop();

            this.fireEvent('contextMenu', {
                row: li.retrieve('row'),
                target: this,
                event: event,
                element: li,
                cell: this.getTdParent(event.target)
            });
        },

        toggleIconClick: function (evt) {
            evt.stop();

            this.accordianOpen(
                this.getLiParent(evt.target)
            );
        },

        accordianOpen: function (li, event) {
            if (typeof li === 'undefined') {
                return;
            }

            let row = li.retrieve('row'),
                section = this.getSection(row);

            if (this.getAttribute('accordion') &&
                (typeof section === 'undefined' || !section)) {
                const li2 = new Element('li.section', {
                    styles: {
                        width: this.sumWidth + this.lastCellRightSpacing
                    }
                });

                li2.addClass('section-' + li.retrieve('row'));

                const oSibling = li.nextSibling;

                if (!oSibling) {
                    this.ulBody.appendChild(li2);
                } else {
                    oSibling.parentNode.insertBefore(li2, oSibling);
                }

                section = li2;
            }

            if (this.getAttribute('autoSectionToggle')) {
                if (this.lastsection) {
                    if (this.lastsection != section) {
                        this.lastsection.setStyle('display', 'none');

                        if (this.lastsection.getPrevious()) {
                            const ToggleIcon = this.lastsection.getPrevious().getElement('.toggleicon');

                            if (ToggleIcon) {
                                ToggleIcon.setStyle('background-position', '0 0');
                            }
                        }
                    }
                }

                if (!this.getAttribute('accordionRenderer') && !this.getAttribute('accordionLiveRenderer')) {
                    section.setStyle('display', 'block');
                }
            }

            if (this.getAttribute('accordionRenderer') ||
                this.getAttribute('accordionLiveRenderer')) {
                this.toggle(section);
            }

            if (this.getAttribute('accordionLiveRenderer')) {
                this.showLoader();

                this.getAttribute('accordionLiveRenderer')({
                    parent: section,
                    row: li.retrieve('row'),
                    grid: this,
                    event: event
                });

                this.hideLoader();
            }

            if (this.getAttribute('showtoggleicon') && li.getElement('.toggleicon')) {
                li.getElement('.toggleicon').setStyle(
                    'background-position',
                    section.getStyle('display') === 'block' ? '-16px 0' : '0 0'
                );
            }

            this.lastsection = section;
        },

        onLoadData: function (data) {
            this.setData(data);

            // API
            this.fireEvent('loadData', {
                target: this
            });
        },

        unique: function (a, asNumber) {
            function om_sort_number(a, b) {
                return a - b;
            }

            const sf = asNumber ? om_sort_number : function () {
            };

            a.sort(sf);
            a = a.unique();

            return a;
        },
        // API
        loadData: function (url) {
            const options = this.getAttributes(),
                container = this.container;

            if (!this.getAttribute('url') && !this.getAttribute('dataProvider')) {
                return;
            }

            let data = {};

            // pagination
            if (this.getAttribute('pagination')) {
                data = {
                    page: this.getAttribute('page'),
                    perpage: this.getAttribute('perPage')
                };
            }

            // server sorting
            if (this.getAttribute('serverSort')) {
                data.sorton = this.getAttribute('sortOn');
                data.sortby = this.getAttribute('sortBy');
            }

            if (this.getAttribute('filterInput')) {
                const cfilter = container.getElement('input.cfilter');

                if (cfilter) {
                    data.filter = cfilter.value;
                }
            }

            this.showLoader();

            if (this.getAttribute('dataProvider')) {
                this.getAttribute('dataProvider').loadData(data);
                return;
            }

            const request = new Request.JSON({
                url: (url !== null) ? url : options.url,
                data: data
            });

            request.addEvent('complete', this.onLoadData.bind(this));
            request.get();
        },

        // API
        refresh: function () {
            this.resetButtons();

            if (this.getAttribute('onrefresh')) {
                this.getAttribute('onrefresh')(this);
            }

            this.fireEvent('refresh', [this]);

            this.loadData();
        },

        resetButtons: function () {
            const btns = this.getAttribute('buttons');

            if (!btns || !btns.length) {
                return;
            }

            let i, len, Btn;

            for (i = 0, len = btns.length; i < len; i++) {
                if (!btns[btns[i].name]) {
                    continue;
                }

                Btn = btns[btns[i].name];

                if (btns[i].disabled) {
                    Btn.setDisable();
                    continue;
                }

                Btn.setNormal();
            }
        },

        /**
         * Return the grid buttons
         *
         * @return {Array}
         */
        getButtons: function () {
            const buttons = [];

            const btns = this.getAttribute('buttons');

            if (!btns || !btns.length) {
                return buttons;
            }

            let i, len;

            for (i = 0, len = btns.length; i < len; i++) {
                if (QUI.Controls.isControl(btns[i])) {
                    buttons.push(btns[i]);
                    continue;
                }

                if (!btns[btns[i].name]) {
                    continue;
                }

                buttons.push(btns[btns[i].name]);
            }

            return buttons;
        },

        /**
         * Return a wanted button by its name
         *
         * @param name
         * @return {null|*}
         */
        getButton: function (name) {
            const buttons = this.getButtons();

            for (let i = 0, len = buttons.length; i < len; i++) {
                if (buttons[i].getAttribute('name') === name) {
                    return buttons[i];
                }
            }

            return null;
        },

        dataLoader: function () {
            this.setAttribute('page', 1);
            this.onLoadData({
                data: {},
                total: 0,
                page: 1,
                perPage: 0
            });

            this.loadData();
        },

        // API
        setData: function (data, cm) {
            const options = this.getAttributes(),
                container = this.container;

            if (!data) {
                return;
            }

            this.$data = data.data;

            if (!this.$columnModel) {
                this.setAutoColumnModel();
            }

            if (this.getAttribute('pagination')) {
                if (typeof data.total === 'undefined') {
                    data.total = this.$data.length;
                }

                if (typeof data.page === 'undefined') {
                    data.page = 1;
                }

                options.page = data.page * 1;
                options.total = data.total;
                options.maxpage = Math.ceil(options.total / options.perPage);

                const cPage = container.getElements('div.pDiv input.cpage');

                cPage.set('value', data.page);
                cPage.setStyle('width', 32);

                const to = (data.page * options.perPage) > data.total ? data.total : (data.page * options.perPage),
                    page = ((data.page - 1) * options.perPage + 1);

                const stats = '<span>' + page + '</span>' +
                    '<span>..</span>' +
                    '<span>' + to + '</span>' +
                    '<span> / </span>' +
                    '<span>' + data.total + '</span>';

                container.getElements('div.pDiv .pPageStat').set('html', stats);

                cPage.getNext('span.cpageMax').set('html', options.maxpage);
            }

            if (cm && this.$columnModel != cm) {
                this.$columnModel = cm;
                this.draw();
            }

            this.reset();
            this.filerData();
            this.hideLoader();
        },

        // API
        getData: function () {
            if (!this.$data.length) {
                this.$data = [];
            }

            return this.$data;
        },

        // API
        getDataByRow: function (row) {
            if (row < 0) {
                return false;
            }

            if (typeof this.$data[row] !== 'undefined') {
                return this.$data[row];
            }
        },

        // API
        getRowElement: function (row) {
            if (typeof this.elements[row] !== 'undefined') {
                return this.elements[row];
            }

            return false;
        },

        // API
        setDataByRow: function (row, data) {
            if (row < 0) {
                return false;
            }

            if (typeof this.$data[row] === 'undefined') {
                return;
            }

            this.$data[row] = data;

            const Row = this.container.getElement('[data-row="' + row + '"]');
            const newRow = this.renderRow(row, this.$data[row]);

            newRow.inject(Row, 'after');
            Row.destroy();

            this.elements[row] = newRow;
        },

        setScroll: function (x, y) {
            new window.Fx.Scroll(
                this.container.getElement('.bDiv')
            ).set(x, y);
        },

        // API
        addRow: function (data, row) {
            if (typeof row === 'undefined') {
                row = 0;

                if (this.$data.length) {
                    row = this.$data.length;
                }
            }

            if (row >= 0) {
                this.$data.splice(row, 0, data);
                this.reset();
            }
        },

        // API
        deleteRow: function (row) {
            if (row >= 0 && row < this.$data.length) {
                this.$data.splice(row, 1);
                this.reset();
            }
        },

        /**
         * Delete multiple rows
         *
         * @param {Array} rowIds - list of the row ids
         */
        deleteRows: function (rowIds) {
            for (let i = 0, len = rowIds.length; i < len; i++) {
                delete this.$data[rowIds[i]];
            }

            this.$data = this.$data.clean();
            this.reset();
        },

        isHidden: function (i) {
            return this.elements[i].hasClass(
                this.getAttribute('filterHideCls')
            );
        },

        hideWhiteOverflow: function () {
            let gBlock;

            if ((gBlock = this.container.getElement('.gBlock'))) {
                gBlock.dispose();
            }
        },

        showWhiteOverflow: function () {
            let gBlock;
            let container = this.container;

            // white overflow & loader
            if ((gBlock = container.getElement('.gBlock'))) {
                gBlock.dispose();
            }

            gBlock = new Element('div.gBlock', {
                styles: {
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    zIndex: 999,
                    background: 'rgba(255, 255, 255, 0.5)',
                    width: '100%',
                    height: '100%'
                }
            });

            container.appendChild(gBlock);
        },

        showLoader: function () {
            if (this.loader) {
                return;
            }

            this.showWhiteOverflow();

            this.loader = new Element('div.elementloader', {
                html: '<span class="fa fa-circle-o-notch fa-spin"></span>'
            }).inject(this.container);
        },

        hideLoader: function () {
            if (!this.loader) {
                return;
            }

            this.hideWhiteOverflow();
            this.loader.destroy();
            this.loader = null;
        },

        // API
        selectAll: function () {
            let i, len, el;

            for (i = 0, len = this.elements.length; i < len; i++) {
                el = this.elements[i];

                if (el.hasClass('hide')) {
                    continue;
                }

                this.selected.push(el.retrieve('row'));

                el.addClass('selected');
            }

            //this.resetButtons();
            this.fireEvent('click', {
                indices: this.selected,
                target: this
            });
        },

        selectRow: function (Row, event) {
            if (typeof event !== 'undefined' &&
                (event.shift || event.control || event.meta) &&
                this.options.multipleSelection) {
                // nothing
            } else {
                this.unselectAll();
            }

            if (Row.hasClass('hide')) {
                return;
            }

            let i, len;
            const children = Row.getParent().getElements('li');

            for (i = 0, len = children.length; i < len; i++) {
                if (children[i] === Row) {
                    break;
                }
            }

            this.selected.push(i);
            Row.addClass('selected');
        },

        unSelectRow: function (Row) {
            Row.removeClass('selected');

            this.selected = this.selected.filter(selectedRowIndex => {
                return this.getRowElement(selectedRowIndex).hasClass('selected');
            });
        },

        // API
        unselectAll: function () {
            for (let i = 0, len = this.elements.length; i < len; i++) {
                this.elements[i].removeClass('selected');
            }

            this.selected = [];
            this.resetButtons();
        },

        // API
        getSelectedIndices: function () {
            return this.selected;
        },

        getSelectedData: function () {
            let i, len;
            const data = [];

            for (i = 0, len = this.selected.length; i < len; i++) {
                data.push(this.getDataByRow(this.selected[i]));
            }

            return data;
        },

        // API
        setSelectedIndices: function (arr) {
            let i, alen, li;

            this.selected = arr;

            for (i = 0, alen = arr.length; i < alen; i++) {
                li = this.elements[arr[i]];

                if (li) {
                    li.addClass('selected');
                }
            }
        },

        // mislim da je visak
        onMouseOver: function (obj) {
            obj.columnModel.onMouseOver(obj.element, obj.data);
        },

        removeHeader: function () {
            const obj = this.container.getElement('.hDiv');

            if (obj) {
                obj.empty();
            }

            this.$columnModel = null;
        },

        // API
        removeAll: function () {
            for (let i = 0, len = this.elements; i < len; i++) {
                this.elements[i].destroy();
            }

            if (this.ulBody) {
                this.ulBody.empty();
            }

            this.selected = [];
        },

        // API
        setColumnModel: function (cmu) {
            if (!cmu) {
                return;
            }

            this.$columnModel = cmu;
            this.draw();
        },

        // API
        setColumnProperty: function (columnName, property, value) {
            let i, len;
            const cmu = this.$columnModel;

            if (!cmu || !columnName || !property) {
                return;
            }

            columnName = columnName.toLowerCase();

            for (i = 0, len = cmu.length; i < len; i++) {
                if (cmu[i].dataIndex.toLowerCase() === columnName) {
                    cmu[i][property] = value;
                    return;
                }
            }
        },

        // Automatsko odredivanje column modela ako nije zadan
        setAutoColumnModel: function () {
            const rowCount = this.$data.length;

            if (!rowCount) {
                return;
            }

            this.$columnModel = [];

            // uzmi schemu od prvog podatka
            for (let cn in this.$data[0]) {
                if (!this.$data[0].hasOwnProperty(cn)) {
                    continue;
                }

                const dataType = typeof (this.$data[0][cn]) === 'number' ? 'number' : 'string';

                this.$columnModel.push({
                    header: cn,
                    dataIndex: cn,
                    dataType: dataType,
                    editable: true
                });
            }

            this.fireEvent('autoColumModel', {
                target: this,
                columnModel: this.$columnModel
            });

            this.draw();
        },

        // API
        setSize: function (w, h) {
            const container = this.container,
                gBlock = container.getElement('.gBlock'),
                hDiv = container.getElement('.hDiv'),
                tDiv = container.getElement('.tDiv'),
                bodyEl = container.getElement('.bDiv');

            this.setAttribute('width', w ? w : this.getAttribute('width'));
            this.setAttribute('height', h ? h : this.getAttribute('height'));

            container.setStyle('width', this.getAttribute('width'));
            container.setStyle('height', this.getAttribute('height'));

            const width = this.getAttribute('width');

            if (this.getAttribute('buttons')) {
                tDiv.setStyle('width', width);
            }

            if (this.getAttribute('showHeader') && hDiv) {
                hDiv.setStyle('width', width);
            }

            bodyEl.setStyle('width', width);
            container.getElement('.pDiv').setStyle('width', width);

            // Height
//            bodyEl.setStyle('height', this.getBodyHeight());

            if (gBlock) {
                gBlock.setStyles({
                    width: this.getAttribute('width')
//                    height: bodyEl.getSize().y
                });
            }
        },

        onBodyScroll: function () {
            const hbox = this.container.getElement('.hDivBox'),
                bbox = this.container.getElement('.bDiv');

            // A queued scroll event can arrive after the grid has been destroyed.
            if (!hbox || !bbox) {
                return;
            }

            const xs = bbox.getScroll().x;

            hbox.setStyle('left', -xs);
            this.rePosDrag();
        },

        onBodyClick: function () {

        },

        onBodyMouseOver: function () {

        },

        onBodyMouseOut: function () {

        },

        // Drag columns events
        rePosDrag: function () {
            const t = this;
            const options = t.getAttributes();

            if (!options.resizeColumns) {
                return;
            }

            let c, oclen, columnModel, dragSt;

            let dragTempWidth = 0,
                container = t.container,

                cDrags = container.getElements('.cDrag div'),
                scrollX = container.getElement('div.bDiv').getScroll().x,

                cModel = this.$columnModel,
                browser = false; //Browser.Engine.trident;

            if (typeof browser === 'undefined') {
                browser = false;
            }

            let gridTemplateColumns = '';

            for (c = 0, oclen = cModel.length; c < oclen; c++) {
                columnModel = cModel[c];
                dragSt = cDrags[c];

                if (typeof dragSt === 'undefined') {
                    continue;
                }

                dragSt.setStyle('left', dragTempWidth + columnModel.width - scrollX);

                if (!columnModel.hidden) {
                    dragTempWidth += columnModel.width;

                    gridTemplateColumns += columnModel.width + 'px ';
                }
            }

            this.container.style.setProperty('--grid-gridTemplateColumns', gridTemplateColumns);

            if (this.getAttribute('storageKey')) {
                this.$saveToStorage();
            }
        },

        onColumnDragComplete: function (target) {
            const t = this;
            let c, len, columnModel;

            t.dragging = false;

            let colindex = parseInt(target.retrieve('column')),
                cDrag = t.container.getElement('div.cDrag'),
                scrollX = t.container.getElement('div.bDiv').getScroll().x,
                dragSt = cDrag.getElements('div')[colindex],
                browser = false, //Browser.Engine.trident,
                cModel = this.$columnModel,
                pos = 0,
                elements = t.ulBody.getElements('li.tr');

            t.sumWidth = 0;

            if (typeof browser === 'undefined') {
                browser = false;
            }

            for (c = 0, len = cModel.length; c < len; c++) {
                columnModel = cModel[c];

                if (c === colindex) {
                    pos = parseInt(dragSt.getStyle('left')) + scrollX - this.sumWidth;
                } else {
                    if (!columnModel.hidden) {
                        t.sumWidth += columnModel.width;
                    }
                }
            }

            if (pos < 30) {
                pos = 30;
            }

            cModel[colindex].width = pos;
            t.sumWidth += pos;

            t.ulBody.setStyle('width', t.sumWidth + this.lastCellRightSpacing);
            const hDivBox = document.id(t.options.name + '_hDivBox');

            hDivBox.setStyle('width', t.sumWidth + this.lastCellRightSpacing);

            elements.each((el) => {
                el.setStyle('width', t.sumWidth + this.lastCellRightSpacing);
            });

            t.rePosDrag();
        },

        onColumnDragStart: function () {
            this.dragging = true;
        },

        onColumnDragging: function (target) {
            target.setStyle('top', 0);
        },

        overDragColumn: function (evt) {
            evt.target.addClass('dragging');
        },

        outDragColumn: function (evt) {
            evt.target.removeClass('dragging');
        },

        // Header events
        clickHeaderColumn: function (evt) {
            if (this.dragging) {
                return;
            }

            let Target = evt.target;

            if (Target.nodeName === 'SPAN') {
                Target = Target.parentNode;
            }

            let colindex = Target.getAttribute('column'),
                columnModel = this.$columnModel[colindex] || {},
                colSort = this.getAttribute('sortBy');

            if (!colSort) {
                colSort = 'DESC';
            }

            Target.removeClass('ASC');
            Target.removeClass('DESC');

            colSort = (colSort === 'ASC') ? 'DESC' : 'ASC';

            this.setAttribute('sortBy', colSort);
            this.setAttribute('sortOn', columnModel.dataIndex);

            Target.addClass(colSort);

            this.sort(colindex, colSort);
        },

        overHeaderColumn: function (evt) {
            if (this.dragging) {
                return;
            }

            const colindex = evt.target.getAttribute('column'),
                columnModel = this.$columnModel[colindex] || {};

            if (typeof columnModel.onmouseover === 'function') {
                columnModel.onmouseover(evt);
            }

            evt.target.addClass(columnModel.sort);
        },

        outHeaderColumn: function (evt) {
            if (this.dragging) {
                return;
            }

            const colindex = evt.target.getAttribute('column'),
                columnModel = this.$columnModel[colindex] || {};

            if (typeof columnModel.onmouseout === 'function') {
                columnModel.onmouseout(evt);
            }

            evt.target.removeClass(columnModel.sort);
        },

        // we probably do not need this function anymore (by @michael.daniel)
        getBodyHeight: function () {
            let height = this.getAttribute('height');

            if (this.getAttribute('showHeader')) {
                height = height - 26;
            }

            if (this.getAttribute('buttons') && this.container.getElement('.tDiv')) {
                height = height - parseInt(this.container.getElement('.tDiv').getStyle('height'));
            }

            if (this.getAttribute('pagination') || this.getAttribute('filterInput')) {
                height = height - 26;
            }

//            return (height - 2);
            return height;
        },

        /**
         * Set the height of the grid
         *
         * @param {number} height
         * @returns {Promise}
         */
        setHeight: function (height) {
            return new Promise(function (resolve) {
                if (height <= 0) {
                    resolve();
                    return;
                }

                this.setAttribute('height', height);

                moofx(this.container).animate({
                    height: height
                }, {
                    duration: 200,
                    callback: resolve
                    // we probably do not need this callback function anymore (by @michael.daniel)
//                    callback: function () {
//                        const bDiv = this.container.getElement('.bDiv');
//
//                        if (bDiv) {
//                            moofx(bDiv).animate({
//                                height: this.getBodyHeight()
//                            }, {
//                                duration: 200,
//                                callback: function () {
//                                    resolve();
//                                }
//                            });
//
//                            return;
//                        }
//
//                        resolve();
//                    }.bind(this)
                });
            }.bind(this));
        },

        /**
         * Set the height of the grid
         *
         * @param {number} width
         * @returns {Promise}
         */
        setWidth: function (width) {
            return new Promise(function (resolve) {
                if (width <= 0) {
                    resolve();
                    return;
                }

                this.setAttribute('width', width);

                moofx(this.container).animate({
                    width: width
                }, {
                    duration: 100,
                    callback: function () {
                        const bDiv = this.container.getElement('.bDiv');

                        if (bDiv) {
                            moofx(bDiv).animate({
                                width: width
                            }, {
                                duration: 200,
                                callback: function () {
                                    resolve();
                                }
                            });

                            return;
                        }

                        resolve();
                    }.bind(this)
                });
            }.bind(this));
        },

        renderData: function () {
            this.ulBody.empty();
            this.inlineEditSafe = null;

            if (!this.$data) {
                return;
            }

            const rowCount = this.$data.length,
                DataEmpty = this.container.getElement('.data-empty');

            if (!rowCount) {
                if (!DataEmpty) {
                    new Element('div', {
                        'class': 'data-empty',
                        html: `<div class="data-empty-cell">${this.options.emptyMessage}</div>`
                    }).inject(this.container.getElement('.bDiv'));
                }
            } else {
                if (DataEmpty) {
                    DataEmpty.destroy();
                }
            }

            for (let r = 0; r < rowCount; r++) {
                const rowData = this.$data[r],
                    li = this.renderRow(r, rowData);

                this.ulBody.appendChild(li);

                if (this.getAttribute('tooltip')) {
                    this.getAttribute('tooltip').attach(li);
                }

                if (this.getAttribute('accordion') &&
                    this.getAttribute('accordionRenderer') && !this.getAttribute('accordionLiveRenderer')) {
                    const li2 = new Element('li.section');
                    li2.addClass('section-' + r);
                    li2.setStyle('width', this.sumWidth + this.lastCellRightSpacing);

                    this.ulBody.appendChild(li2);

                    if (this.getAttribute('accordionRenderer')) {
                        this.getAttribute('accordionRenderer')({
                            parent: li2,
                            row: r,
                            grid: this
                        });
                    }
                }
            }
        },

        /**
         * Render one row
         *
         * @method controls/grid/Grid#renderRow
         *
         * @param {Number} row - row number
         * @param {Object} data - data for the row
         *
         * @return {HTMLElement|Element} li
         */
        renderRow: function (row, data) {
            let c;

            const t = this,
                o = t.getAttributes(),
                r = row,

                columnCount = this.$columnModel.length,
                rowdata = data;

            const li = new Element('li.tr', {
                styles: {
                    width: t.sumWidth + this.lastCellRightSpacing
                }
            });

            li.store('row', r);
            li.set('data-row', r);

            if (this.$data[r].cssClass) {
                li.addClass(this.$data[r].cssClass);
            }

            let columnModel, columnDataIndex, columnData, div, val;
            let firstVisible = -1;

            const func_input_click = function (data) {
                const index = data.columnModel.dataIndex;

                data.list.$data[data.row][index] = data.input.checked ? 1 : 0;
            };

            for (c = 0; c < columnCount; c++) {
                columnModel = this.$columnModel[c];
                columnDataIndex = columnModel.dataIndex;
                columnData = this.$data[r][columnDataIndex] || false;

                div = new Element('div.td', {
                    'data-index': columnModel.dataIndex || ''
                });

                if (columnModel.className) {
                    div.addClass(columnModel.className);
                }

                if (rowdata.className) {
                    div.addClass(rowdata.className);
                }

                li.appendChild(div);

                firstVisible = (!columnModel.hidden && firstVisible === -1) ? c : firstVisible;

                if (columnModel.hidden) {
                    div.setStyle('display', 'none');
                }

                if (columnModel.onMouseOver) {
                    div.onmouseover = t.onMouseOver.bind(t, {
                        element: div,
                        columnModel: columnModel,
                        data: this.$data[r]
                    });
                }

                // set column data as title of the cell
                if (columnData) {
                    let text = columnData;

                    if (typeof columnData !== 'string') {
                        text = columnData.innerText || columnData.textContent;
                    }

                    if (typeof text === 'string') {
                        // replace white spaces and newlines with a single space
                        text = text.replace(/\s+/g, ' ').trim();
                    }

                    if (text) {
                        div.title = text;
                    }
                }

                if (columnModel.dataType === 'button' && columnData) {
                    const _btn = this.$data[r][columnDataIndex];
                    _btn.data = this.$data[r];

                    _btn.data.row = r;
                    _btn.data.List = t;

                    const Btn = new QUIButton(_btn);
                    const node = Btn.create();

                    //node.removeClass( 'button' );
                    //node.addClass( 'button' );
                    node.addClass('btn-silver');

                    _btn.data.quiid = Btn.getId();

                    node.removeProperty('tabindex');  // focus eigenschaft nehmen
                    node.inject(div);
                    continue;
                }

                if (columnModel.dataType === 'QUI' && columnData) {
                    columnData.inject(div);
                    continue;
                }

                if (columnModel.dataType === 'code' && columnData) {
                    val = rowdata[columnDataIndex];

                    div.set('text', val);
                    continue;
                }

                if (columnModel.dataType === 'checkbox') {
                    const input = new Element('input', {type: 'checkbox'});

                    input.onclick = func_input_click.bind(this, {
                        columnModel: columnModel,
                        row: r,
                        list: t,
                        input: input
                    });

                    div.appendChild(input);

                    val = rowdata[columnDataIndex];

                    if (val == 1 || val === 't') {
                        input.set('checked', true);
                    }

                    continue;
                }

                if (columnModel.dataType === 'image') {
                    if (ControlUtils.isFontAwesomeClass(rowdata[columnDataIndex])) {
                        new Element('span', {
                            'class': rowdata[columnDataIndex]
                        }).inject(div);

                        continue;
                    }

                    div.appendChild(
                        new Element('img', {
                            src: rowdata[columnDataIndex]
                        })
                    );

                    if (typeof columnModel.style !== 'undefined') {
                        div.getElement('img').setStyles(columnModel.style);
                    }

                    continue;
                }

                if (columnModel.dataType === 'node') {
                    if (typeof rowdata[columnDataIndex] !== 'undefined' &&
                        rowdata[columnDataIndex] &&
                        rowdata[columnDataIndex].nodeName) {
                        div.appendChild(
                            rowdata[columnDataIndex]
                        );
                    }

                    continue;
                }

                if (typeOf(columnModel.labelFunction) === 'function') {
                    div.innerHTML = columnModel.labelFunction(rowdata, r, columnModel);
                    continue;
                }

                if (columnModel.dataType === 'style') {
                    if (rowdata[columnDataIndex]) {
                        div.setStyles(rowdata[columnDataIndex]);
                    }

                    div.innerHTML = '&nbsp;';
                    continue;
                }

                let str = rowdata[columnDataIndex];

                if (typeof rowdata[columnDataIndex] !== 'undefined' && rowdata[columnDataIndex] !== null) {
                    str = rowdata[columnDataIndex];
                } else {
                    str = '';
                }

                str = str.toString().trim();

                if (str === null ||
                    str === 'null' ||
                    str === 'undefined' ||
                    typeof str === 'undefined' ||
                    str === '' ||
                    str === '&nbsp;') {
                    str = '';
                }

                if (str === '') {
                    div.set('html', '&nbsp;');
                } else {
                    if (columnModel.dataType === 'html') {
                        div.set('html', str);
                    } else {
                        div.set('text', str);
                    }
                }

                let Toggle = false;

                if (firstVisible === c && o.accordion && o.showtoggleicon) {
                    Toggle = new Element('div.toggleicon', {
                        title: o.toggleiconTitle,
                        events: {
                            click: function (event) {
                                t.toggleIconClick(event);
                            }
                        }
                    }).inject(div, 'top');
                }
            }

            this.setEventsToRow(li);

            return li;
        },

        setEventsToRow: function (el) {
            el.removeEvents([
                'mouseover',
                'mouseout',
                'mousedown',
                'mouseup',
                'click',
                'dblclick'
            ]);

            el.addEvents({
                'mouseover': this.onRowMouseOver.bind(this),
                'mouseout': this.onRowMouseOut.bind(this),
                'mousedown': this.onRowMouseDown.bind(this),
                'mouseup': this.onRowMouseUp.bind(this),
                'click': this.onRowClick.bind(this),
                'dblclick': this.onRowDblClick.bind(this),
                'contextmenu': this.onRowContext.bind(this)
            });
        },

        // Main draw function
        draw: function () {
            let i, len, columnModel, sortable;
            const t = this;

            let container = t.container,
                options = t.getAttributes(),
                width = options.width ? options.width : '',
                columnCount = this.$columnModel ? this.$columnModel.length : 0,
                tDiv = null;

            t.removeAll();     // reset variables and only empty ulBody
            container.empty(); // empty all

            // Container
            if (options.width) {
                container.setStyle('width', options.width);
            }

            if (options.styles) {
                container.setStyles(options.styles);
            }

            container.addClass('omnigrid');

            if (options.design) {
                switch (options.design) {
                    case 'simple':
                    case 'clean':
                        container.addClass('omnigrid--design-' + options.design);
                        break;

                    default: {
                        container.addClass('omnigrid--design-simple');
                    }
                }
            }

            if (options.border) {
                switch (options.border) {
                    case 'none':
                    case 'column':
                    case 'row':
                    case 'all':
                        container.addClass('omnigrid--border-' + options.border);
                        break;

                    default: {
                        container.addClass('omnigrid--border-column');
                    }
                }
            }

            if (this.getAttribute('height')) {
                this.setHeight(this.getAttribute('height'));
            }

            // table title and description
            let title = this.getAttribute('title') ? this.getAttribute('title') : '',
                titleTagNode = this.getAttribute('titletagnode') ? this.getAttribute('titletagnode') : '',
                desc = this.getAttribute('description') ? this.getAttribute('description') : '';

            if (title || desc) {
                const Caption = new Element('div.omnigrid__caption').inject(container);

                if (title) {
                    let htmlTag = 'h3.omnigrid__caption-title';

                    if (titleTagNode) {
                        htmlTag = titleTagNode + '.omnigrid__caption-title';
                    }

                    new Element(htmlTag, {
                        text: title
                    }).inject(Caption);
                }

                if (desc) {
                    new Element('div.omnigrid__caption-description', {
                        html: desc
                    }).inject(Caption);
                }
            }


            // Toolbar
            if (this.getAttribute('buttons')) {
                tDiv = new Element('div.tDiv', {
                    styles: {
                        width: width
                    }
                });

                const ToolbarLeft = new Element('div', {
                    'data-position': 'left'
                }).inject(tDiv);
                const ToolbarCenter = new Element('div', {
                    'data-position': 'center'
                }).inject(tDiv);
                const ToolbarRight = new Element('div', {
                    'data-position': 'right'
                }).inject(tDiv);

                container.appendChild(tDiv);

                // button drop down
                this.$Menu = new QUIButton({
                    textimage: 'fa fa-navicon',
                    text: QUILocale.get('quiqqer/core', 'control.grid.menu.button'),
                    dropDownIcon: false
                }).inject(ToolbarRight);

                this.$Menu.getElm().style.setProperty('--_order', 1000);

                const bt = this.getAttribute('buttons');

                let node, Btn;

                const itemClick = function () {
                    if (!this.getChildren().length) {
                        this.click();
                    }
                };

                const itemDisable = function () {
                    this.disable();
                };

                const itemNormal = function () {
                    this.enable();
                };

                for (i = 0, len = bt.length; i < len; i++) {
                    bt[i].type = bt[i].type || '';


                    if (bt[i].type === 'separator') {
                        const Separator = new QUISeparator();

                        if (bt[i].order) {
                            Separator.getElm().style.setProperty('--_order', bt[i].order);
                        }

                        switch (bt[i].position) {
                            case 'left':
                            case 'center':
                            case 'right':
                                Separator.inject(
                                    tDiv.querySelector('[data-position="' + bt[i].position + '"]')
                                );
                                break;
                            default:
                                Separator.inject(ToolbarLeft);
                        }

                        continue;
                    }

                    bt[i].List = this;
                    bt[i].Grid = this;

                    if (QUI.Controls.isControl(bt[i])) {
                        Btn = bt[i];
                    } else {
                        Btn = new QUIButton(bt[i]);
                    }

                    bt[Btn.getAttribute('name')] = Btn;

                    switch (Btn.getAttribute('position')) {
                        case 'left':
                        case 'center':
                        case 'right':
                            Btn.inject(
                                tDiv.querySelector('[data-position="' + Btn.getAttribute('position') + '"]')
                            );
                            break;
                        default:
                            Btn.inject(ToolbarLeft);

                    }

                    node = Btn.getElm();
                    node.removeProperty('tabindex'); // focus eigenschaft nehmen
                    node.type = 'button';
                    node.addClass('btn-silver');

                    if (Btn.getAttribute('order')) {
                        node.style.setProperty('--_order', Btn.getAttribute('order'));
                    }

                    const Item = new QUIContextItem({
                        text: Btn.getAttribute('text'),
                        icon: Btn.getAttribute('icon') || Btn.getAttribute('textimage') || Btn.getAttribute('image'),
                        events: {
                            onClick: itemClick.bind(Btn)
                        }
                    });

                    Btn.addEvents({
                        onDisable: itemDisable.bind(Item),
                        onNormal: itemNormal.bind(Item),
                        onEnable: itemNormal.bind(Item),

                        onSetAttribute: function (key, value) {
                            if (key === 'text') {
                                this.setAttribute(key, value);
                                return;
                            }

                            if (key === 'image' || key === 'textimage') {
                                this.setAttribute('icon', value);
                            }
                        }.bind(Item)
                    });

                    // context menu
                    if ('$items' in Btn) {
                        if (Btn.$items.length) {
                            for (let itm = 0, itmLength = Btn.$items.length; itm < itmLength; itm++) {
                                const ItemClone = new QUIContextItem(
                                    Btn.$items[itm].getAttributes()
                                );

                                ItemClone.addEvent('onClick', itemClick.bind(Btn.$items[itm]));

                                Item.appendChild(ItemClone);
                            }
                        }

                        this.$Menu.appendChild(Item);
                    }

                    if ('isDisabled' in Btn && Btn.isDisabled()) {
                        Item.disable();
                    }
                }
            }

            // Header
            const hDiv = new Element('div.hDiv', {
                styles: {
                    'width': width
                }
            });

            container.appendChild(hDiv);

            const hDivBox = new Element('div.hDivBox', {
                id: this.getAttribute('name') + '_hDivBox'
            });

            hDiv.appendChild(hDivBox);

            t.sumWidth = 0;
            t.visibleColumns = 0;

            const sortBy = this.getAttribute('sortBy');

            let gridTemplateColumns = '';

            for (i = 0; i < columnCount; i++) {
                columnModel = this.$columnModel[i] || {};

                const div = new Element('div.th', {
                    'column': i
                });

                // default postavke columnModela
                if (typeof columnModel.width === 'undefined') {
                    columnModel.width = 100;
                }

                if (sortBy) {
                    columnModel.sort = sortBy;
                } else {
                    columnModel.sort = 'ASC';
                }

                // Header events
                sortable = this.getAttribute('sortHeader');

                if ('sortable' in columnModel) {
                    sortable = columnModel.sortable;
                }

                if (sortable) {
                    div.addEvents({
                        click: t.clickHeaderColumn.bind(this),
                        mouseleave: t.outHeaderColumn.bind(this),
                        mouseenter: t.overHeaderColumn.bind(this)
                    });
                } else {
                    div.setStyle('cursor', 'default');
                }

                div.store('dataType', columnModel.dataType);

                hDivBox.appendChild(div);

                if (typeof columnModel.styles !== 'undefined') {
                    div.setStyles(columnModel.styles);
                }

                if (typeof columnModel.hidden !== 'undefined' && columnModel.hidden) {
                    div.setStyle('display', 'none');
                } else {
                    t.sumWidth += columnModel.width;
                    t.visibleColumns++;
                    gridTemplateColumns += columnModel.width + 'px ';
                }

                const header = columnModel.header,
                    title = columnModel.title;

                if (header) {
                    div.innerHTML = '<span class="header-text">' + header + '</span>';
                }

                if (title) {
                    div.set('title', title);
                }

                if (columnModel.image) {
                    div.style.background = 'url("' + columnModel.image + '") no-repeat center center';
                }
            }

            // this set the width of each column (using css grid)
            this.container.style.setProperty('--grid-gridTemplateColumns', gridTemplateColumns);

            hDivBox.setStyle('width', t.sumWidth + this.lastCellRightSpacing);

            if (this.getAttribute('showHeader') === false) {
                hDiv.setStyle('display', 'none');
            }

            if (this.getAttribute('height')) {
                container.setStyle('height', options.height + 2);
            }

            if (this.getAttribute('resizeColumns')) {
                const cDrag = new Element('div.cDrag');

                if (container.querySelector('.hDiv')) {
                    container.querySelector('.hDiv').appendChild(cDrag);
                } else {
                    container.appendChild(cDrag);
                }

                let dragTempWidth = 0;

                for (i = 0; i < columnCount; i++) {
                    columnModel = this.$columnModel[i] || {};
                    const dragSt = new Element('div', {
                        'class': 'dragElm'
                    });

                    if (typeof columnModel.width === 'undefined') {
                        columnModel.width = 100;
                    }

                    dragSt.setStyles({
                        top: 0,
                        left: dragTempWidth + columnModel.width,
                        display: 'block'
                    });

                    dragSt.store('column', i);
                    cDrag.appendChild(dragSt);

                    // Events
                    dragSt.addEvent('mouseout', t.outDragColumn.bind(this));
                    dragSt.addEvent('mouseover', t.overDragColumn.bind(this));

                    const dragMove = new Drag(dragSt, {snap: 0}); // , {container: this.container.getElement('.cDrag') }
                    dragMove.addEvent('drag', t.onColumnDragging.bind(this));
                    dragMove.addEvent('start', t.onColumnDragStart.bind(this));
                    dragMove.addEvent('complete', t.onColumnDragComplete.bind(this));

                    if (columnModel.hidden) {
                        dragSt.setStyle('display', 'none');
                    } else {
                        dragTempWidth += columnModel.width;
                    }
                }
            }

            // Body
            const bDiv = new Element('div.bDiv', {
                id: this.getAttribute('name') + '_bDiv'
            });

            if (this.getAttribute('width')) {
                bDiv.setStyle('width', width);
            }

            container.appendChild(bDiv);

            //  scroll event
            t.onBodyScrollBind = t.onBodyScroll.bind(t);
            bDiv.addEvent('scroll', t.onBodyScrollBind);

            t.ulBody = new Element('ul', {
                styles: {
                    width: t.sumWidth + this.lastCellRightSpacing
                }
            });

            bDiv.appendChild(t.ulBody);

            if ((this.getAttribute('pagination') ||
                this.getAttribute('filterInput')) && !container.getElement('div.pDiv')) {

                const pDiv = new Element('div.pDiv', {
                    styles: {
                        width: width
                    }
                });

                container.appendChild(pDiv);

                const pDiv2 = new Element('div.pDiv2');
                pDiv.appendChild(pDiv2);

                let h = '';

                if (this.getAttribute('pagination')) {
                    h = h + '<div class="pGroup"><select class="rp" name="rp">';

                    let optIdx;
                    let setDefaultPerPage = false;

                    for (optIdx = 0, len = options.perPageOptions.length; optIdx < len; optIdx++) {
                        if (parseInt(options.perPageOptions[optIdx]) !== parseInt(options.perPage)) {
                            h = h + '<option value="' + options.perPageOptions[optIdx] + '">' +
                                options.perPageOptions[optIdx] + '</option>';
                        } else {
                            setDefaultPerPage = true;

                            h = h + '<option selected="selected" value="' + options.perPageOptions[optIdx] + '">' +
                                options.perPageOptions[optIdx] + '</option>';
                        }
                    }

                    h = h + '</select></div>';

                    h = h +
                        '<div class="btnseparator"></div><div class="pGroup"><div class="pFirst pButton"></div><div class="pPrev pButton"></div></div>';
                    h = h + '<div class="pGroup">' +
                        '<span class="pcontrol">' +
                        '<input class="cpage" type="text" value="1" size="4" style="text-align:center" /> ' +
                        '<span>/</span> ' +
                        '<span class="cpageMax"></span>' +
                        '</span>' +
                        '</div>';
                    h = h + '<div class="pGroup"><div class="pNext pButton"></div><div class="pLast pButton"></div></div>';
                    h = h + '<div class="btnseparator"></div><div class="pGroup"><span class="pPageStat"></span></div>';
                }

                if (this.getAttribute('pagination') || this.getAttribute('button-reload')) {
                    h = h + '<div class="btnseparator"></div><div class="pGroup"><div class="pReload pButton"></div></div>';
                }

                if (options.multipleSelection) {
                    h = h + '<div class="btnseparator"></div>' +
                        '<div class="pGroup">' +
                        '<div class="pSelectAll pButton" title="Alle auswählen"></div>' +
                        '<div class="pUnselectAll pButton" title="Auswahl aufheben"></div>' +
                        '</div>';
                }

                if (options.filterInput) {
                    h = h + '<div class="btnseparator"></div>';
                    h = h + '<div class="pGroup">';
                    h = h + '<span class="pcontrol">';
                    h = h + '<input class="cfilter" ';
                    h = h + 'title="Anzeige filtern" ';
                    h = h + 'type="text" ';
                    h = h + 'value="" ';
                    h = h + 'style="" ';
                    h = h + 'placeholder="Filter..." ';
                    h = h + '/>';
                    h = h + '<span>';
                    h = h + '</div>';
                }

                pDiv2.innerHTML = h;

                const RightButtons = new Element('div', {
                    'class': 'pGroup pGroup--alignRight'
                }).inject(pDiv2);

                if (this.getAttribute('storageKey')) {
                    const SizingBtn = new Element('div', {
                        'class': 'pSizing pButton',
                        title: QUILocale.get('quiqqer/core', 'grid.compact.button.title'),
                        'data-qui-tablesizing': 'normal',
                        events: {
                            click: this.resizeTablePerButtonClick.bind(this)
                        }
                    });

                    if (this.tableSizing === 'small') {
                        SizingBtn.title = QUILocale.get('quiqqer/core', 'grid.compact.button.title.small');
                        SizingBtn.setAttribute('data-qui-tableSizing', 'small');
                    }

                    RightButtons.appendChild(SizingBtn);
                }

                if (options.exportData) {
                    RightButtons.appendHTML(
                        '<div class="pExport pButton" title="' +
                        QUILocale.get('quiqqer/core', 'grid.export.button.title') + '">' +
                        '</div>');
                }

                let o;

                if ((o = pDiv2.getElement('.pFirst'))) {
                    o.addEvent('click', this.firstPage.bind(this));
                }

                if ((o = pDiv2.getElement('.pPrev'))) {
                    o.addEvent('click', this.prevPage.bind(this));
                }

                if ((o = pDiv2.getElement('.pNext'))) {
                    o.addEvent('click', this.nextPage.bind(this));
                }

                if ((o = pDiv2.getElement('.pLast'))) {
                    o.addEvent('click', this.lastPage.bind(this));
                }

                if ((o = pDiv2.getElement('.pReload'))) {
                    o.addEvent('click', this.refresh.bind(this));
                }

                if ((o = pDiv2.getElement('.rp'))) {
                    o.addEvent('change', this.perPageChange.bind(this));
                    o.value = options.perPage;
                }

                if ((o = pDiv2.getElement('input.cpage'))) {
                    pDiv2.getElement('input').addEvents({
                        keydown: this.pageChange.bind(this),
                        mousedown: function () {
                            this.focus();
                        }
                    });
                }

                if (this.getAttribute('filterInput')) {
                    if ((o = pDiv2.getElement('input.cfilter'))) {
                        pDiv2.getElement('input.cfilter').addEvents({
                            keyup: this.filerData.bind(this), // goto 1 & refresh
                            mousedown: function () {
                                this.focus();
                            }
                        });
                    }
                }

                if (this.getAttribute('multipleSelection')) {
                    if ((o = pDiv2.getElement('.pSelectAll'))) {
                        o.addEvent('click', this.selectAll.bind(this));
                    }

                    if ((o = pDiv2.getElement('.pUnselectAll'))) {
                        o.addEvent('click', this.unselectAll.bind(this));
                    }
                }

                if ((o = pDiv2.getElement('.pExport'))) {
                    o.addEvent('click', this.getExportSelect.bind(this));
                }

                if (this.getAttribute('configurable') && this.getAttribute('storageKey')) {
                    new Element('button.pButton', {
                        styles: {
                            cursor: 'pointer',
                            float: 'right',
                            margin: 0
                        },
                        html: '<span class="fa fa-gear"></span>',
                        events: {
                            click: this.openSortWindow
                        }
                    }).inject(RightButtons);
                }
            }
        },

        firstPage: function () {
            this.setAttribute('page', 1);
            this.refresh();
        },

        prevPage: function () {
            if (this.getAttribute('page') > 1) {
                this.setAttribute('page', this.getAttribute('page') - 1);
                this.refresh();
            }
        },

        nextPage: function () {
            if ((this.getAttribute('page') + 1) > this.getAttribute('maxpage')) {
                return;
            }

            this.setAttribute('page', this.getAttribute('page') + 1);
            this.refresh();
        },

        lastPage: function () {
            this.setAttribute('page', this.getAttribute('maxpage'));
            this.refresh();
        },

        perPageChange: function () {
            this.setAttribute('page', 1);
            this.setAttribute('perPage', this.container.getElement('.rp').value);
            this.$saveToStorage();
            this.refresh();
        },

        pageChange: function (event) {
            if (typeOf(event) !== 'domevent') {
                return;
            }

            if (event.key !== 'enter') {
                return;
            }

            const Input = this.container.getElement('div.pDiv2 input');
            const np = Input.value;

            if (np > 0 && np <= this.getAttribute('maxpage')) {
                this.setAttribute('page', np);
                this.refresh();
            } else {
                Input.value = this.getAttribute('page');
            }
        },

        // API
        gotoPage: function (p) {
            if (p > 0 && p <= this.getAttribute('maxpage')) {
                this.setAttribute('page', p);
                this.refresh();
            }
        },

        setPerPage: function (p) {
            if (p > 0) {
                this.setAttribute('perPage', p);
                this.refresh();
            }
        },

        // API
        sort: function (index, by) {
            if (index < 0 || index >= this.$columnModel.length) {
                return;
            }

            if (this.getAttribute('onStart')) {
                this.fireEvent('start');
            }

            if (this.getAttribute('accordionLiveRenderer')) {
                this.removeSections();
            }

            const header = this.container.getElements('.th'),
                el = header[index];

            if (typeof by !== 'undefined') {
                el.addClass(by.toLowerCase());
            }

            if (el.hasClass('ASC')) {
                el.sortBy = 'ASC';
            } else {
                if (el.hasClass('DESC')) {
                    el.sortBy = 'DESC';
                }
            }

            this.$saveToStorage();

            if (this.getAttribute('serverSort')) {
                this.refresh();
                return;
            }

            this.elements.sort(el.compare);
            this.elements.inject(this.ulBody);

            this.selected = [];

            for (let i = 0, len = this.elements.length; i < len; i++) {
                if (this.elements[i].hasClass('selected')) {
                    this.selected.push(this.elements[i].retrieve('row'));
                }
            }

            // Filter
            if (this.filtered) {
                this.filteredAltRow();
                return;
            }

            this.altRow();
        },

        moveup: function () {
            if (typeof this.selected[0] === 'undefined') {
                return;
            }

            let i, len;

            const _data = [],
                index = this.selected[0],
                data = this.$data;

            if (index === 0) {
                return;
            }

            for (i = 0, len = data.length; i < len; i++) {
                if (i == index) {
                    continue;
                }

                if (i === index - 1) {
                    _data.push(data[index]);
                }

                _data.push(data[i]);
            }

            this.setData({
                data: _data
            });

            this.setSelectedIndices([index - 1]);
        },

        movedown: function () {
            if (typeof this.selected[0] === 'undefined') {
                return;
            }

            const _data = [],
                index = this.selected[0],
                data = this.$data,
                len = data.length;

            if (index + 1 >= len) {
                return;
            }

            for (let i = 0; i < len; i++) {
                if (i === index) {
                    continue;
                }

                _data.push(data[i]);

                if (i === index + 1) {
                    _data.push(data[index]);
                }
            }

            this.setData({
                data: _data
            });

            this.setSelectedIndices([index + 1]);
        },

        altRow: function () {
            if (!this.getAttribute('alternaterows')) {
                return;
            }

            let i, len, hiddenCounter = 0;
            const elements = this.elements;

            for (i = 0, len = elements.length; i < len; i++) {
                if (elements[i].classList.contains('hide')) {
                    hiddenCounter++;
                    continue;
                }

                if ((i - hiddenCounter) % 2) {
                    elements[i].addClass('erow');
                    continue;
                }

                elements[i].removeClass('erow');
            }
        },

        filteredAltRow: function () {
            if (!this.getAttribute('alternaterows')) {
                return;
            }

            let i, len;
            const elements = this.ulBody.getElements('.' + this.getAttribute('filterSelectedCls'));

            for (i = 0, len = elements.length; i < len; i++) {
                if (i % 2) {
                    elements[i].addClass('erow');
                    continue;
                }

                elements[i].removeClass('erow');
            }
        },

        filerData: function () {
            if (this.getAttribute('filterInput')) {
                const cfilter = this.container.getElement('input.cfilter');

                if (cfilter) {
                    this.filter(cfilter.value);
                }
            }
        },

        // API
        filter: function (key) {
            const filterHide = this.getAttribute('filterHide'),
                filterHideCls = this.getAttribute('filterHideCls');

            if (!key.length || key === '') {
                this.clearFilter();
                return;
            }

            let i, c, len, clen, data, dat, cml, el, columnModel;

            clen = this.$columnModel.length;
            len = this.$data.length;
            data = this.$data;
            key = key.toString().toLowerCase();

            columnModel = this.$columnModel;

            for (i = 0; i < len; i++) {
                el = this.elements[i];

                if (filterHide) {
                    el.removeClass('erow');
                }

                el.addClass(filterHideCls);

                dat = data[i];

                for (c = 0; c < clen; c++) {
                    cml = columnModel[c];

                    if (cml.type === 'checkbox') {
                        continue;
                    }

                    if (typeof dat[cml.dataIndex] === 'undefined' ||
                        typeOf(dat[cml.dataIndex]) === 'function' ||
                        dat[cml.dataIndex] === null) {
                        continue;
                    }

                    let haystack;

                    if (typeof dat[cml.dataIndex] === 'object' && 'innerHTML' in dat[cml.dataIndex]) {
                        haystack = dat[cml.dataIndex].innerHTML.toLowerCase();
                    } else {
                        haystack = dat[cml.dataIndex].toString().toLowerCase();
                    }

                    if (haystack.indexOf(key) > -1) {
                        el.removeClass(filterHideCls);
                        this.unSelectRow(el);
                        break;
                    }
                }
            }

            this.altRow();
            this.filtered = true;
        },

        // API
        clearFilter: function () {
            let el;

            for (let i = 0, len = this.elements.length; i < len; i++) {
                el = this.elements[i];
                el.removeClass(this.getAttribute('filterSelectedCls'));

                if (this.getAttribute('filterHide')) {
                    el.removeClass(this.getAttribute('filterHideCls'));
                }
            }

            if (this.getAttribute('filterHide')) {
                this.altRow();
                this.filtered = false;
            }
        },

        /**
         * Export window
         */
        getExportSelect: function () {
            const self = this;

            const btnInnerHTMLDownload = QUILocale.get('quiqqer/core', 'grid.export.button.download') +
                ' <span class="fa fa-solid fa-download"></span>';
            const btnInnerHTMLPrint = QUILocale.get('quiqqer/core', 'grid.export.button.print') +
                ' <span class="fa fa-solid fa-print"></span>';

            require([
                'Mustache',
                'text!controls/grid/Grid.ExportWindow.html'
            ], (Mustache, template) => {
                let WinContent = null,
                    currentNav = 1,
                    currentContent = 1;

                const toggleContent = function (next) {
                    hideContent(currentContent);
                    showContent(next);

                    currentContent = next;
                };
                const hideContent = function (index) {
                    const Content = WinContent.querySelector('.contentSlider__items [data-qui-index="' + index + '"]');
                    Content.style.display = 'none';
                    Content.classList.remove('active');
                };

                const showContent = function (index) {
                    const Content = WinContent.querySelector('.contentSlider__items [data-qui-index="' + index + '"]');
                    Content.style.display = null;
                    Content.classList.add('active');
                };

                const toggleNav = function (index) {
                    WinContent.getElements('.nav button').forEach((Btn) => {
                        Btn.classList.remove('active');
                    });

                    WinContent.getElement('.nav button[data-qui-index="' + index + '"]').classList.add('active');
                    currentNav = index;
                };

                const onOpen = function (Win) {
                    Win.$exportTypes = [];
                    Win.$exportTypes2 = [];

                    let c, len, columnModel, header, dataIndex;

                    const options = self.getAttributes();

                    WinContent = Win.getContent();

                    WinContent.set('html', '');

                    WinContent.style.padding = 0;
                    WinContent.set('html', Mustache.render(template, {
                        'navFields': QUILocale.get('quiqqer/core', 'grid.export.nav.fields'),
                        'navExport': QUILocale.get('quiqqer/core', 'grid.export.nav.export'),
                        'contentFieldsTitle': QUILocale.get('quiqqer/core', 'grid.export.message.title'),
                        'contentFieldsDesc': QUILocale.get('quiqqer/core', 'grid.export.message'),
                        'contentExportTitle': QUILocale.get('quiqqer/core', 'grid.export.message.exportType.title'),
                        'contentExportDesc': QUILocale.get('quiqqer/core', 'grid.export.message.exportType'),
                        'btnNext': QUILocale.get('quiqqer/core', 'grid.export.button.next'),
                        'btn': btnInnerHTMLDownload
                    }));

                    /* nav buttons */
                    WinContent.getElements('.nav button').forEach((Btn) => {
                        Btn.addEventListener('click', (event) => {
                            let Btn = event.target;

                            if (Btn.nodeName !== 'BUTTON') {
                                Btn = Btn.getParent('button');
                            }

                            toggleContent(Btn.get('data-qui-index'));
                            toggleNav(Btn.get('data-qui-index'));
                        });
                    });

                    /* next btn */
                    WinContent.getElement('[data-qui-type="next"]').addEvent('click', () => {
                        toggleContent(2);
                        toggleNav(2);
                    });

                    /* download button */
                    const DownloadBtn = WinContent.getElement('[data-qui-type="download"]');
                    DownloadBtn.addEventListener('click', () => {
                        Win.submit();
                    });

                    /* content: fields */
                    const FieldsList = WinContent.getElement('.export-list');

                    for (c = 0, len = self.$columnModel.length; c < len; c++) {
                        columnModel = self.$columnModel[c];
                        header = columnModel.header;
                        dataIndex = columnModel.dataIndex;

                        if (self.exportable(columnModel) === false) {
                            continue;
                        }

                        const label = new Element('label.export-item', {
                                title: header
                            }),
                            span = new Element('span.export-item__text', {
                                html: header
                            }),
                            input = new Element('input', {
                                'class': 'export_' + dataIndex,
                                type: 'checkbox',
                                checked: 'checked',
                                value: dataIndex,
                                name: dataIndex
                            });

                        if (header === '' || header === '&nbsp;') {
                            label.title = QUILocale.get('quiqqer/core', 'grid.export.item.noLabel');
                            span.innerHTML = QUILocale.get('quiqqer/core', 'grid.export.item.noLabel');
                            span.classList.add('export-item__text--noLabel');
                        }

                        label.appendChild(input);
                        label.appendChild(span);

                        FieldsList.appendChild(label);
                    }

                    /* content: export types */
                    const ExportTypeList = WinContent.querySelector('.export-fileFormat__items');

                    let fileImage, Label, Input, types = options.exportTypes;

                    if (typeOf(types) === 'object') {
                        const typeArray = [];

                        for (let exportType in types) {
                            if (typeOf(types[exportType]) === 'boolean' && types[exportType]) {
                                typeArray.push(exportType);
                            }
                        }

                        types = typeArray;
                    }

                    let exportType;

                    for (let i = 0, len = types.length; i < len; i++) {
                        exportType = types[i];

                        switch (exportType) {
                            case 'csv':
                                fileImage = 'fa fa-file-lines';
                                break;

                            case 'json':
                                fileImage = 'fa fa-code';
                                break;

                            case 'xls':
                                fileImage = 'fa fa-file-excel';
                                break;

                            case 'pdf':
                                fileImage = 'fa fa-file-pdf';
                                break;

                            case 'print':
                                fileImage = 'fa fa-print';
                                break;

                            default:
                                fileImage = 'fa fa-file';
                        }

                        Label = new Element('label.export-fileFormat__item', {
                            html: '<span class="' + fileImage + '"></span> ' +
                                '<div class="label"><span class="text">' +
                                QUILocale.get('quiqqer/core', 'grid.export.type.' + exportType) + '</span></div>'
                        });

                        Input = new Element('input', {
                            type: 'radio',
                            name: 'exportType',
                            value: exportType,
                            events: {
                                change: (event) => {
                                    DownloadBtn.disabled = null;

                                    if (!event || !event.target.nodeName === 'INPUT') {
                                        return;
                                    }

                                    if (event.target.value === 'print') {
                                        DownloadBtn.innerHTML = btnInnerHTMLPrint;
                                    } else {
                                        DownloadBtn.innerHTML = btnInnerHTMLDownload;
                                    }
                                }
                            }
                        });

                        Label.querySelector('.label').prepend(Input);
                        ExportTypeList.appendChild(Label);
                        Win.$exportTypes.push(Input);
                    }
                };

                new QUIConfirm({
                    'class': 'qui-window-popup--exportType',
                    icon: 'fa fa-download',
                    title: '',
                    maxHeight: 600,
                    maxWidth: 800,
                    autoclose: false,
                    buttons: false,
                    events: {
                        onOpen: onOpen,
                        onSubmit: function (Win) {
                            const active = Win.$exportTypes.filter(function (Input) {
                                return Input.checked;
                            });

                            if (!active.length) {
                                return;
                            }

                            self.exportGrid(
                                active[0].getAttribute('value')
                            );

                            Win.close();
                        }
                    }
                }).open();

                return false;
            });

        },

        /**
         * Simple resize table design, change button icon and button title
         * @param event
         */
        resizeTablePerButtonClick: function (event) {
            let Btn = event.target;

            if (!Btn.classList.contains('pSizing')) {
                Btn = Btn.getParent('pSizing');
            }

            if (Btn.getProperty('data-qui-tableSizing') === 'small') {
                Btn.setProperty('data-qui-tableSizing', 'normal');
                Btn.title = QUILocale.get('quiqqer/core', 'grid.sizing.button.title');
                this.container.style.setProperty('--_grid-sizingMultiplier', 1);
//                this.setAttribute('tablesizing', 'normal');
                this.tableSizing = 'normal';
                this.$saveToStorage();

                return;
            }

            Btn.setProperty('data-qui-tableSizing', 'small');
            Btn.title = QUILocale.get('quiqqer/core', 'grid.sizing.button.title.small');
            this.container.style.setProperty('--_grid-sizingMultiplier', 0.5);
//            this.setAttribute('tablesizing', 'small');
            this.tableSizing = 'small';

            this.$saveToStorage();
        },

        setExportData: function () {
            let c, i, len, columnModel, header, dataIndex, Checkbox;

            const data = {
                header: {},
                data: []
            };

            for (c = 0, len = this.$columnModel.length; c < len; c++) {
                columnModel = this.$columnModel[c];
                header = columnModel.header;
                dataIndex = columnModel.dataIndex;

                if (this.exportable(columnModel) === false) {
                    continue;
                }

                Checkbox = document.body.getElement('.qui-window-popup--exportType .export-list .export_' + dataIndex);

                if (!Checkbox || !Checkbox.checked) {
                    continue;
                }

                data.header[dataIndex] = {
                    header: header,
                    dataIndex: dataIndex
                };
            }

            const gridData = this.getData();

            if (gridData) {
                for (i = 0, len = gridData.length; i < len; i++) {
                    const dat = gridData[i];
                    data.data[i] = {};

                    for (const h in data.header) {
                        data.data[i][data.header[h].dataIndex] = dat[data.header[h].dataIndex];
                    }
                }
            }

            this.setAttribute('exportData', data);

            return data;
        },

        /**
         *
         * @param columnModel
         * @return {boolean}
         */
        exportable: function (columnModel) {
            if (typeof columnModel.export !== 'undefined' && columnModel.export === false) {
                return false;
            }

            if (columnModel.hidden && typeof columnModel.export !== 'undefined' && columnModel.export) {
                return true;
            }

            return !(columnModel.hidden ||
                columnModel.dataType === 'button' ||
                columnModel.dataType === 'checkbox');
        },

        exportGrid: function (type, data) {
            let self = this,
                exportUrl = this.getAttribute('exportBinUrl'),
                exportName = this.getAttribute('exportName');

            if (typeof data === 'undefined') {
                data = this.setExportData();
            }

            if (!exportName) {
                let Now = new Date();
                let date = QUILocale.getDateTimeFormatter().format(Now);
                exportName = 'export-' + window.location.host + '-' + date;
            }

            if (this.getAttribute('exportRenderer')) {
                this.getAttribute('exportRenderer')({
                    Grid: this,
                    data: data,
                    type: type
                });

                return;
            }

            // parse html nodes to string data
            for (let i = 0, len = data.data.length; i < len; i++) {
                for (let prop in data.data[i]) {
                    if (data.data[i].hasOwnProperty(prop)) {
                        data.data[i][prop] = this.convertToHTMLString(data.data[i][prop]);
                    }
                }
            }

            const tempData = {
                data: data,
                type: type,
                name: exportName,
                cssFile: this.getAttribute('exportCssFile')
            };


            this.showLoader();

            fetch(exportUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(tempData)
            }).then(function (response) {
                if (type === 'print') {
                    return response.text().then((data) => {
                        require(['qui/controls/elements/Sandbox'], (Sandbox) => {
                            new Sandbox({
                                content: data,
                                styles: {
                                    height: 100,
                                    left: 0,
                                    position: 'absolute',
                                    top: -110
                                },
                                events: {
                                    onLoad: function (Box) {
                                        (() => {
                                            Box.getElm().contentWindow.print();
                                        }).delay(500);
                                    }
                                }
                            }).inject(document.body);
                        });

                        self.hideLoader();
                    });
                }

                const Headers = response.headers;

                let filename = Headers.get('Content-Disposition');
                const start = filename.indexOf('filename="') + ('filename="').length;
                const end = filename.indexOf('"', start);

                filename = filename.substr(start, end - start);

                return response.blob().then(function (blob) {
                    require([
                        URL_OPT_DIR + 'bin/quiqqer-asset/downloadjs/downloadjs/download.js'
                    ], function (download) {
                        self.hideLoader();

                        download(blob, filename, Headers.get('Content-Type'));
                    });
                });
            }).catch(function (e) {
                self.hideLoader();

                console.error(e);
            });
        },

        convertToHTMLString: function (obj) {
            if (typeof obj !== 'object' || obj === null) {
                return obj;
            }

            if (obj.nodeType) {
                return obj.outerHTML;
            }

            for (let key in obj) {
                if (obj.hasOwnProperty(key)) {
                    obj[key] = this.convertToHTMLString(obj[key]);
                }
            }

            return obj;
        },

        /**
         * Starts the Drag & Drop
         *
         * @method controls/grid/Grid#startDrag
         *
         * @param {DOMEvent} event
         */
        startDrag: function (event) {
            if (!this.getAttribute('dragdrop')) {
                return;
            }

            if (this._mousedown) {
                return;
            }

            if (this._stopdrag) {
                return;
            }

            this._mousedown = true;

            const mx = event.page.x,
                my = event.page.y,
                li = this.getLiParent(event.target);

            if (!li || typeof li.retrieve('row') === 'undefined') {
                return;
            }

            let row = li.retrieve('row'),
                data = this.getDataByRow(row),
                html = '';

            if (this.getAttribute('dragDropDataIndex') &&
                data[this.getAttribute('dragDropDataIndex')]) {
                html = '<span>' + data[this.getAttribute('dragDropDataIndex')] + '</span>';
            }

            this.selectRow(li);

            this.Drag = new Element('div.class', {
                'class': this.getAttribute('dragDropClass') || 'omni-drag-drop',
                'data-row': row.toString(),
                html: html,
                styles: {
                    position: 'absolute',
                    top: (my - 15),
                    left: (mx - 40),
                    zIndex: 1000,
                    'MozOutline': 'none',
                    outline: 0
                },
                tabindex: '-1'
            }).inject(document.body);

            this.Drag.addEvent('mouseup', function () {
                this.stopDrag();
            }.bind(this));

            // Draging
            new Drag.Move(this.Drag, {

                droppables: this.getAttribute('droppables'),

                onBeforeStart: function () {
                    this.Drag.focus();
                }.bind(this),

                onStart: function (element, droppable) {
                    this.fireEvent('dragDropStart', [
                        element,
                        droppable
                    ]);
                }.bind(this),

                onComplete: function () {
                    this.fireEvent('dragDropComplete');
                }.bind(this),

                onEnter: function (element, droppable) {
                    this.fireEvent('dragDropEnter', [
                        element,
                        droppable
                    ]);
                }.bind(this),

                onLeave: function (element, droppable) {
                    this.fireEvent('dragDropLeave', [
                        element,
                        droppable
                    ]);
                }.bind(this),

                onDrop: function (element, droppable, event) {
                    if (!droppable) {
                        return;
                    }

                    this.fireEvent('drop', [
                        this.getDataByRow(element.getAttribute('data-row')),
                        element,
                        droppable,
                        event
                    ]);

                }.bind(this)

            }).start({
                target: this.getElm(),
                page: {
                    x: mx,
                    y: my
                }
            });

            return false;
        },

        stopDrag: function () {
            if (!this.getAttribute('dragdrop')) {
                return;
            }

            if (!this._mousedown) {
                this._stopdrag = true;
                //this.fireEvent('onclick', [this]);
                return;
            }

            this._mousedown = false;

            if (this.Drag) {
                this.Drag.destroy();
                this.Drag = null;
            }
        },

        /**
         * Disable this grid
         */
        disable: function () {
            if (this.$disabled) {
                return;
            }

            if (this.getElm().getElement('.grid-disabled')) {
                return;
            }

            this.$disabled = true;

            new Element('div', {
                'class': 'grid-disabled'
            }).inject(this.getElm());

            this.getButtons().forEach(function (Button) {
                Button.disable();
            });
        },

        /**
         * Enable this grid
         */
        enable: function () {
            if (this.$disabled === false) {
                return;
            }

            this.$disabled = false;

            if (this.getElm().getElement('.grid-disabled')) {
                this.getElm().getElement('.grid-disabled').destroy();
            }

            this.getButtons().forEach(function (Button) {
                Button.enable();
            });
        },

        /**
         * save this grid to the storage
         */
        $saveToStorage: function () {
            if (!this.getAttribute('storageKey')) {
                return;
            }

            QUI.Storage.set(this.getAttribute('storageKey') + '-key', this.$gridHash);

            QUI.Storage.set(this.getAttribute('storageKey'), JSON.encode({
                column: this.$columnModel,
                perPage: this.getAttribute('perPage'),
                sortOn: this.getAttribute('sortOn'),
                sortBy: this.getAttribute('sortBy'),
                tableSizing: this.tableSizing
            }));
        },

        /**
         * load the storage in to the grid
         */
        $loadFromStorage: function () {
            if (!this.getAttribute('storageKey')) {
                return;
            }

            QUI.Storage.get(this.getAttribute('storageKey'));

            let storage = QUI.Storage.get(this.getAttribute('storageKey'));

            if (!storage) {
                return;
            }

            try {
                storage = JSON.decode(storage);
            } catch (e) {
                return;
            }

            const storageHash = parseInt(QUI.Storage.get(this.getAttribute('storageKey') + '-key'));
            const currentHash = this.$gridHash;

            if (typeof storage.column !== 'undefined' && storageHash === currentHash) {
                try {
                    if (typeOf(storage.column) === 'array') {
                        this.$columnModel = storage.column.map(function (storedColumn) {
                            let originalColumn = null;

                            for (let i = 0, len = this.$originalColumns.length; i < len; i++) {
                                if (this.$originalColumns[i].dataIndex === storedColumn.dataIndex) {
                                    originalColumn = this.$originalColumns[i];
                                    break;
                                }
                            }

                            if (!originalColumn) {
                                return storedColumn;
                            }

                            Object.keys(originalColumn).forEach(function (key) {
                                if (typeOf(originalColumn[key]) === 'function') {
                                    storedColumn[key] = originalColumn[key];
                                }
                            });

                            return storedColumn;
                        }, this);
                    }
                } catch (e) {
                }

                if (typeof storage.perPage !== 'undefined') {
                    this.setAttribute('perPage', storage.perPage);
                }

                if (typeof storage.sortOn !== 'undefined') {
                    this.setAttribute('sortOn', storage.sortOn);
                }

                if (typeof storage.sortBy !== 'undefined') {
                    this.setAttribute('sortBy', storage.sortBy);
                }

                if (typeof storage.tableSizing !== 'undefined') {
                    this.setAttribute('tablesizing', storage.tableSizing);
                }
            } else {
                this.$saveToStorage();
            }
        },

        /**
         * opens the sorting window
         * - the user are able to sort the grid titles (columns)
         */
        openSortWindow: function () {
            require([
                'Mustache',
                'text!controls/grid/Grid.SettingsWindow.html'
            ], (Mustache, template) => {
                new QUIConfirm({
                    'class': 'grid-settingsWindow',
                    icon: 'fa fa-sort',
                    title: QUILocale.get(lg, 'window.grid.sorting.title'),
                    maxHeight: 800,
                    maxWidth: 700,
                    ok_button: {
                        text: QUILocale.get(lg, 'window.grid.sorting.submit'),
                        textimage: 'fa fa-check'
                    },
                    events: {
                        onOpen: (Win) => {
                            Win.Loader.show();
                            const Content = Win.getContent();
                            Content.addClass('grid-dd');

                            Content.set('html', Mustache.render(template, {
                                title: QUILocale.get(lg, 'window.grid.sorting.title'),
                                description: QUILocale.get(lg, 'window.grid.sorting.description'),
                                btnText: QUILocale.get(lg, 'window.grid.sorting.btn.text'),
                                errorMsg: QUILocale.get(lg, 'window.grid.sorting.errorMsg'),
                                hint: QUILocale.get(lg, 'window.grid.sorting.hint')
                            }));

                            const ResetBtn = Content.querySelector('[name="resetGridBtn"]'),
                                ErrorContainer = Content.querySelector('.error'),
                                Checkbox = Content.querySelector('[name="resetGridCheckbox"]');

                            ResetBtn.addEventListener('click', () => {
                                if (!Checkbox.checked) {
                                    ErrorContainer.style.display = null;
                                    return;
                                }

                                this.resetGrid();
                                Win.close();
                            });

                            const List = new Element('ul').inject(Content);

                            require(['package/quiqqer/bricks/bin/Sortables'], (Sortables) => {
                                const columns = this.$columnModel.map(function (col) {
                                    return col.dataIndex;
                                });

                                this.$originalColumns.forEach((data) => {
                                    let header = data.header;

                                    if (typeof header === 'undefined' || header === '' || header === '&nbsp;') {
                                        header = data.dataIndex;
                                    }

                                    let Entry = new Element('li', {
                                        html: header,
                                        'data-index': data.dataIndex
                                    }).inject(List);

                                    new Element('input', {
                                        type: 'checkbox',
                                        checked: columns.indexOf(data.dataIndex) !== -1
                                    }).inject(Entry);
                                });

                                new Sortables(List, {
                                    revert: {
                                        duration: 500,
                                        transition: 'elastic:out'
                                    },
                                    clone: function (event) {
                                        let Target = event.target;

                                        if (Target.nodeName !== 'LI') {
                                            Target = Target.getParent('li');
                                        }

                                        let size = Target.getSize(),
                                            pos = Target.getPosition(Target.getParent('ul'));

                                        return new Element('div', {
                                            styles: {
                                                background: 'rgba(0,0,0,0.5)',
                                                height: size.y,
                                                top: pos.y,
                                                width: size.x,
                                                zIndex: 1000,
                                                position: 'absolute'
                                            }
                                        });
                                    },

                                    onStart: function (element) {
                                        let Ul = element.getParent('ul');

                                        element.addClass('grid-dd-active');

                                        Ul.setStyles({
                                            height: Ul.getSize().y,
                                            overflow: 'hidden',
                                            width: Ul.getSize().x
                                        });
                                    },

                                    onComplete: function (element) {
                                        let Ul = element.getParent('ul');

                                        element.removeClass('grid-dd-active');

                                        Ul.setStyles({
                                            height: null,
                                            overflow: null,
                                            width: null
                                        });
                                    }
                                });

                                Win.Loader.hide();
                            });
                        },

                        onSubmit: (Win) => {
                            const Content = Win.getContent();
                            const List = Content.getElement('ul');
                            const list = List.getElements('li').map(function (Li) {
                                if (!Li.getElement('input').checked) {
                                    return false;
                                }

                                return Li.get('data-index');
                            }).filter(n => n);

                            const getColumn = (column) => {
                                for (let i = 0, len = this.$originalColumns.length; i < len; i++) {
                                    if (this.$originalColumns[i].dataIndex === column) {
                                        return this.$originalColumns[i];
                                    }
                                }

                                return false;
                            };

                            const columns = [];

                            for (let i = 0, len = list.length; i < len; i++) {
                                columns.push(getColumn(list[i]));
                            }

                            this.$columnModel = columns;
                            this.$saveToStorage();

                            this.container.set('html', '');

                            this.draw();
                            this.resize();
                            this.refresh();
                        }
                    }
                }).open();
            });
        },

        /**
         * Reset view of grid table.
         * Get all data, that user can adjust, to its default values
         */
        resetGrid: function () {
//            this.setAttribute('tablesizing', 'normal');
            this.tableSizing = this.getAttribute('tablesizing');
            this.container.style.setProperty('--_grid-sizingMultiplier', null);
            this.setAttribute('perPage', 100);
            this.setAttribute('page', 1);

            if (!this.getAttribute('storageKey')) {
                return;
            }

            QUI.Storage.remove(this.getAttribute('storageKey'));

            this.$columnModel = this.$initialColumnsModel;
            this.draw();
            this.resize();
            this.refresh();
        },

        /**
         * Highlight for a short moment a row, i.e. after editing a row.
         * This method sets a `flash-effect` css class, with performs the animation.
         * You can control the animation by using css variables.
         *
         * @param rowId | number
         */
        highlightRow: function (rowId) {
            if (typeof rowId === 'undefined' || rowId < 0) {
                return;
            }

            const EditableRow = this.getElm().getElement('[data-row="' + rowId + '"]');

            if (!EditableRow) {
                return;
            }

            EditableRow.classList.remove('flash-animation');

            setTimeout(() => {
                EditableRow.classList.add('flash-animation');
            }, 50);

        }
    });
});
