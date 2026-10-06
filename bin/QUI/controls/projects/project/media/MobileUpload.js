/** Bind a mobile upload capability to the selected media folder. */
define('controls/projects/project/media/MobileUpload', ['Ajax', 'Locale'], function (Ajax, Locale) {
    'use strict';

    return function (Panel, Item) {
        let disposed = false;
        let opening = false;
        let Dialog = null;
        let revision = 0;

        const request = (method, endpoint, params) => new Promise((resolve, reject) => {
            Ajax[method](endpoint, resolve, {...params, onError: reject});
        });

        const refresh = async () => {
            const current = ++revision;

            try {
                const value = await request('get', 'ajax_project_get_config', {
                    project: Panel.getProject().getName(),
                    param: 'media_allowQrUpload'
                });
                const enabled = value === true || value === 1 || value === '1';

                if (!disposed && current === revision) {
                    Item[enabled ? 'show' : 'hide']();

                    if (!enabled) { Dialog?.close(); }
                }

                return enabled;
            } catch (error) {
                if (!disposed && current === revision) { Item.hide(); }
                return false;
            }
        };

        Item.getElm();
        Item.hide();

        return {
            refresh,
            open: async () => {
                const Folder = Panel.getCurrentFile();

                if (disposed || opening || Dialog || !Folder) { return; }

                const folderId = Folder.getId();
                const project = Panel.getProject().getName();
                opening = true;

                try {
                    if (!await refresh() || disposed) { return; }

                    const openWindow = await new Promise((resolve, reject) => {
                        require(['controls/upload/mobileUpload/Window'], resolve, reject);
                    });

                    if (disposed) { return; }

                    const session = await request('post', 'ajax_media_createMobileUpload', {
                        project,
                        parentid: folderId
                    });
                    Dialog = openWindow(session, () => {
                        if (!disposed && Panel.getCurrentFile()?.getId() === folderId) {
                            return Panel.openID(folderId, true);
                        }
                    });
                    Dialog.addEvent('onClose', () => { Dialog = null; });

                    if (disposed) { await Dialog.close(); }
                } catch (error) {
                    if (!disposed) {
                        require(['qui/QUI'], async (QUI) => {
                            if (disposed) { return; }
                            const Messages = await QUI.getMessageHandler();
                            Messages.addError(Locale.get('quiqqer/core', 'upload.mobileUpload.error'));
                        });
                    }
                } finally {
                    opening = false;
                }
            },
            destroy: () => {
                disposed = true;
                Dialog?.close();
            }
        };
    };
});
