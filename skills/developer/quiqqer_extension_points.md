---
name: quiqqer_extension_points
description: Use when adding or changing QUIQQER package extension points such as package.xml providers, XML configuration files, events, console tools, permissions, settings, controls, assets, or setup-related integration.
category: developer
---

# QUIQQER Extension Points

Use this skill when implementing QUIQQER package integrations or Core extension points. Add only the extension files and providers required by the task.

## Package First

- For normal feature work, create or extend a package instead of changing Core.
- Use `quiqqer-module` for normal extension packages.
- Use `quiqqer-template` only for template packages.
- Use `quiqqer/core` as the current platform dependency.
- Keep PHP classes autoloadable through Composer PSR-4 under `src/`.
- Put browser-accessible assets under `bin/`.

Minimal package shape:

```text
composer.json
package.xml
locale.xml
src/
bin/
```

Add further XML files only when the package actually provides that behavior.

## Composer Metadata

Every package needs valid Composer metadata:

```json
{
  "name": "vendor/package-name",
  "type": "quiqqer-module",
  "description": "Short package description.",
  "license": "GPL-3.0-or-later",
  "require": {
    "php": "^8.2",
    "quiqqer/core": "^2"
  },
  "autoload": {
    "psr-4": {
      "Vendor\\Package\\": "src/Vendor/Package"
    }
  }
}
```

Declare direct runtime dependencies in `require`. Use `suggest` for optional integrations.

## package.xml Providers

Use `package.xml` for QUIQQER-facing metadata and providers:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<quiqqer xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://www.quiqqer.com/docs/schemas/2.x/package.xsd">
    <package>
        <title>
            <locale group="vendor/package" var="package.title"/>
        </title>

        <description>
            <locale group="vendor/package" var="package.description"/>
        </description>

        <provider>
            <desktopSearch src="\Vendor\Package\Search\Provider"/>
        </provider>
    </package>
</quiqqer>
```

Provider rules:

- Declare a provider only when the package implements that provider API.
- Use fully qualified, Composer-autoloadable class names.
- Keep package metadata consistent with `composer.json`.
- Use locale references for package title and description.
- Run setup after changing provider declarations.

Common provider types include `auth`, `desktopSearch`, `installationWizard`, `rest`, `mcp`, and `mcpSkill`.

## XML Files By Use Case

Use the smallest XML surface needed:

- `console.xml`: register console tools
- `database.xml`: create or update package database tables
- `events.xml`: register event listeners
- `locale.xml`: provide PHP and JavaScript translations
- `media.xml`: add media attributes
- `menu.xml`: add backend menu entries
- `permissions.xml`: declare permissions
- `settings.xml`: add package or project settings
- `site.xml`: add site types or site editor behavior
- `user.xml`: extend user data or UI
- `group.xml`: extend group data or UI
- `panel.xml` / `panels.xml`: add backend panels
- `widgets.xml`: declare desktop widgets

Setup imports package XML files. Run `./console setup` after adding or changing XML metadata in a development installation.

## XML Structure And XSDs

Use `<quiqqer>` as the document root for new or structurally revised XML files read by Core and Utils. Keep the
format-specific element inside it. Files that already have this root must not receive a second wrapper.

Common structures and schema names:

| XML file | Structure below the document root | XSD |
| --- | --- | --- |
| `package.xml` | `quiqqer/package` | `package.xsd` |
| `console.xml`, `events.xml`, `database.xml` | `quiqqer/console`, `quiqqer/events`, `quiqqer/database` | Matching file stem + `.xsd` |
| `user.xml`, `group.xml`, `site.xml` | `quiqqer/user`, `quiqqer/group`, `quiqqer/site` | Matching file stem + `.xsd` |
| `locale.xml` and every language/topic XML | `quiqqer/locales` | `locale.xsd` |
| Package `settings.xml` | `quiqqer/settings` | `settings.xsd` |
| Project `settings.xml` | `quiqqer/project/settings` | `settings.xsd` |
| `panel.xml` | `quiqqer/window` | `panel.xsd` |
| `panels.xml` | `quiqqer/panels` | `panels.xsd` |
| `engines.xml` | `quiqqer/template_engines` | `engines.xsd` |
| `wysiwyg.xml` | `quiqqer/editors` | `wysiwyg.xsd` |
| Legacy editor XML toolbar | `quiqqer/toolbar` | `toolbar.xsd` |

The [schema guide](https://www.quiqqer.com/docs/developer/package-reference/xml-schemas) lists all available schemas,
including `media.xsd`, `menu.xsd`, `permissions.xsd`, and `widgets.xsd`.

Put `xmlns:xsi` and `xsi:noNamespaceSchemaLocation` on the outer `<quiqqer>` element in complete files and copyable
examples. Use the matching public schema at `https://www.quiqqer.com/docs/schemas/2.x/<name>.xsd`. These are standalone
XSD 1.0 schemas without a target namespace: do not use the schema URL as a default `xmlns`.

