/**
 * A group panel
 */
define('controls/groups/Group', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/buttons/ButtonSwitch',
    'qui/controls/buttons/Button',
    'controls/grid/Grid',
    'Groups',
    'Ajax',
    'Editors',
    'Locale',
    'qui/utils/Form',
    'utils/Controls',
    'utils/EntityPanelButtons'

], function(QUI, QUIPanel, QUIButtonSwitch, QUIButton,
    Grid, Groups, Ajax, Editors, QUILocale, FormUtils, ControlUtils, EntityPanelButtons
) {
    'use strict';

    const lg = 'quiqqer/core';

    /**
     * @class controls/groups/Group
     *
     * @param {Number|String} gid - Group-ID
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QUIPanel,
        Type: 'controls/groups/Group',

        Binds: [
            'save',
            'del',
            'refreshUser',
            'openPermissions',

            '$onCreate',
            '$onDestroy',
            '$onResize',
            '$onCategoryLoad',
            '$onCategoryUnload',
            '$onGroupRefresh',
            '$onGroupStatusChange',
            '$onStatusButtonChange',
            '$onGroupDelete',
            '$onGroupGetUser',
            '$onUsersAdd',
            '$onUsersRemove',
            '$onClickSendMail'
        ],

        options: {
            'user-sort': 'DESC',
            'user-field': 'id',
            'user-limit': 20,
            'user-page': 1
        },

        initialize: function(gid, options) {
            // defaults
            this.$Group = null;
            this.$UserGrid = null;

            if (typeOf(gid) === 'string' || typeOf(gid) === 'number') {
                this.$Group = Groups.get(gid);
            }

            this.parent(options);

            this.addEvents({
                onCreate: this.$onCreate,
                onDestroy: this.$onDestroy,
                onResize: this.$onResize,
                onShow: function() {
                    const Status = this.getButtons('status');

                    if (Status) {
                        Status.resize();
                    }
                }
            });
        },

        /**
         * Save the group panel to the workspace
         *
         * @method controls/groups/Group#serialize
         * @return {Object} data
         */
        serialize: function() {
            return {
                attributes: this.getAttributes(),
                groupid: this.getGroup().getId(),
                type: this.getType()
            };
        },

        /**
         * import the saved data form the workspace
         *
         * @method controls/groups/Group#unserialize
         * @param {Object} data
         * @return {Object} this (controls/groups/Group)
         */
        unserialize: function(data) {
            this.setAttributes(data.attributes);

            this.$Group = Groups.get(data.groupid);

            return this;
        },

        /**
         * Return the assigned group
         *
         * @return {Object} classes/groups/Group
         */
        getGroup: function() {
            return this.$Group;
        },

        /**
         * Save the group
         */
        save: function() {
            this.$onCategoryUnload();
            this.getGroup().save();
        },

        /**
         * Delete the Group
         * Opens the delete dialog
         */
        del: function() {
            const self = this;

            require(['qui/controls/windows/Confirm'], function(Confirm) {
                new Confirm({
                    name: 'DeleteUser' + self.getGroup().getId(),
                    icon: 'fa fa-trash-o',
                    texticon: 'fa fa-trash-o',
                    title: QUILocale.get(lg, 'groups.group.delete.title'),
                    information: QUILocale.get(lg, 'groups.group.delete.information'),
                    text: QUILocale.get(lg, 'groups.group.delete.text', {
                        group: self.getGroup().getAttribute('name')
                    }),
                    ok_button: {
                        text: QUILocale.get(lg, 'delete'),
                        textimage: 'fa fa-trash'
                    },
                    maxWidth: 600,
                    maxHeight: 400,
                    autoclose: false,
                    events: {
                        onSubmit: function(Win) {
                            Win.Loader.show();

                            Groups.deleteGroups([
                                self.getGroup().getId()
                            ]).then(function() {
                                Win.close();
                            });
                        }
                    }
                }).open();
            });
        },

        /**
         * Opens the group permissions
         */
        openPermissions: function() {
            const Parent = this.getParent(),
                Group = this.getGroup();

            require(['controls/permissions/Panel'], function(PermPanel) {
                Parent.appendChild(
                    new PermPanel({
                        Object: Group
                    })
                );
            });
        },

        /**
         * event : on create
         * Group panel content creation
         */
        $onCreate: function() {
            const self = this;

            this.$drawButtons();

            this.$drawCategories().then(function() {
                const Group = self.getGroup();

                Group.addEvents({
                    'onRefresh': self.$onGroupRefresh
                });

                Groups.addEvents({
                    'onSwitchStatus': self.$onGroupStatusChange,
                    'onActivate': self.$onGroupStatusChange,
                    'onDeactivate': self.$onGroupStatusChange,
                    'onDelete': self.$onGroupDelete
                });

                self.setAttribute('icon', 'fa fa-group');

                let Prom = Promise.resolve();

                if (Group.getAttribute('title') === false) {
                    Prom = Group.load();
                }

                Prom.then(function() {
                    (function() {
                        self.getButtons('status').enable();

                        if (Group.isActive()) {
                            self.getButtons('status').on();
                        } else {
                            self.getButtons('status').off();
                        }
                    }).delay(400);

                    self.$onGroupRefresh();

                    require(['Permissions'], function (Permissions) {
                        Permissions.hasPermission('quiqqer.admin.users.send_mail').then(function (canSendGroupMail) {
                            const ExtrasBtn = self.getButtons('extra');
                            const btnChildren = ExtrasBtn ? ExtrasBtn.getChildren() : [];
                            let SendMailBtn = null;

                            for (let i = 0, len = btnChildren.length; i < len; i++) {
                                let Item = btnChildren[i];

                                if (Item.getAttribute('name') === 'sendMail') {
                                    SendMailBtn = Item;
                                    break;
                                }
                            }

                            if (!SendMailBtn) {
                                return;
                            }

                            if (canSendGroupMail) {
                                SendMailBtn.enable();
                            } else {
                                SendMailBtn.getElm().set(
                                    'title',
                                    QUILocale.get(lg, 'groups.group.btn.sendMail.no_permission')
                                );
                            }
                        });
                    });
                });
            }).catch(function(err) {
                console.error(err);
                self.destroy();
            });
        },

        /**
         * event: on panel destroying
         */
        $onDestroy: function() {
            this.getGroup().removeEvents({
                'refresh': this.$onGroupRefresh
            });

            Groups.removeEvents({
                'switchStatus': this.$onGroupStatusChange,
                'activate': this.$onGroupStatusChange,
                'deactivate': this.$onGroupStatusChange,
                'delete': this.$onGroupDelete
            });
        },

        /**
         * event : onresize
         * Resize the panel
         */
        $onResize: function() {

        },

        /**
         * event : on group refresh
         * if the group will be refreshed
         */
        $onGroupRefresh: function() {
            this.setAttribute(
                'title',

                QUILocale.get(lg, 'groups.group.title', {
                    group: this.getGroup().getAttribute('name')
                })
            );

            this.refresh();

            const Bar = this.getCategoryBar(),
                Status = this.getButtons('status');

            if (this.getGroup().isActive()) {
                Status.on();
                Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isActivate'));
            } else {
                Status.off();
                Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
            }

            if (Bar.getActive()) {
                this.$onCategoryLoad(Bar.getActive());
                return;
            }

            Bar.firstChild().click();

            // button bar refresh
            this.getButtonBar().resize();
        },

        /**
         * event: groups on delete
         * if one group deleted, check if the group is this group
         *
         * @param {Object} Groups - classes/groups/Manager
         * @param {Array} ids - Array of group ids which have been deleted
         */
        $onGroupDelete: function(Groups, ids) {
            const id = this.getGroup().getId();

            for (let i = 0, len = ids.length; i < len; i++) {
                if (ids[i] == id) {
                    this.destroy();
                }
            }
        },

        /**
         * event: groups on status change
         * if one groups status change, check if the group is this group
         *
         * @param {Object} Groups - classes/groups/Manager
         * @param {Object} groups - groups that change the status
         */
        $onGroupStatusChange: function(Groups, groups) {
            const Group = this.getGroup(),
                id = Group.getId();

            for (let gid in groups) {
                if (gid != id) {
                    continue;
                }

                let Status = this.getButtons('status');

                if (Group.isActive()) {
                    Status.setSilentOn();
                    Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isActivate'));
                } else {
                    Status.setSilentOff();
                    Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
                }

                return;
            }
        },

        /**
         * event :Status Button change
         * @param {Object} Button
         */
        $onStatusButtonChange: function(Button) {
            const buttonStatus = Button.getStatus(),
                Group = this.getGroup(),
                groupStatus = Group.isActive();

            if (buttonStatus == groupStatus) {
                return;
            }

            this.Loader.show();

            let Prom;

            if (buttonStatus) {
                Prom = Group.activate();
            } else {
                Prom = Group.deactivate();
            }

            Prom.then(function() {
                if (Group.isActive()) {
                    Button.on();
                    Button.setAttribute('text', QUILocale.get('quiqqer/core', 'isActivate'));
                } else {
                    Button.off();
                    Button.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
                }

                this.Loader.hide();
            }.bind(this));
        },

        /**
         * Draw the panel action buttons
         *
         * @method controls/groups/Group#$drawButtons
         */
        $drawButtons: function() {
            const ExtrasBtn = EntityPanelButtons.createActions(this);

            this.addButton({
                name: 'groupSave',
                text: QUILocale.get(lg, 'groups.group.btn.save'),
                textimage: 'fa fa-save',
                events: {
                    onClick: this.save
                }
            });

            this.addButton({
                type: 'separator'
            });

            const StatusButton = new QUIButtonSwitch({
                name: 'status',
                text: QUILocale.get('quiqqer/core', 'isActivate'),
                disabled: true,
                events: {
                    onChange: this.$onStatusButtonChange
                }
            });
            EntityPanelButtons.decorateStatus(StatusButton, 'group');
            this.addButton(StatusButton);

            ExtrasBtn.appendChild({
                name: 'sendMail',
                title: QUILocale.get(lg, 'groups.group.btn.sendMail'),
                text: QUILocale.get(lg, 'groups.group.btn.sendMail'),
                icon: 'fa fa-envelope',
                disabled: true,
                events: {
                    onClick: this.$onClickSendMail
                }
            });

            ExtrasBtn.appendChild({
                name: 'groupDelete',
                title: QUILocale.get(lg, 'groups.group.btn.delete'),
                text: QUILocale.get(lg, 'groups.group.btn.delete'),
                icon: 'fa fa-trash-o',
                events: {
                    onClick: this.del
                }
            });

            this.addButton(ExtrasBtn);

            // permissions
            new QUIButton({
                name: 'permissions',
                image: 'fa fa-shield',
                alt: QUILocale.get(lg, 'groups.group.btn.permissions.alt'),
                title: QUILocale.get(lg, 'groups.group.btn.permissions.title'),
                styles: {
                    'float': 'right'
                },
                events: {
                    onClick: this.openPermissions
                }
            }).inject(this.getHeader());
        },

        /**
         * Get the category buttons for the pannel
         *
         * @method controls/groups/Group#drawCategories
         *
         * @param {Function} [onfinish] - Callback function
         * @return {Promise}
         * @ignore
         */
        $drawCategories: function(onfinish) {
            this.Loader.show();

            return new Promise(function(resolve, reject) {
                Ajax.get('ajax_groups_panel_categories', function(result) {
                    for (let i = 0, len = result.length; i < len; i++) {
                        result[i].events = {
                            onActive: this.$onCategoryLoad,
                            onNormal: this.$onCategoryUnload
                        };

                        this.addCategory(result[i]);
                    }

                    if (typeof onfinish === 'function') {
                        onfinish(result);
                    }

                    resolve();
                }.bind(this), {
                    gid: this.getGroup().getId(),
                    onError: reject
                });
            }.bind(this));
        },

        /**
         * event: on category click
         *
         * @param {Object} Category - qui/controls/buttons/Button
         * @param {Boolean} [force]
         */
        $onCategoryLoad: function(Category, force) {
            force = force || false;

            // 200ms limit, so you can't DDOS it
            if (this.$categoryLoad) {
                clearInterval(this.$categoryLoad);
            }

            if (force === false) {
                this.$categoryLoad = (function() {
                    this.$onCategoryLoad(Category, true);
                }).delay(200, this);

                return;
            }

            const self = this;

            this.Loader.show();

            Ajax.get('ajax_groups_panel_category', function(result, Request) {
                let Form;

                const Category = Request.getAttribute('Category'),
                    Group = self.getGroup(),
                    Body = self.getBody();

                Body.set(
                    'html',
                    '<form name="group-panel-' + Group.getId() + '">' + result + '</form>'
                );

                Form = Body.getElement('form');

                const attributes = Object.clone(Group.getAttributes());

                if (attributes.toolbar && attributes.toolbar.indexOf(':') === -1) {
                    attributes.toolbar = attributes.toolbar.replace('.xml', '');

                    if (attributes.toolbar !== '') {
                        attributes.toolbar = 'quiqqer/ckeditor4:' + attributes.toolbar;
                    }
                }

                if (attributes.assigned_toolbar) {
                    attributes.assigned_toolbar = attributes.assigned_toolbar.split(',').map(function (toolbar) {
                        if (toolbar.indexOf(':') !== -1) {
                            return toolbar;
                        }

                        toolbar = toolbar.replace('.xml', '');

                        if (toolbar === '') {
                            return '';
                        }

                        return 'quiqqer/ckeditor4:' + toolbar;
                    }).filter(function (toolbar) {
                        return toolbar !== '';
                    }).join(',');
                }

                FormUtils.setDataToForm(attributes, Form);

                switch (Category.getAttribute('name')) {
                    case 'settings':
                        self.$onCategorySettingsLoad();
                        break;

                    case 'users':
                        self.$onCategoryUsersLoad();
                        break;

                    default:
                        Category.fireEvent('onLoad', [
                            Category,
                            self
                        ]);
                }

                ControlUtils.parse(Body).then(function() {
                    return QUI.parse(Body);
                }).then(function() {
                    self.Loader.hide();
                });

            }, {
                plugin: Category.getAttribute('plugin'),
                tab: Category.getAttribute('name'),
                gid: this.getGroup().getId(),
                Category: Category
            });
        },

        /**
         * event: on set normal a category = unload a category
         */
        $onCategoryUnload: function() {
            const Content = this.getBody(),
                Frm = Content.getElement('form'),
                data = FormUtils.getFormData(Frm);

            this.getGroup().setAttributes(data);
        },

        /**
         * event: on category click (settings)
         */
        $onCategorySettingsLoad: function() {
            const Group = this.getGroup(),
                Content = this.getContent(),
                Parent = Content.getElement('[name="parent"]'),
                Toolbar = Content.getElement('[name="toolbar"]');

            if (Group.getId() === 1 ||
                Group.getId() === 0 ||
                Group.getId() === window.QUIQQER_CONFIG.globals.root) {
                Parent.getParent('tr').setStyle('display', 'none');
            } else {
                Parent.set('value', Group.getAttribute('parent'));
            }

            // load the wysiwyg toolbars
            if (Toolbar) {
                Toolbar.addEvent('change', function() {
                    Group.setAttribute('toolbar', this.value);
                });

                let rendered = false;
                let AssignedToolbar;
                const ATNode = Content.getElement('[name="assigned_toolbar"]');

                const renderToolbars = function() {
                    if (rendered) {
                        return;
                    }

                    rendered = true;

                    return Editors.getToolbarsFromGroup(
                        Group.getId(),
                        AssignedToolbar.getValue()
                    ).then(function(toolbars) {
                        Toolbar.set('html', '');

                        for (let i = 0, len = toolbars.length; i < len; i++) {
                            let label = toolbars[i];

                            if (label.indexOf(':') !== -1) {
                                label = label.split(':').pop();
                            }

                            new Element('option', {
                                value: toolbars[i],
                                html: label
                            }).inject(Toolbar);
                        }

                        Toolbar.value = Group.getAttribute('toolbar');

                        if (Toolbar.value === '' && Toolbar.getElement('option')) {
                            Toolbar.value = Toolbar.getElement('option').value;
                            Toolbar.fireEvent('change');
                        }
                    });
                };

                const loadToolbars = function() {
                    AssignedToolbar = QUI.Controls.getById(ATNode.get('data-quiid'));
                    AssignedToolbar.addEvent('change', renderToolbars);
                    renderToolbars();
                };

                if (!ATNode.get('data-quiid')) {
                    ATNode.addEvent('load', loadToolbars);
                } else {
                    loadToolbars();
                }
            }
        },

        /**
         * event: on category click (user listing)
         */
        $onCategoryUsersLoad: function() {
            const Content = this.getBody(),
                GridCon = new Element('div'),
                self = this;

            Content.set('html', '');
            GridCon.inject(Content);

            this.$UserGrid = new Grid(GridCon, {
                buttons: [
                    {
                        name: 'adduser',
                        text: QUILocale.get(lg, 'controls.group.table.btns.adduser'),
                        textimage: 'fa fa-user-plus',
                        events: {
                            onClick: this.$onUsersAdd
                        }
                    },
                    {
                        name: 'removeuser',
                        text: QUILocale.get(lg, 'controls.group.table.btns.removeuser'),
                        textimage: 'fa fa-user-times',
                        events: {
                            onClick: this.$onUsersRemove
                        }
                    }
                ],
                columnModel: [
                    {
                        header: QUILocale.get(lg, 'status'),
                        dataIndex: 'status',
                        dataType: 'node',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'user_id'),
                        dataIndex: 'id',
                        dataType: 'integer',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'username'),
                        dataIndex: 'username',
                        dataType: 'integer',
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
                        header: QUILocale.get(lg, 'c_date'),
                        dataIndex: 'regdate',
                        dataType: 'date',
                        width: 150
                    }
                ],
                pagination: true,
                filterInput: true,
                perPage: this.getAttribute('user-limit'),
                page: this.getAttribute('user-page'),
                sortOn: this.getAttribute('user-sort'),
                serverSort: true,
                showHeader: true,
                sortHeader: true,
                width: Content.getSize().x,
                height: Content.getSize().y - 45,
                onrefresh: this.refreshUser,

                alternaterows: true,
                resizeColumns: true,
                selectable: true,
                multipleSelection: true,
                resizeHeaderOnly: true
            });

            this.$UserGrid.addEvents({
                onDblClick: function(data) {
                    require(['controls/users/User'], function(QUI_User) {
                        this.getParent().appendChild(
                            new QUI_User(
                                data.target.getDataByRow(data.row).id
                            )
                        );
                    }.bind(this));
                }.bind(this),
                onClick: function() {
                    const TableButtons = self.$UserGrid.getAttribute('buttons');

                    if (TableButtons.removeuser) {
                        TableButtons.removeuser.enable();
                    }
                },
                onRefresh: function() {
                    const TableButtons = self.$UserGrid.getAttribute('buttons');

                    if (TableButtons.removeuser) {
                        TableButtons.removeuser.disable();
                    }
                }
            });

            GridCon.setStyles({
                margin: 0
            });

            this.$UserGrid.refresh();
        },

        $onClickSendMail: function () {
            const gid = this.getGroup().getId();

            require(['controls/groups/mail/SendGroupMail'], function (SendGroupMail) {
                new SendGroupMail({
                    groupId: gid
                }).open();
            });
        },

        /**
         * Refresh the user grid
         *
         * @return {Object} this (controls/groups/Group)
         */
        refreshUser: function() {
            if (typeof this.$UserGrid === 'undefined') {
                return this;
            }

            this.Loader.show();

            var Grid = this.$UserGrid;

            this.setAttribute('user-field', Grid.getAttribute('sortOn'));
            this.setAttribute('user-order', Grid.getAttribute('sortBy'));
            this.setAttribute('user-limit', Grid.getAttribute('perPage'));
            this.setAttribute('user-page', Grid.getAttribute('page'));

            this.getGroup().getUsers(this.$onGroupGetUser, {
                limit: this.getAttribute('user-limit'),
                page: this.getAttribute('user-page'),
                field: this.getAttribute('user-field'),
                order: this.getAttribute('user-order')
            });

            return this;
        },

        /**
         * if users return for the user grid
         *
         * @param {Array} result - user list
         */
        $onGroupGetUser: function(result) {
            if (typeof this.$UserGrid === 'undefined') {
                return;
            }

            if (typeof result.data === 'undefined') {
                return;
            }

            for (var i = 0, len = result.data.length; i < len; i++) {
                if (result.data[i].active == 1) {
                    result.data[i].status = new Element('div', {
                        'class': 'fa fa-check',
                        styles: {
                            margin: '5px 0 5px 12px'
                        }
                    });

                } else {
                    result.data[i].status = new Element('div', {
                        'class': 'fa fa-remove',
                        styles: {
                            margin: '5px 0 5px 12px'
                        }
                    });
                }
            }

            this.$UserGrid.setData(result);
            this.Loader.hide();
        },

        /**
         * Add one or more users to the groups
         */
        $onUsersAdd: function() {
            var self = this;

            require([
                'controls/users/search/Window'
            ], function(UserSearchWindow) {
                new UserSearchWindow({
                    search: true,
                    searchSettings: {
                        filter: {
                            filter_groups_exclude: [self.$Group.getId()]
                        }
                    },
                    events: {
                        onSubmit: function(Control, users) {
                            var userIds = [];

                            for (var i = 0, len = users.length; i < len; i++) {
                                userIds.push(users[i].id);
                            }

                            Groups.addUsers(self.$Group.getId(), userIds).then(function() {
                                self.refreshUser();
                            });
                        }
                    }
                }).open();
            });
        },

        /**
         * Remove one or more users from this group
         */
        $onUsersRemove: function() {
            var self = this;
            var userIds = [];
            var users = [];
            var selected = this.$UserGrid.getSelectedIndices();

            if (!selected.length) {
                return;
            }

            for (var i = 0, len = selected.length; i < len; i++) {
                var User = this.$UserGrid.getDataByRow(selected[i]);

                userIds.push(User.id);
                users.push(User.username + ' (#' + User.id + ')');
            }

            this.Loader.show();

            require([
                'qui/controls/windows/Confirm'
            ], function(QUIConfirm) {
                new QUIConfirm({
                    autoclose: true,
                    title: QUILocale.get('quiqqer/core', 'controls.group.deleteusers.confirm.title'),
                    texticon: 'fa fa-user-times',
                    icon: 'fa fa-user-times',

                    information: QUILocale.get(
                        'quiqqer/core',
                        'controls.group.deleteusers.confirm.info', {
                            groupId: self.$Group.getId(),
                            groupName: self.$Group.getName(),
                            users: users.join(', ')
                        }
                    ),

                    cancel_button: {
                        text: false,
                        textimage: 'fa fa-remove'
                    },
                    ok_button: {
                        text: false,
                        textimage: 'fa fa-check'
                    },
                    events: {
                        onSubmit: function(Confirm) {
                            Confirm.Loader.show();

                            Groups.removeUsers(self.$Group.getId(), userIds).then(function() {
                                self.refreshUser();
                                Confirm.Loader.hide();
                            });
                        }
                    }
                }).open();

                self.Loader.hide();
            });
        }
    });
});
