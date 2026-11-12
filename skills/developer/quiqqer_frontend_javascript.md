---
name: quiqqer_frontend_javascript
description: Use when writing or changing any JavaScript in QUIQQER packages, regardless of file location, including control modules, template JavaScript, brick scripts, DOM selection, event handling, or QUIControl classes. Prefer vanilla JavaScript over MooTools and select elements through data-name attributes instead of CSS classes.
category: developer
---

# QUIQQER Frontend JavaScript

Use this skill when writing or changing JavaScript in QUIQQER packages: control modules, template
JavaScript, brick scripts, and any other script files. The rules apply to all JavaScript in a package,
regardless of where the file lives in the directory tree.

Load `quiqqer_frontend_accessibility` as well when the change also touches markup or ARIA state.

## Vanilla JavaScript Instead Of MooTools

- New modules: write plain vanilla JavaScript wherever possible.
  Exception: the module scaffolding stays as it is. The AMD `define`/`require` wrapper and the MooTools
  `new Class({...})` definition (with `Extends: QUIControl` and similar) are QUIQQER control infrastructure.
  Do not convert modules to ES modules. Vanilla applies to the code inside.
- Existing modules: when touching a module, write new code inside it directly in vanilla JavaScript.
  Do not rewrite existing MooTools code unprompted (no big-bang refactoring), but do not extend it either.

Vanilla instead of MooTools means concretely:

| MooTools                            | Vanilla                                 |
|-------------------------------------|-----------------------------------------|
| `Elm.getElement('.foo')`            | `Elm.querySelector(…)`                  |
| `Elm.getElements('.foo')`           | `Elm.querySelectorAll(…)`               |
| `Elm.addEvent('click', fn)`         | `Elm.addEventListener('click', fn)`     |
| `Elm.addClass()` / `removeClass()`  | `Elm.classList.add()` / `.remove()`     |
| `Elm.setStyle()` / `setStyles()`    | `Elm.style.x = …`                       |
| `new Element('div', {...})`         | `document.createElement` + attributes   |
| `Array.each`, `Elements.forEach`    | `Array.from(...).forEach`, `for…of`     |
| `typeof x !== 'undefined'` cascades | optional chaining `?.`, `??`            |

## Element Selection Only Through data-name

JavaScript selects elements exclusively through `data-name` attributes, never through CSS classes.
CSS classes are the styling API, `data-name` is the JavaScript API. This allows renaming and restructuring
classes without breaking JavaScript.

`Elm` in the examples below is the control's own root element (in a QUIControl: `this.getElm()`).

```html
<nav class="quiqqer-portfolio-list-categories" data-name="categories">
    <button type="button"
            class="quiqqer-portfolio-list-categories-entry"
            data-name="category">
        …
    </button>
</nav>
```

```js
const Categories = Elm.querySelector('[data-name="categories"]');
const entries = Elm.querySelectorAll('[data-name="category"]');
```

- `data-name` must be set in the HTML template. When switching a selector in JavaScript, always adjust the
  template together with it.
- Keep values short and element-related (do not repeat the package name). They must be unique within the
  control scope: always select relative to the control element itself, never globally through
  `document.querySelector`.
- Keep representing state through classes or ARIA attributes (`.…__active`, `aria-pressed`), not through
  changing `data-name` values. For toggle states prefer the ARIA attribute as the selector
  (`[aria-pressed="true"]`) so there is only one source of truth.

## Locale Contexts And Asynchronous Loading

Use AMD module `Locale` for the QUIQQER Locale singleton. `Locale.get()` remains synchronous. Standard page headers
load one language bundle containing shared translations and the `frontend` or `administration` context.
The bundle registers its shared AMD module, so loading a custom context does not download shared translations again.
Publications use stable paths and a versioned cache argument; they do not retain historical directories.
An already open page can receive newer translations when loading another context. Custom contexts must be loaded explicitly:

```js
async function renderTitle(element) {
    let language;

    do {
        language = QUILocale.getCurrent();
        await QUILocale.loadContext('namescoreDashboard');
    } while (language !== QUILocale.getCurrent());

    element.textContent = QUILocale.get('vendor/package', 'dashboard.title');
}
```

Import `Locale` as `QUILocale` in the surrounding AMD module. Call this function from a control method or lifecycle
handler and handle its rejection in the caller. Each request captures its language; after a language
change, await loading for the new language before calling `get()`. Repeated and concurrent identical requests are
reused. Failed requests can be retried. Unknown contexts reject; a published empty context resolves.

Do not read a custom-context translation at AMD factory evaluation time or inside static control options. Move the
read into a method that awaits `loadContext()` first. Merely starting the Promise is insufficient. Do not make the
AMD factory itself async: consumers expect the control class synchronously.

Use `context` on each locale entry, independently of `datatype`. Multiple names are OR memberships; for example,
`administration,namescoreDashboard` loads automatically in administration. Missing or empty context is shared.
Keep widely shared text unmarked, and check dynamic keys and shared controls before narrowing an existing entry.
See `quiqqer_extension_points` for name validation and metadata/override rules.

Package-owned `package.title` and `package.description` default to `context="administration"`; PHP usage does not
require frontend JavaScript delivery. See [Locale Delivery Contexts](quiqqer_extension_points.md#locale-delivery-contexts)
for the frontend exception and text-only override rules.

## Checklist

- [ ] New code is vanilla JavaScript (except the `new Class` scaffolding).
- [ ] No new MooTools calls (`getElement`, `addEvent`, `setStyle`, …).
- [ ] Selection through `[data-name="…"]`, relative to the control element.
- [ ] `data-name` added in the HTML template.
- [ ] New or functionally changed package-owned JavaScript translations have been checked against their actual
      consumers: backend-only uses `administration`, frontend-only uses `frontend`, and shared text stays unmarked.
      Explicit custom contexts and text-only override ownership are preserved; assignments match across languages.
- [ ] Custom locale contexts are awaited before `get()`, including after a language change.
