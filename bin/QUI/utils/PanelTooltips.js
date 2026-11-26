/**
 * Fast, text-only tooltips for backend panel header and toolbar buttons.
 * An optional root includes another toolbar owned by the panel.
 */
define('utils/PanelTooltips', [
    'Locale',
    URL_OPT_DIR + 'quiqqer/core/bin/QUI/lib/tippy/tippy.min.js',
    'css!' + URL_OPT_DIR + 'quiqqer/core/bin/QUI/lib/tippy/tippy.css'
], function (QUILocale, tippy) {
    'use strict';

    const attached = new WeakSet();

    const attachArea = function (Panel, Root) {
        if (!Root || attached.has(Root) || Root._tippy) {
            return;
        }

        attached.add(Root);
        const entries = new Map();
        let Current = null;
        // Use the toolbar as reference: Tippy refuses show() on disabled native buttons.
        const Tooltip = tippy(Root, {
            animation: 'shift-away',
            allowHTML: false,
            trigger: 'manual',
            delay: 0,
            content: '',
            aria: {content: null, expanded: false},
            getReferenceClientRect: () => (Current || Root).getBoundingClientRect(),
            onShow: (Instance) => {
                let zIndex = Instance.props.zIndex;

                for (let Ancestor = Current || Root; Ancestor; Ancestor = Ancestor.parentElement) {
                    const ancestorZIndex = Number.parseInt(window.getComputedStyle(Ancestor).zIndex, 10);

                    if (Number.isFinite(ancestorZIndex)) {
                        zIndex = Math.max(zIndex, ancestorZIndex + 1);
                    }
                }

                if (zIndex !== Instance.props.zIndex) {
                    Instance.setProps({zIndex: zIndex});
                }
            }
        });

        const hide = () => {
            if (Current) {
                const descriptions = (Current.getAttribute('aria-describedby') || '').split(/\s+/)
                    .filter((id) => id && id !== Tooltip.popper.id);

                if (descriptions.length) {
                    Current.setAttribute('aria-describedby', descriptions.join(' '));
                } else {
                    Current.removeAttribute('aria-describedby');
                }
            }

            Current = null;
            Tooltip.hide();
        };

        const textFor = (Button, entry) => {
            const text = entry.title || Button.getAttribute('aria-label') || Button.textContent.trim();

            if (!text && Root === Panel.$Header && Button.getAttribute('name') === 'close') {
                return QUILocale.get('quiqqer/core', 'close');
            }

            return text;
        };
        const release = (Button, entry) => {
            if (Current === Button) {
                hide();
            }

            if (entry.title !== null && !Button.hasAttribute('title')) {
                Button.setAttribute('title', entry.title);
            }

            if (entry.label && Button.getAttribute('aria-label') === entry.label) {
                Button.removeAttribute('aria-label');
            }

            entries.delete(Button);
        };

        const refresh = () => {
            entries.forEach((entry, Button) => {
                if (!Root.contains(Button)) {
                    release(Button, entry);
                }
            });

            Array.from(Root.getElementsByTagName('button')).forEach((Button) => {
                if (Button._tippy) {
                    return;
                }

                let entry = entries.get(Button);

                if (!entry) {
                    entry = {title: null, label: null};
                    entries.set(Button, entry);
                }

                if (Button.hasAttribute('title')) {
                    entry.title = Button.getAttribute('title');
                    Button.removeAttribute('title');

                    if (entry.label && Button.getAttribute('aria-label') === entry.label) {
                        Button.removeAttribute('aria-label');
                        entry.label = null;
                    }
                }

                const text = textFor(Button, entry);

                if (text && !Button.textContent.trim() && !Button.hasAttribute('aria-label') &&
                    !Button.hasAttribute('aria-labelledby')) {
                    Button.setAttribute('aria-label', text);
                    entry.label = text;
                }

                if (Current === Button && Tooltip.props.content !== text) {
                    Tooltip.setContent(text);
                }
            });
        };

        const show = (event) => {
            const Button = event.composedPath().find((Node) => Node.tagName === 'BUTTON' && Root.contains(Node));

            if (!Button) {
                return;
            }

            refresh();
            const entry = entries.get(Button);
            const text = entry && textFor(Button, entry);

            if (!text || Button._tippy) {
                return;
            }

            if (Current !== Button) {
                hide();
                Current = Button;
            }

            const descriptions = new Set((Button.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            descriptions.add(Tooltip.popper.id);
            Button.setAttribute('aria-describedby', Array.from(descriptions).join(' '));
            Tooltip.setContent(text);
            Tooltip.show();
            Tooltip.popperInstance?.update();
        };

        const leave = (event) => {
            if (Current?.contains(event.relatedTarget) ||
                (event.type === 'pointerout' && document.activeElement === Current)) {
                return;
            }

            hide();
        };

        const keydown = (event) => {
            if (event.key === 'Escape') {
                hide();
            }
        };

        const listeners = {
            pointerover: show,
            focusin: show,
            pointerout: leave,
            focusout: leave,
            keydown: keydown
        };
        Object.entries(listeners).forEach(([name, callback]) => Root.addEventListener(name, callback));
        const Observer = new MutationObserver(refresh);
        Observer.observe(Root, {
            subtree: true,
            childList: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['title']
        });
        refresh();

        Panel.addEvent('onDestroy', () => {
            Observer.disconnect();
            Object.entries(listeners).forEach(([name, callback]) => Root.removeEventListener(name, callback));
            hide();
            Tooltip.destroy();
            entries.forEach((entry, Button) => release(Button, entry));
            attached.delete(Root);
        });
    };

    return function (Panel, Root) {
        const areas = Root ? [Root] : [Panel.$Buttons, Panel.$Header];
        areas.forEach((Area) => attachArea(Panel, Area));
    };
});
