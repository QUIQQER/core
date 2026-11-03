define('controls/users/auth/GlobalAuthenticatorSettings', [

    'qui/QUI',
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'Mustache',

    'text!controls/users/auth/GlobalAuthenticatorSettings.Row.html',
    'text!controls/users/auth/GlobalAuthenticatorSettings.Header.html'

], function (QUI, QUIControl, QUIAjax, QUILocale, Mustache, templateRow, templateHeader) {
    "use strict";

    const lg = 'quiqqer/core';

    return new Class({

        Type: 'controls/users/auth/GlobalAuthenticatorSettings',
        Extends: QUIControl,

        Binds: [
            '$onChange'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$primaryTable = null;
            this.$secondaryTable = null;
            this.$availableAuthenticators = [];
            this.$secondaryModes = {};
            this.$setupPromise = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * event : on import
         */
        $onImport: function () {
            QUIAjax.get('ajax_users_authenticator_globalAuthenticators', (result) => {
                const available = result.available,
                    globals = result.global;

                this.$availableAuthenticators = available;

                new Element('div', {
                    html: QUILocale.get(lg, 'quiqqer.settings.auth.global.desc')
                }).inject(this.getElm(), 'after');

                let i, r, len, rLen, newRow;
                let row, cell;

                const Table = this.getElm().getParent('table');

                // primary authenticator
                row = document.createElement('tr');
                cell = document.createElement('td');

                row.appendChild(cell);
                Table.appendChild(row);

                let tableHeaderText = Mustache.render(templateHeader, {
                    title: QUILocale.get(lg, 'quiqqer.settings.auth.primary')
                });

                const primaryTable = document.createElement('table');
                primaryTable.classList.add('data-table', 'data-table-flexbox');
                primaryTable.innerHTML = '<thead>' + tableHeaderText + '</thead>';
                primaryTable.setAttribute('data-name', 'primary-authenticator');

                for (i = 0, len = available.length; i < len; i++) {
                    if (!available[i].isPrimaryAuthentication) {
                        continue;
                    }

                    newRow = document.createElement('div');
                    newRow.innerHTML = Mustache.render(templateRow, {
                        title: available[i].title,
                        description: available[i].description,
                        authenticator: available[i].authenticator
                    });

                    newRow.getElements('input').addEvent('change', this.$onChange);

                    primaryTable.appendChild(newRow);
                }

                cell.appendChild(primaryTable);
                this.$primaryTable = primaryTable;


                // secondary authenticator
                const secondaryDescription = document.createElement('div');
                secondaryDescription.classList.add('quiqqer-message', 'quiqqer-message-information');
                secondaryDescription.style.display = 'flex';
                secondaryDescription.style.alignItems = 'center';
                secondaryDescription.style.gap = '10px';
                secondaryDescription.style.width = '100%';
                secondaryDescription.style.maxWidth = '100%';
                secondaryDescription.style.margin = '10px 0';
                secondaryDescription.style.borderWidth = '0';
                secondaryDescription.innerHTML = `
                    <span>
                        ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.type.information')}
                    </span>
                `;


                tableHeaderText = Mustache.render(templateHeader, {
                    title: QUILocale.get(lg, 'quiqqer.settings.auth.secondary')
                });

                const secondaryTable = document.createElement('table');
                secondaryTable.classList.add('data-table', 'data-table-flexbox');
                secondaryTable.innerHTML = '<thead>' + tableHeaderText + '</thead><tbody></tbody>';
                secondaryTable.setAttribute('data-name', 'secondary-authenticator');

                const secondTd = secondaryTable.querySelector('thead th div:nth-child(2)');
                const thirdTd = secondaryTable.querySelector('thead th div:nth-child(3)');

                secondTd.parentNode.removeChild(secondTd);
                thirdTd.parentNode.removeChild(thirdTd);

                secondaryTable.querySelector('tbody').innerHTML = `
                    <tr>
                        <td>
                            <label class="field-container">
                                <div class="field-container-item">
                                    ${QUILocale.get(lg, 'quiqqer.settings.auth.frontend')}
                                </div>  
                                <select class="field-container-field" name="secondary_frontend" data-name="secondary_frontend">
                                    <option value="0">
                                        ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.type.0')}
                                    </option>
                                    <option value="1">
                                        ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.type.1')}
                                    </option>
                                    <option value="2">
                                        ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.type.2')}
                                    </option>
                                </select>                                      
                            </label>
                        </td>
                    </tr>
                    <tr style="display: none;">
                        <td>
                             <div class="field-container">
                                 <div class="field-container-item">
                                    ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.frontend.available')}
                                 </div>
                                 <div data-name="secondary_frontend_authenticators" class="field-container-field" ></div>
                             </div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label class="field-container">
                                <div class="field-container-item">
                                    ${QUILocale.get(lg, 'quiqqer.settings.auth.backend')}
                                </div>  
                                <select class="field-container-field" name="secondary_backend" data-name="secondary_backend">
                                    <option value="0">
                                        ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.type.0')} 
                                    </option>
                                    <option value="1">
                                        ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.type.1')}
                                    </option>
                                    <option value="2">
                                        ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.type.2')}
                                    </option>
                                </select>  
                            </label>
                        </td>
                    </tr>
                    <tr style="display: none;">
                        <td>
                             <div class="field-container">
                                 <div class="field-container-item">
                                    ${QUILocale.get(lg, 'quiqqer.settings.auth.secondary.backend.available')}
                                 </div>
                                 <div data-name="secondary_backend_authenticators" class="field-container-field" ></div>
                             </div>
                        </td>
                    </tr>
                `;

                secondaryTable.querySelector('thead th').style.flexDirection = 'column';
                secondaryTable.querySelector('thead th').appendChild(secondaryDescription);
                cell.appendChild(secondaryTable);
                this.$secondaryTable = secondaryTable;

                const sfAuthenticators = this.$secondaryTable
                    .querySelector('[data-name="secondary_frontend_authenticators"]');

                const sbAuthenticators = this.$secondaryTable
                    .querySelector('[data-name="secondary_backend_authenticators"]');

                let labelNode, clone1, clone2;

                for (i = 0, len = available.length; i < len; i++) {
                    if (!available[i].isSecondaryAuthentication) {
                        continue;
                    }

                    labelNode = document.createElement('label');
                    labelNode.style.width = '100%';
                    labelNode.innerHTML = `
                        <input 
                            type="checkbox" 
                            name="secondary_authenticator" 
                            value="${available[i].authenticator}" 
                        />
                        <span>${available[i].title}</span>
                    `;

                    clone1 = labelNode.cloneNode(true);
                    clone2 = labelNode.cloneNode(true);
                    clone1.querySelector('input').addEventListener('change', this.$onChange);
                    clone2.querySelector('input').addEventListener('change', this.$onChange);

                    if (globals.secondary.frontend.indexOf(available[i].authenticator) !== -1) {
                        clone1.querySelector('input').checked = true;
                    }

                    if (globals.secondary.backend.indexOf(available[i].authenticator) !== -1) {
                        clone2.querySelector('input').checked = true;
                    }

                    sfAuthenticators.appendChild(clone1);
                    sbAuthenticators.appendChild(clone2);
                }

                // configs / checkbox / selected
                this.$checkCheckboxes(globals.primary.frontend, primaryTable, 'frontend');
                this.$checkCheckboxes(globals.primary.backend, primaryTable, 'backend');

                const secondaryFrontend = this.$secondaryTable.querySelector('[data-name="secondary_frontend"]');
                const secondaryBackend = this.$secondaryTable.querySelector('[data-name="secondary_backend"]');

                secondaryFrontend.addEventListener('change', this.$onChange);
                secondaryFrontend.value = globals.secondary_settings.frontend;

                secondaryBackend.addEventListener('change', this.$onChange);
                secondaryBackend.value = globals.secondary_settings.backend;

                this.$secondaryModes = {
                    frontend: Number(secondaryFrontend.value),
                    backend: Number(secondaryBackend.value)
                };

                this.$onChange();
            });
        },

        /**
         * Checks all checkboxes with given names and the specified value ("frontend" or "backend") and sets them to checked.
         *
         * @param {Array<string>} config - Array of names.
         * @param {HTMLElement} table - The DOM element to search in.
         * @param {string} type - frontend / backend
         */
        $checkCheckboxes: function (config, table, type) {
            for (let i = 0, len = config.length; i < len; i++) {
                let condition = [];
                condition.push('[name="' + config[i].escapeRegExp() + '"]');
                condition.push('[value="' + type + '"]');
                condition = condition.join('');

                let checkbox = table.querySelector(condition);

                if (checkbox) {
                    checkbox.checked = true;
                }
            }
        },

        /**
         * event: on checkbox change
         */
        $onChange: function () {
            const result = {
                primary: {frontend: [], backend: []},
                secondary: {frontend: [], backend: []}
            };

            function collect(table, target) {
                table.getElements('input[type="checkbox"]').forEach((elm) => {
                    if (elm.checked) {
                        if (elm.value === 'frontend') target.frontend.push(elm.name);
                        if (elm.value === 'backend') target.backend.push(elm.name);
                    }
                });
            }

            collect(this.$primaryTable, result.primary);

            result.secondary_frontend = parseInt(
                this.$secondaryTable.querySelector('[data-name="secondary_frontend"]').value
            );

            result.secondary_backend = parseInt(
                this.$secondaryTable.querySelector('[data-name="secondary_backend"]').value
            );

            // show 2fa auths
            const sfAuthenticators = this.$secondaryTable.querySelector('[data-name="secondary_frontend_authenticators"]');
            const sbAuthenticators = this.$secondaryTable.querySelector('[data-name="secondary_backend_authenticators"]');

            sfAuthenticators.closest('tr').style.display = result.secondary_frontend !== 0 ? '' : 'none';
            sbAuthenticators.closest('tr').style.display = result.secondary_backend !== 0 ? '' : 'none';

            if (result.secondary_frontend !== 0) {
                result.secondary.frontend = Array.from(
                    sfAuthenticators.querySelectorAll('[type="checkbox"]:checked')
                ).map((node) => {
                    return node.value;
                });
            }

            if (result.secondary_backend !== 0) {
                result.secondary.backend = Array.from(
                    sbAuthenticators.querySelectorAll('[type="checkbox"]:checked')
                ).map((node) => {
                    return node.value;
                });
            }

            // save
            if (this.getElm().nodeName === 'INPUT') {
                this.getElm().value = JSON.encode(result);
            }
        },

        $openSecondFactorSetup: function (authenticators, requiredAreas) {
            return new Promise((resolve, reject) => {
                let finished = false;
                const cancel = () => {
                    if (finished) return;
                    finished = true;

                    requiredAreas.forEach((area) => {
                        this.$secondaryTable.querySelector('[data-name="secondary_' + area + '"]').value =
                            String(this.$secondaryModes[area]);
                    });
                    this.$onChange();
                    reject(new Error('2FA setup cancelled'));
                };

                require(['controls/users/auth/EnableSecondaryAuthenticatorWindow'], (SetupWindow) => {
                    const Setup = new SetupWindow({
                        authenticators: authenticators,
                        message: QUILocale.get(lg, 'exception.auth.require_own_second_factor'),
                        events: {
                            onCompleted: () => {
                                if (finished) return;
                                finished = true;
                                this.$saveAuthenticators().then(resolve, reject);
                            },
                            onCancel: cancel,
                            onClose: () => setTimeout(cancel, 0)
                        }
                    });
                    Setup.setAttribute('backgroundClosable', true);
                    Setup.open();
                }, cancel);
            });
        },

        /**
         * Save the authenticator settings
         *
         * @returns {Promise}
         */
        save: function () {
            if (this.$setupPromise) {
                return this.$setupPromise;
            }

            this.$setupPromise = this.$saveAuthenticators().catch((error) => {
                if (Number(error.getCode?.()) !== 409) {
                    throw error;
                }

                const settings = JSON.parse(this.getElm().value);
                const requiredAreas = ['frontend', 'backend'].filter((area) => {
                    return settings['secondary_' + area] === 1;
                });

                if (requiredAreas.some((area) => settings.secondary[area].length === 0)) {
                    throw error;
                }

                const selected = requiredAreas.flatMap((area) => settings.secondary[area]);
                const authenticators = this.$availableAuthenticators.filter((authenticator) => {
                    return authenticator.isSecondaryAuthentication && selected.includes(authenticator.authenticator);
                });

                if (authenticators.length === 0) {
                    throw error;
                }

                return this.$openSecondFactorSetup(authenticators, requiredAreas);
            }).finally(() => {
                this.$setupPromise = null;
            });

            return this.$setupPromise;
        },

        $saveAuthenticators: function () {
            const authenticators = this.getElm().value;

            return new Promise((resolve, reject) => {
                QUIAjax.post('ajax_users_authenticator_save', (result) => {
                    const settings = JSON.parse(authenticators);
                    this.$secondaryModes = {
                        frontend: settings.secondary_frontend,
                        backend: settings.secondary_backend
                    };
                    resolve(result);
                }, {
                    authenticators: authenticators,
                    onError: reject
                });
            });
        }
    });
});
