/**
 * Manage a user's allowed mobile upload browsers without exposing token hashes.
 */
define('controls/upload/mobileUpload/Devices', [
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'controls/grid/Grid',
    'qui/QUI',
    'qui/controls/windows/Prompt',
    'qui/controls/windows/Confirm',
    'css!controls/upload/mobileUpload/Devices.css'
], function (QUIControl, Ajax, Locale, Grid, QUI, Prompt, Confirm) {
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
            this.$disposed = false;
            this.$revision = 0;
            this.$devices = [];
            this.$filter = '';
            this.$Dialog = null;
            const start = () => {
                if (this.$Grid) { return; }
                this.buildGrid();
                this.load();
            };
            this.addEvent('onImport', start);
            this.addEvent('onInject', start);
            this.addEvent('onDestroy', () => {
                this.$disposed = true;
                this.$Observer?.disconnect();
                this.$Dialog?.close();
                this.$Grid?.destroy();
            });
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

        buildGrid: function () {
            const Root = this.getElm();
            Root.classList.add('quiqqer-upload-devices');
            Root.closest('form')?.classList.add('quiqqer-upload-devices-form');
            Root.replaceChildren();

            const Hint = document.createElement('p');
            Hint.textContent = label('devicesHint');
            Hint.className = 'quiqqer-upload-devices-hint';
            this.$Status = document.createElement('p');
            this.$Status.dataset.name = 'status';
            this.$Status.setAttribute('role', 'status');
            const Container = document.createElement('div');
            Container.dataset.name = 'devices-grid';
            Root.append(Hint, this.$Status, Container);

            this.$Grid = new Grid(Container, {
                'button-reload': true,
                pagination: true,
                perPage: 20,
                perPageOptions: [10, 20, 50],
                selectable: true,
                multipleSelection: false,
                sortHeader: false,
                filterInput: true,
                emptyMessage: label('devicesEmpty'),
                width: Root.clientWidth,
                height: Root.clientHeight || 400,
                columnModel: [
                    {header: label('deviceName'), dataIndex: 'name', dataType: 'node', width: 200},
                    {header: label('deviceBrowser'), dataIndex: 'description', dataType: 'node', width: 300},
                    {header: label('deviceCreated'), dataIndex: 'createdAt', dataType: 'node', width: 160},
                    {header: label('deviceLastUsed'), dataIndex: 'lastUsedAt', dataType: 'node', width: 160}
                ],
                buttons: [
                    {
                        name: 'rename',
                        text: label('deviceEdit'),
                        textimage: 'fa fa-edit',
                        disabled: true,
                        events: {onClick: () => this.editSelected('rename')}
                    },
                    {
                        name: 'revoke',
                        title: label('deviceRevoke'),
                        icon: 'fa fa-trash-o',
                        position: 'right',
                        disabled: true,
                        events: {onClick: () => this.editSelected('revoke')}
                    }
                ],
                onrefresh: () => this.load()
            });
            this.$Grid.getButtons().forEach(Button => {
                Button.getElm().setAttribute('aria-label', label(
                    Button.getAttribute('name') === 'rename' ? 'deviceEdit' : 'deviceRevoke'
                ));
            });
            this.$Grid.addEvents({
                onClick: () => this.updateButtons(),
                onDblClick: () => this.editSelected('rename')
            });
            // Filter the complete device list before paginating it.
            this.$Grid.filter = (query) => {
                query = query.trim().toLocaleLowerCase();
                if (query === this.$filter) { return; }
                this.$filter = query;
                this.$Grid.setAttribute('page', 1);
                this.render(this.$devices);
            };
            this.$Observer = new ResizeObserver(() => {
                if (!this.$disposed && Root.clientWidth > 0) {
                    this.$Grid.setWidth(Root.clientWidth);
                    this.$Grid.setHeight(Root.clientHeight);
                }
            });
            this.$Observer.observe(Root);
        },

        load: async function () {
            if (this.$busy || this.$disposed) { return; }
            const revision = ++this.$revision;
            this.$Status.textContent = label('loading');
            this.$loading = true;
            this.updateButtons();

            try {
                const devices = await this.request('list');

                if (!this.$disposed && revision === this.$revision) {
                    this.render(devices);
                    this.$Status.textContent = '';
                }
            } catch (error) {
                if (!this.$disposed && revision === this.$revision) {
                    this.notice('error');
                }
            } finally {
                if (!this.$disposed && revision === this.$revision) {
                    this.$loading = false;
                    this.updateButtons();
                }
            }
        },

        notice: function (key) {
            const message = label(key);
            this.$Status.textContent = message;
            QUI.getMessageHandler().then(Messages => {
                if (!this.$disposed) {
                    Messages[key === 'error' ? 'addError' : 'addSuccess'](message);
                }
            });
        },

        updateButtons: function () {
            if (!this.$Grid || this.$disposed) { return; }
            const enabled = !this.$busy && !this.$loading && !this.$Dialog
                && this.$Grid.getSelectedData().length === 1;
            this.$Grid.getButtons().forEach(Button => Button[enabled ? 'enable' : 'disable']());
        },

        editSelected: function (operation) {
            if (this.$busy || this.$loading || this.$disposed || this.$Dialog) { return; }
            const selected = this.$Grid.getSelectedData();
            if (selected.length !== 1) { return; }
            const device = selected[0];
            const options = {
                maxWidth: 460,
                maxHeight: 650,
                width: 460,
                height: 650,
                title: label(operation === 'rename' ? 'deviceEdit' : 'deviceRevoke'),
                icon: operation === 'rename' ? 'fa fa-edit' : 'fa fa-trash-o',
                events: {
                    onOpen: Window => this.styleDeviceDialog(Window, operation, device),
                    onClose: () => {
                        this.$Dialog = null;
                        this.updateButtons();
                    }
                }
            };

            if (operation === 'rename') {
                options.value = device.deviceName;
                options.text = label('deviceName');
                options.check = Window => {
                    const name = Window.getValue().trim();
                    return name.length > 0 && name.length <= 100;
                };
                options.events.onSubmit = name => this.changeDevice(operation, device.id, name.trim());
                this.$Dialog = new Prompt(options);
            } else {
                options.text = label('deviceRevokeConfirm');
                options.events.onSubmit = () => this.changeDevice(operation, device.id);
                this.$Dialog = new Confirm(options);
            }

            this.updateButtons();
            this.$Dialog.open();
        },

        styleDeviceDialog: function (Window, operation, device) {
            const rename = operation === 'rename';
            const Icon = document.createElement('span');
            Icon.className = 'quiqqer-upload-device-dialog-icon fa ' + (rename ? 'fa-mobile' : 'fa-trash-o');
            Icon.setAttribute('aria-hidden', 'true');
            const Heading = document.createElement('h2');
            Heading.textContent = label(rename ? 'deviceEdit' : 'deviceRevoke');
            const Description = document.createElement('p');
            Description.textContent = label(rename ? 'deviceEditHint' : 'deviceRevokeConfirm');
            Description.id = 'upload-device-dialog-hint-' + Window.getId();
            const Content = Window.getContent();
            Window.getElm().classList.add('quiqqer-upload-device-dialog');
            Content.classList.add('quiqqer-upload-device-dialog-content');

            if (rename) {
                const Input = Window.getInput();
                Input.maxLength = 100;
                Input.id = 'upload-device-name-' + Window.getId();
                Input.dataset.name = 'device-name';
                Input.placeholder = label('deviceName');
                Input.setAttribute('aria-label', label('deviceName'));
                Input.setAttribute('aria-describedby', Description.id);
                const Field = document.createElement('div');
                Field.className = 'quiqqer-upload-device-dialog-field';
                Field.append(Input);
                Content.replaceChildren(Icon, Heading, Description, Field);
            } else {
                const Name = document.createElement('strong');
                Name.className = 'quiqqer-upload-device-dialog-name';
                Name.textContent = device.deviceName;
                Content.replaceChildren(Icon, Heading, Name, Description);
            }
        },

        changeDevice: async function (operation, id, name = '') {
            if (this.$busy || this.$disposed) { return; }
            this.$busy = true;
            ++this.$revision;
            this.updateButtons();

            try {
                const updated = await this.request(operation, id, name);
                if (!this.$disposed) {
                    this.render(updated);
                    this.notice(operation === 'rename' ? 'deviceSaved' : 'deviceRevoked');
                }
            } catch (error) {
                if (!this.$disposed) { this.notice('error'); }
            } finally {
                this.$busy = false;
                this.updateButtons();
            }
        },

        render: function (devices) {
            this.$devices = devices;
            const filtered = devices.filter(device =>
                (device.name + ' ' + device.description).toLocaleLowerCase().includes(this.$filter));
            const perPage = Math.max(1, Number(this.$Grid.getAttribute('perPage')) || 20);
            const lastPage = Math.max(1, Math.ceil(filtered.length / perPage));
            const page = Math.max(1, Math.min(Number(this.$Grid.getAttribute('page')) || 1, lastPage));
            const text = (value) => {
                const Node = document.createElement('span');
                Node.textContent = value;
                Node.title = value;
                return Node;
            };
            const rows = filtered.slice((page - 1) * perPage, page * perPage).map(device => ({
                id: device.id,
                deviceName: device.name,
                name: text(device.name),
                description: text(device.description),
                createdAt: text(new Date(device.createdAt * 1000).toLocaleString()),
                lastUsedAt: text(new Date(device.lastUsedAt * 1000).toLocaleString())
            }));

            this.$Grid.setData({data: rows, total: filtered.length, page});
            this.$Grid.unselectAll();
            this.updateButtons();
        }
    });
});
