---
name: quiqqer_package_quality_upgrade
description: Use when modernizing and completing a QUIQQER package in the mandatory sequence quality files, PHPUnit coverage, DBAL migration, and PHPStan 2 at level 8, with current PHIVE tools, portable database.xml schemas, CI stubs for optional dependencies, package metadata, locale organization and completeness, licensing, README documentation, and required visual assets.
category: developer
---

# QUIQQER Package Quality Upgrade

Modernize one package at a time. Preserve behavior and keep commits reviewable. Work in the repository and branch the
developer has already selected. Do not switch branches or infer that another branch would be more appropriate.
Load `quiqqer_developer_workflow` as well when commit or handover rules are needed.

## 1. Respect The Current Repository State

Inspect the worktree before changing files:

```shell
git status --short
git diff
```

Preserve unrelated local changes. Never switch branches, initialize a different package checkout, discard changes, or
rewrite history as part of this quality workflow unless the developer explicitly requests it.

## 2. Inventory The Package

Inspect at least:

- `composer.json` and `composer.lock` context
- `.phive/phars.xml` (the current PHIVE configuration; do not use or create a root-level `phive.xml`)
- `phpstan.neon`, `phpstan.dist.neon`, and baselines
- `phpunit.dist.xml` and `tests/`
- `phpcs.xml.dist`
- CI configuration
- `database.xml`
- `locale.xml`, referenced language files, locale groups, and translation coverage
- active legacy database calls

Use `rg` to find suppressed analysis, optional dependencies, PDO, MySQL-only SQL, and old database APIs. Include dead or
commented legacy implementations in the cleanup decision.

## 3. Follow The Mandatory Upgrade Sequence

For a full package quality upgrade, the following phase order takes precedence over the order of the detailed reference
sections below. Do not combine or reorder the phases merely because PHPStan, DBAL, and PHPUnit findings overlap.

### Phase 1: Quality Files And Tooling

Modernize the quality infrastructure before changing tests or production behavior:

- CI PHP versions and jobs
- package-local PHIVE tools
- Composer requirements, scripts, and development autoloading
- PHPCS, PHPUnit, and PHPStan configuration
- supported PHP version range
- an empty PHPStan baseline

Validate configuration syntax and Composer metadata. PHPStan may be run once to inventory findings, but do not start the
production-code remediation in this phase. Keep dependency changes in separate commits when they carry their own semantic
meaning, such as a required ERP major upgrade. Commit the quality-file phase before adding the test suite.

### Phase 2: PHPUnit Before Production Modernization

Build the PHPUnit safety net against the existing production implementation. Reach at least 80% line coverage before the
DBAL migration starts. Database-backed tests must use DBAL from the beginning for fixtures, assertions, and cleanup even
while the production code still uses legacy database APIs.

Exercise the behavior that the later migration must preserve, including create, update, lookup, search, sort, pagination,
archive, delete, session/global state, and relevant ERP workflows. Run the suite twice consecutively and commit the tests
and fixtures before changing production database access.

### Phase 3: DBAL And Portable Schema

Only after the test commit, migrate production database access to DBAL and convert `database.xml` to portable schema
metadata. Keep the PHPUnit suite green throughout the migration. Fix database-specific defects exposed by the tests, but
do not weaken assertions to preserve broken MySQL-only or nonexistent-column behavior. Commit the coherent DBAL/schema
migration before or together with only those type fixes that are inseparable from the changed database code.

### Phase 4: PHPStan And Final Quality Gate

After the DBAL migration, resolve the remaining PHPStan 2 level-8 findings in production and test code. Keep the baseline
empty and do not add ignores merely to make the final gate pass. Run the complete package quality command sequence twice,
including PHPCS, PHPStan, and PHPUnit, then verify coverage remains at least 80% and commit the final analysis fixes.

If the developer explicitly requests a different phase boundary, follow that request and document the deviation.
Task-specific content restrictions, such as not changing README or package texts, also override the completion defaults
below.

