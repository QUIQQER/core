---
name: quiqqer_developer_workflow
description: Use when developing QUIQQER packages or Core changes and you need the standard workflow for commits, checks, coding standards, branch handling, and merge-request preparation.
category: developer
---

# QUIQQER Developer Workflow

Use this skill for QUIQQER development work before changing code, preparing commits, or handing work over for review.

## Skill Routing

Use this skill as the entry point for general QUIQQER development. Load the focused skill that matches the task before
implementing specialized work:

- `quiqqer_extension_points` for providers, package XML files, events, console tools, permissions, settings, controls,
  assets, and setup integrations.
- `quiqqer_package_quality_upgrade` for PHIVE tool upgrades, PHPStan 2 and level 8, optional-dependency analysis stubs,
  DBAL/PostgreSQL migration, portable `database.xml`, and PHPUnit integration coverage.
- `quiqqer_frontend_css_variables` for control and module CSS, binding settings into styling through the three-layer
  CSS variable pattern, theming, and color usage.
- `quiqqer_frontend_javascript` for JavaScript in packages: vanilla JavaScript instead of MooTools and element
  selection through `data-name` attributes.
- `quiqqer_frontend_accessibility` for every change to HTML templates, markup, or DOM-creating JavaScript:
  semantic HTML, targeted ARIA usage, keyboard and focus handling.
- `quiqqer_secure_coding` whenever dynamic values are written into Smarty templates or database queries
  are built: context-aware XSS prevention and SQL parameter binding.

Combine focused skills when a task spans several areas, for example when a package modernization also changes an
extension declaration. Keep this workflow loaded for branch selection, atomic commits, validation, and handover rules.

## Development Scope

- Prefer creating or extending a package for normal project work.
- Change Core only when the task explicitly targets platform behavior.
- Read the local repository structure before editing. Prefer existing package patterns over new abstractions.
- Keep changes small and reviewable. Do not mix unrelated formatting, refactoring, features, and fixes.
- Use context-appropriate escaping for dynamic template output and parameter binding for database queries.
  Load `quiqqer_secure_coding` for the full rules.

## Atomic Commits

Make every commit one complete reversible work unit:

- one feature
- one bug fix
- one isolated refactor
- one style-only change
- one dependency/tooling change
- tests for one behavior

If a file contains unrelated edits, split them before committing. Do not include broad code-style cleanup in a feature or fix commit unless it is limited to the changed lines.

## Commit Message Standard

Use Conventional Commits:

```text
<type>[(<scope>)][!]: <short summary>
```

Header rules:

- Write the header in English.
- Keep the header at most 72 characters.
- Start the summary with a lowercase letter.
- Use imperative, present tense.
- Do not end the header with a period.

Allowed types:

- `build`: build process, dependencies, package metadata, tooling inputs
- `ci`: CI configuration and scripts
- `docs`: documentation
- `feat`: new user-facing or package-facing capability
- `fix`: bug fix or correction of unwanted behavior
- `perf`: performance improvement
- `refactor`: code change that is neither a fix nor a feature
- `revert`: revert another commit
- `style`: code style-only change
- `test`: tests and fixtures

Use a scope when it clarifies the affected area:

```text
fix(locale): handle missing translation fallback
```

Mark breaking changes with `!` and a `BREAKING CHANGE` footer:

```text
build!: bump minimum PHP version to 8.2

BREAKING CHANGE: Drop support for PHP 7.4
```

Use footers for ticket references:

```text
Related: quiqqer/package#123
Closes: quiqqer/package#124
Acked-by: Jane Doe <jane@example.com>
```

Use `Related` when a commit is connected to an issue. Use `Closes` only when the issue should close after the commit reaches the release branch.

## Checks

QUIQQER package repositories should provide package-local development tools through PHIVE and Composer scripts. Treat this as the standard for `quiqqer/*` modules and Core unless the repository explicitly documents a different setup.

Common commands:

```shell
composer dev:init
composer dev:lint
composer dev:phpunit
composer test
./tools/phpcs
./tools/phpstan
./tools/phpunit
```

Common PHIVE-managed tools:

- `./tools/phpcs` for PHP code style.
- `./tools/phpstan` for static analysis.
- `./tools/phpunit` for PHPUnit tests.
- `./tools/phpcbf` for fixable PHPCS violations.
- `./tools/captainhook` for Git hooks.

Use `.phive/phars.xml` to pin tool versions. This is the current PHIVE configuration file. Do not create or
restore a `phive.xml` in the repository root; that location is outdated and must not be used as a template.
Core's `.phive/phars.xml` shows the expected shape: PHPUnit `^10.5`, PHPStan `2.*`, PHPCS `4.*`, PHPCBF `4.*`,
Composer Require Checker, and CaptainHook. Composer scripts should wrap these local tools with `dev:init`,
`dev:lint`, `dev:phpunit`, and `test`.

