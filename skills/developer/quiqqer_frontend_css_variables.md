---
name: quiqqer_frontend_css_variables
description: Use for CSS and styling decisions in QUIQQER controls, bricks, modules, and templates, including backend and shared backend/frontend controls. Covers styling ownership, readable selectors and variants, cascade, design tokens, centralized fallbacks, and the three-layer pattern for setting-backed properties.
category: developer
---

# QUIQQER CSS: Ownership, Maintainability And Variables

Use this skill for frontend, backend, and shared controls, including bricks. Determine which styling belongs
to the component before introducing CSS declarations, settings, or theming variables.

Goal: PHP only sets the config values, the CSS defines fallbacks and theming hooks in exactly one place.
No inline `style` with long variable names, no scattered fallbacks in the CSS body.

Load `quiqqer_frontend_accessibility` as well when the change also touches markup.

General CSS decision rules live here alongside QUIQQER's variable contract. For brick class naming and
pragmatic BEM, load `quiqqer_bricks_develop_brick_types`; for Presentation tokens and components, load
`quiqqer_template_presentation_frontend_conventions` when that template is relevant. Template tokens
are not automatically a contract supported by every QUIQQER environment.

## Styling Ownership And Reuse

Determine the intended usage from callers, asset loading, package dependencies, and the task: backend-only,
reusable frontend control or brick, shared backend/frontend control, or template-specific component.
Make this decision independently when the evidence is sufficient. Ask only when an unresolved ambiguity
would change the implementation. The environment currently used for testing does not define every supported
usage context.

- Let the surrounding template or backend supply its typography, standard element styling, and component
  design. Do not redeclare font sizes, colors, borders, radii, field padding, or interaction states merely
  to make a reusable control look right in the current installation.
- Inspect the relevant template's actual CSS and loaded assets to discover its available components,
  variants, and variables. Buttons, inputs, badges, cards, and typography are examples, not an exhaustive
  list. For backend usage, inspect the backend's component styles and shared UI-library defaults as well.
  See "Existing Components First" below before writing a replacement.
- Keep necessary component layout and behavior in control CSS, such as arrangement, spacing between
  component sections, and sizing constraints. Preserve semantic markup and accessible interaction.
- A concrete component requirement, requested design variant, or intentional editor setting can justify
  additional styling. Prefer existing variants or hooks, keep the exception scoped, and briefly explain
  why the environment's existing styling does not meet the requirement. Do not add settings just to
  recreate a template's default design.
- For controls shared by backend and frontend, place backend-specific appearance in an appropriate
  stylesheet loaded only by backend entrypoints, with selectors scoped to the intended control. Reuse
  an existing backend stylesheet where suitable; do not require one new file per control. Do not load
  backend appearance from the shared control or force its dependencies into frontend templates.
- A CSS variable makes a value configurable, not automatically compatible with other templates. Likewise,
  `:where()` can make justified defaults easier to override, but does not fix misplaced design ownership
  or missing variable definitions. Do not compensate with increasingly specific selectors or `!important`.

Apply this distinction to new work and the requested change; do not turn a focused task into a general
restyling or migration of existing controls and templates.

## Readable Selectors, Defaults And Variants

Required boundaries: keep selectors within their intended scope, preserve public styling hooks and
accessible states, and limit changes to the task. Do not rename classes, introduce markup wrappers,
reorder unrelated CSS, or migrate working code merely to satisfy these recommendations.

Use the following as decision guidelines, not a ban on repetition or overrides:

- Place declarations where their intended applicability is clear. Share actual common behavior;
  assign variant-specific values directly when a broader rule would only require unnecessary undoing.
- A base rule may describe a deliberate default with exceptions. Responsive changes, interaction
  states, theme overrides, progressive enhancement, and scoped third-party integration are legitimate
  uses of the cascade. Do not split a clear default into exhaustive selector lists just to avoid overrides.
- Group selectors when they express the same design decision and should change together. Equal values
  alone do not justify coupling unrelated components. A little repetition is preferable to an artificial
  abstraction; do not add a class, variable, or helper solely to shorten a selector list.
- Prefer short, explicit selector lists with one selector per line. Use `:is()` only when it improves
  readability; avoid chains of exclusions or deep nesting to compress variant logic. `:is()` takes
  its most specific argument's specificity, while `:where()` has zero specificity. Neither is
  automatically a behavior-neutral replacement for an existing selector list.
- Prefer existing component classes and low specificity. Add ancestor or structural selectors only
  when that relationship matters, not simply to win the cascade. Scoped element selectors can be
  appropriate for editorial content. Do not add IDs or `!important` as routine specificity fixes;
  a necessary integration workaround should be narrow and explain why it is needed.
- Reset only properties the component needs to control. Values such as `margin: 0`, `min-width: 0`,
  and `max-width: none` can be necessary. Omitting a declaration can expose another matching rule,
  so removing a reset is not automatically behavior-preserving. Avoid broad resets such as `all: unset`
  unless their full effects are required and accessible behavior is preserved.
