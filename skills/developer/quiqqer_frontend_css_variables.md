---
name: quiqqer_frontend_css_variables
description: Use for CSS and styling decisions in QUIQQER controls, bricks, modules, and templates, including backend and shared backend/frontend controls. Determine styling ownership, reuse the environment's components, and apply the three-layer variable pattern to justified component-specific styling.
category: developer
---

# QUIQQER Frontend CSS Variables

Use this skill for frontend, backend, and shared controls, including bricks. Determine which styling belongs
to the component before introducing CSS declarations, settings, or theming variables.

Goal: PHP only sets the config values, the CSS defines fallbacks and theming hooks in exactly one place.
No inline `style` with long variable names, no scattered fallbacks in the CSS body.

Load `quiqqer_frontend_accessibility` as well when the change also touches markup.

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

### 2. CSS: The Root Element Defines All Variables

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

### 3. CSS Body: Use Only The Short Variables

The rest of the CSS uses exclusively `var(--_NAME)`, without inline fallbacks (they already live in the
root definition):

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

For breakpoints, override the short variable locally instead of setting the value directly:

```css
@media (max-width: 768px) {
    .<vendor>-<control>-split {
        --_split-gap: 2rem;
        grid-template-columns: 1fr;
    }
}
```

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

## Checklist

- [ ] Inferred the supported usage contexts and separated component layout from environment-owned design.
- [ ] Inspected actual template/backend components; any custom design has a concrete reason.
- [ ] Backend-specific appearance stays out of shared frontend assets; referenced classes and tokens are
  available in their intended contexts.
- [ ] PHP: a private `setCustomVariable()` writes `--_q-controlConf-<name>` and skips empty values.
- [ ] CSS root: every configurable property as `--_NAME: var(long, var(--_q-controlConf-NAME, fallback))`.
- [ ] CSS body: only `var(--_NAME)`, no scattered fallbacks.
- [ ] Recurring spacings as pure theming `--_NAME` variables on the root.
- [ ] No hardcoded colors in module CSS: cascade > design system variable > theming variable.
- [ ] Default values (fallbacks) match the previous behavior exactly (no unintended visual change).

## Reference Implementation

- Breadcrumb (quiqqer/core): `src/QUI/Controls/Breadcrumb.php` plus `Breadcrumb.Slider.css`. Origin of the
  `--_q-controlConf-` convention.