If tools are missing in a QUIQQER package, add or restore the package-local tooling when that is in scope. Otherwise report the missing tool explicitly. Do not replace package checks with unrelated global tools.

Treat PHPStan findings as code-quality issues and fix the type, API, or control-flow problem unless the package has a documented ignore.

## PHPUnit Tests

Write or update PHPUnit tests for every fix and every feature. A behavior change without a test needs an explicit reason in the handover.

Use the repository's existing test layout. Core uses:

```text
phpunit.dist.xml
tests/phpunit-bootstrap.php
tests/unit/
tests/integration/
tests/stubs/
```

Core's `phpunit.dist.xml` defines separate Unit and Integration suites and loads `tests/phpunit-bootstrap.php`. Unit tests should isolate pure behavior where possible. Integration tests should use repository helpers and clean up created projects, media, database rows, files, and runtime state.

When PHPUnit cannot resolve optional package dependencies, MCP classes, external integrations, or environment-only classes, add narrowly scoped test stubs under `tests/stubs/` or test fixtures under the relevant `tests/.../Fixtures/` directory. Do not skip test coverage just because a dependency is absent in the test environment. Keep stubs minimal and guarded with `class_exists()` or `interface_exists()` when they emulate optional runtime classes.

## Coding Standards

- Use PSR-12 for PHP.
- Use package-local PHPCS and PHPStan when available.
- Use JSHint when a package provides JavaScript linting.
- Keep imports organized and names consistent with the package namespace.
- Add comments only when they clarify non-obvious behavior.

### Readable Code

Apply these rules to new and changed production code, tests, fixtures, and examples. A passing PHPCS check does not
replace a readability review.

- Use consistent indentation. Put each entry of a configuration, payload, or result array on its own line.
- Split long calls and declarations across lines, with one argument or parameter per line and aligned closing brackets.
- Put each part of a compound validation condition on its own line. Keep the order of type checks before field access
  visible so short-circuit behavior is easy to review.
- Separate nested operations into meaningful local variables when they mix steps such as reading, JSON decoding,
  validation, result construction, encoding, and writing. Avoid constructs such as `fwrite($handle, json_encode([...]))`.
- Prefer explicit control flow over nested ternaries or dense fallback expressions. Keep simple, clear nullsafe calls
  and fallbacks; do not replace them with unnecessary boilerplate.
- Separate logical steps with blank lines. Use descriptive names for intermediate values and do not introduce helpers
  or abstractions solely to hide a short expression.
- Keep these rules consistent throughout the changed implementation, including error paths and tests. Limit unrelated
  formatting to a separately requested cleanup.
- Use the existing QUIQQER/Composer autoloader. Load it at standalone entrypoints when needed; do not add autoloader
  `require` calls inside ordinary classes or methods.

### System Commands With Symfony Process