The remaining sections describe the detailed requirements for these phases; their document order is not an alternative
execution order.

## 4. Update The Local Toolchain

Use package-local PHIVE tools. Upgrade the required quality tools with:

```shell
phive install phpstan:2.* phpcs:4.* phpcbf:4.*
```

Keep existing PHPUnit and other tools unless their upgrade is part of the task. Verify that `.phive/phars.xml`, Composer
scripts, and CI invoke the same package-local tools. `.phive/phars.xml` is the current PHIVE configuration file; do not
create or restore the outdated `phive.xml` in the repository root. Prefer these commands:

```shell
./tools/phpcs
./tools/phpcbf
./tools/phpstan
./tools/phpunit
```

Keep the isolated toolchain/CI change separate from large analysis fixes.

## 5. Reach PHPStan Level 8

Run PHPStan without trusting an old result cache:

```shell
./tools/phpstan clear-result-cache
./tools/phpstan --no-progress --error-format=table
```

Work in controlled stages:

1. Make PHPStan 2 run with the existing configuration.
2. Configure the supported PHP version range.
3. Remove obsolete exclusions and ignores.
4. Empty the baseline; do not regenerate it to hide findings.
5. Set `level: 8` if the package is below level 8.
6. Fix findings in related groups and commit each coherent group.

Ensure the PHPStan configuration contains this version range below `parameters`:

```neon
parameters:
    phpVersion:
        min: 80200
        max: 80509
```

Keep existing `parameters` entries and merge `phpVersion` into that block; do not create a second `parameters` section.

Fix root causes: real parameter and return types, nullable paths, failed conversions, array shapes, and control flow. Do not
weaken production types or add blanket ignores. Replace legacy static access such as `QUI::$Ajax` with supported accessors
such as `QUI::getAjax()`.

Prefer PHP's nullsafe operator (`?->`) when `null` may naturally propagate and the surrounding condition, fallback, or
return value already handles it correctly. Use an explicit null check only when `null` requires distinct control flow,
logging, state changes, or an exception. Do not add a verbose guard when a nullsafe call expresses the same behavior.

### Optional Dependency Stubs

CI installations may intentionally omit optional ERP, payment, PDF, or integration packages. If PHPStan must understand
their types, add minimal analysis-only shims under `tests/phpstan-shims/` or the package's established stub directory.

- Declare only the classes, interfaces, constants, and method signatures the package consumes.
- Guard declarations with `class_exists()` or `interface_exists()` when runtime overlap is possible.
- Load them through `scanFiles` or the established PHPStan bootstrap.
- Never place compatibility shims in production source code.
- Do not use a shim when the dependency is actually required and missing from `composer.json`.

Run PHPStan both in the full local installation and in CI. CI is authoritative for optional-dependency coverage.

## 6. Migrate Database Access To DBAL

Follow the Core DBAL migration rules from
`https://dev.quiqqer.com/quiqqer/core/-/work_items/1525` and the database XML reference at
`https://quiqqer.com/docs/developer/package-reference/database-xml`.

Remove active uses of:

- `QUI::getDataBase()` / `QUI::getDatabase()`
- legacy `fetch`, `insert`, `update`, `delete`, and `execSQL` database wrappers
- direct `QUI::getPDO()` queries
- MySQL backticks and MySQL-only DDL or functions
- manually concatenated values and `LIMIT offset,count`

Use `QUI::getDataBaseConnection()` for simple DBAL operations:

```php
$Connection = QUI::getDataBaseConnection();
$Connection->insert($table, $data);
$Connection->update($table, $data, $criteria);
$Connection->delete($table, $criteria);
```

Use `QUI::getQueryBuilder()` for filtering, joins, expressions, sorting, counts, and pagination:

```php
$QueryBuilder = QUI::getQueryBuilder();
$result = $QueryBuilder
    ->select('id')
    ->from(QUI\Utils\Doctrine::quoteIdentifier($table))
    ->where($QueryBuilder->expr()->eq('status', ':status'))
    ->setParameter('status', $status)
    ->setFirstResult($offset)
    ->setMaxResults($limit)
    ->executeQuery()
    ->fetchFirstColumn();
```

