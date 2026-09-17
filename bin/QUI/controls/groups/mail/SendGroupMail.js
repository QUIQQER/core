define('controls/groups/mail/SendGroupMail', [
    'qui/QUI',
    'qui/controls/windows/Confirm',
    'Ajax',
    'Locale',
    'Mustache',
    'text!controls/groups/mail/SendGroupMail.html',
    'css!controls/groups/mail/SendGroupMail.css'
], function (QUI, QUIConfirm, QUIAjax, QUILocale, Mustache, template) {
    "use strict";

    const lg = 'quiqqer/core';

    return new Class({
        Extends: QUIConfirm,
        type   : 'controls/groups/mail/SendGroupMail',

        Binds: [
            '$onOpen',
            '$onSubmit',
            '$onConfirmOpen'
        ],

        options: {
            groupId: false,
            maxHeight: 820,
            maxWidth : 1000
        },

        initialize: function (options) {
            this.parent(options);

            this.setAttributes({
                icon         : 'fa fa-envelope',
                title        : QUILocale.get(lg, 'controls.SendGroupMail.title'),
                autoclose    : false,
                cancel_button: {
                    textimage: 'fa fa-close',
                    text     : QUILocale.get(lg, 'close')
                },
                ok_button    : {
                    textimage: 'fa fa-envelope',
                    text     : QUILocale.get(lg, 'controls.SendGroupMail.submit')
                }
            });

            this.$MailData = null;
            this.$MailSubjectInput = null;
            this.$MailContentEditor = null;

            this.addEvents({
                onOpen  : this.$onOpen,
                onSubmit: this.$onSubmit
            });
        },

        $onOpen: async function () {
            const Content = this.getContent();

            this.Loader.show();

            try {
                const MailData = await this.$getMailData();

                this.$MailData = MailData;

                Content.set({
                    html: Mustache.render(template, {
                        labelGroupName     : QUILocale.get(lg, 'group'),
                        labelRecipientCount: QUILocale.get(lg, 'controls.SendGroupMail.tpl.labelRecipientCount'),
                        labelMailSubject   : QUILocale.get(lg, 'controls.SendGroupMail.tpl.labelMailSubject'),
                        labelMailContent   : QUILocale.get(lg, 'controls.SendGroupMail.tpl.labelMailContent'),
                        labelPlaceholders  : QUILocale.get(lg, 'controls.SendGroupMail.tpl.labelPlaceholders'),
                        placeholdersHint   : QUILocale.get(lg, 'controls.SendGroupMail.tpl.placeholdersHint'),
                        placeholders       : [
                            {
                                token: 'user_uuid',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userUuid')
                            },
                            {
                                token: 'user_id',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userId')
                            },
                            {
                                token: 'user_salutation',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userSalutation')
                            },
                            {
                                token: 'user_firstname',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userFirstname')
                            },
                            {
                                token: 'user_lastname',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userLastname')
                            },
                            {
                                token: 'user_street_no',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userStreetNo')
                            },
                            {
                                token: 'user_city',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userCity')
                            },
                            {
                                token: 'user_country',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userCountry')
                            },
                            {
                                token: 'user_email',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userEmail')
                            },
                            {
                                token: 'user_company',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userCompany')
                            },
                            {
                                token: 'user_zip',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userZip')
                            },
                            {
                                token: 'user_username',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.userUsername')
                            },
                            {
                                token: 'group_title',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.groupTitle')
                            },
                            {
                                token: 'group_uuid',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.groupUuid')
                            },
                            {
                                token: 'group_id',
                                label: QUILocale.get(lg, 'controls.SendGroupMail.placeholder.groupId')
                            }
                        ],
                        groupName          : MailData.name,
                        recipientCount     : MailData.uniqueEmailCount
                    })
                });

                Content.addClass('quiqqer-quiqqer-group-mail');

                const MailContainer = Content.getElement('.quiqqer-quiqqer-group-mail-mailEditor');
                const MailEditorContainer = Content.getElement('.quiqqer-quiqqer-group-mail-mailEditor-content');

                this.$MailSubjectInput = Content.getElement('.quiqqer-quiqqer-group-mail-mailEditor-subject');

                if (!MailData.uniqueEmailCount) {
                    MailContainer.set('html', '');

                    new Element('div', {
                        'class': 'messages-message box message-attention',
                        html   : QUILocale.get(lg, 'controls.SendGroupMail.no_recipients')
                    }).inject(MailContainer);

                    this.getButton('submit').disable();
                    this.Loader.hide();
                    this.setAttribute('maxHeight', 500);
                    this.resize();

                    return;
                }

                const Editors = await new Promise(function (resolve) {
                    require(['Editors'], resolve);
                });

                const Editor = await Editors.getEditor();

                Editor.addEvent('onLoaded', function () {
                    this.Loader.hide();
                    this.fireEvent('load', [this]);
                    Editor.resize();

                    (function () {
                        this.$MailSubjectInput.focus();
                    }.bind(this)).delay(200);
                }.bind(this));

                Editor.inject(MailEditorContainer);
                this.$MailContentEditor = Editor;
            } catch (err) {
                console.error(err && err.getMessage ? err.getMessage() : err);
                this.close();
            }
        },

        $onSubmit: async function () {
            if (this.$MailSubjectInput.value.trim() === '') {
                const MH = await QUI.getMessageHandler();

                MH.addAttention(
                    QUILocale.get(lg, 'controls.SendGroupMail.empty_subject')
                );

                this.$MailSubjectInput.focus();

                return;
            }

            if (this.$MailContentEditor.getContent().trim() === '') {
                const MH = await QUI.getMessageHandler();

                MH.addAttention(
                    QUILocale.get(lg, 'controls.SendGroupMail.empty_body')
                );

                return;
            }

            const confirmed = await this.$confirmSend();

            if (!confirmed) {
                return;
            }

            this.Loader.show();

            try {
                await this.$sendMail();
                this.close();
            } catch (err) {
                console.error(err && err.getMessage ? err.getMessage() : err);
                this.Loader.hide();
            }
        },

        $confirmSend: async function () {
            const self = this;
            let previewHtml = '';

            this.Loader.show();

            try {
                previewHtml = await this.$getRenderedMailHtml();
            } catch (err) {
                console.error(err && err.getMessage ? err.getMessage() : err);
                this.Loader.hide();

                return false;
            }

            this.Loader.hide();

            return new Promise(function (resolve) {
                let settled = false;

                const finish = function (result) {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    resolve(result);
                };

                new QUIConfirm({
                    icon         : 'fa fa-envelope',
                    texticon     : 'fa fa-envelope',
                    title        : QUILocale.get(lg, 'controls.SendGroupMail.confirm.title'),
                    maxHeight    : 900,
                    maxWidth     : 1100,
                    autoclose    : true,
                    cancel_button: {
                        textimage: 'fa fa-close',
                        text     : QUILocale.get(lg, 'close')
                    },
                    ok_button    : {
                        textimage: 'fa fa-envelope',
                        text     : QUILocale.get(lg, 'controls.SendGroupMail.confirm.submit')
                    },
                    events       : {
                        onOpen  : function (Win) {
                            self.$onConfirmOpen(Win, previewHtml);
                        },
                        onSubmit: function (Win) {
                            finish(true);
                            Win.close();
                        },
                        onClose : function () {
                            finish(false);
                        }
                    }
                }).open();
            });
        },

        $onConfirmOpen: function (Win, previewHtml) {
            const Content = Win.getContent();
            const subject = this.$MailSubjectInput.value;

            Content.set('html',
                '<div class="quiqqer-quiqqer-group-mail-confirm">' +
                '   <div class="quiqqer-quiqqer-group-mail-confirm-message messages-message message-information">' +
                QUILocale.get(lg, 'controls.SendGroupMail.confirm.information', {
                    group: this.$MailData.name,
                    count: this.$MailData.uniqueEmailCount
                }) +
                '   </div>' +
                '   <div class="quiqqer-quiqqer-group-mail-confirm-text">' +
                QUILocale.get(lg, 'controls.SendGroupMail.confirm.text', {
                    group: this.$MailData.name,
                    count: this.$MailData.uniqueEmailCount
                }) +
                '   </div>' +
                '   <div class="quiqqer-quiqqer-group-mail-confirm-subject">' +
                '       <span class="quiqqer-quiqqer-group-mail-confirm-subject-label">' +
                QUILocale.get(lg, 'controls.SendGroupMail.tpl.labelMailSubject') +
                ':</span> ' +
                '       <span class="quiqqer-quiqqer-group-mail-confirm-subject-value"></span>' +
                '   </div>' +
                '   <div class="quiqqer-quiqqer-group-mail-confirm-preview-title">' +
                QUILocale.get(lg, 'controls.SendGroupMail.confirm.preview.title') +
                '   </div>' +
                '   <iframe class="quiqqer-quiqqer-group-mail-confirm-preview-frame"></iframe>' +
                '</div>'
            );

            Content.getElement('.quiqqer-quiqqer-group-mail-confirm-subject-value').set('text', subject);

            const Frame = Content.getElement('.quiqqer-quiqqer-group-mail-confirm-preview-frame');
            const FrameDocument = Frame.contentWindow.document;

            FrameDocument.open();
            FrameDocument.write(previewHtml);
            FrameDocument.close();
        },

        $getMailData: function () {
            const self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_groups_getMailData', resolve, {
                    'package': 'quiqqer/core',
                    groupId  : self.getAttribute('groupId'),
                    onError  : reject
                });
            });
        },

        $getRenderedMailHtml: function () {
            const self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_email_getRenderedHtml', resolve, {
                    'package'  : 'quiqqer/core',
                    mailSubject: self.$MailSubjectInput.value,
                    mailContent: self.$MailContentEditor.getContent(),
                    onError    : reject
                });
            });
        },

        $sendMail: function () {
            const self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_groups_sendMail', resolve, {
                    'package'  : 'quiqqer/core',
                    groupId    : self.getAttribute('groupId'),
                    mailSubject: self.$MailSubjectInput.value,
                    mailContent: self.$MailContentEditor.getContent(),
                    onError    : reject
                });
            });
        }
    });
});
