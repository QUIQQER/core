/**
 * Standalone mobile file upload. Files are sent unchanged to the registered provider.
 */
(function () {
    'use strict';

    const Root = document.querySelector('[data-name="mobile-upload"]');
    const find = (name) => Root.querySelector('[data-name="' + name + '"]');
    const labels = JSON.parse(find('labels').textContent);
    const bearer = window.location.hash.slice(1);
    const Form = find('form');
    const Controls = find('controls');
    const Status = find('status');
    const ErrorMessage = find('error');
    const FileList = find('files');
    const Progress = find('progress');
    const Send = find('send');
    const Done = find('done');
    const Verification = find('verification');
    const CodeInput = find('verification-code');
    const DeviceName = find('device-name');
    const Verify = find('verify');
    const entries = [];
    let busy = false;
    let active = true;
    let expiryTimer;
    let settings = null;
    let stage = null;
    let waitTimer;

    const formatSize = (bytes) => {
        const unit = bytes >= 1000000 ? 'MB' : 'KB';
        const size = bytes / (unit === 'MB' ? 1000000 : 1000);

        return size.toLocaleString(undefined, {
            maximumFractionDigits: 2
        }) + ' ' + unit;
    };

    const identifier = () => {
        const bytes = crypto.getRandomValues(new Uint8Array(32));

        return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    };

    const showError = (error) => {
        const messages = {
            403: labels.inactive,
            404: labels.inactive,
            410: labels.inactive,
            409: labels.freshCode,
            422: labels.codeInvalid,
            428: labels.cookiesRequired,
            413: labels.limit,
            415: labels.invalid,
            429: labels.rate
        };

        ErrorMessage.textContent = messages[error.status] || labels.error;
        ErrorMessage.hidden = false;

        if ([403, 404, 410].includes(error.status)) {
            active = false;
            Controls.disabled = true;
            Done.disabled = true;
            Verify.disabled = true;
            Verification.hidden = true;
        }
    };

    const request = async (action, values = {}, progress = null) => {
        const endpoint = new URL('api.php', window.location.href);
        endpoint.hash = '';

        const response = await fetch(endpoint, {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (!response.ok) {
            throw {status: response.status};
        }

        const markup = await response.text();
        const Page = new DOMParser().parseFromString(markup, 'text/html');
        const RequestForm = Page.querySelector('[data-name="request-form"]');

        if (!RequestForm) {
            throw {status: 0};
        }

        return send(endpoint, RequestForm, action, values, progress);
    };

    const send = (endpoint, RequestForm, action, values, progress) => new Promise((resolve, reject) => {
        const Body = new FormData(RequestForm);
        Body.append('action', action);

        Object.entries(values).forEach(([key, value]) => {
            Body.append(key, value);
        });

        const Request = new XMLHttpRequest();
        Request.open('POST', endpoint.href);
        Request.setRequestHeader('Authorization', 'Bearer ' + bearer);
        Request.responseType = 'json';
        Request.timeout = 120000;

        if (progress) {
            Request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    progress(event.loaded / event.total);
                }
            });
        }

        Request.addEventListener('load', () => {
            if (Request.status >= 200 && Request.status < 300 && Request.response) {
                resolve(Request.response);
            } else {
                reject({status: Request.status});
            }
        });

        const failed = () => reject({status: 0});
        Request.addEventListener('error', failed);
        Request.addEventListener('timeout', failed);
        Request.send(Body);
    });

    const render = () => {
        FileList.replaceChildren();

        entries.forEach((entry) => {
            const Item = document.createElement('li');
            const Name = document.createElement('p');
            Name.textContent = entry.file.name;
            Item.appendChild(Name);

            if (entry.preview) {
                const Preview = document.createElement('img');
                Preview.src = entry.preview;
                Preview.alt = entry.file.name;
                Item.appendChild(Preview);
            }

            const Remove = document.createElement('button');
            Remove.type = 'button';
            Remove.className = 'btn btn-secondary-outline';
            Remove.textContent = labels.remove;
            Remove.setAttribute('aria-label', labels.remove + ': ' + entry.file.name);
            Remove.addEventListener('click', () => {
                if (entry.preview) {
                    URL.revokeObjectURL(entry.preview);
                }

                entries.splice(entries.indexOf(entry), 1);
                render();
                find('choose').focus();
            });

            Item.appendChild(Remove);
            FileList.appendChild(Item);
        });

        find('empty').hidden = entries.length > 0;
        Send.disabled = busy || !active || entries.length === 0;
    };

    const select = (Input) => {
        ErrorMessage.hidden = true;

        for (const file of Array.from(Input.files || [])) {
            if (!file.size || (settings.maxBytes > 0 && file.size > settings.maxBytes)) {
                ErrorMessage.textContent = labels.invalid;
                ErrorMessage.hidden = false;
                continue;
            }

            if (settings.maxFiles > 0 && entries.length >= settings.maxFiles) {
                showError({status: 413});
                break;
            }

            entries.push({
                id: identifier(),
                file,
                preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null
            });
        }

        Input.value = '';
        render();
    };

    find('camera').addEventListener('click', () => find('camera-input').click());
    find('choose').addEventListener('click', () => find('file-input').click());
    find('camera-input').addEventListener('change', (event) => select(event.target));
    find('file-input').addEventListener('change', (event) => select(event.target));

    Form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy || !active || entries.length === 0) {
            return;
        }

        busy = true;
        Controls.disabled = true;
        Done.disabled = true;
        ErrorMessage.hidden = true;
        Progress.hidden = false;

        const total = entries.length;
        let completed = 0;

        try {
            while (entries.length > 0) {
                const entry = entries[0];
                Status.textContent = labels.uploading + ' ' + entry.file.name;

                await request('upload', {
                    uploadId: entry.id,
                    file: entry.file
                }, (fraction) => {
                    Progress.value = (completed + fraction) / total * 100;
                });

                const Receipt = document.createElement('li');
                Receipt.textContent = labels.saved + ': ' + entry.file.name;
                find('receipts').appendChild(Receipt);

                if (entry.preview) {
                    URL.revokeObjectURL(entry.preview);
                }

                entries.shift();
                completed++;
            }

            Status.textContent = labels.saved;
        } catch (error) {
            Status.textContent = '';
            showError(error);
        } finally {
            busy = false;
            Controls.disabled = !active;
            Done.disabled = !active;
            Progress.hidden = true;
            render();
        }
    });

    Done.addEventListener('click', async () => {
        if (busy || (entries.length > 0 && !window.confirm(labels.discard))) {
            return;
        }

        Done.disabled = true;

        try {
            await request('close');

            entries.forEach((entry) => {
                if (entry.preview) {
                    URL.revokeObjectURL(entry.preview);
                }
            });

            entries.length = 0;
            active = false;
            Form.hidden = true;
            ErrorMessage.hidden = true;
            Status.textContent = labels.finished;
            window.clearTimeout(expiryTimer);
        } catch (error) {
            showError(error);
            Done.disabled = !active;
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (busy || entries.length > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    if (!/^[a-f0-9]{64}\.[a-f0-9]{64}$/.test(bearer)) {
        Status.textContent = '';
        showError({status: 410});
        return;
    }

    const applyInfo = (info) => {
        stage = info.stage;
        window.clearInterval(waitTimer);
        window.clearTimeout(expiryTimer);
        Form.hidden = stage !== 'upload';
        Verification.hidden = stage === 'upload';
        Status.textContent = '';

        if (stage !== 'upload') {
            const registering = stage === 'register';
            find('verification-title').textContent = registering ? labels.registerTitle : labels.unlockTitle;
            find('verification-hint').textContent = registering ? labels.registerHint : labels.unlockHint;
            find('device-name-label').hidden = !registering;
            DeviceName.required = registering;
            DeviceName.disabled = !registering;
            Verify.textContent = registering ? labels.registerDevice : labels.unlockUpload;
            CodeInput.value = '';

            const waitUntil = Date.now() + Math.max(0, info.retryAt - info.serverTime) * 1000;
            const updateWait = () => {
                const remaining = Math.max(0, Math.ceil((waitUntil - Date.now()) / 1000));
                Verify.disabled = remaining > 0;
                CodeInput.disabled = remaining > 0;
                find('verification-wait').textContent = remaining ? labels.freshCode + ' (' + remaining + ' s)' : '';

                if (!remaining) {
                    window.clearInterval(waitTimer);
                }
            };

            waitTimer = window.setInterval(updateWait, 1000);
            updateWait();
            (registering ? DeviceName : CodeInput).focus();
            return;
        }

        settings = info;
        find('reference').textContent = info.label;
        find('file-input').accept = info.allowedTypes.join(',');

        const imageTypes = info.allowedTypes.filter((type) => type.startsWith('image/'));
        find('camera').hidden = info.allowedTypes.length > 0 && imageTypes.length === 0;
        find('camera-input').accept = imageTypes.length > 0 ? imageTypes.join(',') : 'image/*';

        const hints = [];
        const typeLabels = {
            'application/pdf': 'PDF',
            'image/jpeg': 'JPG',
            'image/png': 'PNG',
            'image/webp': 'WebP',
            'image/heic': 'HEIC',
            'image/*': labels.images
        };

        if (info.allowedTypes.length > 0) {
            const types = info.allowedTypes.map((type) => typeLabels[type] || type);
            hints.push(types.join(', '));
        }

        if (info.maxBytes > 0) {
            hints.push(labels.maxSize + ': ' + formatSize(info.maxBytes));
        }

        find('limits').textContent = hints.join(' · ');
        Status.textContent = '';
        Form.hidden = false;

        expiryTimer = window.setTimeout(
            () => showError({status: 410}),
            Math.max(0, (info.expiresAt - info.serverTime) * 1000)
        );
        find('camera').hidden ? find('choose').focus() : find('camera').focus();
    };

    Verification.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (Verify.disabled || !active) {
            return;
        }

        Verify.disabled = true;
        ErrorMessage.hidden = true;

        try {
            const info = await request(stage, {
                code: CodeInput.value,
                name: DeviceName.value
            });
            applyInfo(info);
        } catch (error) {
            showError(error);

            if (active) {
                try {
                    applyInfo(await request('info'));
                } catch (refreshError) {
                    showError(refreshError);
                    Verify.disabled = !active;
                }
            }
        }
    });

    request('info').then(applyInfo).catch((error) => {
        Status.textContent = '';
        showError(error);
    });
})();
