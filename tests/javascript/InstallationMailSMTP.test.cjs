const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture(checked, adminMail = 'admin@example.test', error = null) {
    let definition;
    const requests = [];
    const elements = Object.fromEntries(Object.entries({
        'smtp-server': 'smtp.example.test',
        'smtp-port': '587',
        'smtp-user': 'user@example.test',
        'smtp-password': 'test-only-password',
        'smtp-secure': 'tls'
    }).map(([name, value]) => [name, {value}]));
    for (const name of ['smtp-auth', 'smtp-secure-verify_peer', 'smtp-secure-verify_peer_name',
        'mail.settings.allow_self_signed']) {
        elements[name] = {checked: checked[name], value: 'on'};
    }
    const ajax = {};
    for (const method of ['get', 'post']) {
        ajax[method] = (name, done, options) => {
            requests.push({method, name, params: JSON.parse(options.params)});
            error ? options.onError(error) : done();
        };
    }
    vm.runInNewContext(readFileSync(resolve(__dirname,
        '../../bin/QUI/controls/installation/MailSMTP.js'), 'utf8'), {
        JSON: {encode: JSON.stringify, stringify: JSON.stringify},
        Class: function (value) { definition = value; return value; },
        define: (name, dependencies, factory) => factory({}, {}, ajax)
    });
    const form = {elements};
    const step = {...definition,
        getElm: () => ({getElement: () => form, querySelector: () => form}),
        getAttribute: () => ({getData: () => ({'mail.admin_mail': adminMail})})
    };
    return {step, requests};
}

for (const authenticated of [true, false]) {
    test(`SMTP test sends authentication ${authenticated} and actual checkbox states via POST`, async () => {
        const {step, requests} = fixture({
            'smtp-auth': authenticated,
            'smtp-secure-verify_peer': authenticated,
            'smtp-secure-verify_peer_name': !authenticated,
            'mail.settings.allow_self_signed': authenticated
        });
        await step.checkSMTPServer();
        assert.deepEqual(requests, [{method: 'post', name: 'ajax_system_mailTest', params: {
            adminMail: 'admin@example.test', SMTP: 1, SMTPAuth: Number(authenticated),
            SMTPServer: 'smtp.example.test', SMTPPort: '587',
            SMTPUser: 'user@example.test', SMTPPass: 'test-only-password', SMTPSecure: 'tls',
            SMTPSecureSSL_verify_peer: Number(authenticated), SMTPSecureSSL_verify_peer_name: Number(!authenticated),
            SMTPSecureSSL_allow_self_signed: Number(authenticated)
        }}]);
    });
}

test('empty recipient prevents the SMTP request', async () => {
    const {step, requests} = fixture({}, '');
    await assert.rejects(step.checkSMTPServer(), reason => reason === 'Empty admin mail');
    assert.equal(requests.length, 0);
});

test('SMTP failure rejects the check so the wizard cannot advance', async () => {
    const error = new Error('SMTP authentication failed');
    const {step} = fixture({}, 'admin@example.test', error);
    await assert.rejects(step.checkSMTPServer(), error);
});