Parameterize every value. Whitelist dynamic sort columns and directions. Quote identifiers through the current database
platform or `QUI\Utils\Doctrine`; never apply identifier quoting to user-provided values. Preserve empty-result, count,
exception, sorting, and pagination behavior during conversion.

### Portable `database.xml`

Replace SQL fragments in `type` attributes with portable schema metadata:

```xml
<field type="bigint" autoincrement="true" primary="true">id</field>
<field type="string" length="255">title</field>
<field type="datetime" nullable="true" default="null">editDate</field>
<field type="boolean" default="0">active</field>
```

Use portable types, explicit nullability, explicit defaults, primary keys, and indexes for real lookup paths. Use composite
indexes only when the query patterns justify them. Validate XML and scan again for `AUTO_INCREMENT`, `UNSIGNED`, backticks,
engines, and other MySQL-only syntax.

## 7. Add PHPUnit Coverage

Add unit tests for isolated behavior and integration tests for database-backed workflows. Follow the package layout; a
typical integration setup uses:

```text
phpunit.dist.xml
tests/phpunit-bootstrap.php
tests/integration/
tests/stubs/
```

Configure PHPUnit to measure all production source files, including files that no test loads:

```xml
<source>
    <include>
        <directory suffix=".php">src</directory>
    </include>
</source>
<coverage includeUncoveredFiles="true"/>
```

Reach at least 80% line coverage over the existing production code. Treat completely untested source files as uncovered;
do not exclude production paths or add coverage-ignore annotations merely to raise the percentage.

Integration tests must exercise public package APIs and the real QUIQQER bootstrap/database. Cover the workflow changed by
the migration, including relevant create, update, lookup, search, sort, pagination, archive, and delete behavior.

Make fixtures repeatable:

- use a package-specific unique prefix
- clean stale fixtures before the class and before each test
- clean in `tearDown()` and `tearDownAfterClass()`
- remove orphaned dependent rows as well as parent rows
- restore session users, configuration, events, and other global state
- skip only when required infrastructure is genuinely unavailable

Do not disable database tests merely because they run in CI when CI provides the intended integration environment. Add
narrow test stubs for optional classes when necessary; do not change production behavior to accommodate tests.

Run the integration suite twice consecutively. The second run detects incomplete cleanup, fixed IDs, leaked global state,
and ordering assumptions.

## 8. Complete The Package

Completion inspection is mandatory for every full package quality upgrade. Inspect repository-local package metadata,
licensing, documentation, locale text, support text, and required visual assets, but do not treat inspection as permission
to rewrite suitable existing content. Preserve content that is complete, correct, current, and internally consistent.
Change it only when a concrete defect or omission is found or when the developer explicitly requests a rewrite. Do not
rephrase good text merely for style, standardization, tone, or recurring quality runs.

### Composer Metadata

Follow `https://quiqqer.com/docs/developer/package-development#composer-metadata`.

- Use `quiqqer-module` for normal extension packages, `quiqqer-template` for project presentation packages, and
  `quiqqer-asset` only for the corresponding generated browser asset packages.
- Remove a `version` field. The QUIQQER update server derives and manages package versions.
- When maintainer metadata is missing, stale, or still contains a personal legacy entry that no longer represents current
  maintenance, use the company maintainer entry:

```json
"authors": [
  {
    "name": "PCSG - Computer & Internet Service OHG",
    "email": "info@quiqqer.com",
    "homepage": "https://www.quiqqer.com",
    "role": "Maintainer"
  }
]
```

- Verify package name, description, homepage, support email, source URL, issue URL, PHP constraint, Core constraint, required
  PHP extensions, and package dependencies.
- Run `composer validate`. Keep Composer, `package.xml`, locales, and README metadata consistent.

### License And README

