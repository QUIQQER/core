/**
 * A User Panel
 * Here you can change / edit the user
 *
 * @event onQuiqqerUserPanelCreate [self] - Fires when the User panel is created
 */
define('controls/users/User', [

    'qui/QUI',
    'qui/controls/desktop/Panel',

    'qui/controls/buttons/Button',
    'qui/controls/buttons/ButtonSwitch',
    'qui/controls/buttons/ButtonMultiple',

    'qui/controls/windows/Confirm',
    'controls/grid/Grid',
    'qui/utils/Form',
    'utils/Controls',
    'Users',
    'Ajax',
    'Locale',
    'Editors',

    'css!controls/users/User.css',
    'css!controls/desktop/panels/XML.css'

], function (QUI, QUIPanel, QUIButton, QUIButtonSwitch, QUIButtonMultiple, QUIConfirm, Grid,
             FormUtils, ControlUtils, Users, QUIAjax, QUILocale, Editors
) {
    'use strict';

    const lg = 'quiqqer/core';

    /**
     * @class controls/users/User
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QUIPanel,
        Type: 'controls/users/User',

        Binds: [
            'openPermissions',
            'savePassword',
            'generatePassword',

            '$onCreate',
            '$onDestroy',
            '$onStatusButtonChange',
            '$onButtonActive',
            '$onButtonNormal',
            '$onUserRefresh',
            '$onUserDelete',
            '$onClickSave',
            '$onClickDel',
            '$onClickSendMail',
            '$refreshAuthenticator'
        ],

        initialize: function (uid, options) {
            this.$uid = uid;
            this.$userId = uid;
            this.$savePromise = null;
            this.setAttribute('name', 'user-panel-' + uid);
            this.setAttribute('#id', 'user-panel-' + uid);

            QUI.Controls.$cids[this.$uid] = this;

            this.$AddressGrid = null;
            this.$PasswordGenerateButton = null;
            this.parent(options);

            this.addEvents({
                onCreate: this.$onCreate,
                onDestroy: this.$onDestroy,
                onShow: function () {
                    const Status = this.getButtons('status');

                    if (Status) {
                        Status.resize();
                    }
                }
            });

            Users.addEvent('onDelete', this.$onUserDelete);
        },

        /**
         * Save the group panel to the workspace
         *
         * @return {Object} data
         */
        serialize: function () {
            return {
                attributes: this.getAttributes(),
                userid: this.getUser().getId(),
                type: this.getType()
            };
        },

        /**
         * import the saved data form the workspace
         *
         * @param {Object} data
         * @return {Object} this (controls/users/User)
         */
        unserialize: function (data) {
            this.setAttributes(data.attributes);

            this.$uid = data.userid;
            this.$userId = data.userid;

            this.setAttribute('name', 'user-panel-' + data.userid);
            this.setAttribute('#id', 'user-panel-' + data.userid);

            QUI.Controls.$cids[this.$uid] = this;

            return this;
        },

        /**
         * Return the user of the panel
         *
         * @return {Object} classes/users/User
         */
        getUser: function () {
            return Users.get(this.$userId);
        },

        /**
         * Opens the user permissions
         */
        openPermissions: function () {
            const Parent = this.getParent(),
                User = this.getUser();

            require(['controls/permissions/Panel'], function (PermPanel) {
                Parent.appendChild(
                    new PermPanel({
                        'Object': User
                    })
                );
            });
        },

        /**
         * Create the user panel
         */
        $onCreate: function () {
            const self = this;

            this.getElm().classList.add('quiqqer-xml-panel');
            this.Loader.show();

            this.addButton({
                name: 'userSave',
                text: QUILocale.get(lg, 'users.user.btn.save'),
                textimage: 'fa fa-save',
                events: {
                    onClick: this.$onClickSave
                }
            });

            this.addButton({
                type: 'separator'
            });

            this.addButton(
                new QUIButtonSwitch({
                    name: 'status',
                    text: QUILocale.get('quiqqer/core', 'isActivate'),
                    status: true,
                    disabled: true,
                    events: {
                        onChange: this.$onStatusButtonChange
                    }
                })
            );

            const ExtrasBtn = new QUIButtonMultiple({
                name: 'extra',
                textimage: 'fa fa-caret-down',
                title: QUILocale.get(lg, 'quiqqer.customer.panel.extras.title'),
                events: {
                    onClick: function () {
                        ExtrasBtn.getMenu().then(function (Menu) {
                            const pos = ExtrasBtn.getElm().getPosition(),
                                size = ExtrasBtn.getElm().getSize();

                            Menu.setAttribute('corner', 'topRight');

                            ExtrasBtn.openMenu().then(function () {
                                Menu.setPosition(
                                    pos.x - 150,
                                    pos.y + size.y + 10
                                );
                            });
                        });
                    }
                },
                styles: {
                    'float': 'right'
                }
            });

            ExtrasBtn.appendChild({
                name: 'sendMail',
                title: QUILocale.get(lg, 'users.user.btn.sendMail'),
                text: QUILocale.get(lg, 'users.user.btn.sendMail'),
                icon: 'fa fa-envelope',
                disabled: true,
                events: {
                    onClick: this.$onClickSendMail
                }
            });

            ExtrasBtn.appendChild({
                name: 'userDelete',
                title: QUILocale.get(lg, 'users.user.btn.delete'),
                text: QUILocale.get(lg, 'users.user.btn.delete'),
                icon: 'fa fa-trash-o',
                events: {
                    onClick: this.$onClickDel
                }
            });

            ExtrasBtn.getElm().addClass('quiqqer-quiqqer-user-mail-extrasbtn');

            this.addButton(ExtrasBtn);

            // permissions
            new QUIButton({
                image: 'fa fa-shield',
                alt: QUILocale.get(lg, 'users.user.btn.permissions.alt'),
                title: QUILocale.get(lg, 'users.user.btn.permissions.title'),
                events: {
                    onClick: this.openPermissions
                },
                styles: {
                    'float': 'right',
                    'border-left-width': 1,
                    'border-right-width': 1,
                    'width': 40,
                    'outline': 0
                }
            }).inject(this.getHeader());

            Users.addEvent('onRefresh', this.$onUserRefresh);
            Users.addEvent('onSave', this.$onUserRefresh);
            Users.addEvent('switchStatus', this.$onUserRefresh);


            QUIAjax.get('ajax_users_getCategories', function (result) {
                let i, len;
                const User = self.getUser(),
                    Status = self.getButtons('status');

                for (i = 0, len = result.length; i < len; i++) {
                    result[i].events = {
                        onActive: self.$onButtonActive,
                        onNormal: self.$onButtonNormal
                    };

                    self.addCategory(result[i]);
                }

                self.setAttribute('icon', 'fa fa-user');

                User.loadIfNotLoaded().then(function () {
                    self.setAttribute('title', QUILocale.get(lg, 'users.user.title', {
                        username: User.getAttribute('username')
                    }));

                    self.refresh();

                    const Active = self.getCategoryBar().getActive();

                    if (!Active) {
                        self.getCategoryBar().firstChild().click();
                    }

                    if (User.isActive() === -1) {
                        Status.setSilentOff();
                        Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
                        Status.disable();
                        return;
                    }

                    Status.enable();

                    if (!User.isActive()) {
                        Status.off();
                        Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
                    } else {
                        Status.on();
                        Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isActivate'));
                    }
                });

                require(['Permissions'], function (Permissions) {
                    Permissions.hasPermission('quiqqer.admin.users.send_mail').then(function (canSendUserMail) {
                        const btnChildren = ExtrasBtn.getChildren();
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

                        if (canSendUserMail) {
                            SendMailBtn.enable();
                        } else {
                            SendMailBtn.getElm().set(
                                'title',
                                QUILocale.get(lg, 'users.user.btn.sendMail.no_permission')
                            );
                        }
                    });
                });

                QUI.fireEvent('quiqqerUserPanelCreate', [self]);
            }, {
                uid: this.getUser().getId(),
                onError: function () {
                    self.destroy();
                }
            });
        },

        /**
         * the panel on destroy event
         * remove the binded events
         */
        $onDestroy: function () {
            Users.removeEvent('refresh', this.$onUserRefresh);
            Users.removeEvent('save', this.$onUserRefresh);
            Users.removeEvent('switchStatus', this.$onUserRefresh);
            Users.removeEvent('delete', this.$onUserDelete);
        },

        /**
         * Refresh the current display
         * -> execute no unload
         * -> uses the current user data
         */
        refreshDisplay: function () {
            let Active = this.getCategoryBar().getActive();

            if (!Active) {
                Active = this.getCategoryBar().firstChild();
            }

            this.$onButtonActive(Active);
        },

        /**
         * the button active event
         * load the template of the category, parse the controls and insert the values
         *
         * @param {Object} Btn - qui/controls/buttons/Button
         */
        $onButtonActive: function (Btn) {
            const self = this,
                Body = self.getBody(),
                User = self.getUser(),
                attributes = User.getAttributes();

            this.Loader.show();

            this.$hideCurrentContent().then(function () {
                return self.$getCategoryContent(Btn);
            }).then(function (result) {
                if (!result) {
                    result = '';
                }

                Body.set('html', '<form>' + result + '</form>');

                const Form = Body.getElement('form');

                Form.setStyles({
                    display: 'inline-block',
                    opacity: 0,
                    position: 'relative',
                    top: -50,
                    width: '100%'
                });

                // insert the values
                const extras = JSON.decode(attributes.extra);
                const normalizedAttributes = Object.clone(attributes);

                if (normalizedAttributes.toolbar && normalizedAttributes.toolbar.indexOf(':') === -1) {
                    normalizedAttributes.toolbar = normalizedAttributes.toolbar.replace('.xml', '');

                    if (normalizedAttributes.toolbar !== '') {
                        normalizedAttributes.toolbar = 'quiqqer/ckeditor4:' + normalizedAttributes.toolbar;
                    }
                }

                if (normalizedAttributes.assigned_toolbar) {
                    normalizedAttributes.assigned_toolbar = normalizedAttributes.assigned_toolbar.split(',').map(function (toolbar) {
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

                FormUtils.setDataToForm(extras, Form);
                FormUtils.setDataToForm(normalizedAttributes, Form);

                Body.getElements('[data-qui]').set({
                    'data-qui-options-uid': self.getUser().getId()
                });

                // parse all the controls
                return QUI.parse(Body);
            }).then(function () {
                return ControlUtils.parse(self.getBody());
            }).then(() => {
                const Created = self.getBody().getElement('[name="regdate"]');
                const LastEdit = self.getBody().getElement('[name="lastedit"]');
                const LastVisit = self.getBody().getElement('[name="lastvisit"]');

                const dateOptions = {
                    year: 'numeric',
                    month: 'numeric',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: 'numeric',
                    second: 'numeric',
                    hour12: false
                };

                if (Created && Created.value !== '' && parseInt(Created.value) !== 0) {
                    try {
                        Created.value = QUILocale.getDateTimeFormatter(dateOptions).format(
                            new Date(Created.value * 1000)
                        );
                    } catch (e) {
                    }
                } else {
                    if (Created) {
                        Created.value = '---';
                    }
                }

                if (LastEdit && LastEdit.value !== '' && parseInt(LastEdit.value) !== 0) {
                    try {
                        LastEdit.value = QUILocale.getDateTimeFormatter(dateOptions).format(
                            new Date(LastEdit.value)
                        );
                    } catch (e) {
                    }
                } else {
                    if (LastEdit) {
                        LastEdit.value = '---';
                    }
                }

                if (LastVisit && LastVisit.value !== '' && parseInt(LastVisit.value) !== 0) {
                    try {
                        LastVisit.value = QUILocale.getDateTimeFormatter(dateOptions).format(
                            new Date(LastVisit.value * 1000)
                        );
                    } catch (e) {
                    }
                } else {
                    if (LastVisit) {
                        LastVisit.value = '---';
                    }
                }

                QUI.Controls.getControlsInElement(Body).each(function (Control) {
                    Control.setAttribute('Panel', self);
                    Control.setAttribute('uid', self.getUser().getId());
                    Control.setAttribute('User', self.getUser());

                    if (!('setValue' in Control)) {
                        return;
                    }

                    const name = Control.getAttribute('name');

                    if (!name || name === '') {
                        return;
                    }

                    if (name in attributes) {
                        Control.setValue(attributes[name]);
                    }
                });

                // password save
                let i, len;

                const PasswordField = Body.getElement('input[name="password2"]'),
                    PasswordExpire = Body.getElements('input[name="expire"]'),
                    ShowPasswords = Body.getElement('input[name="showPasswords"]'),
                    Toolbar = Body.getElement('[name="toolbar"]'),
                    Groups = Body.getElement('[name="usergroup"]'),
                    AddressList = Body.querySelector('[data-name="address-list"]'),
                    authenticators = Array.from(Body.querySelectorAll('[data-name="authenticator"]'));

                if (PasswordField) {
                    PasswordField.setStyle('float', 'left');

                    this.$PasswordGenerateButton = new QUIButton({
                        textimage: 'fa fa-lock',
                        text: QUILocale.get(lg, 'users.user.btn.password.generate'),
                        events: {
                            onClick: self.generatePassword
                        }
                    }).inject(PasswordField, 'after');

                    ShowPasswords.addEvent('change', function () {
                        const PasswordFields = Body.getElements(
                            '[name="password2"],[name="password"]'
                        );

                        if (this.checked) {
                            PasswordFields.set('type', 'text');
                            return;
                        }

                        PasswordFields.set('type', 'password');
                    });

                    if (ShowPasswords.checked) {
                        ShowPasswords.checked = false;
                    }

                    // has a password?
                    if (!self.getUser().getAttribute('hasPassword')) {
                        new Element('tr', {
                            html: '<td colspan="2">' +
                                '    <div class="content-message-error">' +
                                QUILocale.get('quiqqer/core', 'message.user.has.no.password') +
                                '    </div>' +
                                '</td>'
                        }).inject(Body.getElement('tbody'));
                    }
                }

                // Generate and send new password btn
                const GenerateAndSendContainer = Body.querySelector('[data-name="generate-and-send-password"]');

                if (GenerateAndSendContainer) {
                    new QUIButton({
                        title: QUILocale.get(lg, 'users.user.btn.password.generateAndSend'),
                        text: QUILocale.get(lg, 'users.user.btn.password.generateAndSend'),
                        textimage: 'fa fa-asterisk',
                        events: {
                            onClick: function () {
                                require(['controls/users/password/send/SendPassword'], function (SendPassword) {
                                    new SendPassword({
                                        userId: User.getId()
                                    }).open();
                                });
                            }
                        }
                    }).inject(GenerateAndSendContainer);
                }

                // authenticator
                if (authenticators) {
                    let cls, text, title, enabled,
                        button, settingButton;

                    for (i = 0, len = authenticators.length; i < len; i++) {
                        enabled = false;
                        title = QUILocale.get('quiqqer/core', 'isDeactivate');
                        text = QUILocale.get('quiqqer/core', 'isDeactivate');
                        cls = 'btn-red';

                        if (authenticators[i].classList.contains('authenticator-enabled')) {
                            enabled = true;
                            title = QUILocale.get('quiqqer/core', 'isActivate');
                            text = QUILocale.get('quiqqer/core', 'isActivate');
                            cls = 'btn-green';
                        }

                        button = document.createElement('button');
                        button.classList.add('btn', 'btn-secondary', 'qui-button', 'quiqqer-user-authenticator-status', cls);
                        button.type = 'button';
                        button.dataset.name = 'authenticator-status';
                        button.setAttribute('aria-pressed', String(enabled));
                        button.title = text;
                        button.textContent = text;
                        button.style.float = 'right';

                        button.addEventListener('click', (e) => {
                            e.stopPropagation();
                            e.preventDefault();

                            const button = e.currentTarget;

                            this.$toggleAuthentication(button).catch((err) => {
                                console.error(err);
                            });
                        });

                        authenticators[i].querySelector('[data-name="authenticator-title"]').appendChild(button);


                        if (authenticators[i].getAttribute('data-settings')) {
                            settingButton = document.createElement('button');
                            settingButton.classList.add(
                                'btn',
                                'btn-secondary',
                                'qui-button',
                                'quiqqer-user-authenticator-settings'
                            );
                            settingButton.type = 'button';
                            settingButton.name = 'settings';
                            settingButton.dataset.name = 'authenticator-settings';
                            settingButton.setAttribute('aria-label', QUILocale.get(
                                lg, 'user.settings.authenticators.additionalSettings'
                            ));
                            settingButton.innerHTML = '<span class="fa fa-gears" aria-hidden="true"></span>';
                            settingButton.style.float = 'right';
                            settingButton.style.marginRight = '10px';


                            settingButton.addEventListener('click', (e) => {
                                e.stopPropagation();
                                e.preventDefault();

                                this.$openAuthSettings(e.currentTarget);
                            });

                            settingButton.disabled = !authenticators[i].classList.contains('authenticator-enabled');

                            authenticators[i].querySelector('[data-name="authenticator-title"]').appendChild(settingButton);
                        }
                    }
                }

                // password expire
                if (PasswordExpire.length) {
                    const expire = attributes.expire || false;

                    if (!expire || expire === '0000-00-00 00:00:00' || expire === 'never') {
                        PasswordExpire[0].checked = true;
                    } else {
                        PasswordExpire[1].checked = true;
                        Body.getElement('input[name="expire_date"]').value = expire;
                    }
                }

                if (AddressList) {
                    self.$createAddressTable();
                }

                if (Toolbar) {
                    const AssignedToolbar = QUI.Controls.getById(
                        Body.getElement('[name="assigned_toolbar"]').get('data-quiid')
                    );

                    const renderToolbars = function () {
                        return Editors.getToolbarsFromUser(
                            self.getUser().getId(),
                            AssignedToolbar.getValue()
                        ).then(function (toolbars) {
                            Toolbar.set('html', '');

                            new Element('option', {
                                value: '',
                                html: ''
                            }).inject(Toolbar);

                            for (i = 0, len = toolbars.length; i < len; i++) {
                                let label = toolbars[i];

                                if (label.indexOf(':') !== -1) {
                                    label = label.split(':').pop();
                                }

                                new Element('option', {
                                    value: toolbars[i],
                                    html: label
                                }).inject(Toolbar);
                            }

                            Toolbar.value = User.getAttribute('toolbar');

                            if (Toolbar.value === '' && toolbars.length) {
                                Toolbar.value = toolbars[0];
                            }
                        });
                    };

                    renderToolbars();

                    AssignedToolbar.addEvent('change', renderToolbars);
                }

                if (Groups) {
                    const GroupControl = QUI.Controls.getById(Groups.get('data-quiid'));
                    const normalizeGroups = function (value) {
                        if (!value) {
                            return '';
                        }

                        return String(value).split(',').filter(function (groupId) {
                            return groupId !== '';
                        }).join(',');
                    };

                    let userGroups = normalizeGroups(self.getUser().getAttribute('usergroup'));

                    GroupControl.addEvent('change', function () {
                        const groupValue = normalizeGroups(GroupControl.getValue());

                        if (groupValue === userGroups) {
                            return;
                        }

                        self.Loader.show();
                        self.$onClickSave().then(function () {
                            userGroups = groupValue;

                            QUI.Controls.getById(
                                Body.getElement('[name="assigned_toolbar"]').get('data-quiid')
                            ).fireEvent('change');
                        }).then(function () {
                            self.Loader.hide();
                        });
                    });
                }

                if (!Btn.getAttribute('onload_require') && !Btn.getAttribute('onload')) {
                    self.Loader.hide();
                    self.$showCurrentContent();
                    return;
                }

                // require onload
                try {
                    const exec = Btn.getAttribute('onload'),
                        req = Btn.getAttribute('onload_require');

                    if (req) {
                        require([req], function (result) {
                            self.Loader.hide();

                            if (typeOf(result) === 'class') {
                                new result(self);
                            }

                            if (typeOf(result) === 'function') {
                                result(self);
                            }

                            if (exec) {
                                eval(exec + '(self)');
                            }

                            self.$showCurrentContent();
                        });
                        return;
                    }

                    eval(exec + '( self )');

                    self.$showCurrentContent();
                    self.Loader.hide();
                } catch (Exception) {
                    console.error('some error occurred ' + Exception.getMessage());
                    self.$showCurrentContent();
                    self.Loader.hide();
                }
            });
        },

        /**
         * hide the current body content
         *
         * @returns {Promise}
         */
        $hideCurrentContent: function () {
            const self = this;

            return new Promise(function (resolve) {
                const Body = self.getBody(),
                    Form = Body.getElement('form');

                if (!Form) {
                    resolve();
                    return;
                }

                Form.setStyle('position', 'relative');

                moofx(Form).animate({
                    opacity: 0,
                    top: -50
                }, {
                    duration: 250,
                    callback: resolve
                });
            });
        },

        /**
         * show the current body content
         *
         * @returns {Promise}
         */
        $showCurrentContent: function () {
            const self = this;

            return new Promise(function (resolve) {
                const Body = self.getBody(),
                    Form = Body.getElement('form');

                if (!Form) {
                    resolve();
                    return;
                }

                Form.setStyle('position', 'relative');

                moofx(Form).animate({
                    opacity: 1,
                    top: 0
                }, {
                    duration: 250,
                    callback: resolve
                });
            });
        },

        /**
         * Return the category content
         *
         * @param {Object} Btn
         * @returns {Promise}
         */
        $getCategoryContent: function (Btn) {
            const self = this;

            return new Promise(function (resolve) {
                QUIAjax.get('ajax_users_getCategory', resolve, {
                    Tab: Btn,
                    plugin: Btn.getAttribute('plugin'),
                    tab: Btn.getAttribute('name'),
                    uid: self.getUser().getId()
                });
            });
        },

        /**
         * if the button was active and know normal
         * = unload event of the button
         */
        $onButtonNormal: function () {
            const Content = this.getBody(),
                Frm = Content.getElement('form'),
                data = FormUtils.getFormData(Frm);

            if (data.expire_date) {
                data.expire = data.expire_date;
            }

            this.getUser().setAttributes(data);
        },

        /**
         * Refresh the Panel if the user is refreshed
         */
        $onUserRefresh: function (Users, User) {
            const userId = this.getUser().getId();

            if (User && User.getId && User.getId() != userId) {
                return;
            }

            if (User && !User.getId && typeof User === 'object' && typeof User[userId] === 'undefined') {
                return;
            }

            this.setAttribute('title', QUILocale.get(lg, 'users.user.title', {
                username: this.getUser().getAttribute('username')
            }));

            let Active = this.getCategoryBar().getActive(),
                Status = this.getButtons('status');

            if (this.getUser().isActive() === -1) {
                Status.enable();
                Status.setSilentOff();
                Status.disable();

                this.setAttribute('icon', 'fa fa-user');
                this.refresh();

                if (!Active) {
                    Active = this.getCategoryBar().firstChild();
                }

                if (Active) {
                    Active.click();
                }
                return;
            }

            if (this.getUser().isActive()) {
                Status.setSilentOn();
                Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isActivate'));
            } else {
                Status.setSilentOff();
                Status.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
            }

            this.setAttribute('icon', 'fa fa-user');
            this.refresh();

            if (!Active) {
                Active = this.getCategoryBar().firstChild();
            }

            if (Active) {
                Active.click();
            }
        },

        /**
         * event: of button status on / off changes
         *
         * @param {Object} Button - qui/controls/buttons/ButtonSwitch
         */
        $onStatusButtonChange: function (Button) {
            const buttonStatus = Button.getStatus(),
                User = this.getUser(),
                userStatus = User.isActive();

            if (buttonStatus === userStatus || userStatus === -1) {
                return;
            }

            this.Loader.show();

            let Prom;

            if (buttonStatus) {
                Prom = User.activate();
            } else {
                Prom = this.userDeactivation();
            }

            Prom.then(function () {
                if (User.isActive() === -1) {
                    Button.disable();
                    this.Loader.hide();
                    return;
                }

                if (User.isActive()) {
                    Button.on();
                    Button.setAttribute('text', QUILocale.get('quiqqer/core', 'isActivate'));
                } else {
                    Button.off();
                    Button.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
                }

                this.Loader.hide();
            }.bind(this)).catch(function () {
                if (User.isActive() === -1) {
                    Button.disable();
                    this.Loader.hide();
                    return;
                }

                if (User.isActive()) {
                    Button.setSilentOn();
                    Button.setAttribute('text', QUILocale.get('quiqqer/core', 'isActivate'));
                } else {
                    Button.setSilentOff();
                    Button.setAttribute('text', QUILocale.get('quiqqer/core', 'isDeactivate'));
                }

                this.Loader.hide();
            }.bind(this));
        },

        /**
         * event on user delete
         *
         * @param {Object} Users - qui/classes/users/Manager
         * @param {Array} uids - user ids, which are deleted
         */
        $onUserDelete: function (Users, uids) {
            const uid = this.getUser().getId();

            for (let i = 0, len = uids.length; i < len; i++) {
                if (uid == uids[i]) {
                    this.destroy();
                    break;
                }
            }
        },

        /**
         * Event: click on save
         *
         * @method controls/users/User#$onClickSave
         */
        $onClickSave: function () {
            if (this.$savePromise) {
                return this.$savePromise;
            }

            const Active = this.getActiveCategory(),
                User = this.getUser(),
                SaveButton = this.getButtons('userSave');

            if (Active) {
                this.$onButtonNormal(Active);
            }

            this.Loader.show();

            if (SaveButton) {
                SaveButton.disable();
            }

            let PassWordSave = Promise.resolve();

            if (Active && Active.getAttribute('name') === 'security') {
                PassWordSave = this.savePassword();
            }

            this.$savePromise = PassWordSave.then(function () {
                return User.save();
            }).then(function () {
                if (SaveButton) {
                    SaveButton.enable();
                }

                this.Loader.hide();
            }.bind(this)).catch(function (err) {
                if (SaveButton) {
                    SaveButton.enable();
                }

                this.Loader.hide();
                throw err;
            }.bind(this)).finally(function () {
                this.$savePromise = null;
            }.bind(this));

            return this.$savePromise;
        },

        /**
         * Event: click on delete
         *
         * @method controls/users/User#$onClickDel
         */
        $onClickDel: function () {
            const uid = this.getUser().getId();
            const username = this.getUser().getAttribute('username');
            const List = new Element('ul');

            new Element('li', {
                'class': 'user-delete-window-list-entry',
                html: '<span class="user-delete-window-list-entry-username">' + username + '</span>' +
                    '<span class="user-delete-window-list-entry-uuid">' + uid + '</span>'
            }).inject(List);

            new QUIConfirm({
                name: 'DeleteUsers',
                icon: 'fa fa-trash-o',
                texticon: 'fa fa-trash-o',
                title: QUILocale.get(lg, 'users.panel.delete.window.title'),
                text: QUILocale.get(lg, 'users.panel.delete.window.text'),
                information: QUILocale.get(lg, 'users.panel.delete.window.information'),
                maxWidth: 700,
                maxHeight: 400,
                events: {
                    onOpen: (Win) => {
                        const Header = Win.getContent().getElement('.text');

                        List.inject(Header, 'after');
                    },
                    onSubmit: function (Win) {
                        Win.Loader.show();
                        Users.deleteUsers([uid]).then(function () {
                            Win.close();
                        });
                    }
                }
            }).open();
        },

        /**
         * Open "send user mail" dialog
         */
        $onClickSendMail: function () {
            const uid = this.getUser().getId();

            require(['controls/users/mail/SendUserMail'], function (SendUserMail) {
                new SendUserMail({
                    userId: uid
                }).open();
            });
        },

        /**
         * Saves the password to the user
         * only triggerd if the password tab are open
         *
         * @method controls/users/User#savePassword
         * @return {Promise}
         */
        savePassword: function () {
            return new Promise(function (resolve, reject) {
                const Body = this.getBody(),
                    Form = Body.getElement('form'),
                    Pass1 = Form.elements.password,
                    Pass2 = Form.elements.password2;

                if (!Pass1 || !Pass2) {
                    return reject();
                }

                this.getUser().savePassword(Pass1.value, Pass2.value).then(function () {
                    resolve();
                }).catch(function (err) {
                    QUI.getMessageHandler().then(function (MH) {
                        if (typeOf(err) === 'string') {
                            MH.addError(err);
                            return;
                        }

                        if (typeof err.getMessage === 'function') {
                            MH.addError(err.getMessage());
                        }
                    });

                    reject(err);
                });
            }.bind(this));
        },

        /**
         * Generate a random password and set it to the password fields
         * it saves not the passwords!!
         */
        generatePassword: function () {
            const Body = this.getBody(),
                Form = Body.getElement('form'),
                Pass1 = Form.elements.password,
                Pass2 = Form.elements.password2,
                Show = Form.elements.showPasswords,
                Button = this.$PasswordGenerateButton;

            if (!Pass1 || !Pass2) {
                return;
            }

            const resetLoadingState = () => {
                Pass1.disabled = false;
                Pass2.disabled = false;
                Button.setAttribute('textimage', 'fa fa-lock');
                Button.enable();
            };

            Pass1.disabled = true;
            Pass2.disabled = true;
            Button.setAttribute('textimage', 'fa fa-spinner fa-spin');
            Button.disable();

            QUIAjax.get('ajax_user_generateRandomPassword', (newPassword) => {
                Pass1.value = newPassword;
                Pass2.value = newPassword;

                if (!Show.checked) {
                    Show.checked = true;
                    Show.fireEvent('change');
                }

                resetLoadingState();
            }, {
                'package': 'quiqqer/core',
                onError: resetLoadingState
            });
        },

        /**
         * Addresses
         */

        /**
         * Create the address table
         */
        $createAddressTable: function () {
            const self = this,
                Content = this.getContent(),
                size = Content.getSize(),
                AddressList = Content.querySelector('[data-name="address-list"]');

            if (!AddressList) {
                return;
            }

            this.$AddressGrid = new Grid(AddressList, {
                columnModel: [
                    {
                        header: '&nbsp;',
                        dataIndex: 'default',
                        dataType: 'node',
                        width: 40
                    },
                    {
                        header: QUILocale.get(lg, 'id'),
                        dataIndex: 'id',
                        dataType: 'string',
                        width: 60
                    },
                    {
                        header: QUILocale.get(lg, 'salutation'),
                        dataIndex: 'salutation',
                        dataType: 'string',
                        width: 60
                    },
                    {
                        header: QUILocale.get(lg, 'firstname'),
                        dataIndex: 'firstname',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'lastname'),
                        dataIndex: 'lastname',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'users.user.address.table.phone'),
                        dataIndex: 'phone',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'email'),
                        dataIndex: 'mail',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'company'),
                        dataIndex: 'company',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'street'),
                        dataIndex: 'street_no',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'zip'),
                        dataIndex: 'zip',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'city'),
                        dataIndex: 'city',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'country'),
                        dataIndex: 'country',
                        dataType: 'string',
                        width: 100
                    }
                ],

                buttons: [
                    {
                        name: 'add',
                        text: QUILocale.get(lg, 'users.user.address.table.btn.add'),
                        textimage: 'fa fa-plus',
                        events: {
                            onClick: function () {
                                self.createAddress();
                            }
                        }
                    },
                    {
                        type: 'separator'
                    },
                    {
                        name: 'edit',
                        text: QUILocale.get(lg, 'users.user.address.table.btn.edit'),
                        textimage: 'fa fa-edit',
                        disabled: true,
                        events: {
                            onClick: function () {
                                self.editAddress(
                                    self.$AddressGrid.getSelectedData()[0].id
                                );
                            }
                        }
                    },
                    {
                        name: 'delete',
                        text: QUILocale.get(lg, 'users.user.address.table.btn.delete'),
                        textimage: 'fa fa-remove',
                        disabled: true,
                        events: {
                            onClick: function () {
                                self.deleteAddress(
                                    self.$AddressGrid.getSelectedData()[0].id
                                );
                            }
                        }
                    }
                ],

                height: 300,
                onrefresh: function () {
                    self.$refreshAddresses();
                }
            });

            this.$AddressGrid.addEvents({
                onClick: function () {
                    const buttons = self.$AddressGrid.getButtons(),
                        sels = self.$AddressGrid.getSelectedIndices();

                    if (!sels) {
                        buttons.each(function (Btn) {
                            if (Btn.getAttribute('name') !== 'add') {
                                Btn.disable();
                            }
                        });

                        return;
                    }

                    buttons.each(function (Btn) {
                        Btn.enable();
                    });
                },

                onDblClick: function () {
                    self.editAddress(
                        self.$AddressGrid.getSelectedData()[0].id
                    );
                }
            });

            this.$AddressGrid.setWidth(size.x - 60);
            this.$AddressGrid.refresh();
        },

        /**
         * Load / refresh the adresses
         */
        $refreshAddresses: function () {
            if (!this.$AddressGrid) {
                return;
            }

            const self = this;

            QUIAjax.get('ajax_users_address_list', function (result) {
                for (let i = 0, len = result.length; i < len; i++) {
                    if (result[i].default) {
                        result[i].default = new Element('span', {
                            'class': 'fa fa-check'
                        });
                    } else {
                        result[i].default = new Element('span', {
                            html: '&nbsp;'
                        });
                    }
                }

                self.$AddressGrid.setData({
                    data: result
                });
            }, {
                uid: this.getUser().getId()
            });
        },

        /**
         * Creates a new address and opens the edit control
         */
        createAddress: function () {
            const self = this;

            QUIAjax.post('ajax_users_address_save', function (newId) {
                self.editAddress(newId);
                self.$AddressGrid.refresh();
            }, {
                uid: this.getUser().getId(),
                aid: 0,
                data: JSON.encode([])
            });
        },

        /**
         * Edit an address
         *
         * @param {Number} addressId - ID of the address
         */
        editAddress: function (addressId) {
            const self = this,
                Sheet = this.createSheet({
                    title: QUILocale.get(lg, 'users.user.address.edit'),
                    icon: 'fa fa-edit'
                });

            Sheet.addEvents({
                onOpen: function (Sheet) {
                    require(['controls/users/Address'], function (Address) {
                        const UserAddress = new Address({
                            addressId: addressId,
                            uid: self.getUser().getId(),
                            events: {
                                onSaved: function () {
                                    Sheet.hide();
                                    self.$AddressGrid.refresh();
                                }
                            }
                        }).inject(Sheet.getContent());

                        Sheet.addButton({
                            textimage: 'fa fa-save',
                            text: QUILocale.get(lg, 'save'),
                            events: {
                                onClick: function () {
                                    UserAddress.save();
                                }
                            }
                        });
                    });
                }
            });

            Sheet.show();
        },

        /**
         * Delete an address
         *
         * @param {Number} addressId - ID of the address
         */
        deleteAddress: function (addressId) {
            const self = this;

            new QUIConfirm({
                title: QUILocale.get(lg, 'users.user.address.window.delete.title'),
                text: QUILocale.get(lg, 'users.user.address.window.delete.text'),
                information: QUILocale.get(lg, 'users.user.address.window.delete.information'),
                events: {
                    onSubmit: function () {
                        QUIAjax.post('ajax_users_address_delete', function () {
                            self.$AddressGrid.refresh();
                        }, {
                            aid: addressId,
                            uid: self.getUser().getId()
                        });
                    }
                }
            }).open();
        },

        userDeactivation: function () {
            return new Promise((resolve, reject) => {
                const User = this.getUser();

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

                            User.deactivate().then(function () {
                                Win.close();
                                resolve();
                            });
                        },

                        onCancel: reject
                    }
                }).open();
            });
        },

        $toggleAuthentication: function (button) {
            const user = this.getUser();

            let table = button.closest('table'),
                tbody = table.querySelector('tbody'),
                auth = table.get('data-authenticator'),
                wasEnabled = table.hasClass('authenticator-enabled'),
                toggleStatus = Promise.resolve();

            button.style.width = button.offsetWidth + 'px';
            button.innerHTML = '<span class="fa fa-spinner fa-spin"></span>';

            if (wasEnabled) {
                toggleStatus = user.disableAuthenticator(auth);
            } else {
                toggleStatus = user.enableAuthenticator(auth);
            }

            return toggleStatus.then(() => {
                return user.hasAuthenticator(auth);
            }).then((enabled) => {
                button.setAttribute('aria-pressed', String(enabled));

                if (enabled) {
                    table.classList.add('authenticator-enabled');

                    button.style.width = '';
                    button.innerHTML = QUILocale.get('quiqqer/core', 'isActivate');
                    button.classList.remove('btn-red');
                    button.classList.add('btn-green');

                    if (table.querySelector('[name="settings"]')) {
                        table.querySelector('[name="settings"]').disabled = false;
                    }

                    if (!wasEnabled && auth === 'QUI\\Users\\Auth\\WebAuthn') {
                        const settingsButton = table.querySelector('[name="settings"]');

                        if (settingsButton) {
                            this.$openAuthSettings(settingsButton);
                        }
                    }

                    //return user.getAuthenticatorSettings(auth);
                    return;
                }

                table.classList.remove('authenticator-enabled');

                button.style.width = '';
                button.innerHTML = QUILocale.get('quiqqer/core', 'isDeactivate');
                button.classList.add('btn-red');
                button.classList.remove('btn-green');

                if (table.querySelector('[name="settings"]')) {
                    table.querySelector('[name="settings"]').disabled = true;
                }

                return false;
            }).then((settings) => {
                return;

                if (!settings || settings === '') {
                    if (tbody) {
                        tbody.destroy();
                    }
                    return;
                }

                if (!tbody) {
                    tbody = new Element('tbody', {
                        html: '<tr><td></td></tr>'
                    }).inject(table);
                }

                tbody.querySelector('td').innerHTML = settings;

                Array.from(tbody.querySelectorAll('[data-qui]')).forEach((node) => {
                    node.setAttribute('data-qui-options-uid', user.getId());
                });

                return QUI.parse(tbody);
            }).then(() => {
                QUI.Controls.getControlsInElement(tbody).each((Control) => {
                    Control.setAttribute('Panel', this);
                    Control.setAttribute('uid', this.getUser().getId());
                    Control.setAttribute('User', this.getUser());
                });
            }).catch(function (Exception) {
                console.error(Exception);
                button.innerHTML = '<span class="fa fa-bolt"></span>';
            });
        },

        $refreshAuthenticator: function (authenticator) {
            const user = this.getUser();
            const authenticators = Array.from(this.getBody().querySelectorAll('[data-name="authenticator"]'));

            let table = null;

            authenticators.some((entry) => {
                if (entry.get('data-authenticator') === authenticator) {
                    table = entry;
                    return true;
                }

                return false;
            });

            if (!table) {
                return Promise.resolve();
            }

            const button = table.querySelector('thead th > button:not([name="settings"])');
            const settingsButton = table.querySelector('thead th > button[name="settings"]');

            return user.hasAuthenticator(authenticator).then((enabled) => {
                if (button) {
                    button.setAttribute('aria-pressed', String(enabled));
                }

                if (enabled) {
                    table.classList.add('authenticator-enabled');

                    if (button) {
                        button.innerHTML = QUILocale.get('quiqqer/core', 'isActivate');
                        button.title = QUILocale.get('quiqqer/core', 'isActivate');
                        button.classList.remove('btn-red');
                        button.classList.add('btn-green');
                    }

                    if (settingsButton) {
                        settingsButton.disabled = false;
                    }

                    return;
                }

                table.classList.remove('authenticator-enabled');

                if (button) {
                    button.innerHTML = QUILocale.get('quiqqer/core', 'isDeactivate');
                    button.title = QUILocale.get('quiqqer/core', 'isDeactivate');
                    button.classList.add('btn-red');
                    button.classList.remove('btn-green');
                }

                if (settingsButton) {
                    settingsButton.disabled = true;
                }
            });
        },

        $openAuthSettings: function (button) {
            const user = this.getUser();
            const Panel = this;

            let table = button.closest('table'),
                auth = table.get('data-authenticator');

            require([
                'qui/controls/windows/Popup'
            ], (Popup) => {
                new Popup({
                    maxHeight: 700,
                    maxWidth: 800,
                    title: QUILocale.get(lg, 'user.settings.authenticators.additionalSettings'),
                    icon: 'fa fa-gears',
                    buttons: false,
                    events: {
                        onOpen: function (win) {
                            win.Loader.show();
                            win.getContent().innerHTML = '';

                            user.getAuthenticatorSettings(auth).then((settingHtml) => {
                                win.getContent().innerHTML = settingHtml;

                                return QUI.parse(win.getContent());
                            }).then(() => {
                                QUI.Controls.getControlsInElement(win.getContent()).each((Control) => {
                                    Control.setAttribute('uid', user.getId());
                                    Control.setAttribute('authenticator', auth);
                                    Control.setAttribute('User', user);
                                    Control.setAttribute('Panel', Panel);
                                });

                                win.Loader.hide();
                            });
                        },

                        onClose: function () {
                            if (auth !== 'QUI\\Users\\Auth\\WebAuthn') {
                                return;
                            }

                            QUIAjax.post('ajax_users_authenticator_webauthn_cleanupEmpty', () => {
                                Panel.$refreshAuthenticator(auth).catch((err) => {
                                    if (window.console) {
                                        console.error(err);
                                    }
                                });
                            }, {
                                userUuid: user.getAttribute('uuid'),
                                onError: (err) => {
                                    if (window.console) {
                                        console.error(err);
                                    }
                                }
                            });
                        }
                    }
                }).open();
            });
        }
    });
});
