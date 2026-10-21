/**
 * Address control
 * Edit and saves an user address
 *
 * @event onSaved [self]
 * @event onLoaded [self]
 * @event onLoadError [self, error]
 * @event onSaveError [self, error]
 */
define('controls/users/Address', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/windows/Confirm',
    'controls/grid/Grid',
    'Users',
    'Ajax',
    'Locale',

    'css!controls/users/Address.css'

], function (QUI, QUIControl, QUILoader, QUIConfirm, Grid, Users, Ajax, Locale) {
    "use strict";

    const lg = 'quiqqer/core';

    return new Class({

        Extends: QUIControl,
        Type: 'controls/users/Address',

        Binds: [
            '$onInject'
        ],

        options: {
            uid: false,
            addressId: false
        },

        initialize: function (options) {
            this.parent(options);

            this.Loader = new QUILoader();

            this.$PhoneGrid = null;
            this.$MailGrid = null;

            this.$Company = null;
            this.$Salutation = null;
            this.$Firstname = null;
            this.$Lastname = null;
            this.$StreetNo = null;
            this.$Zip = null;
            this.$City = null;
            this.$State = null;
            this.$Country = null;

            this.addEvents({
                onInject: this.$onInject,
                onDestroy: () => {
                    this.$addressDestroyed = true;
                }
            });
        },

        /**
         * create the node element
         *
         * @return {HTMLElement}
         */
        create: function () {
            this.$Elm = new Element('div', {
                'class': 'control-users-address box'
            });

            this.Loader.inject(this.$Elm);

            return this.$Elm;
        },

        /**
         * event : on inject
         */
        $onInject: function () {
            const self = this;

            this.Loader.show();

            Ajax.get([
                'ajax_users_address_template',
                'ajax_users_address_get'
            ], function (template, data) {
                if (self.$addressDestroyed) {
                    return;
                }

                const Elm = self.getElm();

                Elm.set('html', template);

                // objects
                self.$Company = Elm.getElement('[name="address-company"]');
                self.$Salutation = Elm.getElement('[name="address-salutation"]');
                self.$Firstname = Elm.getElement('[name="address-firstname"]');
                self.$Lastname = Elm.getElement('[name="address-lastname"]');
                self.$StreetNo = Elm.getElement('[name="address-street_no"]');
                self.$Zip = Elm.getElement('[name="address-zip"]');
                self.$City = Elm.getElement('[name="address-city"]');
                self.$State = Elm.querySelector('[data-name="state"]');
                self.$Country = Elm.getElement('[name="address-country"]');
                self.$Standard = Elm.getElement('[name="address-standard"]');

                self.$Company.value = data.company;
                self.$Salutation.value = data.salutation;
                self.$Firstname.value = data.firstname;
                self.$Lastname.value = data.lastname;
                self.$StreetNo.value = data.street_no;
                self.$Zip.value = data.zip;
                self.$City.value = data.city;
                self.$State.value = data.state ?? '';
                self.$Country.value = data.country;
                self.$Standard.checked = !!data.default;

                // tel fax handy grid
                self.$PhoneGrid = new Grid(Elm.getElement('.user-address-edit-tel'), {
                    columnModel: [{
                        header: Locale.get(lg, 'number'),
                        dataIndex: 'no',
                        dataType: 'string',
                        width: 200
                    }, {
                        header: Locale.get(lg, 'type'),
                        dataIndex: 'type',
                        dataType: 'string',
                        width: 200
                    }],
                    buttons: [{
                        name: 'add',
                        text: Locale.get(lg, 'users.address.btn.add'),
                        textimage: 'fa fa-plus',
                        events: {
                            onClick: function () {
                                self.openPhoneWindow();
                            }
                        }
                    }, {
                        type: 'separator'
                    }, {
                        name: 'edit',
                        text: Locale.get(lg, 'users.address.btn.edit'),
                        textimage: 'fa fa-edit',
                        disabled: true,
                        events: {
                            onClick: function () {
                                self.openPhoneWindow(
                                    self.$PhoneGrid.getSelectedIndices()[0]
                                );
                            }
                        }
                    }, {
                        name: 'delete',
                        text: Locale.get(lg, 'users.address.btn.delete'),
                        textimage: 'fa fa-remove',
                        disabled: true,
                        events: {
                            onClick: function () {
                                self.openPhoneDeleteWindow(
                                    self.$PhoneGrid.getSelectedIndices()[0]
                                );
                            }
                        }
                    }],
                    height: 200
                });

                self.$PhoneGrid.addEvents({
                    onClick: function () {
                        const buttons = self.$PhoneGrid.getButtons(),
                            sels = self.$PhoneGrid.getSelectedIndices();

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
                        self.openPhoneWindow(
                            self.$PhoneGrid.getSelectedIndices()[0]
                        );
                    }
                });


                // email grid
                self.$MailGrid = new Grid(Elm.getElement('.user-address-edit-mail'), {
                    columnModel: [{
                        header: Locale.get(lg, 'email'),
                        dataIndex: 'email',
                        dataType: 'string',
                        width: 200
                    }],
                    buttons: [{
                        name: 'add',
                        text: Locale.get(lg, 'users.address.mail.btn.add'),
                        textimage: 'fa fa-plus',
                        events: {
                            onClick: function () {
                                self.openEmailWindow();
                            }
                        }
                    }, {
                        type: 'separator'
                    }, {
                        name: 'edit',
                        text: Locale.get(lg, 'users.address.mail.btn.edit'),
                        textimage: 'fa fa-edit',
                        disabled: true,
                        events: {
                            onClick: function () {
                                self.openEmailWindow(
                                    self.$MailGrid.getSelectedIndices()[0]
                                );
                            }
                        }
                    }, {
                        name: 'delete',
                        text: Locale.get(lg, 'users.address.mail.btn.delete'),
                        textimage: 'fa fa-remove',
                        disabled: true,
                        events: {
                            onClick: function () {
                                self.openEmailDeleteWindow(
                                    self.$MailGrid.getSelectedIndices()[0]
                                );
                            }
                        }
                    }],
                    height: 200
                });

                self.$MailGrid.addEvents({
                    onClick: function () {
                        const buttons = self.$MailGrid.getButtons(),
                            sels = self.$MailGrid.getSelectedIndices();

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
                        self.openEmailWindow(
                            self.$MailGrid.getSelectedIndices()[0]
                        );
                    }
                });


                // grid data
                const mailData = [];

                const mail = JSON.decode(data.mail),
                    phone = JSON.decode(data.phone);

                for (let i = 0, len = mail.length; i < len; i++) {
                    mailData.push({
                        email: mail[i]
                    });
                }

                self.$MailGrid.setData({
                    data: mailData
                });

                self.$PhoneGrid.setData({
                    data: phone
                });

                self.Loader.hide();
                self.fireEvent('loaded', [self]);
            }, {
                uid: this.getAttribute('uid'),
                aid: this.getAttribute('addressId'),
                onError: (error) => {
                    if (!this.$addressDestroyed) {
                        this.Loader.hide();
                        this.fireEvent('loadError', [this, error]);
                    }
                }
            });
        },

        /**
         * Saves the address
         */
        save: function () {
            const self = this;

            const emails = this.$MailGrid.getData().map(function (data) {
                return data.email;
            });

            const data = {
                salutation: this.$Salutation.value,
                firstname: this.$Firstname.value,
                lastname: this.$Lastname.value,
                company: this.$Company.value,
                street_no: this.$StreetNo.value,
                zip: this.$Zip.value,
                city: this.$City.value,
                state: this.$State.value,
                country: this.$Country.value,
                mails: emails,
                phone: this.$PhoneGrid.getData(),
                standard: this.$Standard.checked ? 1 : 0
            };

            Ajax.post('ajax_users_address_save', function () {
                // if address is default address, we neet to refresh the user
                if (self.$Standard.checked) {
                    Users.get(self.getAttribute('uid')).load().then(function () {
                        self.fireEvent('saved', [self]);
                    }).catch((error) => {
                        self.fireEvent('saveError', [self, error]);
                    });

                    return;
                }

                self.fireEvent('saved', [self]);
            }, {
                uid: this.getAttribute('uid'),
                aid: this.getAttribute('addressId'),
                data: JSON.encode(data),
                onError: (error) => {
                    this.fireEvent('saveError', [this, error]);
                }
            });
        },

        /**
         * add a phone number
         *
         * @param {String} no - tel, fax, mobile number
         * @param {String} type - type of the number, can be tel, fax, mobile -> standard = tel
         */
        addPhone: function (no, type) {
            this.$PhoneGrid.addRow({
                no: no,
                type: type
            });
        },

        /**
         * Edit a phone
         *
         * @param {Number|String} index - grid index
         * @param {String} no - tel, fax, mobile number
         * @param {String} type - type of the number, can be tel, fax, mobile -> standard = tel
         */
        editPhone: function (index, no, type) {
            this.$PhoneGrid.setDataByRow(index, {
                no: no,
                type: type
            });
        },

        /**
         * delete the phone entry
         *
         * @param {String|Number} index - grid index
         */
        deletePhone: function (index) {
            this.$PhoneGrid.deleteRow(index);
        },

        /**
         * add an e-mail
         *
         * @param {String} email - E-Mail address
         */
        addMail: function (email) {
            this.$MailGrid.addRow({
                email: email
            });
        },

        /**
         * edit an e-mail
         *
         * @param {Number} index - grid index
         * @param {String} email - E-Mail address
         */
        editMail: function (index, email) {
            this.$MailGrid.setDataByRow(index, {
                email: email
            });
        },

        /**
         * delete the mail entry
         *
         * @param {String|Number} index - grid index
         */
        deleteMail: function (index) {
            this.$MailGrid.deleteRow(index);
        },

        /**
         * windows - dialogs
         */

        /**
         * Open the add / edit window to add a tel / fax / mobile number
         *
         * @param {String} [phoneId] - (optional), Grid index
         */
        openPhoneWindow: function (phoneId) {
            const self = this;

            new QUIConfirm({
                title: Locale.get(lg, 'users.address.phone.window.title'),
                icon: 'fa fa-phone',
                text: Locale.get(lg, 'users.address.phone.window.text'),
                information: '<form style="text-align: center; width: 100%">' +
                    '<input type="text" name="number" value="" placeholder="" />' +
                    '<select name="type">' +
                    '   <option value="tel">' + Locale.get(lg, 'tel') + '</option>' +
                    '   <option value="fax">' + Locale.get(lg, 'fax') + '</option>' +
                    '   <option value="mobile">' + Locale.get(lg, 'mobile') + '</option>' +
                    '</select>' +
                    '</form>',
                maxWidth: 600,
                events: {
                    onOpen: function (Win) {
                        const Content = Win.getContent(),
                            InputNumber = Content.getElement('[name="number"]'),
                            InputType = Content.getElement('[name="type"]'),
                            Form = Content.getElement('form');

                        InputNumber.set(
                            'placeholder',
                            Locale.get(lg, 'users.address.phone.window.input.placeholder')
                        );

                        (function () {
                            if (InputNumber) {
                                InputNumber.focus();
                                InputNumber.select();
                            }
                        }).delay(600);


                        if (typeof phoneId !== 'undefined') {
                            const data = self.$PhoneGrid.getDataByRow(phoneId);

                            InputNumber.value = data.no;
                            InputType.value = data.type;
                        }

                        Form.addEvents({
                            submit: function (event) {
                                event.stop();
                                Win.submit();
                            }
                        });
                    },

                    onSubmit: function (Win) {
                        const Content = Win.getContent(),
                            InputNumber = Content.getElement('[name="number"]'),
                            InputType = Content.getElement('[name="type"]');

                        if (typeof phoneId !== 'undefined') {
                            self.editPhone(
                                phoneId,
                                InputNumber.value,
                                InputType.value
                            );

                            return;
                        }

                        self.addPhone(
                            InputNumber.value,
                            InputType.value
                        );
                    }
                }
            }).open();
        },

        /**
         * opens the delete submit window
         *
         * @param {String|Number} phoneId - index of the grid
         */
        openPhoneDeleteWindow: function (phoneId) {
            const self = this;

            new QUIConfirm({
                title: Locale.get(lg, 'users.address.phone.delete.window.title'),
                icon: 'fa fa-remove',
                text: Locale.get(lg, 'users.address.phone.delete.window.text'),
                information: Locale.get(lg, 'users.address.phone.delete.window.information'),
                events: {
                    onSubmit: function () {
                        self.deletePhone(phoneId);
                    }
                }
            }).open();
        },

        /**
         * Open the add / edit window to add an email
         *
         * @param {String} [emailId] - (optional), Grid index
         */
        openEmailWindow: function (emailId) {
            const self = this;

            new QUIConfirm({
                title: Locale.get(lg, 'users.address.email.window.title'),
                icon: 'fa fa-envelope-o',
                text: Locale.get(lg, 'users.address.email.window.text'),
                information: '<form style="text-align: center; width: 100%">' +
                    '<input type="text" name="email" value="" placeholder="" />' +
                    '</form>',
                maxWidth: 600,
                events: {
                    onOpen: function (Win) {
                        const Content = Win.getContent(),
                            InputEmail = Content.getElement('[name="email"]'),
                            Form = Content.getElement('form');

                        InputEmail.set(
                            'placeholder',
                            Locale.get(lg, 'users.address.email.window.input.placeholder')
                        );

                        (function () {
                            if (InputEmail) {
                                InputEmail.focus();
                                InputEmail.select();
                            }
                        }).delay(600);


                        if (typeof emailId !== 'undefined') {
                            const data = self.$MailGrid.getDataByRow(emailId);

                            InputEmail.value = data.email;
                        }

                        Form.addEvents({
                            submit: function (event) {
                                event.stop();
                                Win.submit();
                            }
                        });
                    },

                    onSubmit: function (Win) {
                        const Content = Win.getContent(),
                            InputEmail = Content.getElement('[name="email"]');

                        if (typeof emailId !== 'undefined') {
                            self.editMail(emailId, InputEmail.value);
                            return;
                        }

                        self.addMail(InputEmail.value);
                    }
                }
            }).open();
        },

        /**
         * opens the delete submit window
         *
         * @param {String|Number} emailId - index of the grid
         */
        openEmailDeleteWindow: function (emailId) {
            const self = this;

            new QUIConfirm({
                title: Locale.get(lg, 'users.address.email.delete.window.title'),
                icon: 'fa fa-remove',
                text: Locale.get(lg, 'users.address.email.delete.window.text'),
                information: Locale.get(lg, 'users.address.email.delete.window.information'),
                events: {
                    onSubmit: function () {
                        self.deleteMail(emailId);
                    }
                }
            }).open();
        }
    });
});
