/**
 * Manage a user's allowed mobile upload browsers without exposing token hashes.
 */
define('controls/upload/mobileUpload/Devices', [
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'css!controls/upload/mobileUpload/Devices.css'
], function (QUIControl, Ajax, Locale) {
    'use strict';

    const label = (key) => Locale.get('quiqqer/core', 'upload.mobileUpload.' + key);

    return new Class({
        Extends: QUIControl,
        Type: 'controls/upload/mobileUpload/Devices',
        options: {
            uid: false
        },

        initialize: function (options) {
            this.parent(options);
            this.addEvent('onImport', () => this.load());
        },

        request: function (action, id = '', name = '') {
            return new Promise((resolve, reject) => {
                Ajax.post('ajax_upload_mobileUpload_devices', resolve, {
                    uid: String(this.getAttribute('uid')),
                    action,
                    id,
                    name,
                    onError: reject
                });
            });
        },

        load: async function () {
            const Root = this.getElm();
            Root.classList.add('quiqqer-upload-devices');
            Root.textContent = label('loading');

            try {
                this.render(await this.request('list'));
            } catch (error) {
                Root.textContent = label('error');
            }
        },

        render: function (devices) {
            const Root = this.getElm();
            Root.replaceChildren();

            const Hint = document.createElement('p');
            Hint.textContent = label('devicesHint');
            const Status = document.createElement('p');
            Status.setAttribute('role', 'status');
            Root.append(Hint, Status);

            if (!devices.length) {
                Status.textContent = label('devicesEmpty');
                return;
            }

            const List = document.createElement('ul');
            Root.appendChild(List);

            devices.forEach((device) => {
                const Item = document.createElement('li');
                const NameLabel = document.createElement('label');
                NameLabel.textContent = label('deviceName');
                const Name = document.createElement('input');
                Name.type = 'text';
                Name.maxLength = 100;
                Name.value = device.name;
                NameLabel.appendChild(Name);

                const Details = document.createElement('p');
                Details.textContent = device.description;
                const Dates = document.createElement('p');
                Dates.textContent = label('deviceCreated') + ': ' + new Date(device.createdAt * 1000).toLocaleString()
                    + ' · ' + label('deviceLastUsed') + ': ' + new Date(device.lastUsedAt * 1000).toLocaleString();
                Item.append(NameLabel, Details, Dates);

                const action = (key, fn) => {
                    const Button = document.createElement('button');
                    Button.type = 'button';
                    Button.className = 'btn btn-light';
                    Button.textContent = label(key);
                    Button.setAttribute('aria-label', label(key) + ': ' + device.name);
                    Button.addEventListener('click', async () => {
                        Button.disabled = true;

                        try {
                            await fn();
                        } catch (error) {
                            Status.textContent = label('error');
                        } finally {
                            Button.disabled = false;
                        }
                    });
                    Item.appendChild(Button);
                };

                action('deviceRename', async () => {
                    await this.request('rename', device.id, Name.value);
                    Status.textContent = label('deviceSaved');
                });
                action('deviceRevoke', async () => {
                    if (!window.confirm(label('deviceRevokeConfirm'))) {
                        return;
                    }

                    await this.request('revoke', device.id);
                    Item.remove();
                    Status.textContent = label('deviceRevoked');
                    Status.tabIndex = -1;
                    Status.focus();
                });

                List.appendChild(Item);
            });
        }
    });
});
