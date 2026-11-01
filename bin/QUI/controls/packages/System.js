/**
 * System update panel
 *
 * @event onLoad
 * @event onShowLoader
 * @event onHideLoader
 */
define('controls/packages/System', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/windows/Popup',
    'Packages',
    'Mustache',
    'Locale',
    'package/quiqqer/translator/bin/Translator',

    'text!controls/packages/System.html',
    'css!controls/packages/System.css'

], function (QUI, QUIControl, QUIButton, QUIPopup, Packages, Mustache, QUILocale, Translator, template) {
    "use strict";

    const lg = 'quiqqer/core';

    return new Class({

        Extends: QUIControl,
        Type: 'controls/packages/System',

        Binds: [
            '$onInject',
            'checkUpdates',
            'executeCompleteSetup',
            'startUpdateRun',
            'prepareUpdateRun',
            'openUpdateRun',
            'cancelUpdateRun',
            'showUpdateListWindow',
            'refreshActiveRun',
            'refreshPreparedRunStatus',
            'loadRunHistory',
            'renderRunHistory',
            'startUpdateRunMonitor',
            'stopUpdateRunMonitor',
            'syncUpdateRunStatus'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$Buttons = null;
            this.$CheckButtons = null;
            this.$RunButtons = null;
            this.$Result = null;
            this.$RunState = null;
            this.$CheckSummary = null;
            this.$HistoryResult = null;
            this.$list = [];
            this.$preparedRun = null;
            this.$runStatusTimer = null;
            this.$runMonitorTimer = null;
            this.$runMonitorBusy = false;

            this.addEvents({
                onInject: this.$onInject
            });
        },

        /**
         * Create the DOM-Node element
         *
         * @returns {HTMLDivElement}
         */
        create: function () {
            this.$Elm = new Element('div', {
                'class': 'qui-control-packages-update',
                html: Mustache.render(template, {
                    URL_BIN_DIR: URL_BIN_DIR,
                    titleQUIQQER: QUILocale.get(lg, 'packages.panel.header.quiqqer'),
                    titlePHP: QUILocale.get(lg, 'packages.panel.header.php'),
                    titleDatabase: QUILocale.get(lg, 'packages.panel.header.database'),
                    titleReference: QUILocale.get(lg, 'packages.panel.header.reference'),
                    titleLastUpdateCheck: QUILocale.get(lg, 'packages.panel.header.lastUpdateCheck'),
                    titleLastUpdate: QUILocale.get(lg, 'packages.panel.header.lastUpdate'),
                    titleAvailableUpdates: QUILocale.get(lg, 'packages.panel.update.available.title'),
                    textAvailableUpdates: QUILocale.get(lg, 'packages.panel.update.available.description'),
                    textNoUpdateCheckLoaded: QUILocale.get(lg, 'packages.panel.update.check.notLoaded'),
                    titleSystemUpdate: QUILocale.get(lg, 'packages.panel.update.system.title'),
                    textSystemUpdate: QUILocale.get(lg, 'packages.panel.update.system.description'),
                    textNoUpdatePrepared: QUILocale.get(lg, 'packages.panel.update.run.empty'),
                    titleUpdateHistory: QUILocale.get(lg, 'packages.panel.update.history.title'),
                    textUpdateHistory: QUILocale.get(lg, 'packages.panel.update.history.description')
                })
            });

            this.$CheckButtons = this.$Elm.querySelector('.qui-update-check-actions');
            this.$RunButtons = this.$Elm.querySelector('.qui-update-run-actions');
            this.$Result = this.$Elm.querySelector('.qui-control-packages-update-result');
            this.$RunState = this.$Elm.querySelector('.qui-update-run-state');
            this.$CheckSummary = this.$Elm.querySelector('.qui-update-check-summary');
            this.$HistoryResult = this.$Elm.querySelector('.qui-update-history-result');

            this.$Update = new QUIButton({
                name: 'update',
                text: QUILocale.get(lg, 'packages.panel.btn.startUpdate'),
                textimage: 'fa fa-check-circle-o',
                events: {
                    onClick: () => {
                        this.checkUpdates(true);
                    }
                }
            }).inject(this.$CheckButtons);
            this.$Update.getElm().addClass('qui-update-check-button');

            this.$PrepareUpdate = new QUIButton({
                name: 'prepareUpdate',
                text: QUILocale.get(lg, 'packages.panel.btn.prepareUpdate'),
                textimage: 'fa fa-cog',
                events: {
                    onClick: this.startUpdateRun
                }
            }).inject(this.$RunButtons);
            this.$PrepareUpdate.getElm().addClass('btn-green');

            this.$OpenRun = new QUIButton({
                name: 'openUpdateRun',
                text: QUILocale.get(lg, 'packages.panel.btn.openUpdate'),
                textimage: 'fa fa-external-link',
                events: {
                    onClick: this.openUpdateRun
                }
            });

            this.$CancelRun = new QUIButton({
                name: 'cancelUpdateRun',
                text: QUILocale.get(lg, 'packages.panel.btn.cancelUpdate'),
                textimage: 'fa fa-ban',
                events: {
                    onClick: this.cancelUpdateRun
                }
            });

            if (parseInt(QUIQQER_CONFIG.globals.development)) {
                this.$Setup = new QUIButton({
                    name: 'setup',
                    text: QUILocale.get(lg, 'packages.panel.btn.setup'),
                    textimage: 'fa fa-hdd-o',
                    events: {
                        onClick: this.executeCompleteSetup
                    },
                    styles: {
                        margin: '0 0 0 auto'
                    }
                }).inject(this.$CheckButtons);
            }

            this.$setRunButtons(false);

            return this.$Elm;
        },

        /**
         * event : on inject
         */
        $onInject: function () {
            const container = this.getElm();

            require(['QUIQQER'], (QUIQQER) => {
                QUIQQER.getInformation().then((data) => {
                    const hashNode = container.querySelector('.qui-update-ref');
                    const hashRow = container.querySelector('.qui-update-ref-row');

                    container.querySelector('.qui-update-version-value').set('html', data.version);
                    this.$renderPhpVersion(data.php_version, data.php_update_warning);
                    container.querySelector('.qui-database-value').set(
                        'html',
                        `${data.database.version} (${data.database.type})`
                    );

                    if (data.hash !== '') {
                        hashNode.set('html', data.hash);
                    } else if (hashRow) {
                        hashRow.style.display = 'none';
                    }
                }).then(() => {
                    return this.refreshLastUpdateCheckDate();
                }).then(() => {
                    return this.loadCachedUpdates();
                }).then(() => {
                    return this.refreshActiveRun();
                }).then(() => {
                    return this.loadRunHistory();
                }).then(() => {
                    this.startUpdateRunMonitor();
                    this.fireEvent('load', [this]);
                });
            });
        },

        /**
         * Show the PHP version and explain the requirement for future updates.
         *
         * @param {String} version
         * @param {String|null} warning
         */
        $renderPhpVersion: function (version, warning) {
            const container = this.getElm();
            const Notice = container.querySelector('[data-name="phpVersionNotice"]');
            const NoticeText = container.querySelector('[data-name="phpVersionNoticeText"]');
            container.querySelector('[data-name="phpVersion"]').textContent = version;
            NoticeText.textContent = warning ?? '';
            Notice.hidden = !warning;
        },

        /**
         * Cleanup timers.
         */
        destroy: function () {
            this.stopRunPolling();
            this.stopUpdateRunMonitor();
            this.parent();
        },

        /**
         * Refresh the last update date display
         *
         * @returns {Promise}
         */
        refreshLastUpdateCheckDate: function () {
            return Promise.all([
                Packages.getLastUpdateCheck(false),
                Packages.getLastUpdate(false)
            ]).then((res) => {
                let lastUpdateCheck = res[0];
                let lastUpdate = res[1];
                let language = 'en-US';

                const options = {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                };

                switch (USER.lang) {
                    case 'de':
                        language = 'de-DE';
                        break;

                    case 'en':
                        language = 'en-US';
                        break;
                }

                if (!parseInt(lastUpdateCheck, 10)) {
                    lastUpdateCheck = '---';
                } else {
                    lastUpdateCheck = new Date(parseInt(lastUpdateCheck, 10) * 1000).toLocaleString(language, options);
                }

                if (!parseInt(lastUpdate, 10)) {
                    lastUpdate = '---';
                } else {
                    lastUpdate = new Date(parseInt(lastUpdate, 10) * 1000).toLocaleString(language, options);
                }

                this.$Elm.querySelector('.qui-update-lastCheck').set('html', lastUpdateCheck);
                this.$Elm.querySelector('.qui-update-last').set('html', lastUpdate);
            });
        },

        /**
         * Load cached updates from the scheduled/manual update check.
         *
         * @returns {Promise}
         */
        loadCachedUpdates: function () {
            return Packages.getOutdated(false).then((result) => {
                this.$list = result || [];
                this.renderUpdateList(false);
            });
        },

        /**
         * Execute a complete setup
         *
         * @returns {Promise}
         */
        executeCompleteSetup: function () {
            const Button = this.$Setup;

            Button.setAttribute('textimage', 'fa fa-spinner fa-spin');

            return QUI.getMessageHandler().then(function (MH) {
                return MH.addLoading(QUILocale.get(lg, 'message.setup.runs'));
            }).then(function (Loading) {
                return Packages.setup().then(function () {
                    return Translator.refreshLocale();
                }).then(function () {
                    Loading.finish(QUILocale.get(lg, 'message.setup.successfull'));

                    return QUI.getMessageHandler().then(function (Handler) {
                        Handler.pushSuccess(
                            QUILocale.get(lg, 'message.setup.successfull.title'),
                            QUILocale.get(lg, 'message.setup.successfull'),
                            false
                        );
                    });
                }).catch(function (Error) {
                    return QUI.getMessageHandler().then(function (MH) {
                        if (typeOf(Error) === 'string') {
                            MH.addError(Error);
                            Loading.finish(Error, 'error');
                            return;
                        }

                        if ("getMessage" in Error) {
                            MH.addError(Error.getMessage());
                            Loading.finish(Error.getMessage(), 'error');
                            return;
                        }

                        console.error(Error);
                    });
                });

            }).then(function () {
                Button.setAttribute('textimage', 'fa fa-hdd-o');
            });
        },

        /**
         * Check updates manually.
         *
         * @param {Boolean} force
         * @returns {Promise}
         */
        checkUpdates: function (force) {
            const Button = this.$Update;

            Button.setAttribute('textimage', 'fa fa-spinner fa-spin');
            this.$CheckSummary.set('html', QUILocale.get(lg, 'packages.panel.update.check.running'));

            return Packages.getOutdated(force || false).then((result) => {
                this.$list = result || [];
                this.renderUpdateList(true);
                Button.setAttribute('textimage', 'fa fa-check-circle-o');

                return this.refreshLastUpdateCheckDate();
            }).catch((Exception) => {
                Button.setAttribute('textimage', 'fa fa-check-circle-o');

                return this.$showError(Exception);
            });
        },

        /**
         * Start an update process in a new browser tab.
         *
         * @returns {Promise}
         */
        startUpdateRun: function () {
            const runWindow = window.open('about:blank', '_blank');

            if (runWindow) {
                runWindow.document.write('<!doctype html><title>QUIQQER update</title><body>Preparing update ...</body>');
                runWindow.document.close();
            }

            return this.prepareUpdateRun(runWindow);
        },

        /**
         * Prepare an update process.
         *
         * @param {Window|null} [runWindow]
         * @returns {Promise}
         */
        prepareUpdateRun: function (runWindow) {
            this.$PrepareUpdate.setAttribute('textimage', 'fa fa-spinner fa-spin');

            return Packages.prepareUpdateRun().then((result) => {
                this.$PrepareUpdate.setAttribute('textimage', 'fa fa-cog');

                if (result.active && result.run) {
                    const metadata = result.run.metadata || {};

                    if (runWindow) {
                        if (metadata.webUrl) {
                            runWindow.location.href = this.getAbsoluteRunUrl(metadata.webUrl);
                        } else {
                            runWindow.close();
                        }
                    }

                    this.$preparedRun = {
                        id: result.run.id,
                        url: metadata.webUrl || null,
                        state: result.run
                    };
                    this.renderRunState(QUILocale.get(lg, 'packages.panel.update.run.active'));
                    this.$setRunButtons(Boolean(this.$preparedRun.url));
                    this.startRunPolling();
                    this.loadRunHistory();
                    return;
                }

                this.$preparedRun = {
                    id: result.id,
                    url: this.getAbsoluteRunUrl(result.url),
                    token: result.token || null,
                    state: result.run
                };
                this.renderRunState(QUILocale.get(lg, 'packages.panel.update.run.prepared'));
                this.$setRunButtons(true);
                this.loadRunHistory();

                if (runWindow && this.$preparedRun.url) {
                    if (this.submitUpdateRun(this.$preparedRun.url, this.$preparedRun.token, runWindow)) {
                        this.$preparedRun.token = null;
                    }
                }
            }).catch((Exception) => {
                if (runWindow) {
                    runWindow.close();
                }

                this.$PrepareUpdate.setAttribute('textimage', 'fa fa-cog');
                return this.$showError(Exception);
            });
        },

        /**
         * Open prepared update process in a new tab.
         */
        openUpdateRun: function () {
            if (!this.$preparedRun || !this.$preparedRun.url) {
                return;
            }

            if (this.submitUpdateRun(this.$preparedRun.url, this.$preparedRun.token)) {
                this.$preparedRun.token = null;
            }

            this.startRunPolling();
        },

        /**
         * Open an update runner and exchange its one-time token via POST.
         *
         * @param {String} url
         * @param {String|null} token
         * @param {Window|null} [runWindow]
         * @returns {Boolean}
         */
        submitUpdateRun: function (url, token, runWindow) {
            const TargetWindow = runWindow || window.open('about:blank', '_blank');

            if (!TargetWindow) {
                return false;
            }

            url = this.getAbsoluteRunUrl(url);

            if (!token) {
                TargetWindow.location.href = url;
                return true;
            }

            const TargetDocument = TargetWindow.document;
            const Form = TargetDocument.createElement('form');
            const TokenInput = TargetDocument.createElement('input');

            TargetDocument.title = 'QUIQQER update';
            TargetDocument.body.textContent = 'Opening update ...';
            Form.method = 'post';
            Form.action = url;
            Form.hidden = true;
            TokenInput.type = 'hidden';
            TokenInput.name = 'token';
            TokenInput.value = token;
            Form.appendChild(TokenInput);
            TargetDocument.body.appendChild(Form);
            Form.submit();

            return true;
        },

        /**
         * Cancel the active/prepared update process.
         *
         * @returns {Promise|undefined}
         */
        cancelUpdateRun: function () {
            if (!this.$preparedRun || !this.$preparedRun.id) {
                return;
            }

            this.$CancelRun.setAttribute('textimage', 'fa fa-spinner fa-spin');

            return Packages.cancelUpdateRun(this.$preparedRun.id).then((state) => {
                this.$CancelRun.setAttribute('textimage', 'fa fa-ban');
                this.stopRunPolling();
                this.$preparedRun = null;
                this.renderRunState(QUILocale.get(lg, 'packages.panel.update.run.cancelled'));
                this.$setRunButtons(false);
                this.loadRunHistory();
            }).catch((Exception) => {
                this.$CancelRun.setAttribute('textimage', 'fa fa-ban');
                return this.$showError(Exception);
            });
        },

        /**
         * Load active update process status on panel open.
         *
         * @returns {Promise}
         */
        refreshActiveRun: function (showErrors) {
            showErrors = showErrors !== false;

            return Packages.getActiveUpdateRuns().then((result) => {
                const active = result && result.active || [];

                if (!active.length) {
                    this.$preparedRun = null;
                    this.renderRunState();
                    this.$setRunButtons(false);
                    return;
                }

                const metadata = active[0].metadata || {};

                this.$preparedRun = {
                    id: active[0].id,
                    url: metadata.webUrl || null,
                    state: active[0]
                };
                this.renderRunState(QUILocale.get(lg, 'packages.panel.update.run.active'));
                this.$setRunButtons(Boolean(this.$preparedRun.url));
                this.startRunPolling();
                this.loadRunHistory();
            }).catch((Exception) => {
                if (showErrors) {
                    return this.$showError(Exception);
                }
            });
        },

        /**
         * Refresh prepared update process state.
         *
         * @returns {Promise|undefined}
         */
        refreshPreparedRunStatus: function () {
            if (!this.$preparedRun || !this.$preparedRun.id) {
                return;
            }

            return Packages.getUpdateRunStatus(this.$preparedRun.id).then((state) => {
                this.$preparedRun.state = state;

                if (!this.$preparedRun.url && state.metadata && state.metadata.webUrl) {
                    this.$preparedRun.url = state.metadata.webUrl;
                }

                this.renderRunState();

                if (['finished', 'failed', 'cancelled'].indexOf(state.status) !== -1) {
                    this.stopRunPolling();
                    this.$preparedRun = null;
                    this.renderRunState();
                    this.$setRunButtons(false);
                    this.refreshLastUpdateCheckDate();
                    this.loadRunHistory();
                }
            }).catch(() => {
                this.stopRunPolling();
            });
        },

        /**
         * Render update list.
         *
         * @param {Boolean} manualCheck
         */
        renderUpdateList: function (manualCheck) {
            this.$Result.set('html', '');
            this.$CheckSummary.removeEvents('click');

            if (!this.$list.length) {
                this.$CheckSummary.set('class', 'qui-update-check-summary qui-update-banner empty');
                this.$CheckSummary.set('html', '');

                new Element('div', {
                    html: '<div class="qui-update-banner-title"></div>' +
                        '<div class="qui-update-banner-meta"></div>'
                }).inject(this.$CheckSummary);

                this.$CheckSummary.querySelector('.qui-update-banner-title').set(
                    'text',
                    manualCheck ?
                        QUILocale.get(lg, 'packages.panel.update.check.none') :
                        QUILocale.get(lg, 'packages.panel.update.check.noCachedResult')
                );
                this.$CheckSummary.querySelector('.qui-update-banner-meta').set(
                    'text',
                    QUILocale.get(lg, 'packages.panel.update.available.description')
                );

                this.$Update.getElm().inject(this.$CheckSummary);

                return;
            }

            const majorUpdates = this.countMajorUpdates(this.$list);
            const devUpdates = this.countDevUpdates(this.$list);

            this.$CheckSummary.set('class', 'qui-update-check-summary qui-update-banner warning');
            this.$CheckSummary.set('html', '');
            this.$Update.getElm().inject(this.$CheckButtons);

            const summary = new Element('div', {
                html: '<div class="qui-update-banner-title"></div>' +
                    '<div class="qui-update-banner-meta"></div>'
            }).inject(this.$CheckSummary);

            summary.querySelector('.qui-update-banner-title').set(
                'text',
                this.$list.length + ' ' + QUILocale.get(lg, 'packages.panel.update.summary.updates')
            );
            summary.querySelector('.qui-update-banner-meta').set(
                'text',
                majorUpdates + ' ' + QUILocale.get(lg, 'packages.panel.update.summary.majorChanges') +
                ' · ' +
                devUpdates + ' ' + QUILocale.get(lg, 'packages.panel.update.summary.devTargets')
            );

            new Element('button', {
                type: 'button',
                'class': 'qui-button qui-utils-noselect qui-update-banner-button',
                html: '<span class="fa fa-list"></span> ' +
                    QUILocale.get(lg, 'packages.panel.btn.showUpdates'),
                events: {
                    click: (event) => {
                        event.stop();
                        this.showUpdateListWindow();
                    }
                }
            }).inject(this.$CheckSummary);

            this.$CheckSummary.addEvent('click', this.showUpdateListWindow);
        },

        /**
         * Show available updates in a window.
         */
        showUpdateListWindow: function () {
            if (!this.$list.length) {
                return;
            }

            new QUIPopup({
                title: QUILocale.get(lg, 'packages.panel.update.available.title'),
                maxWidth: 820,
                maxHeight: 680,
                buttons: false,
                events: {
                    onOpen: (Win) => {
                        const Content = Win.getContent();
                        const majorUpdates = this.countMajorUpdates(this.$list);
                        const devUpdates = this.countDevUpdates(this.$list);

                        Content.set('html', '');
                        Content.addClass('qui-update-packages-window');

                        const intro = new Element('div', {
                            'class': 'qui-update-window-intro',
                            html: '<div><strong></strong><span></span></div>'
                        }).inject(Content);

                        intro.querySelector('strong').set(
                            'text',
                            this.$list.length + ' ' + QUILocale.get(lg, 'packages.panel.update.summary.updates')
                        );
                        intro.querySelector('span').set(
                            'text',
                            majorUpdates + ' ' + QUILocale.get(lg, 'packages.panel.update.summary.majorChanges') +
                            ' · ' +
                            devUpdates + ' ' + QUILocale.get(lg, 'packages.panel.update.summary.devTargets')
                        );

                        const table = new Element('div', {
                            'class': 'qui-update-window-table'
                        }).inject(Content);

                        this.renderPackageRows(table, this.$list);
                    }
                }
            }).open();
        },

        /**
         * Render package update rows into a target.
         *
         * @param {HTMLElement} Target
         * @param {Array} list
         */
        renderPackageRows: function (Target, list) {
            new Element('div', {
                'class': 'qui-update-package-header',
                html: '<span>' + QUILocale.get(lg, 'packages.panel.update.table.package') + '</span>' +
                    '<span>' + QUILocale.get(lg, 'packages.panel.update.table.installed') + '</span>' +
                    '<span>' + QUILocale.get(lg, 'packages.panel.update.table.available') + '</span>'
            }).inject(Target);

            list.forEach((pkg) => {
                new Element('div', {
                    'class': 'qui-update-package',
                    html: '<span class="qui-update-package-name"></span>' +
                        '<span class="qui-update-package-version old"></span>' +
                        '<span class="qui-update-package-version new"></span>'
                }).inject(Target);

                const row = Target.getLast();
                row.querySelector('.qui-update-package-name').set('text', pkg.package);
                row.querySelector('.old').set('text', pkg.oldVersion);
                row.querySelector('.new').set('text', pkg.version);
            });
        },

        /**
         * Count updates with a changed major version.
         *
         * @param {Array} list
         * @returns {Number}
         */
        countMajorUpdates: function (list) {
            return list.filter((pkg) => {
                const oldMajor = String(pkg.oldVersion || '').match(/\d+/);
                const newMajor = String(pkg.version || '').match(/\d+/);

                return oldMajor && newMajor && oldMajor[0] !== newMajor[0];
            }).length;
        },

        /**
         * Count dev-target updates.
         *
         * @param {Array} list
         * @returns {Number}
         */
        countDevUpdates: function (list) {
            return list.filter((pkg) => {
                return String(pkg.version || '').indexOf('dev-') !== -1;
            }).length;
        },

        /**
         * Load update run history.
         *
         * @returns {Promise}
         */
        loadRunHistory: function () {
            return Packages.getUpdateRunHistory(20).then((history) => {
                this.renderRunHistory(history || []);
            }).catch(() => {
                this.renderRunHistory([]);
            });
        },

        /**
         * Render update run history.
         *
         * @param {Array} history
         */
        renderRunHistory: function (history) {
            this.$HistoryResult.set('html', '');

            if (!history.length) {
                new Element('div', {
                    'class': 'qui-update-run-empty',
                    text: QUILocale.get(lg, 'packages.panel.update.history.empty')
                }).inject(this.$HistoryResult);
                return;
            }

            const Table = new Element('div', {
                'class': 'qui-update-history-table'
            }).inject(this.$HistoryResult);

            new Element('div', {
                'class': 'qui-update-history-row qui-update-history-head',
                html: '<span>' + QUILocale.get(lg, 'packages.panel.update.history.date') + '</span>' +
                    '<span>' + QUILocale.get(lg, 'packages.panel.update.history.type') + '</span>' +
                    '<span>' + QUILocale.get(lg, 'packages.panel.update.history.status') + '</span>' +
                    '<span>' + QUILocale.get(lg, 'packages.panel.update.history.phase') + '</span>' +
                    '<span>' + QUILocale.get(lg, 'packages.panel.update.history.id') + '</span>'
            }).inject(Table);

            history.forEach((state) => {
                const metadata = state.metadata || {};
                const status = state.status || 'created';
                const phase = state.phase || 'created';
                const type = metadata.type || (metadata.webUrl ? 'web' : (metadata.cliCommand ? 'cli' : 'runner'));

                const row = new Element('div', {
                    'class': 'qui-update-history-row',
                    html: '<span class="qui-update-history-date"></span>' +
                        '<span class="qui-update-history-type"></span>' +
                        '<span class="qui-update-history-status"></span>' +
                        '<span class="qui-update-history-phase"></span>' +
                        '<span class="qui-update-history-id"></span>'
                }).inject(Table);

                row.querySelector('.qui-update-history-date').set('text', this.formatTimestamp(state.createdAt));
                row.querySelector('.qui-update-history-type').set('text', this.getRunTypeLabel(type));

                const statusNode = row.querySelector('.qui-update-history-status');
                statusNode.addClass(status);
                statusNode.set(
                    'html',
                    '<span class="fa ' + this.getRunStatusIcon(status) + '"></span><span></span>'
                );
                statusNode.querySelector('span:last-child').set('text', this.getRunStateLabel('status', status));

                row.querySelector('.qui-update-history-phase').set('text', this.getRunStateLabel('phase', phase));
                row.querySelector('.qui-update-history-id').set('text', state.id || '');
            });
        },

        /**
         * Render update process state.
         *
         * @param {String} [message]
         */
        renderRunState: function (message) {
            if (!this.$preparedRun || !this.$preparedRun.state) {
                this.$RunState.removeClass('active');
                this.$RunState.set('html', '');
                return;
            }

            this.$RunState.addClass('active');

            const state = this.$preparedRun.state;
            const created = state.createdAt ?
                new Date(parseInt(state.createdAt, 10) * 1000).toLocaleString() :
                '---';

            let html = '';

            if (message) {
                html += '<div class="qui-update-check-summary">' + message + '</div>';
            }

            html += '<div class="qui-update-run-detail">' +
                '<div class="qui-update-run-row"><span>' +
                QUILocale.get(lg, 'packages.panel.update.run.id') +
                '</span><span></span></div>' +
                '<div class="qui-update-run-row"><span>' +
                QUILocale.get(lg, 'packages.panel.update.run.status') +
                '</span><span></span></div>' +
                '<div class="qui-update-run-row"><span>' +
                QUILocale.get(lg, 'packages.panel.update.run.phase') +
                '</span><span></span></div>' +
                '<div class="qui-update-run-row"><span>' +
                QUILocale.get(lg, 'packages.panel.update.run.created') +
                '</span><span></span></div>';

            if (this.$preparedRun.url) {
                html += '<div class="qui-update-run-url"></div>';
            }

            html += '</div>';

            this.$RunState.set('html', html);

            const values = this.$RunState.querySelectorAll('.qui-update-run-row span:last-child');
            values[0].set('text', state.id || this.$preparedRun.id);
            values[1].set('text', this.getRunStateLabel('status', state.status || 'prepared'));
            values[2].set('text', this.getRunStateLabel('phase', state.phase || 'created'));
            values[3].set('text', created);

            const urlNode = this.$RunState.querySelector('.qui-update-run-url');

            if (urlNode && this.$preparedRun.url) {
                urlNode.set('text', this.getAbsoluteRunUrl(this.$preparedRun.url));
            }

            this.renderRunActions();
        },

        /**
         * Start update status polling.
         */
        startRunPolling: function () {
            this.stopRunPolling();
            this.$runStatusTimer = window.setInterval(this.refreshPreparedRunStatus, 3000);
        },

        /**
         * Stop update status polling.
         */
        stopRunPolling: function () {
            if (!this.$runStatusTimer) {
                return;
            }

            window.clearInterval(this.$runStatusTimer);
            this.$runStatusTimer = null;
        },

        /**
         * Start monitoring update runs while this panel is open.
         */
        startUpdateRunMonitor: function () {
            if (this.$runMonitorTimer) {
                return;
            }

            this.$runMonitorTimer = window.setInterval(this.syncUpdateRunStatus, 5000);
        },

        /**
         * Stop monitoring update runs.
         */
        stopUpdateRunMonitor: function () {
            if (!this.$runMonitorTimer) {
                return;
            }

            window.clearInterval(this.$runMonitorTimer);
            this.$runMonitorTimer = null;
        },

        /**
         * Sync visible update run state.
         *
         * @returns {Promise|undefined}
         */
        syncUpdateRunStatus: function () {
            if (this.$runMonitorBusy) {
                return;
            }

            this.$runMonitorBusy = true;

            const request = this.$preparedRun && this.$preparedRun.id ?
                this.refreshPreparedRunStatus() :
                this.refreshActiveRun(false);

            return Promise.resolve(request).finally(() => {
                this.$runMonitorBusy = false;
            });
        },

        /**
         * Enable / disable update process buttons.
         *
         * @param {Boolean} openEnabled
         */
        $setRunButtons: function (openEnabled) {
            this.renderRunActions(openEnabled);

            if (!this.$PrepareUpdate) {
                return;
            }

            const hasRun = Boolean(this.$preparedRun && this.$preparedRun.id);

            if (this.$Update) {
                if (openEnabled || hasRun) {
                    this.$Update.disable();
                } else {
                    this.$Update.enable();
                }
            }

            if (openEnabled) {
                this.$PrepareUpdate.disable();
                return;
            }

            if (hasRun) {
                this.$PrepareUpdate.disable();
                return;
            }

            this.$PrepareUpdate.enable();
        },

        /**
         * Render update action buttons inside the update entry.
         *
         * @param {Boolean} [openEnabled]
         */
        renderRunActions: function (openEnabled) {
            const detail = this.$RunState.querySelector('.qui-update-run-detail');

            if (!detail) {
                return;
            }

            let actions = detail.querySelector('.qui-update-entry-actions');

            if (actions) {
                actions.destroy();
            }

            actions = new Element('div', {
                'class': 'qui-update-entry-actions'
            }).inject(detail);

            const canOpen = Boolean(openEnabled || this.$preparedRun && this.$preparedRun.url);

            new Element('button', {
                type: 'button',
                'class': 'qui-button qui-utils-noselect qui-update-run-action' + (canOpen ? '' : ' disabled'),
                disabled: !canOpen,
                html: '<span class="fa fa-external-link"></span> ' +
                    QUILocale.get(lg, 'packages.panel.btn.openUpdate'),
                events: {
                    click: (event) => {
                        event.preventDefault();
                        this.openUpdateRun();
                    }
                }
            }).inject(actions);

            const cancelButton = new Element('button', {
                type: 'button',
                'class': 'qui-button qui-utils-noselect qui-update-run-action danger',
                html: '<span class="fa fa-ban"></span> ' +
                    QUILocale.get(lg, 'packages.panel.btn.cancelUpdate'),
                events: {
                    click: (event) => {
                        event.preventDefault();
                        cancelButton.disabled = true;
                        cancelButton.addClass('disabled');
                        cancelButton.set('html', '<span class="fa fa-spinner fa-spin"></span> ' +
                            QUILocale.get(lg, 'packages.panel.btn.cancelUpdate'));

                        Promise.resolve(this.cancelUpdateRun()).finally(() => {
                            cancelButton.disabled = false;
                            cancelButton.removeClass('disabled');
                            cancelButton.set('html', '<span class="fa fa-ban"></span> ' +
                                QUILocale.get(lg, 'packages.panel.btn.cancelUpdate'));
                        });
                    }
                }
            });

            cancelButton.inject(actions);
        },

        /**
         * Return an absolute update runner URL.
         *
         * @param {String} url
         * @returns {String}
         */
        getAbsoluteRunUrl: function (url) {
            if (!url) {
                return '';
            }

            if (/^https?:\/\//i.test(url)) {
                return url;
            }

            if (url.charAt(0) !== '/') {
                url = '/' + url;
            }

            return window.location.origin + url;
        },

        /**
         * Return translated run status / phase label.
         *
         * @param {String} type
         * @param {String} value
         * @returns {String}
         */
        getRunStateLabel: function (type, value) {
            return QUILocale.get(lg, 'packages.panel.update.run.' + type + '.' + value);
        },

        /**
         * Return translated update run type label.
         *
         * @param {String} value
         * @returns {String}
         */
        getRunTypeLabel: function (value) {
            return QUILocale.get(lg, 'packages.panel.update.history.type.' + value);
        },

        /**
         * Return an icon for a run status.
         *
         * @param {String} status
         * @returns {String}
         */
        getRunStatusIcon: function (status) {
            switch (status) {
                case 'finished':
                    return 'fa-check-circle';

                case 'failed':
                    return 'fa-times-circle';

                case 'cancelled':
                    return 'fa-ban';

                case 'running':
                case 'restart_required':
                    return 'fa-refresh';

                default:
                    return 'fa-clock-o';
            }
        },

        /**
         * Format a unix timestamp with the current user language.
         *
         * @param {Number|String} timestamp
         * @returns {String}
         */
        formatTimestamp: function (timestamp) {
            timestamp = parseInt(timestamp, 10);

            if (!timestamp) {
                return '---';
            }

            return new Date(timestamp * 1000).toLocaleString(
                USER.lang === 'de' ? 'de-DE' : 'en-US',
                {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }
            );
        },

        /**
         * Show ajax errors.
         *
         * @param {Object|String} Exception
         * @returns {Promise}
         */
        $showError: function (Exception) {
            return QUI.getMessageHandler().then(function (Handler) {
                if (Exception && typeof Exception.getMessage === 'function') {
                    Handler.addError(Exception.getMessage());
                    return;
                }

                if (typeof Exception === 'string') {
                    Handler.addError(Exception);
                    return;
                }

                console.error(Exception);
            });
        }
    });
});