- Preserve the package's intended licensing meaning and use a valid SPDX identifier where one exists.
- Ensure the repository's `LICENSE` file exists and is consistent with Composer and `package.xml`. Do not replace or
  rewrite a correct existing license file.
- If the intended license cannot be determined unambiguously from existing repository evidence, ask the developer instead
  of inventing or changing a license.
- Create a README when it is missing. Update an existing README only when required information is absent, incorrect,
  outdated, inconsistent with the package, or explicitly requested. A suitable README should be at least in English and
  include a clear title, description, installation, configuration when applicable, usage, relevant technical notes,
  license, and support.
- Preserve good README wording and structure. Do not rewrite, reorder, translate, or expand it merely because a quality
  upgrade is being performed.
- Remove obsolete personal developer attribution and stale instructions. Keep useful package-specific documentation.

### `package.xml`, Locales, And Images

- For XML structure, schema links, and validation, follow
  [XML Structure And XSDs](./quiqqer_extension_points.md#xml-structure-and-xsds). New or structurally revised Core/Utils
  XML documents use `<quiqqer>` with the matching XSD; existing legacy roots remain readable but fail schema validation.
- Verify localized title and short description, package image reference, support information, copyright, license, and all
  referenced locale variables. Ensure English locale text exists.
- Preserve suitable package descriptions, locale wording, support text, and other module-facing text. Edit only concrete
  omissions, stale facts, invalid references, or inconsistencies; do not rephrase them for stylistic uniformity.
- Reuse suitable existing images. Never regenerate or replace an existing suitable image merely to standardize its file
  type, style, name, or location.
- Required visual asset types are the README header, package logo/icon, and GitLab project avatar image. Screenshots are not
  currently required.
- Generate only missing asset types with `https://completion.quiqqer.com/`. Supply the package title, the appropriate type
  (`QUIQQER`, `ERP`, `ecoyn`, or `Kimai`), and a Font Awesome icon. If the icon or type is ambiguous, ask the developer.
- The generator outputs `Readme.png` at 1200x600, `Logo.png` at 400x300, and `Gitlab.png` at 100x100. Preserve an
  established package image directory; otherwise place new assets under `bin/images/` and update repository references.
- Codex cannot assume permission to change the external GitLab project avatar. Report the intended `Gitlab` image's exact
  repository path so the developer can upload it manually.

### Locale Files And Translation Coverage

For a full quality upgrade, inspect locale structure and completeness as part of package completion. For a task limited to
locales, apply this section without starting the unrelated toolchain, test-coverage, or DBAL upgrade phases.

#### Flat Language Files

- Use `quiqqer/locales` in the manifest and every language/topic file, linking `locale.xsd` on the outer `<quiqqer>`
  element. Keep file references or translation groups inside `<locales>`; a split does not change their structure.
- Store language XML files directly in the package-root `locale/` directory. Keep the root `locale.xml` as the manifest
  referencing them with paths such as `/locale/de.xml`.
- When the source-language catalog contains more than 50 distinct `(group, variable)` pairs, split it into thematic
  language files. Count source variables once, not once per translation. The threshold triggers logical organization;
  it is not a hard limit of 50 entries per resulting file. Keep coherent topics together.
- Keep common terms, buttons, and number formats in `<language>.xml`. Use `<language>.<topic>.xml` for other topics, for
  example `de.users.xml`, `de.auth.xml`, or `de.mail.xml`. Use a flat directory, without language or topic subdirectories.
- Choose topics that fit the package. Core's examples are `languages`, `users`, `auth`, `permissions`, `projects`, `media`,
  `packages`, `settings`, `mail`, `backend`, and `console`; smaller packages need only their relevant topics.
- Apply the same topic assignment by variable key to every target language. Preserve additional valid translations that
  exist only in another language. Do not create empty topic files just to match a file count.
- Group manifest references by language, with a blank line and a language-name XML comment between blocks. Reference each
  file exactly once and update code or tests that read a moved language file directly.

Splitting files does not introduce new locale groups. Preserve every variable name, group, group `datatype`, locale
attribute such as `html` or `priority`, and translation content. Core entries stay in `quiqqer/core`; other packages keep
their existing groups. File splitting can reduce the size of individual XML import operations, but does not establish a
runtime memory reduction when the loader still loads the entire group.

#### Source Language And Translation Integrity

- Determine the most complete source language from actual key coverage; German is the usual starting point, not an
  assumption that permits dropping keys found only elsewhere. Preserve valid existing translations and fill concrete gaps.
- Use the package's existing or explicitly requested target languages. Core currently provides `de`, `en`, `es`, `fr`,
  `it`, `pl`, and `pt`; do not impose that language set on unrelated packages without a translation requirement.
- Preserve placeholders, HTML structure, URLs, command syntax, and locale attributes during translation. Keep intentional
  empty values and meaningful whitespace, including spaces used as number-grouping separators. Number formats and language
  names must follow the target language rather than being copied mechanically from the source.
- Inspect separately named catalogs too. A suffix such as `.qui.xml` or `.system.xml` does not by itself mean that its
  contents are untranslatable, unused, or deprecated.

#### Duplicate Keys And Legacy Compatibility

- Inspect duplicate keys within the same group and language across all manifest files. Compare text and all metadata,
  including `datatype`; the same key in different groups or languages is not a duplicate.
- Remove redundant occurrences only when their contents and metadata are identical. When they differ, report both
  variants and the effective import order. Preserve that order during file moves and resolve conflicting entries only
  according to the developer's chosen version; apply the choice consistently to the corresponding translations.
- Preserve legacy groups needed for compatibility. In Core, the retained `de.system.xml` and `en.system.xml` contain
  `quiqqer/system` translations. Place their manifest entries after the active files in each language block, immediately
  below a `Deprecated` comment explaining compatibility. Keep active `.qui.xml` references before that comment.
- A file reorganization does not authorize a group migration. When a migration from `quiqqer/quiqqer` or `quiqqer/system`
  to `quiqqer/core` is requested, first verify the variable exists with the intended meaning in the Core locale XML files.
  If absent, add its translations to the appropriate Core language files before changing references. Preserve legacy
  definitions when compatibility is required.

#### Locale Validation

Parse the manifest and every referenced XML file. Check that referenced paths exist, intended language files are included
exactly once, source keys have the required target translations, and no unresolved duplicate definitions were introduced.
Validate the manifest and each changed language file separately against `locale.xsd` using the extension skill's XSD
validation instructions. A valid manifest alone does not establish that its referenced catalogs are valid.
Compare the catalog before and after a structural change, including metadata, translation text, and the effective values
of duplicate keys. Account explicitly for intended translation additions or duplicate removals. Use QUIQQER's XML reader
to check import interpretation when changing the file structure; do not publish translations or import into a live database
merely to validate an XML reorganization. Run relevant existing tests when their locale paths or behavior are affected.

Completion does not include screenshots, CI status review, milestone creation, version creation, tags, releases, or a list
of manual release steps.

Keep completion changes reviewable. Prefer separate Conventional Commits for metadata/license/README changes and visual
assets when both categories are changed.

## 9. Validate And Deliver

Run the complete package checks:

```shell
xmllint --noout database.xml
./tools/phpcs
./tools/phpstan clear-result-cache
./tools/phpstan --no-progress --error-format=table
./tools/phpunit
./tools/phpunit
git diff --check
```

Omit `xmllint` only when the package has no `database.xml`. Scan one final time for legacy database access and MySQL-only
constructs. Review `git diff` and confirm that the worktree contains only intended changes.

Prefer separate Conventional Commits for:

- toolchain and CI
- PHPStan/type fixes grouped by concern
- DBAL query migration
- portable database schema
- tests and fixtures

Commit or push only when the developer requests it. Report commits when present, PHPStan level, test count/assertions,
skipped tests, CI-only stubs, DBAL/schema changes, and any remaining risks.
