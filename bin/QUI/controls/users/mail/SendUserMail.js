/**
 * Send e-mail to a QUIQQER user
 *
 * @event onLoad [this] - Fires if control has finished loading everything
 */
define('controls/users/mail/SendUserMail', [

    'qui/QUI',
    'qui/controls/windows/Confirm',

    'Ajax',
    'Locale',
    'Mustache',

    'text!controls/users/mail/SendUserMail.html',
    'css!controls/users/mail/SendUserMail.css'

], function (QUI, QUIConfirm, QUIAjax, QUILocale, Mustache, template) {
    "use strict";

    const lg = 'quiqqer/core';

    return new Class({

        Extends: QUIConfirm,
        type: 'controls/users/mail/SendUserMail',

        Binds: [
            '$onOpen',
            '$onSubmit'
        ],

        options: {
            userId: false,  // QUIQQER user ID

            maxHeight: 820,
            maxWidth: 1000
        },

        initialize: function (options) {
            this.parent(options);

            this.setAttributes({
                icon: 'fa fa-envelope',
                title: QUILocale.get(lg, 'controls.SendUserMail.title'),
                autoclose: false,
                cancel_button: {
                    textimage: 'fa fa-close',
                    text: QUILocale.get(lg, 'close')
                },
                ok_button: {
                    textimage: 'fa fa-envelope',
                    text: QUILocale.get(lg, 'controls.SendUserMail.submit')
                }
            });

            this.$MailSubjectInput = null;
            this.$MailContentEditor = null;

            this.addEvents({
                onOpen: this.$onOpen,
                onSubmit: this.$onSubmit
            });
        },

        /**
         * event: on open
         */
        $onOpen: function () {
            const self = this,
                Content = this.getContent();

            this.Loader.show();

            this.$getMailData().then(function (MailData) {
                Content.set({
                    html: Mustache.render(template, {
                        labelUserName: QUILocale.get(lg, 'username'),
                        labelUserLang: QUILocale.get(lg, 'language'),
                        labelUserEmail: QUILocale.get(lg, 'email'),
                        labelMailSubject: QUILocale.get(lg, 'controls.SendUserMail.tpl.labelMailSubject'),
                        labelMailContent: QUILocale.get(lg, 'controls.SendUserMail.tpl.labelMailContent'),
                        labelPlaceholders: QUILocale.get(lg, 'controls.SendUserMail.tpl.labelPlaceholders'),
                        placeholdersHint: QUILocale.get(lg, 'controls.SendUserMail.tpl.placeholdersHint'),
                        placeholders: [
                            {
                                token: 'user_uuid',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userUuid')
                            },
                            {
                                token: 'user_id',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userId')
                            },
                            {
                                token: 'user_salutation',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userSalutation')
                            },
                            {
                                token: 'user_firstname',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userFirstname')
                            },
                            {
                                token: 'user_lastname',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userLastname')
                            },
                            {
                                token: 'user_street_no',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userStreetNo')
                            },
                            {
                                token: 'user_city',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userCity')
                            },
                            {
                                token: 'user_country',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userCountry')
                            },
                            {
                                token: 'user_email',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userEmail')
                            },
                            {
                                token: 'user_company',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userCompany')
                            },
                            {
                                token: 'user_zip',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userZip')
                            },
                            {
                                token: 'user_username',
                                label: QUILocale.get(lg, 'controls.SendUserMail.placeholder.userUsername')
                            }
                        ],
                        userName: MailData.name,
                        userLang: MailData.lang,
                        userEmail: MailData.email
                    })
                });

                Content.addClass('quiqqer-quiqqer-user-mail');

                const MailContainer = Content.getElement('.quiqqer-quiqqer-user-mail-mailEditor'),
                    MailEditorContainer = Content.getElement('.quiqqer-quiqqer-user-mail-mailEditor-content');

                self.$MailSubjectInput = Content.getElement('.quiqqer-quiqqer-user-mail-mailEditor-subject');

                if (!MailData.email) {
                    MailContainer.set('html', '');

                    new Element('div', {
                        'class': 'messages-message box message-attention',
                        html: QUILocale.get(lg, 'controls.SendUserMail.user_no_mail')
                    }).inject(MailContainer);

                    self.getButton('submit').disable();

                    self.Loader.hide();

                    self.setAttribute('maxHeight', 500);
                    self.resize();

                    return;
                }

                require(['Editors'], function (Editors) {
                    Editors.getEditor().then(function (Editor) {
                        Editor.addEvent('onLoaded', function () {
                            self.Loader.hide();
                            self.fireEvent('load', [self]);

                            Editor.resize();

                            (function () {
                                self.$MailSubjectInput.focus();
                            }).delay(200);
                        });

                        Editor.inject(MailEditorContainer);

                        self.$MailContentEditor = Editor;
                    });
                });
            }, function (err) {
                console.error(err.getMessage());
                self.close();
            });
        },

        /**
         * Event: onSubmit
         */
        $onSubmit: function () {
            const self = this;

            if (this.$MailSubjectInput.value.trim() === '') {
                QUI.getMessageHandler().then(function (MH) {
                    MH.addAttention(
                        QUILocale.get(lg, 'controls.SendUserMail.empty_subject')
                    );
                });

                this.$MailSubjectInput.focus();

                return;
            }

            if (this.$MailContentEditor.getContent().trim() === '') {
                QUI.getMessageHandler().then(function (MH) {
                    MH.addAttention(
                        QUILocale.get(lg, 'controls.SendUserMail.empty_body')
                    );
                });

                return;
            }

            this.Loader.show();

            this.$sendMail().then(function () {
                self.close();
            }, function (err) {
                console.error(err.getMessage());
                self.Loader.hide();
            });
        },

        /**
         * Get data of the entity that is outputted
         *
         * @return {Promise}
         */
        $getMailData: function () {
            const self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_user_getMailData', resolve, {
                    'package': 'quiqqer/core',
                    userId: self.getAttribute('userId'),
                    onError: reject
                });
            });
        },

        /**
         * Send an e-mail to a QUIQQER user
         *
         * @return {Promise}
         */
        $sendMail: function () {
            const self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_user_sendMail', resolve, {
                    'package': 'quiqqer/core',
                    userId: self.getAttribute('userId'),
                    mailSubject: self.$MailSubjectInput.value,
                    mailContent: self.$MailContentEditor.getContent(),
                    onError: reject
                });
            });
        }
    });
});