Execute external commands from PHP through `Symfony\Component\Process\Process`, as agreed in
[Core #1466](https://dev.quiqqer.com/quiqqer/core/-/work_items/1466). This applies to Core and packages, including CLI,
background jobs, and process-launching test fixtures.

- Replace native process execution such as `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, and PHP
  backtick execution when changing the affected code. Do not build a new native fallback or custom process wrapper that
  reimplements Symfony's process lifecycle.
- Pass executable and arguments as an array to `new Process(...)`. Do not manually concatenate or shell-escape arguments
  in that array. Declare a compatible `symfony/process` dependency in the package that uses it instead of relying on an
  unrelated package to install it.
- Use `Process::fromShellCommandline()` only when shell syntax is actually required, for example a shell pipeline.
  Keep the shell expression fixed and pass dynamic values through Symfony's escaped placeholders or standard input;
  never interpolate user-controlled values into the shell expression. Still use Symfony for execution and errors.
- Preserve the intended binary, working directory, environment, standard input, output handling, and synchronous or
  asynchronous behavior. Choose the timeout deliberately; disabling it requires an execution model that permits an
  unbounded job, not a blanket workaround.
- Use `mustRun()` when nonzero exit codes are exceptional, or `run()` with explicit exit-code handling. Handle relevant
  start failures, timeouts, and signals at the caller's error boundary. For asynchronous execution, manage completion
  through Symfony's process API.
- Choose output handling to match the workload: read bounded output, stream it, or disable capture when it is not needed.
  Do not retain unlimited output from long-running jobs or copy credentials and raw command output into diagnostics.

Example of an argument-array command with readable configuration:

```php
use Symfony\Component\Process\Process;

$command = [
    PHP_BINARY,
    $scriptPath,
    '--mode',
    $mode
];

$Process = new Process(
    $command,
    cwd: $workingDirectory,
    timeout: 60
);

$Process->mustRun();
$output = $Process->getOutput();
```

## Git Workflow

- Before changing code, inspect the current branch and worktree. If the developer has not already selected a branch
  workflow, ask whether to create a new short-lived ticket/feature branch from the matching `next-*.x` branch or work
  directly in that `next-*.x` branch. Ask in the developer's language and do not create or switch branches before the
  developer answers.
- Do not ask again when the developer already named the target branch, explicitly chose direct `next-*.x` work, or the
  repository is already on the intended task branch.
- When the developer chooses a feature or fix branch, keep the work isolated on that short-lived branch.
- When the developer chooses direct `next-*.x` work, stay on that branch and preserve the same atomic commit,
  validation, and handover requirements.
- Start feature branches from the `next-*.x` branch for the major version that should receive the change.
- Use the next unreleased major branch, for example `next-3.x`, for breaking changes when the current major is `2.x`.
- Prefix branch names with the GitLab ticket number when one exists, for example `42-feat-new-logo`.
- When using a feature branch, open its merge request back into the source `next-*.x` branch.
- Keep the feature branch private to the people actively working on that change.
- Make the branch stable and tested before assigning the merge request to a maintainer.
- Merge the target branch into the feature branch when resolving merge conflicts.
- Use `git pull --rebase` when updating a local feature branch from its remote.
- Use `git revert <commit>` for pushed commits that must be undone.
- Avoid rewriting shared history unless the team explicitly agrees.
- Use `git fetch --prune` to clean stale remote branch references.

## Merge Request Handover

Before opening or handing over a merge request:

- Keep commits atomic.
- Verify commit messages.
- Run PHPCS, PHPStan, and PHPUnit through the package-local tools.
- Add or update PHPUnit coverage for each fix and feature.
- Review changed code for readability and verify external commands use Symfony Process.
- Document behavior changes and known test gaps.
- Target the repository's branching flow; normal package work is reviewed into the matching `next-*.x` branch.
- Verify that breaking changes target the next unreleased major branch, not the current stable major branch.
- Do not publish versions directly. Maintainers handle release branches and version creation.

## Branching And Releases

Long-lived branches:

- `main` is stable, protected, and publishable. Merging into it creates a release.
- `next-*.x` branches prepare the next release for a major version and are protected.
- `next-n.x` prepares the next unreleased major version.
- `*.x` branches publish maintenance releases for older supported major versions.

Feature branches:

- When the developer selects branch-based work, create feature and fix branches from the matching `next-*.x` branch.
- When the developer selects a branch for breaking work, create it from the next unreleased major branch.
- Delete feature branches after they are merged into the target `next-*.x` branch.

Maintainers publish releases through merge requests:

- Minor or patch release: merge the current major `next-*.x` branch into `main`.
- Major release: merge the next major `next-*.x` branch into `main`; it must contain at least one breaking-change commit.
- Previous major release: merge `next-*.x` into the matching `*.x` branch.
- Hotfix: branch from the current release branch (`main` or `*.x`), merge the fix there, then merge that release branch back into the matching `next-*.x`.

Developers do not publish versions directly. Maintainers merge protected branches and semantic-release creates tags and GitLab releases from commit messages.

Commit messages drive release type:

- `fix` creates a patch release.
- `feat` creates a minor release.
- `!` or `BREAKING CHANGE` creates a major release.

Use dependency version constraints deliberately in `composer.json`. For current packages, require `php` and
`quiqqer/core` with explicit constraints and pin local development tools in `.phive/phars.xml`. Never create a
root-level `phive.xml`; it is no longer the current PHIVE configuration location.

Repository setup for maintainers can be automated through the stabilization CI component. Use `with-release-workflow` for branch management and semantic-release, `with-php-tooling` for PHP linting/analysis/testing, and `with-quiqqer` for QUIQQER package bootstrapping and update-server integration.

## References

- Developer docs: https://www.quiqqer.com/docs/developer/
- Workflow: https://www.quiqqer.com/docs/developer/development-workflow
- Coding standards: https://www.quiqqer.com/docs/developer/coding-standards
- Branching concept: https://dev.quiqqer.com/quiqqer/stabilization/documentation/-/wikis/Branching-Konzept
- Maintainer guide: https://dev.quiqqer.com/quiqqer/stabilization/documentation/-/wikis/Anleitung-f%C3%BCr-Maintainer