A locale manifest uses the same schema as its referenced language files:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<quiqqer xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://www.quiqqer.com/docs/schemas/2.x/locale.xsd">
    <locales>
        <!-- German -->
        <file file="/locale/de.xml"/>

        <!-- English -->
        <file file="/locale/en.xml"/>
    </locales>
</quiqqer>
```

Language files put `<groups name="vendor/package" datatype="php,js">` inside `quiqqer/locales`, with their existing
`<locale>` entries and language elements inside each group. Wrapping a file must preserve variable names, groups,
attributes, translation text, CDATA, and meaningful whitespace. Partial snippets such as `<field>` or `<category>`
remain fragments; place them inside the documented parent structure rather than wrapping each fragment separately.

In UI `<settings>` groups, use `<title>` for the heading and `<description>` for explanatory content. Direct `<text>`
children are deprecated and rejected by the XSDs, but remain readable for compatibility. When updating a legacy group,
replace body text with `<description>` and a `<text>` used as the heading with `<title>`; the Settings reader retains its
old first-text-as-title fallback. Descriptions support plain text, locale references, and CDATA, preserve their position
among the controls, and never become the heading. `<text>` labels inside inputs, other controls, categories, and tabs
remain supported. Apply this rule to settings groups in package/project settings, user, group, site, and panel XML.

### Compatibility And Scope

- Core and Utils continue to read legacy roots such as `<events>`, `<user>`, and `<locales>` for backward compatibility.
- XSD validation deliberately requires `<quiqqer>` and rejects legacy roots. Do not relax the schemas or remove schema
  links to hide these errors. Reader compatibility and schema validity are separate requirements.
- When changing a reader, test that legacy and wrapped documents produce the same declarations. Existing readers often
  already support both through descendant queries; do not rewrite them merely to introduce the wrapper.
- This standard covers formats read by Core and Utils. Module-owned parsers, for example for `cron.xml` or `demodata.xml`,
  require separate verification and are not implicitly part of a Core XML migration. External formats such as PHPUnit,
  PHPCS, PHIVE, and SVG keep their own roots. Do not bulk-convert unrelated modules or legacy test fixtures.

### Schema Validation And Maintenance

Validate edited complete documents against the matching XSD, for example:

```shell
curl -fsSLo /tmp/quiqqer-events.xsd https://www.quiqqer.com/docs/schemas/2.x/events.xsd
xmllint --nonet --noout --schema /tmp/quiqqer-events.xsd events.xml
```

Validate locale manifests and each referenced catalog separately with `locale.xsd`; validating the manifest does not
validate its referenced files. Schema checks do not verify PHP classes, paths, permissions, or translation keys, and a
schema-location attribute does not enable automatic XSD validation during imports.

Schemas and complete XML examples belong in the `quiqqer/ecosystem/documentation` repository, not Core's former `doc/`
directory. Change `scripts/generate_xsd.py` when the supported structure changes, then run
`python3 scripts/generate_xsd.py`, `python3 scripts/test_xsd.py`, and `python3 scripts/generate_xsd.py --check` from that
repository. Update its XML examples and reference pages together. Keep both generated copies in `xml/` and
`docs/public/schemas/2.x/` synchronized; the documentation build publishes the latter. Do not hand-edit generated XSDs
or infer a schema from a single example.

## Events

Register event listeners in `events.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<quiqqer xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://www.quiqqer.com/docs/schemas/2.x/events.xsd">
    <events>
        <event on="onPackageSetup" fire="\Vendor\Package\EventHandler::onPackageSetup"/>
    </events>
