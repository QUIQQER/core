/**
 * Groups manager panel
 */
define('controls/groups/Panel', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'Groups',
    'controls/grid/Grid',
    'utils/Controls',
    'controls/groups/sitemap/Window',
    'utils/Template',
    'qui/controls/messages/Attention',
    'qui/controls/windows/Prompt',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'qui/controls/buttons/Switch',
    'Locale',
    'Mustache',
    'utils/PanelTooltips',

    'text!controls/groups/Panel.groupSearch.html',
    'css!controls/groups/Panel.css'

], function () {
    'use strict';

    const lg = 'quiqqer/core';

    const QUI = arguments[0],
        Panel = arguments[1],
        Groups = arguments[2],
        Grid = arguments[3],
        ControlUtils = arguments[4],
        GroupSitemapWindow = arguments[5],
        Template = arguments[6],
        Attention = arguments[7],
        QUIPrompt = arguments[8],
        QUIConfirm = arguments[9],
        QUIButton = arguments[10],
        QUISwitch = arguments[11],
        QUILocale = arguments[12],
        Mustache = arguments[13],
        PanelTooltips = arguments[14],
        groupSearchTemplate = arguments[15]
    ;

    /**
     * @class qui/controls/groups/Panel
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: Panel,
        Type: 'controls/groups/Panel',

        Binds: [
            '$onCreate',
            '$onResize',
            '$onSwitchStatus',
            '$onDeleteGroup',
            '$onRefreshGroup',
            '$onButtonEditClick',
            '$onButtonDelClick',

            '$gridClick',
            '$gridDblClick',
            '$gridBlur',

            'search',
            'createGroup',
            'openPermissions'
        ],

        options: {
            active_text: '', // (optional)
            deactive_text: '', // (optional)

            field: 'name',
            order: 'ASC',
            limit: 20,
            page: 1,
            view: 'table',

            search: '',
            searchfields: ['id', 'name']
        },

        initialize: function (options) {
            this.$uid = String.uniqueID();

            this.parent(options);
            if (typeof this.getAttribute('search') !== 'string') {
                this.setAttribute('search', '');
            }

            this.$Grid = null;
            this.$Container = null;
            this.$filterContainer = null;
            this.$SearchNotice = null;

            this.addEvent('onCreate', this.$onCreate);
            this.addEvent('onResize', this.$onResize);

            Groups.addEvents({
                onSwitchStatus: this.$onSwitchStatus,
                onActivate: this.$onSwitchStatus,
                onDeactivate: this.$onSwitchStatus,
                onDelete: this.$onDeleteGroup,
                onRefresh: this.$onRefreshGroup
            });

            this.setAttributes({
                active_text: QUILocale.get(lg, 'groups.panel.group.is.active'),
                deactive_text: QUILocale.get(lg, 'groups.panel.group.is.deactive')
            });

            this.addEvent('onDestroy', () => {
                Groups.removeEvent('switchStatus', this.$onSwitchStatus);
            });
        },

        /**
         * Return the group grid
         *
         * @return {controls/grid/Grid|null}
         */
        getGrid: function () {
            return this.$Grid;
        },

        /**
         * create the group panel
         */
        $onCreate: function () {
            this.$createSearchToolbar();

            // create grid
            const Body = this.getBody();
            Body.classList.add('quiqqer-groups-panel-body');
            const gridSize = this.$getGridSize();

            this.$GridContainer = new Element('div');
            this.$GridContainer.inject(Body);

            this.$Grid = new Grid(this.$GridContainer, {
                buttons: [
                    {
                        name: 'groupNew',
                        events: {
                            onMousedown: this.createGroup
                        },
                        text: QUILocale.get(lg, 'groups.panel.btn.create'),
                        textimage: 'fa fa-plus'
                    },
                    {
                        name: 'groupEdit',
                        events: {
                            onMousedown: this.$onButtonEditClick
                        },
                        text: QUILocale.get(lg, 'groups.panel.btn.edit'),
                        disabled: true,
                        textimage: 'fa fa-edit'
                    },
                    {
                        name: 'groupDel',
                        events: {
                            onMousedown: this.$onButtonDelClick
                        },
                        text: QUILocale.get(lg, 'groups.panel.btn.delete'),
                        disabled: true,
                        textimage: 'fa fa-trash-o',
                        position: 'right'
                    }
                ],
                columnModel: [
                    {
                        header: QUILocale.get(lg, 'status'),
                        dataIndex: 'status',
                        dataType: 'QUI',
                        width: 60
                    }, {
                        header: QUILocale.get(lg, 'groupname'),
                        dataIndex: 'name',
                        dataType: 'number',
                        width: 150
                    }, {
                        header: QUILocale.get(lg, 'groups.panel.grid.admin'),
                        dataIndex: 'admin',
                        dataType: 'string',
                        width: 150
                    }, {
                        header: QUILocale.get(lg, 'groups.panel.grid.users'),
                        dataIndex: 'users',
                        dataType: 'number',
                        width: 150
                    }, {
                        header: QUILocale.get(lg, 'group_id'),
                        dataIndex: 'uuid',
                        dataType: 'string',
                        width: 240
                    }
                ],
                pagination: true,
                filterInput: true,
                perPage: this.getAttribute('limit'),
                page: this.getAttribute('page'),
                sortOn: this.getAttribute('field'),
                serverSort: true,
                showHeader: true,
                sortHeader: true,
                width: gridSize.width,
                height: gridSize.height,
                alternaterows: true,
                resizeColumns: true,
                selectable: true,
                multipleSelection: true,
                resizeHeaderOnly: true
            });

            // Events
            this.$Grid.addEvents({
                onClick: this.$gridClick,
                onDblClick: this.$gridDblClick,
                onBlur: this.$gridBlur,
                onRefresh: (gridInstance) => {
                    const options = gridInstance.options;

                    this.setAttribute('field', options.sortOn);
                    this.setAttribute('order', options.sortBy);
                    this.setAttribute('limit', options.perPage);
                    this.setAttribute('page', options.page);
                    this.load();
                }
            });

            this.$Grid.getButtons().forEach((Button) => {
                PanelTooltips(this, Button.getElm().parentElement);
            });

            // start and list the groups
            this.load();
        },

        /**
         * Create the search toolbar and its filter popup.
         */
        $createSearchToolbar: function () {
            const Toolbar = this.getButtonBar();
            Toolbar.setAttribute('width', '100%');

            const searchField = document.createElement('div');
            searchField.className = 'quiqqer-groups-search-field';
            searchField.setAttribute('role', 'search');
            const searchInput = document.createElement('input');
            searchInput.type = 'search';
            searchInput.name = 'group-search';
            searchInput.setAttribute('data-name', 'group-search');
            searchInput.setAttribute('aria-label', QUILocale.get(lg, 'search'));
            searchField.appendChild(searchInput);

            const SearchButton = new QUIButton({
                'class': 'quiqqer-groups-search-button',
                events: {
                    onClick: () => this.search()
                }
            });

            this.addButton(SearchButton);
            const searchButton = SearchButton.getElm();
            const searchContainer = searchButton.parentElement;
            searchContainer.classList.add('quiqqer-groups-search-toolbar');
            searchButton.setAttribute('data-name', 'group-search-submit');
            searchButton.setAttribute('aria-label', QUILocale.get(lg, 'search'));
            searchButton.setAttribute('tabindex', '0');
            searchButton.style.outline = '';
            const searchIcon = document.createElement('span');
            searchIcon.className = 'fa fa-search';
            searchIcon.setAttribute('aria-hidden', 'true');
            searchButton.appendChild(searchIcon);
            searchField.appendChild(searchButton);
            searchContainer.appendChild(searchField);

            const FilterButton = new QUIButton({
                text: QUILocale.get(lg, 'user.panel.search.filter.title').replace(/:\s*$/, ''),
                'class': 'quiqqer-groups-filter-button'
            });

            this.addButton(FilterButton);
            const filterButton = FilterButton.getElm();
            filterButton.setAttribute('data-name', 'group-filter');
            filterButton.setAttribute('aria-expanded', 'false');
            filterButton.setAttribute('tabindex', '0');
            filterButton.style.outline = '';

            const filterIcon = document.createElement('span');
            filterIcon.className = 'fa fa-filter';
            filterIcon.setAttribute('aria-hidden', 'true');
            filterButton.prepend(filterIcon);

            const chevron = document.createElement('span');
            chevron.className = 'fa fa-angle-down';
            chevron.setAttribute('aria-hidden', 'true');
            filterButton.appendChild(chevron);

            // filter
            const filter = document.createElement('div');
            filter.id = 'group-search-filter-' + this.$uid;
            filter.setAttribute('data-name', 'group-search-filter');
            filterButton.setAttribute('aria-controls', filter.id);
            filter.style.zIndex = '1000';
            filter.style.display = 'none';
            filter.style.overflow = 'auto';
            filter.style.maxHeight = '550px';

            this.$filterContainer = filter;
            document.body.appendChild(this.$filterContainer);
            this.$renderFilter();

            const closeFilter = () => {
                filter.style.display = 'none';
                filterButton.setAttribute('aria-expanded', 'false');
                document.body.removeEventListener('mouseup', handleFilterClickOutside);
                document.removeEventListener('keydown', handleFilterKeyDown);
                this.search();
            };

            const handleFilterClickOutside = (e) => {
                if (!filter.contains(e.target) && !filterButton.contains(e.target)) {
                    closeFilter();
                }
            };

            const handleFilterKeyDown = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeFilter();
                    filterButton.focus();
                }
            };

            filterButton.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();

                if (filterButton.getAttribute('aria-expanded') === 'true') {
                    closeFilter();
                    return;
                }

                const rect = filterButton.getBoundingClientRect();

                filter.style.display = 'flex';
                filter.style.left = `${Math.max(8, rect.right - filter.offsetWidth) + window.scrollX}px`;
                filter.style.top = `${rect.bottom + 8 + window.scrollY}px`;
                filterButton.setAttribute('aria-expanded', 'true');

                document.body.addEventListener('mouseup', handleFilterClickOutside);
                document.addEventListener('keydown', handleFilterKeyDown);
            });

            this.addEvent('onDestroy', () => {
                document.body.removeEventListener('mouseup', handleFilterClickOutside);
                document.removeEventListener('keydown', handleFilterKeyDown);
                filter.remove();
            });

            searchInput.addEventListener('change', () => {
                this.search();
            });

            searchInput.addEventListener('keyup', (e) => {
                if (e.key === 'Enter') {
                    this.search();
                }
            });
        },

        $renderFilter: function () {
            this.$filterContainer.innerHTML = Mustache.render(groupSearchTemplate, {
                searchTitle: QUILocale.get(lg, 'user.panel.search.in.title'),
                groupId: QUILocale.get(lg, 'group_id'),
                groupName: QUILocale.get(lg, 'groupname')
            });

            QUI.parse(this.$filterContainer);
        },

        /**
         * Load the groups with the settings
         */
        load: function () {
            this.Loader.show();
            this.$loadGroups();
        },

        /**
         * create a group panel
         *
         * @param {Number} gid - Group-ID
         * @return {Object} this (controls/groups/Panel)
         */
        openGroup: function (gid) {
            require(['controls/groups/Group'], function (Group) {
                this.getParent().appendChild(
                    new Group(gid)
                );

            }.bind(this));

            return this;
        },

        /**
         * Opens the groups search settings
         */
        search: function () {
            const searchString = this.getElm().querySelector('[data-name="group-search"]').value.trim();
            const fields = [];

            if (this.$filterContainer) {
                const form = this.$filterContainer.querySelector('form');

                if (!form.elements.gid.checked && !form.elements.name.checked) {
                    form.elements.gid.checked = true;
                    form.elements.name.checked = true;
                }

                if (form.elements.gid.checked) {
                    fields.push('id');
                    fields.push('uuid');
                }

                if (form.elements.name.checked) {
                    fields.push('name');
                }
            }

            this.setAttribute('search', searchString);
            this.setAttribute('searchfields', fields);
            this.load();
        },

        /**
         * Open the group create dialog
         */
        createGroup: function () {
            const self = this;

            new GroupSitemapWindow({
                title: QUILocale.get(lg, 'groups.panel.create.window.title'),
                text: QUILocale.get(lg, 'groups.panel.create.window.sitemap.text'),
                information: QUILocale.get(lg, 'groups.panel.create.window.sitemap.information'),
                maxWidth: 400,
                maxHeight: 600,
                events: {
                    // now we need a groupname
                    onSubmit: function (Win, result) {
                        if (!result.length) {
                            return;
                        }

                        new QUIPrompt({
                            title: QUILocale.get(lg, 'groups.panel.create.window.new.group.title'),
                            text: QUILocale.get(lg, 'groups.panel.create.window.new.group.text'),
                            information: QUILocale.get(lg, 'groups.panel.create.window.new.group.information'),
                            icon: 'fa fa-group',
                            titleicon: false,
                            maxWidth: 400,
                            maxHeight: 400,
                            pid: result[0],
                            events: {
                                onDrawEnd: function (Win) {
                                    Win.getBody().getElement('input').focus();
                                },

                                onSubmit: function (result, Win) {
                                    Win.Loader.show();

                                    Groups.createGroup(result, Win.getAttribute('pid')).then(function (newgroupid) {
                                        self.load();
                                        self.openGroup(newgroupid);
                                        Win.close();
                                    });
                                }
                            }
                        }).open();
                    }
                }
            }).open();
        },

        /**
         * Convert a Group to a grid data field
         *
         * @param {Object} Group - controls/groups/Group
         * @return {Object}
         */
        groupToData: function (Group) {
            // defaults
            const data = {
                status: false,
                id: Group.getId(),
                name: Group.getAttribute('name'),
                admin: QUILocale.get(lg, 'no')
            };

            if (Group.getAttribute('admin')) {
                data.admin = QUILocale.get(lg, 'yes');
            }

            data.status = new QUISwitch({
                status: Group.isActive(),
                value: Group.getId(),
                gid: Group.getId(),
                title: Group.isActive() ? this.getAttribute('active_text') : this.getAttribute('deactive_text'),
                events: {
                    onChange: this.$btnSwitchStatus
                }
            });

            return data;
        },

        /**
         * click on the grid
         *
         * @param {DOMEvent} data
         */
        $gridClick: function (data) {
            const len = data.target.selected.length,
                Edit = this.$Grid.getButton('groupEdit'),
                Delete = this.$Grid.getButton('groupDel');

            Edit.disable();
            Delete.disable();

            if (len === 0) {
                return;
            }

            const selectedData = this.$Grid.getSelectedData();
            const guestEveryone = selectedData.filter(function (entry) {
                return parseInt(entry.id) === 1 || parseInt(entry.id) === 0;
            }).length;

            if (!guestEveryone) {
                Delete.enable();
            }

            if (len === 1) {
                Edit.enable();
            }


            if ('evt' in data) {
                data.evt.stop();
            }
        },

        /**
         * dblclick on the grid
         *
         * @param {Object} data - grid selected data
         */
        $gridDblClick: function (data) {
            this.openGroup(
                data.target.getDataByRow(data.row).uuid
            );
        },

        /**
         * onblur on the grid
         */
        $gridBlur: function () {
            this.getGrid().unselectAll();
            this.getGrid().removeSections();

            this.$Grid.getButton('groupEdit').disable();
            this.$Grid.getButton('groupDel').disable();
        },

        /**
         * Measure the available grid area, including wrapped search notices.
         */
        $getGridSize: function () {
            const Body = this.getBody();
            const style = window.getComputedStyle(Body);
            const width = Math.max(0, Body.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight));
            let height = Body.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);

            if (this.$SearchNotice) {
                const notice = this.$SearchNotice.getElm();
                notice.style.width = `${width}px`;
                const noticeStyle = window.getComputedStyle(notice);
                height -= notice.getBoundingClientRect().height +
                    parseFloat(noticeStyle.marginTop) + parseFloat(noticeStyle.marginBottom);
            }

            return {
                width: width,
                height: Math.max(0, height)
            };
        },

        /**
         * Resize the groups panel
         */
        $onResize: function () {
            const Body = this.getBody();

            if (!Body) {
                return;
            }

            if (!this.getGrid()) {
                return;
            }

            const gridSize = this.$getGridSize();
            this.getGrid().setHeight(gridSize.height);
            this.getGrid().setWidth(gridSize.width);

            // resize switches
            let i, len, Control;
            const switches = Body.getElements('.qui-switch');

            for (i = 0, len = switches.length; i < len; i++) {
                Control = QUI.Controls.getById(switches[i].get('data-quiid'));

                if (Control) {
                    Control.resize();
                }
            }
        },

        /**
         * Load the groups to the grid
         */
        $loadGroups: function () {
            const self = this;

            this.Loader.show();

            this.setAttribute('title', QUILocale.get(lg, 'groups.panel.title'));
            this.setAttribute('icon', 'fa fa-spinner fa-spin');
            this.refresh();

            if (typeof this.getAttribute('search') !== 'string') {
                this.setAttribute('search', '');
            }

            if (this.getAttribute('search') !== '' && !this.$SearchNotice) {
                this.$SearchNotice = new Attention({
                    message: QUILocale.get(lg, 'groups.panel.search.active.message'),
                    events: {
                        onClick: (Message) => {
                            this.setAttribute('search', '');
                            this.setAttribute('searchfields', ['id', 'uuid', 'name']);

                            const searchString = this.getElm().querySelector('[data-name="group-search"]');

                            if (searchString) {
                                searchString.value = '';
                            }

                            Message.destroy();
                            this.$SearchNotice = null;
                            this.$renderFilter();
                            this.load();
                        }
                    },
                    styles: {
                        margin: '0 0 10px',
                        'border-width': 1,
                        cursor: 'pointer'
                    }
                }).inject(this.getBody(), 'top');

                const attNode = this.$SearchNotice.getElm();
                attNode.style.textAlign = 'center';
                attNode.style.borderRadius = '5px';

                if (attNode.querySelector('.messages-message-destroy')) {
                    attNode.querySelector('.messages-message-destroy').parentNode.remove();
                }
            }

            if (this.getAttribute('search') === '' && this.$SearchNotice) {
                this.$SearchNotice.destroy();
                this.$SearchNotice = null;
            }

            this.resize();

            // search
            Groups.getList({
                field: this.getAttribute('field'),
                order: this.getAttribute('order'),
                limit: this.getAttribute('limit'),
                page: this.getAttribute('page'),
                search: this.getAttribute('search'),
                searchSettings: this.getAttribute('searchfields')
            }).then(function (result) {
                let i, len, data, admin;

                const Grid = self.getGrid();

                if (!Grid) {
                    self.Loader.hide();
                    return;
                }

                data = result.data;

                for (i = 0, len = data.length; i < len; i++) {
                    admin = parseInt(data[i].admin);

                    data[i].active = parseInt(data[i].active);
                    data[i].admin = QUILocale.get(lg, 'no');

                    if (admin) {
                        data[i].admin = QUILocale.get(lg, 'yes');
                    }

                    data[i].status = new QUISwitch({
                        status: data[i].active,
                        value: data[i].uuid,
                        gid: data[i].uuid,
                        title: data[i].active ?
                            self.getAttribute('active_text') :
                            self.getAttribute('deactive_text'),
                        events: {
                            onChange: self.$btnSwitchStatus
                        }
                    });
                }

                Grid.setData(result);

                self.setAttribute('title', QUILocale.get(lg, 'groups.panel.title'));
                self.setAttribute('icon', 'fa fa-group');
                self.refresh();

                self.Loader.hide();
            });
        },

        /**
         * execute a group status switch
         *
         * @param {Object} Switch - qui/controls/buttons/Switch
         */
        $btnSwitchStatus: function (Switch) {
            Groups.switchStatus(Switch.getAttribute('gid'));
        },

        /**
         * event : status change of a group
         * if a group status is changed
         *
         * @param {Object} Groups - classes/groups/Manager
         * @param {Object} ids - Group-IDs with status
         */
        $onSwitchStatus: function (Groups, ids) {
            let i, len, Status, entry, status;

            const Grid = this.getGrid(),
                data = Grid.getData();

            for (i = 0, len = data.length; i < len; i++) {
                if (typeof ids[data[i].id] === 'undefined') {
                    continue;
                }

                entry = data[i];

                status = parseInt(ids[data[i].id]);
                Status = entry.status;

                // group is active
                if (status === 1) {
                    Status.setAttribute('title', this.getAttribute('active_text'));
                    Status.setSilentOn();
                    continue;
                }

                // group is deactive
                Status.setAttribute('title', this.getAttribute('deactive_text'));
                Status.setSilentOff();
            }
        },

        /**
         * event : group fresh
         * if a group is refreshed
         *
         * @param {Object} Groups - classes/groups/Manager
         * @param {Object} Group - classes/groups/Group
         */
        $onRefreshGroup: function (Groups, Group) {
            if (typeof Group === 'undefined') {
                return;
            }

            let i, len;

            const Grid = this.getGrid(),
                data = Grid.getData(),
                id = Group.getId();

            for (i = 0, len = data.length; i < len; i++) {
                if (data[i].id !== id) {
                    continue;
                }

                Grid.setDataByRow(i, this.groupToData(Group));
            }
        },

        /**
         * event: group deletion
         * if a group is deleted
         *
         * @param {classes/groups/Manager} Groups
         * @param {Array} ids - Delete Group-IDs
         */
        $onDeleteGroup: function (Groups, ids) {
            let i, id, len;

            const Grid = this.getGrid(),
                data = Grid.getData(),
                _tmp = {};

            for (i = 0, len = ids.length; i < len; i++) {
                _tmp[ids[i]] = true;
            }

            for (i = 0, len = data.length; i < len; i++) {
                id = data[i].id;

                if (_tmp[id]) {
                    this.load();
                    break;
                }
            }
        },

        /**
         * Open all marked groups
         */
        $onButtonEditClick: function () {
            const Parent = this.getParent(),
                Grid = this.getGrid(),
                seldata = Grid.getSelectedData();

            if (!seldata.length) {
                return;
            }

            if (seldata.length === 1) {
                this.openGroup(seldata[0].id);
                return;
            }

            let i, len;

            if (Parent.getType() === 'qui/controls/desktop/Tasks') {
                require([
                    'controls/groups/Group',
                    'qui/controls/taskbar/Group'
                ], function (GroupControl, QUITaskGroup) {
                    let Group, Task, TaskGroup;

                    TaskGroup = new QUITaskGroup();
                    Parent.appendTask(TaskGroup);

                    for (i = 0, len = seldata.length; i < len; i++) {
                        Group = new GroupControl(seldata[i].id);
                        Task = Parent.instanceToTask(Group);

                        TaskGroup.appendChild(Task);
                    }

                    // TaskGroup.refresh( Task );
                    TaskGroup.click();
                });

                return;
            }

            for (i = 0, len = seldata.length; i < len; i++) {
                this.openGroup(seldata[i].id);
            }
        },

        /**
         * Open deletion popup
         */
        $onButtonDelClick: function () {
            let i, len;

            const gids = [],
                data = this.getGrid().getSelectedData();

            for (i = 0, len = data.length; i < len; i++) {
                // everyone and guest is not deletable
                if (data[i].id === 0 || data[i].id === 1) {
                    continue;
                }

                gids.push(data[i].id);
            }

            if (!gids.length) {
                return;
            }

            new QUIConfirm({
                name: 'DeleteGroups',
                icon: 'fa fa-trash-o',
                texticon: 'fa fa-trash-o',
                title: QUILocale.get(lg, 'groups.panel.delete.window.title'),
                text: QUILocale.get(lg, 'groups.panel.delete.window.text') + '<br /><br />' + gids.join(', '),
                information: QUILocale.get(lg, 'groups.panel.delete.window.information'),
                ok_button: {
                    text: QUILocale.get(lg, 'delete'),
                    textimage: 'fa fa-trash'
                },
                maxWidth: 600,
                maxHeight: 400,
                autoclose: false,
                events: {
                    onSubmit: function (Win) {
                        Win.Loader.show();
                        Groups.deleteGroups(gids).then(function () {
                            Win.close();
                        });
                    }
                }
            }).open();
        }
    });
});