- Keep base rules, variants, states, and responsive adjustments easy to follow within the existing
  file organization. Comment non-obvious reasons or constraints, not what a declaration already says.

Example with abbreviated class names: all three layouts share a column, but only two constrain text.

```css
/* Before: an unnecessarily broad width rule followed by its reversal. */
.author__card--top .author__description,
.author__card--topLeft .author__description,
.author__card--topTextLeft .author__description {
    grid-column: 1;
    max-width: 44ch;
}

.author__card--topLeft .author__description {
    max-width: none;
}
```

```css
/* After: group separately by the behavior that is actually shared. */
.author__card--top .author__description,
.author__card--topLeft .author__description,
.author__card--topTextLeft .author__description {
    grid-column: 1;
}

.author__card--top .author__description,
.author__card--topTextLeft .author__description {
    max-width: 44ch;
}
```

This simplification assumes no other matching width rule needs the explicit `none` override.

## Choosing Values And Variables

First decide whether a declaration is needed at all; do not simply wrap every number in a variable.

- Inherit typography and colors when the environment already supplies the intended appearance.
  Choose semantic HTML for meaning: `strong` expresses importance and `em` expresses emphasis, not
  merely a desired font weight or style. Purely visual treatment can remain CSS when the component owns it.
- Prefer design tokens established for the intended use; a variable's existence alone does not make
  it a recommended convention. When no established spacing token fits, purposeful component-owned
  distances are allowed. A simple property without its own fallback or editor setting may use an
  established token directly, such as `font-size: var(--qui-fs-sm)`.
  No private alias or new public hook is required when that token is available in all supported contexts.
- When a fallback is needed, define it once in a private variable on the control root and use that alias
  in the consuming declarations. Fallbacks must be valid for the property, including appropriate units
  where needed; unitless values are correct for some properties.
- Setting-backed properties must use all three layers below. A public theming hook without an editor
  setting needs a concrete customization purpose; repetition alone does not require a public API.
- Keep functional values such as `0`, `100%`, and `aspect-ratio: 1` direct. `border-radius: 50%` is valid
  when a circular shape is a component requirement. Component-specific dimensions such as a readable
  text measure need a purpose, not automatically a variable.

For example, a private alias can centralize a portability fallback without exposing another public hook:

```css
.vendor-package-control {
    --_infoFontSize: var(--qui-fs-sm, 0.875rem);
}

.vendor-package-control__info {
    font-size: var(--_infoFontSize);
}
```

## The Three-Layer Pattern

For properties the component intentionally owns, every setting-backed CSS property passes through three layers:

1. Theming layer: a long, collision-safe variable, overridable from outside (theme or project CSS):
   `--<vendor>-<control>-<name>` (fully written out).
2. Config layer: the value set by PHP from the settings: `--_q-controlConf-<name>`.
3. Fallback: the default value directly in the CSS.

Nested from outside to inside, bound to a short internal variable `--_<name>`:

```css
--_<name>: var(--<vendor>-<control>-<name>, var(--_q-controlConf-<name>, <fallback>));
```

Concrete example from the Breadcrumb control in quiqqer/core (`Breadcrumb.Slider.css`):

```css
--_font-size: var(--quiqqer-core-controls-breadcrumb-font-size, var(--_q-controlConf-font-size, 0.9em));
```

### Naming Convention `--_`

The underscore prefix `--_NAME` marks: do not override this variable from outside. It is only used
internally in the CSS body. Overriding happens exclusively through the long theming variable (layer 1).

## Implementation

### 1. PHP: Set The Config Values

A small private helper method writes the config layer onto the control element. Empty values are skipped,
so the CSS fallback applies:

```php
/**
 * Write a control config CSS variable to the root element style. The CSS
 * picks it up via the long, themeable variable as a fallback layer.
 */
private function setCustomVariable(string $name, string $value): void
{
    if ($name === '' || $value === '') {
        return;
    }

    $this->setStyle('--_q-controlConf-' . $name, $value);
}
```

Call it per setting, only when a value exists:

```php
$sectionGap = (string)$Project->getConfig($prefix . 'sectionGap');

if (isset(self::SECTION_GAP_PRESETS[$sectionGap])) {
    $this->setCustomVariable('sectionGap', self::SECTION_GAP_PRESETS[$sectionGap]);
}
```

Note: `setStyle()` writes the `style="…"` onto the outer control wrapper element. If the actual CSS root
element lives inside the template (for example an `<article>` inside the wrapper), that is fine: CSS custom
properties inherit, so the variable is available in the inner element through `var()`.

### 2. CSS: Centralize Fallbacks And Theming On The Root

Define the short `--_NAME` variables once on the unique root element. The complete fallback and theming
logic lives here:

```css
.<vendor>-<control> {
    /* config layer: long themeable variable > control config > fallback */
    --_sectionGap: var(--<vendor>-<control>-sectionGap, var(--_q-controlConf-sectionGap, 4rem));

    /* theming layer: pure theming variables (without PHP config) */
    --_fields-gap: var(--<vendor>-<control>-fields-gap, 2rem);

    row-gap: var(--_sectionGap);
}
```