</quiqqer>
```

Listener rules:

- Use the `on...` event name form in XML.
- Match the listener method signature to the event parameters.
- Guard setup listeners against unrelated packages:

```php
if ($Package->getName() !== 'vendor/package') {
    return;
}
```

- Use `priority` only when listener order matters.
- Keep request-level listeners small and predictable.
- Catch expected exceptions only when the event should not abort the workflow.

## Console Tools

Register runtime commands in `console.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<quiqqer xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://www.quiqqer.com/docs/schemas/2.x/console.xsd">
    <console>
        <tool exec="\Vendor\Package\Console\RebuildIndex"/>
    </console>
</quiqqer>
```

Implement tools by extending `QUI\System\Console\Tool`. Use explicit package-scoped command names such as `vendor-package:rebuild-index`. Use `addArgument()` for documented arguments and avoid reserved argument names: `help`, `tool`, `listtools`, `u`, `p`, `username`, `password`.

Use `composer.json` scripts for repository checks. Use `console.xml` only for commands that run inside an installed QUIQQER system.

## Permissions

Declare package permissions in `permissions.xml` and check them where behavior is exposed to users, tools, Ajax endpoints, or MCP tools.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<quiqqer xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://www.quiqqer.com/docs/schemas/2.x/permissions.xsd">
    <permissions>
        <permission name="vendor.package.action" type="bool">
            <defaultvalue>0</defaultvalue>
        </permission>
    </permissions>
</quiqqer>
```

Use dedicated permissions for sensitive write, delete, publish, update, cache, or automation actions.

## Controls And Assets

Use PHP controls when the server prepares attributes, templates, permissions, locale values, or package data. PHP controls extend `QUI\Control` and can set a JavaScript control through `setJavaScriptControl()`.

JavaScript controls are AMD modules loaded through RequireJS paths. The control name must match the value used in `data-qui` or `setJavaScriptControl()`.

Prefer established Core controls for common backend inputs:

- `controls/projects/project/site/Input`
- `controls/projects/project/media/Input`
- `controls/lang/InputMultiLang`
- `controls/lang/ContentMultiLang`
- `controls/editors/Input`
- `controls/users/Select`
- `controls/usersAndGroups/Select`

## Validation

After changing extension declarations:

- Validate edited XML syntax and complete documents against their matching XSD as described above.
- Validate `composer.json`.
- Run `./console setup` in a development installation when package metadata must be re-imported.
- Run the package-local checks: `./tools/phpcs`, `./tools/phpstan`, and `./tools/phpunit`.
- Manually verify the feature in the administration interface, CLI, or relevant package workflow.

## Tests For Extension Work

Add or update PHPUnit tests for every fix and every feature that changes extension behavior.

Use the repository's existing test structure. Core is a useful reference:

- `phpunit.dist.xml` defines Unit and Integration suites.
- `tests/phpunit-bootstrap.php` loads the QUIQQER bootstrap and shared test helpers.
- `tests/unit/` contains isolated behavior tests.
- `tests/integration/` contains database, project, media, user, group, and runtime integration tests.
- `tests/stubs/` contains narrow compatibility stubs for optional or environment-specific classes.

For provider, XML, event, console, permission, and setup behavior:

- Test pure parsing or registration logic with unit tests when possible.
- Test database, project, media, or setup side effects with integration tests.
- Use fixtures for fake actions, fake providers, or controlled runtime collaborators.
- Use stubs when PHPUnit cannot load optional package dependencies or classes that only exist with a newer optional package version.
- Keep stubs minimal and local to the test suite. Do not weaken production code to make tests pass.

If a test cannot run because the environment is missing a real external service, mark only that integration path as skipped and keep unit coverage for the decision logic.

## References

- Package development: https://www.quiqqer.com/docs/developer/package-development
- XML configuration: https://www.quiqqer.com/docs/developer/package-reference/xml-files
- package.xml: https://www.quiqqer.com/docs/developer/package-reference/package-xml
- Events: https://www.quiqqer.com/docs/developer/events
- CLI: https://www.quiqqer.com/docs/developer/cli
