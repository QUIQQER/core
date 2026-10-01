/**
 * User Manager (View)
 */
define('controls/users/Panel', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'controls/grid/Grid',
    'Users',
    'qui/controls/messages/Attention',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Switch',
    'controls/users/CreateWindow',
    'controls/users/InviteWindow',
    'utils/Template',
    'utils/Controls',
    'Locale',
    'Mustache',

    'text!controls/users/Panel.userSearch.html',
    'css!controls/users/Panel.css'

], function () {
    'use strict';

    const lg = 'quiqqer/core';

    const QUI = arguments[0],
        Panel = arguments[1],
        Grid = arguments[2],
        Users = arguments[3],
        Attention = arguments[4],
        QUIConfirm = arguments[5],
        QUISwitch = arguments[6],
        CreateWindow = arguments[7],
        InviteWindow = arguments[8],
        Template = arguments[9],
        ControlUtils = arguments[10],
        QUILocale = arguments[11],
        Mustache = arguments[12],
        userSearchTemplate = arguments[13];

    /**
     * @class controls/users/Panel
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: Panel,
        Type: 'controls/users/Panel',

        Binds: [
            '$onCreate',
            '$onResize',
            '$onSwitchStatus',
            '$btnSwitchStatus',
            '$onDeleteUser',
            '$onUserRefresh',
            '$onButtonEditClick',
            '$onButtonDelClick',
            '$onButtonWipeClick',
            '$gridClick',
            '$gridDblClick',
            '$gridBlur',

            'search',
            'createUser',
            'inviteUser'
        ],

        initialize: function (options) {
            this.$uid = String.uniqueID();

            // defaults
            this.setAttributes({
                field: 'regdate',
                order: 'DESC',
                limit: 100,
                page: 1,
                search: false,
                searchSettings: {},
                tabbar: false
            });

            this.parent(options);

            this.$Grid = null;
            this.$Container = null;
            this.$filterContainer = null;

            this.addEvent('onCreate', this.$onCreate);
            this.addEvent('onResize', this.$onResize);

            Users.addEvents({
                onSwitchStatus: this.$onSwitchStatus,
                onDelete: this.$onDeleteUser,
                onRefresh: this.$onUserRefresh,
                onSave: this.$onUserRefresh
            });

            this.addEvent('onDestroy', () => {
                Users.removeEvents({
                    onSwitchStatus: this.$onSwitchStatus,
                    onDelete: this.$onDeleteUser,
                    onRefresh: this.$onUserRefresh,
                    onSave: this.$onUserRefresh
                });
            });


            //this.active_image = 'fa fa-check';
            this.active_text = QUILocale.get(lg, 'users.panel.user.is.active');

            //this.deactive_image = 'fa fa-remove';
            this.deactive_text = QUILocale.get(lg, 'users.panel.user.is.deactive');
        },

        /**
         * Return the user grid
         *
         * @return {controls/grid/Grid|null}
         */
        getGrid: function () {
            return this.$Grid;
        },

        /**
         * create the user panel
         */
        $onCreate: function () {
            this.addButton({}); // placeholder, workaround, button bar will be shown

            // suche
            const searchContainer = document.createElement('div');
            searchContainer.style.float = 'right';
            searchContainer.style.paddingLeft = '10px';
            searchContainer.style.position = 'relative';

            searchContainer.innerHTML = `
                <div style="position: relative; float: left;">
                    <input name="user-search" type="search" />
                    <span style="position: absolute; left: 10px; top: 16px;">
                        <span class="fa fa-search"></span>
                    </span>
                </div>
                <button name="filter" style="cursor: pointer;">
                    <span class="fa fa-filter"></span>
                </button>
            `;

            // filter
            const filter = document.createElement('div');
            filter.setAttribute('data-name', 'user-search-filter');
            filter.style.zIndex = '1000';
            filter.style.width = '500px';
            filter.style.display = 'none';
            filter.style.overflow = 'auto';
            filter.style.maxHeight = '550px';

            this.$filterContainer = filter;
            document.body.appendChild(this.$filterContainer);
            this.renderFilter();
            this.addButton(searchContainer);

            const filterButton = searchContainer.querySelector('[name="filter"]');

            const handleFilterClickOutside = (e) => {
                if (!filter.contains(e.target) && e.target !== filterButton) {
                    filter.style.display = 'none';
                    document.body.removeEventListener('mouseup', handleFilterClickOutside);
                    this.search();
                }
            };

            filterButton.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();

                const rect = filterButton.getBoundingClientRect();

                filter.style.display = 'flex';
                filter.style.left = `${parseInt(rect.left) - parseInt(filter.style.width) + 30}px`;
                filter.style.top = `${parseInt(rect.bottom)}px`;

                setTimeout(() => {
                    document.body.addEventListener('mouseup', handleFilterClickOutside);
                }, 0);
            });


            const searchInput = searchContainer.querySelector('[name="user-search"]');
            searchInput.style.paddingLeft = '30px';
            searchInput.style.width = '280px';
            searchInput.style.marginTop = '9px';
            searchInput.style.borderRadius = '5px';

            searchInput.addEventListener('change', () => {
                this.search();
            });

            searchInput.addEventListener('keyup', (e) => {
                if (e.key === 'Enter') {
                    this.search();
                }
            });

            // create grid
            const Body = this.getBody();

            this.$Container = new Element('div');
            this.$Container.inject(Body);

            this.$Grid = new Grid(this.$Container, {
                buttons: [
                    {
                        name: 'userNew',
                        Users: this,
                        text: QUILocale.get(lg, 'users.panel.btn.create'),
                        textimage: 'fa fa-plus',
                        events: {
                            onClick: this.createUser
                        }
                    },
                    {
                        name: 'userEdit',
                        Users: this,
                        text: QUILocale.get(lg, 'users.panel.btn.edit'),
                        disabled: true,
                        textimage: 'fa fa-edit',
                        events: {
                            onMousedown: this.$onButtonEditClick
                        }
                    },
                    {
                        name: 'userWipe',
                        title: QUILocale.get(lg, 'users.user.btn.wipe'),
                        disabled: true,
                        icon: 'fa fa-eraser',
                        position: 'right',
                        events: {
                            onMousedown: this.$onButtonWipeClick
                        }
                    },
                    {
                        name: 'userDel',
                        Users: this,
                        title: QUILocale.get(lg, 'users.panel.btn.delete'),
                        disabled: true,
                        icon: 'fa fa-trash-o',
                        position: 'right',
                        events: {
                            onMousedown: this.$onButtonDelClick
                        }
                    }
                ],
                columnModel: [
                    {
                        header: QUILocale.get(lg, 'status'),
                        dataIndex: 'status',
                        dataType: 'QUI',
                        width: 60
                    },
                    {
                        header: QUILocale.get(lg, 'username'),
                        dataIndex: 'username',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'group'),
                        dataIndex: 'userGroups',
                        dataType: 'node',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'email'),
                        dataIndex: 'email',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'firstname'),
                        dataIndex: 'firstname',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'lastname'),
                        dataIndex: 'lastname',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'e_date'),
                        dataIndex: 'lastedit',
                        dataType: 'date',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'c_date'),
                        dataIndex: 'regdate',
                        dataType: 'date',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'user_id'),
                        dataIndex: 'id',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        dataIndex: 'uuid',
                        dataType: 'string',
                        hidden: true
                    }
                ],
                configurable: true,
                storageKey: 'quiqqer-user-table',
                pagination: true,
                filterInput: true,
                perPage: this.getAttribute('limit'),
                page: this.getAttribute('page'),
                sortOn: this.getAttribute('field'),
                serverSort: true,
                showHeader: true,
                sortHeader: true,
                width: Body.getSize().x - 40,
                height: Body.getSize().y - 40,
                onrefresh: function (me) {
                    const options = me.options;

                    this.setAttribute('field', options.sortOn);
                    this.setAttribute('order', options.sortBy);
                    this.setAttribute('limit', options.perPage);
                    this.setAttribute('page', options.page);

                    this.load();
                }.bind(this),

                alternaterows: true,
                resizeColumns: true,
                selectable: true,
                multipleSelection: true,
                resizeHeaderOnly: true,
                exportData: true
            });

            // Events
            this.$Grid.addEvents({
                onClick: this.$gridClick,
                onDblClick: this.$gridDblClick,
                onBlur: this.$gridBlur
            });

            // start and list the users
            this.load();
        },

        /**
         * Load the users with the settings
         *
         * @param {Function} [callback]
         */
        load: function (callback) {
            this.Loader.show();
            this.$loadUsers(callback);
        },

        /**
         * create a user panel
         *
         * @param {Number} uid - User-ID
         * @return {Object} this
         */
        openUser: function (uid) {
            require([
                'controls/users/User',
                'utils/Panels'
            ], function (User, PanelUtils) {
                PanelUtils.openPanelInTasks(new User(uid));
            });

            return this;
        },

        /**
         * Opens the users search settings
         */
        search: function () {
            const searchString = this.getElm().querySelector('[name="user-search"]');

            this.setAttribute('search', true);

            const searchSettings = {
                userSearchString: searchString.value
            };

            if (this.$filterContainer) {
                const form = this.$filterContainer.querySelector('form');

                searchSettings.fields = {
                    id: form.elements.uid.checked,
                    username: form.elements.username.checked,
                    email: form.elements.email.checked,
                    firstname: form.elements.firstname.checked,
                    lastname: form.elements.lastname.checked
                };

                searchSettings.filter = {
                    filter_status: form.elements.filter_status.value,
                    filter_group: form.elements.filter_group.value,
                    filter_regdate_first: form.elements.filter_regdate_first.value,
                    filter_regdate_last: form.elements.filter_regdate_last.value
                };
            }

            this.setAttribute('searchSettings', searchSettings);
            this.$loadUsers();
        },

        renderFilter: function () {
            this.$filterContainer.innerHTML = Mustache.render(userSearchTemplate, {
                searchTitle: QUILocale.get(lg, 'user.panel.search.in.title'),
                user_id: QUILocale.get(lg, 'user_id'),
                username: QUILocale.get(lg, 'username'),
                email: QUILocale.get(lg, 'email'),
                firstname: QUILocale.get(lg, 'firstname'),
                lastname: QUILocale.get(lg, 'lastname'),
                filterTitle: QUILocale.get(lg, 'user.panel.search.filter.title'),
                status: QUILocale.get(lg, 'status'),
                filter_regdate_first: QUILocale.get(lg, 'user.panel.search.filter.regdate.first'),
                filter_regdate_last: QUILocale.get(lg, 'user.panel.search.filter.regdate.last'),
                groups: QUILocale.get(lg, 'groups'),
            });

            QUI.parse(this.$filterContainer);
        },

        /**
         * Open the user create dialog
         *
         * @return {Promise}
         */
        createUser: function () {
            const self = this;

            return new Promise((resolve) => {
                let handled = false;

                new CreateWindow({
                    events: {
                        onCreateSubmit: (Win, username) => {
                            handled = true;

                            Users.createUser(username).then((result) => {
                                Win.close();
                                self.openUser(result);
                                self.load(() => {
                                    resolve(result);
                                });
                            }).catch(() => {
                                handled = false;
                                Win.Loader.hide();
                                resolve(false);
                            });
                        },

                        onInvite: (Win) => {
                            handled = true;
                            Win.close();

                            self.inviteUser().then(resolve);
                        },

                        onCancel: () => {
                            handled = true;
                            resolve(false);
                        },

                        onClose: () => {
                            if (!handled) {
                                resolve(false);
                            }
                        }
                    }
                }).open();
            });
        },

        /**
         * Open the user invite dialog
         *
         * @return {Promise}
         */
        inviteUser: function () {
            const self = this;

            return new Promise((resolve) => {
                let handled = false;

                new InviteWindow({
                    events: {
                        onInviteSubmit: (Win, email, groups) => {
                            handled = true;

                            Users.inviteUser(email, groups).then((result) => {
                                Win.close();
                                self.openUser(result);
                                self.load(() => {
                                    resolve(result);
                                });
                            }).catch(() => {
                                handled = false;
                                Win.Loader.hide();
                                resolve(false);
                            });
                        },

                        onCancel: () => {
                            handled = true;
                            self.createUser().then(resolve);
                        },

                        onClose: () => {
                            if (!handled) {
                                resolve(false);
                            }
                        }
                    }
                }).open();
            });
        },

        /**
         * onclick on the grid
         */
        $gridClick: function (data) {
            const len = data.target.selected.length,
                Edit = this.$Grid.getButton('userEdit'),
                Delete = this.$Grid.getButton('userDel'),
                Wipe = this.$Grid.getButton('userWipe');

            if (len === 0) {
                Edit.disable();
                Delete.disable();
                Wipe.disable();

                return;
            }

            if (len === 1) {
                Edit.enable();
            } else {
                Edit.disable();
            }

            Delete.enable();
            Wipe.enable();
            data.evt.stop();
        },

        /**
         * dblclick on the grid
         */
        $gridDblClick: function (data) {
            this.openUser(
                data.target.getDataByRow(data.row).id
            );
        },

        /**
         * onblur on the grid
         */
        $gridBlur: function () {
            this.getGrid().unselectAll();
            this.getGrid().removeSections();

            this.$Grid.getButton('userEdit').disable();
            this.$Grid.getButton('userDel').disable();
            this.$Grid.getButton('userWipe').disable();
        },

        /**
         * Resize the users panel
         */
        $onResize: function () {
            const Body = this.getBody();

            if (!Body) {
                return;
            }

            if (!this.getGrid()) {
                return;
            }

            if (this.getAttribute('search')) {
                this.getGrid().setHeight(Body.getSize().y - 120);

            } else {
                this.getGrid().setHeight(Body.getSize().y - 40);
            }

            const Message = Body.getElement('.messages-message');

            if (Message) {
                Message.setStyle('width', this.getBody().getSize().x - 40);
            }

            this.getGrid().setWidth(Body.getSize().x - 40);


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
         * Load the users to the grid
         *
         * @param {Function} [callback]
         */
        $loadUsers: function (callback) {
            this.Loader.show();

            this.setAttribute('title', QUILocale.get(lg, 'users.panel.title'));
            this.setAttribute('icon', 'fa fa-spinner fa-spin');
            this.refresh();

            if (this.getAttribute('search') && !this.getBody().getElement('.messages-message')) {
                let attNode = new Attention({
                    Users: this,
                    message: QUILocale.get(lg, 'users.panel.search.info'),
                    events: {
                        onClick: (Message) => {
                            this.setAttribute('search', false);
                            this.setAttribute('searchSettings', {});

                            const searchString = this.getElm().querySelector('[name="user-search"]');

                            if (searchString) {
                                searchString.value = '';
                            }

                            Message.destroy();
                            this.renderFilter();
                            this.$loadUsers();
                        }
                    },
                    styles: {
                        margin: '0 0 20px',
                        'border-width': 1,
                        cursor: 'pointer'
                    }
                }).inject(this.getBody(), 'top');

                attNode = attNode.getElm();
                attNode.style.textAlign = 'center';
                attNode.style.borderRadius = '5px';

                if (attNode.querySelector('.messages-message-destroy')) {
                    attNode.querySelector('.messages-message-destroy').parentNode.remove();
                }
            }

            if (!this.getAttribute('search') && this.getBody().getElement('.messages-message')) {
                this.getBody().getElement('.messages-message').destroy();
            }

            this.resize();


            Users.getList({
                field: this.getAttribute('field'),
                order: this.getAttribute('order'),
                limit: this.getAttribute('limit'),
                page: this.getAttribute('page'),
                search: this.getAttribute('search'),
                searchSettings: this.getAttribute('searchSettings')
            }).then((result) => {
                const Grid = this.getGrid();

                this.setAttribute('title', QUILocale.get(lg, 'users.panel.title'));
                this.setAttribute('icon', 'fa fa-user');
                this.refresh();

                if (!Grid) {
                    this.Loader.hide();
                    return;
                }

                this.$parseDataForGrid(result.data);

                Grid.setData(result);

                if (typeof callback === 'function') {
                    callback();
                }

                this.Loader.hide();
            });
        },

        /**
         * execute a user user status switch
         *
         * @param {Object} Switch - qui/controls/buttons/Switch
         */
        $btnSwitchStatus: function (Switch) {
            const self = this,
                User = Users.get(Switch.getAttribute('uid'));

            if (!User.isLoaded()) {
                User.load(function () {
                    Switch = self.$getUserSwitch(User);
                    self.$btnSwitchStatus(Switch);
                });

                return;
            }

            const userStatus = !!User.isActive();

            // status is the same as the switch, we must do nothing
            if (userStatus === Switch.getStatus()) {
                return;
            }

            if (!Switch.getStatus()) {
                new QUIConfirm({
                    title: QUILocale.get(lg, 'users.panel.deactivate.window.title'),
                    text: QUILocale.get(lg, 'users.panel.deactivate.window.text', {
                        userid: User.getId(),
                        username: User.getName()
                    }),
                    information: QUILocale.get(lg, 'users.panel.deactivate.window.information'),
                    maxHeight: 400,
                    maxWidth: 600,
                    autoclose: false,
                    events: {
                        onSubmit: function (Win) {
                            Win.Loader.show();

                            Users.deactivate(Switch.getAttribute('uid')).then(function () {
                                const Switch = self.$getUserSwitch(User);

                                if (Switch) {
                                    if (User.isActive()) {
                                        Switch.setSilentOn();
                                    } else {
                                        Switch.setSilentOff();
                                    }
                                }

                                Win.close();
                            });
                        },

                        onCancel: function () {
                            Switch.setSilentOn();
                        }
                    }
                }).open();

                return;
            }

            Users.activate(Switch.getAttribute('uid')).then(function () {
                const Switch = self.$getUserSwitch(User);

                if (Switch) {
                    if (User.isActive()) {
                        Switch.setSilentOn();
                    } else {
                        Switch.setSilentOff();
                    }
                }
            });
        },

        /**
         * if a user status is changed
         *
         * @param {Object} Users - classes/users/Users
         * @param {Object} ids - User-IDs
         */
        $onSwitchStatus: function (Users, ids) {
            let i, len, Switch, entry, status;

            const Grid = this.getGrid(),
                data = Grid.getData();

            for (i = 0, len = data.length; i < len; i++) {
                if (typeof ids[data[i].id] === 'undefined') {
                    continue;
                }

                entry = data[i];

                status = parseInt(entry.active);
                Switch = entry.status;

                // user is active
                if (status) {
                    Switch.setAttribute('alt', this.active_text);
                    Switch.setSilentOn();
                    continue;
                }

                // user is deactive
                Switch.setAttribute('alt', this.deactive_text);
                Switch.setSilentOff();
            }
        },

        /**
         * if a user status is changed
         *
         * @param {Object} Users - classes/users/Users
         * @param {Object} User - classes/users/User
         */
        $onUserRefresh: function (Users, User) {
            const Grid = this.getGrid(),
                data = Grid.getData(),
                id = User.getId();

            for (let i = 0, len = data.length; i < len; i++) {
                if (data[i].id == id) {
                    Grid.setDataByRow(i, this.userToGridData(User.getAttributes()));
                }
            }
        },

        /**
         * if a user is deleted
         */
        $onDeleteUser: function (Users, ids) {
            let i, id, len;

            const Grid = this.getGrid(),
                data = Grid.getData(),
                tmp = {};

            for (i = 0, len = ids.length; i < len; i++) {
                tmp[ids[i]] = true;
            }

            for (i = 0, len = data.length; i < len; i++) {
                id = data[i].id;

                if (tmp[id]) {
                    this.load();
                    break;
                }
            }
        },

        /**
         * Open all marked users
         */
        $onButtonEditClick: function (instance, event) {
            event.preventDefault();
            event.stopPropagation();

            const Parent = this.getParent(),
                Grid = this.getGrid(),
                seldata = Grid.getSelectedData();

            if (!seldata.length) {
                return;
            }

            if (seldata.length === 1) {
                this.openUser(seldata[0].id);
                return;
            }

            let i, len;

            if (Parent.getType() === 'qui/controls/desktop/Tasks') {
                require([
                    'controls/users/User',
                    'qui/controls/taskbar/Group'
                ], function (UserPanel, QUITaskGroup) {
                    let User, Task, TaskGroup;

                    TaskGroup = new QUITaskGroup();
                    Parent.appendTask(TaskGroup);

                    for (i = 0, len = seldata.length; i < len; i++) {
                        User = new UserPanel(seldata[i].id);
                        Task = Parent.instanceToTask(User);

                        TaskGroup.appendChild(Task);
                    }

                    // TaskGroup.refresh( Task );
                    TaskGroup.click();
                });

                return;
            }

            for (i = 0, len = seldata.length; i < len; i++) {
                this.openUser(seldata[i].id);
            }
        },

        /**
         * Open deletion popup
         */
        $onButtonDelClick: function (instance, event) {
            event.preventDefault();
            event.stopPropagation();
            this.$openDeleteWindow('delete');
        },

        $onButtonWipeClick: function (instance, event) {
            event.preventDefault();
            event.stopPropagation();
            this.$openDeleteWindow('wipe');
        },

        $openDeleteWindow: function (mode) {
            const users = this.getGrid().getSelectedData().map(user => ({
                id: user.uuid,
                name: [user.firstname, user.lastname].filter(Boolean).join(' ').trim() ||
                    user.email || user.username
            }));

            if (!users.length) {
                return;
            }

            require(['controls/users/DeleteWindow'], (DeleteWindow) => {
                new DeleteWindow({
                    mode: mode,
                    users: users,
                    events: {onComplete: () => this.load()}
                }).open();
            });
        },

        /**
         * Parse the Ajax data for the grid
         *
         * @param {Array} data
         * @return {Array}
         */
        $parseDataForGrid: function (data) {
            for (let i = 0, len = data.length; i < len; i++) {
                data[i] = this.userToGridData(data[i]);
            }

            return data;
        },

        /**
         * Return the Switch button for the user entry
         *
         * @param {Object} User - classes/users/User
         * @returns {Object|Boolean} (qui/controls/buttons/Switch)
         */
        $getUserSwitch: function (User) {
            const Grid = this.getGrid(),
                data = Grid.getData(),
                id = User.getId();

            for (let i = 0, len = data.length; i < len; i++) {
                if (data[i].id == id) {
                    return Grid.getDataByRow(i).status;
                }
            }

            return false;
        },

        /**
         * Parse the attributes to grid data entry
         *
         * @param {Object} user - user attributes
         * @return {Object}
         */
        userToGridData: function (user) {
            let groups, userGroups;

            const userData = {
                status: '',
                username: '',
                userGroups: null,
                email: '',
                firstname: '',
                lastname: '',
                lastedit: '',
                regdate: '',
                id: '',
            };

            // status
            if (typeof user.active === 'undefined' || parseInt(user.active) === -1) {
                userData.status = new Element('span', {
                    html: '&nbsp;'
                });
            } else {
                userData.status = new QUISwitch({
                    status: parseInt(user.active) === 1,
                    uid: user.id,
                    title: parseInt(user.active) ? this.active_text : this.deactive_text,
                    events: {
                        onChange: this.$btnSwitchStatus
                    }
                });
            }

            // groups
            userGroups = user.usergroup;

            if (typeof userGroups === 'string') {
                userGroups = userGroups.split(',');
            }

            if (!Array.isArray(userGroups)) {
                userGroups = [];
            }

            userGroups = userGroups.map(function (group) {
                return String(group).trim();
            }).filter(function (group) {
                return group !== '' && group !== '0';
            });

            groups = document.createElement('div');
            groups.style.display = 'flex';
            groups.style.gap = '0.5rem';

            if (typeof user.usergroup === 'undefined' || !user.usergroup) {
                user.usergroup = '';
            }

            userGroups.forEach((group) => {
                const node = document.createElement('span');
                node.classList.add('badge', 'badge-success');

                // if uuid or int
                if (
                    group.match(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i)
                    || group.match(/^[0-9]+$/)
                ) {
                    require(['Groups'], (GroupManager) => {
                        const groupInstance = GroupManager.get(group);
                        const renderGroupName = function () {
                            const groupName = groupInstance.getName();

                            if (!groupName) {
                                node.destroy();
                                return;
                            }

                            node.innerHTML = groupName;
                        };

                        if (groupInstance.isLoaded()) {
                            renderGroupName();
                        } else {
                            groupInstance.load().then(renderGroupName).catch(() => {
                                node.destroy();
                            });
                        }
                    });
                } else {
                    node.innerHTML = group;
                }

                groups.appendChild(node);
            });

            userData.userGroups = groups;

            // id
            if (typeof user.id !== 'undefined') {
                userData.id = user.id;
            }

            if (typeof user.uuid !== 'undefined') {
                userData.id = user.uuid;
                userData.uuid = user.uuid;
            }

            // data
            if (typeof user.username !== 'undefined') {
                userData.username = user.username;
            }

            if (typeof user.email !== 'undefined') {
                userData.email = user.email;
            }

            if (typeof user.firstname !== 'undefined') {
                userData.firstname = user.firstname;
            }

            if (typeof user.lastname !== 'undefined') {
                userData.lastname = user.lastname;
            }

            if (typeof user.lastedit !== 'undefined') {
                userData.lastedit = user.lastedit;
            }

            if (typeof user.regdate !== 'undefined') {
                userData.regdate = user.regdate;
            }

            return userData;
        }
    });
});