### 3. CSS Body: Consume Aliases Or Direct Tokens

For properties with centralized fallback or theming logic, use `var(--_NAME)` without repeating that
logic in the CSS body. Simple properties may use verified tokens directly as described above:

```css
.<vendor>-<control>-fields {
    gap: var(--_fields-gap);
}
```

## Pure Theming Variables (Without Config)

Recurring values that do not come from settings but should stay adjustable through theming in one place
also get a `--_NAME` variable on the root, just without the config layer:

```css
--_fields-gap: var(--<vendor>-<control>-fields-gap, 2rem);
```

This allows overriding, for example, a uniform spacing that repeats across several selectors centrally.
Do not introduce this public hook when a direct token or a private fallback alias already meets the need.

## Responsive Defaults Preserve The Variable Contract

A breakpoint may change the default without bypassing a public theming value or PHP configuration.
Do not replace an alias that resolves those layers with a literal. Instead, change an internal default
on the same control root where the fallback chain is resolved:

```css
.vendor-package-control {
    --_gapDefault: 1.5rem;
    --_gap: var(--vendor-package-control-gap, var(--_q-controlConf-gap, var(--_gapDefault)));
}

.vendor-package-control__fields {
    gap: var(--_gap);
}

@media (max-width: 768px) {
    .vendor-package-control {
        --_gapDefault: 0.5rem;
    }
}
```

The default and its alias must resolve together: changing only a default on a descendant does not
recompute an alias already resolved on an ancestor. Purely internal layout values without a public or
config contract can still change directly at a breakpoint; no extra variable layer is needed for them.

## Existing Components First

Before writing new CSS, inspect the relevant template or backend stylesheets and component documentation.
Find the existing selectors, variants, and public variable hooks for the requested UI, including hover,
focus, disabled, and supported theme states. Use those components in the markup; adjust variants through
their hooks only when the task requires a deviation from the environment's defaults.

For reusable packages, distinguish a component contract supported by their target environments from a class
or token specific to one installed template. Do not assume the latter exists elsewhere; keep template-specific
adaptations in the template or an explicit integration.

Writing custom button or chip CSS (reset, padding, radius, hover) parallel to an existing component
duplicates the design system and drifts apart on theme changes.

## Colors: No Module-Level Hardcodes

Modules and controls do not introduce their own color values (no `color: #23303d` or similar in module
CSS). Instead, in this order:

1. Use the cascade: when the context (body, card container, …) already provides the correct color, do not
   set `color` or `background` at all. Often removing a hardcoded color is the correct fix.
2. When the component must explicitly set a color, use a verified design-system contract for its intended
   contexts. Inspect the canonical token definitions and their loading paths. Template-specific code may
   use that template's tokens; shared controls must not assume backend or single-template tokens exist
   everywhere. Prefer semantic roles that follow the theme over a palette shade chosen for one appearance.
3. Use a theming variable following the three-layer pattern when the color must be configurable per project
   or control, with a design system variable (not a hex value) as the fallback.

Hex or RGB values directly in module CSS are only acceptable for truly module-specific, non-themeable
effects (for example skeleton shimmer or overlays with `rgba(0,0,0,…)`).

Do not hide arbitrary module colors inside fallback chains to claim portability. If an essential color
role has no shared token contract, resolve the intended integration or explicit component requirement;
a token from the currently installed template alone does not establish cross-template support.

## Checklist

- [ ] Inferred the supported usage contexts and separated component layout from environment-owned design.
- [ ] Inspected actual template/backend components; any custom design has a concrete reason.
- [ ] Backend-specific appearance stays out of shared frontend assets; referenced classes and tokens are
  available in their intended contexts.
- [ ] Shared rules express real common behavior; variants and overrides have a clear purpose.
- [ ] No unnecessary selector coupling, resets, specificity escalation, or cosmetic refactoring.
- [ ] For setting-backed styles, PHP writes only `--_q-controlConf-<name>` and skips empty values.
- [ ] CSS root: every setting-backed property as `--_NAME: var(long, var(--_q-controlConf-NAME, fallback))`.
- [ ] Needed fallbacks are centralized; simple verified tokens and functional values may be used directly.
- [ ] Public hooks have a customization purpose; responsive defaults preserve theme/config priority.
- [ ] No hardcoded colors in module CSS: cascade > design system variable > theming variable.
- [ ] Default values (fallbacks) match the previous behavior exactly (no unintended visual change).

## Reference Implementation

- Breadcrumb (quiqqer/core): `src/QUI/Controls/Breadcrumb.php` plus `Breadcrumb.Slider.css`. Origin of the
  `--_q-controlConf-` convention.

## Further Reading

Consult these for background when needed, not as additional mandatory rulebooks. The local decisions
above remain authoritative; do not import external naming, tooling, or architecture conventions wholesale.

- MDN, CSS organization: https://developer.mozilla.org/en-US/docs/Learn_web_development/Core/Styling_basics/Organizing
- MDN, specificity: https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_cascade/Specificity
- Harry Roberts, meaningful rather than coincidental reuse: https://cssguidelin.es/#dry
