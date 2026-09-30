define('controls/installation/MailSMTP', [

    'qui/QUI',
    'qui/controls/Control',
    'Ajax',

    'css!controls/installation/MailSMTP.css'

], function (QUI, QUIControl, QUIAjax) {
    "use strict";

    let noSMTP  = null;
    let useSMTP = null;

    return new Class({

        Extends: QUIControl,
        Type   : 'controls/installation/MailSMTP',

        Binds: [
            '$onImport',
            'useSmtp',
            'useNoSmtp',
        ],

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onImport: this.$onImport
            });
        },

        $onImport: function () {
            const Buttons = this.getElm().getElements('.quiqqer-setup-mailSmtp-btns');

            this.getElm().getElement('div').addClass('quiqqer-setup-mailSmtp');

            Buttons.getElement('[name="use-smtp"]').addEvent('click', this.useSmtp);
            Buttons.getElement('[name="no-smtp"]').addEvent('click', this.useNoSmtp);
        },

        next: function () {
            if (noSMTP) {
                return true;
            }

            if (useSMTP === true) {
                return true;
            }

            const Wizard = this.getAttribute('Wizard');

            // check smtp
            Wizard.showLoader();

            this.checkSMTPServer().then(() => {
                useSMTP = true;
                Wizard.next();
            }).catch(() => {
                Wizard.hideLoader();
            });

            return false;
        },

        useSmtp: function () {
            const Buttons = this.getElm().getElement('.quiqqer-setup-mailSmtp-btns');
            const Form    = this.getElm().getElement('form');

            Buttons.setStyle('position', 'relative');
            Form.elements["use-smtp"].value = 1;

            moofx(Buttons).animate({
                left   : -10,
                opacity: 0
            }, {
                duration: 300,
                callback: function () {
                    Buttons.setStyle('display', 'none');

                    Form.setStyle('opacity', 0);
                    Form.setStyle('left', -10);
                    Form.setStyle('display', null);

                    moofx(Form).animate({
                        left   : 0,
                        opacity: 1
                    }, {
                        duration: 300,
                        callback: function () {

                        }
                    });
                }
            });
        },

        useNoSmtp: function () {
            noSMTP = true;
            this.getAttribute('Wizard').next();
        },

        checkSMTPServer: function () {
            const Form   = this.getElm().querySelector('[data-name="smtp-form"]');
            const Wizard = this.getAttribute('Wizard');
            const data   = Wizard.getData();

            if (data['mail.admin_mail'] === '') {
                return Promise.reject('Empty admin mail');
            }

            return new Promise(function (resolve, reject) {
                QUIAjax.post('ajax_system_mailTest', resolve, {
                    'package': 'quiqqer/core',
                    onError  : reject,
                    params   : JSON.stringify({
                        adminMail : data['mail.admin_mail'],
                        SMTP      : 1,
                        SMTPAuth  : Form.elements['smtp-auth'].checked ? 1 : 0,
                        SMTPServer: Form.elements['smtp-server'].value,
                        SMTPUser  : Form.elements['smtp-user'].value,
                        SMTPPass  : Form.elements['smtp-password'].value,
                        SMTPPort  : Form.elements['smtp-port'].value,
                        SMTPSecure: Form.elements['smtp-secure'].value,

                        SMTPSecureSSL_verify_peer      : Form.elements['smtp-secure-verify_peer'].checked ? 1 : 0,
                        SMTPSecureSSL_verify_peer_name : Form.elements['smtp-secure-verify_peer_name'].checked ? 1 : 0,
                        SMTPSecureSSL_allow_self_signed: Form.elements['mail.settings.allow_self_signed'].checked ? 1 : 0
                    })
                });
            });
        }
    });
});
