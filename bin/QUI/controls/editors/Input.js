/**
 * Input field which opens an editor in the window
 */
define('controls/editors/Input', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/windows/Confirm',
    'controls/editors/Preview',

    'css!controls/editors/Input.css'

], function (QUI, QUIControl, QUIConfirm, Preview) {
    "use strict";

    return new Class({

        Extends: QUIControl,
        Type   : '',

        Binds: [
            'open',
            '$onImport'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$Input = null;
            this.$Editor = null;
            this.$Project = null;
            this.$Preview = null;
            this.$Click = null;
            this.$opened = false;
            this.$projectFilesRequest = 0;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * event : on import
         */
        $onImport: function () {
            var self = this;

            this.$Input = this.getElm();
            this.$Input.type = 'hidden';

            this.$Input.addEvent('change', function () {
                self.$Preview.setContent(self.$Input.value);
            });

            this.$Input.addEvent('focus', this.open);

            this.$Elm = new Element('div', {
                'class' : 'field-container-field control-editor-input',
                events  : {
                    click: this.open,
                    focus: this.open
                },
                tabIndex: -1
            }).wraps(this.$Input);


            this.$Preview = new Preview({
                events: {
                    onLoad: function () {
                        self.$Preview.setContent(self.$Input.value);
                    }
                },
                styles: {
                    position: 'relative',
                    zIndex  : 1
                }
            }).inject(this.$Elm);

            this.$Click = new Element('div', {
                'class': 'control-editor-input-click',
                events : {
                    click: this.open,
                    focus: this.open
                }
            }).inject(this.$Elm);

            this.resize();
        },

        /**
         * resize
         */
        resize: function () {
            var computed = this.$Elm.getComputedSize();

            this.$Preview.setStyles({
                height: computed.height - computed.computedTop - computed.computedBottom
            });
        },

        /**
         * Set the used project
         *
         * @param {Object}  Project
         */
        setProject: function (Project) {
            if (typeOf(Project) === 'string') {
                require(['Projects'], function (Projects) {
                    this.setProject(Projects.get(Project));
                }.bind(this));
                return;
            }

            this.$Project = Project;
            this.$setProjectToEditor();
        },

        /**
         * open the editor window
         */
        open: function (event) {
            if (typeOf(event) === 'domevent') {
                event.stop();
            }

            if (this.$opened) {
                return;
            }

            this.$opened = true;

            var self = this;

            new QUIConfirm({
                title    : 'Inhalt einfügen', // #locale
                maxHeight: 600,
                maxWidth : 800,
                events   : {
                    onOpen: function (Win) {
                        var Content = Win.getContent();

                        Content.set('html', '');

                        require(['Editors'], function (Editors) {
                            Editors.getEditor().then(function (Editor) {
                                self.$Editor = Editor;

                                self.$setProjectToEditor();
                                Editor.setContent(self.$Input.value);
                                Editor.inject(Content);
                            });
                        });
                    },

                    onSubmit: function () {
                        if (!self.$Editor) {
                            return;
                        }

                        self.$Input.value = self.$Editor.getContent();
                        self.$Preview.setContent(self.$Input.value);

                        // trigger change event
                        if ("createEvent" in document) {
                            var evt = document.createEvent("HTMLEvents");
                            evt.initEvent("change", false, true);
                            self.$Input.dispatchEvent(evt);
                        } else {
                            self.$Input.fireEvent("onchange");
                        }
                    },

                    onClose: function () {
                        self.$opened = false;
                    }
                }
            }).open();
        },

        /**
         * event : on set project to editor
         */
        $setProjectToEditor: function () {
            const Project = this.$Project;
            const request = ++this.$projectFilesRequest;

            if (this.$Editor) {
                this.$Editor.setProject(Project);
            }

            this.$Preview?.setCSSFiles([]);

            if (!Project) {
                return;
            }

            require(['Editors'], (Editors) => {
                Editors.getProjectFiles(Project).then((files) => {
                    if (request === this.$projectFilesRequest) {
                        this.$Preview?.setCSSFiles(files.cssFiles);
                    }
                });
            });
        }
    });
});
