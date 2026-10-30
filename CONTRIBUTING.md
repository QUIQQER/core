# Contributing to QUIQQER

Thank you for improving QUIQQER. These guidelines cover bug reports, bugfixes and other contributions to Core. For extensions, also check the affected repository’s README, tools and CI configuration.

## Languages

- [🇬🇧 English](CONTRIBUTING.md)
- [🇩🇪 Deutsch](doc/CONTRIBUTING-de.md)
- [🇪🇸 Español](doc/CONTRIBUTING-es.md)
- [🇫🇷 Français](doc/CONTRIBUTING-fr.md)
- [🇮🇹 Italiano](doc/CONTRIBUTING-it.md)
- [🇵🇱 Polski](doc/CONTRIBUTING-pl.md)
- [🇵🇹 Português](doc/CONTRIBUTING-pt.md)

## Where to contribute

[GitLab](https://dev.quiqqer.com/quiqqer/core) is the canonical repository and the preferred place for contributions. Report Core bugs in its [issue tracker](https://dev.quiqqer.com/quiqqer/core/-/issues); report package bugs in the affected package’s repository. Search existing issues first and link related reports.

You can also open a pull request in the [GitHub mirror](https://github.com/QUIQQER/core). Maintainers transfer accepted changes to GitLab. You do not need a GitLab account for this route. The same commit, code style and test requirements apply on both platforms. Maintainers complete the GitLab import before the change is considered integrated.

## Report a bug

Use a clear title and provide the affected QUIQQER and package versions, relevant PHP/database/browser/system information, precise reproduction steps, expected and actual behaviour, and a minimal example. Add sanitized logs, stack traces, screenshots and configuration where useful. Mention a regression, impact or workaround if known.

“Media is broken. Please fix quickly.” is insufficient: it gives no version, reproduction or expected result. Remove credentials, tokens, personal data and customer data from every attachment and example.

Answer follow-up questions and update the issue when new information appears. Issues awaiting your feedback are closed after three months without a reply. Maintainers manage labels and status. The following templates use English for cross-language collaboration; replace the example details with your own report.

````markdown
## Summary
The media manager shows no preview for a transparent PNG.

## Versions and environment
- QUIQQER / Core: <version>
- Affected package(s): <name and version>
- PHP / database: <versions>
- Browser / operating system: <versions, if relevant>

## Steps to reproduce
1. Open the administration area and go to Media.
2. Upload the attached minimal example.png.
3. Open its details.

## Expected behaviour
The image preview is displayed.

## Actual behaviour
The details panel opens, but the preview remains blank.

## Additional context
- Reproducible with: <minimal example or configuration>
- Regression / last working version: <if known>
- Workaround / impact: <if known>
- Sanitized logs, screenshot or stack trace: <if available>
````

## Report a security vulnerability

Send vulnerabilities privately to [support@pcsg.de](mailto:support@pcsg.de). Include affected versions, reproduction steps or a minimal proof of concept, prerequisites and possible impact. Send only the information needed to investigate, with sensitive data removed.

Do not publish vulnerability details in a public issue, PR, MR or Discord channel. For example, unauthorized access to private files belongs in a private security report.

## Prepare a change

1. Check or create an issue and describe the intended fix. Coordinate larger changes with maintainers before implementing them.
2. Fork the repository and create a focused branch from the matching `next-*.x` branch. For Core 2.x, use `next-2.x`. Prefix the branch with the GitLab issue number when available, for example `123-fix-media-preview`.
3. Target the same development branch with your MR or PR. Breaking changes belong on the next unreleased major branch, for example `next-3.x` while 2.x is stable; confirm the target with maintainers.
4. Reproduce the issue, fix its cause and keep the change small. Separate unrelated refactoring and formatting. Preserve backwards compatibility; document intentional API changes, deprecations and migrations.
5. Add or update regression tests for fixes and tests for new behaviour. Record manual test steps. Explain explicitly if automated coverage is not possible.

Use `git pull --rebase` to update a local feature branch from its remote. Resolve target-branch conflicts by merging the target into your feature branch. Avoid rewriting shared history without agreement; undo published changes with `git revert`.

## Commit messages

Each commit must be one complete, independently reversible work unit. Write the entire message in English, using this header:

```text
<type>[(<scope>)][!]: <short summary>
```

- Maximum 72 characters for the complete header; no final period.
- Start the summary with a lowercase letter and use the imperative present tense, such as `fix`, not `fixed`.
- Allowed types: `build`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`.
- An optional scope names an area of the codebase, for example `media`.
- Separate header, optional body and optional footer with blank lines. Body and footer lines must not exceed 100 characters. Start the body with a capital letter and explain the motivation in the imperative present tense.
- Reference a related issue with `Related: project/package#123`. Use `Closes: project/package#123` only when it should close on reaching the default/release branch. Include the full GitLab issue URL in GitHub PR descriptions to avoid ambiguous issue numbers.
- Breaking changes require both `!` before the colon and a `BREAKING CHANGE:` footer explaining the change and any migration.

GitLab server hooks validate commit messages and can reject a push. Check every incoming commit before accepting a GitHub PR, including the final merge or squash message. Do not rely on the later import to repair messages.

```text
fix(media): restore preview for transparent PNG images

Use the original image when the thumbnail URL is missing.
Add a regression test for the preview metadata.

Related: quiqqer/core#123
```

```text
build!: drop support for an obsolete dependency

BREAKING CHANGE: Remove support for old/package 1.x
Upgrade to old/package 2.x before updating.
```

## Code style and checks

Use a working QUIQQER development installation. Run the commands below from the Core package directory (`packages/quiqqer/core`). Use the PHP version and extensions required by `composer.json`; Composer and PHIVE must be available. `composer dev:init` installs the pinned tools and enabled Git hooks. Run it when setting up the checkout; run `composer test` before handing over a change.

The first block initializes and checks Core; the second lists individual checks:

```shell
composer dev:init
composer test
```

```shell
composer dev:lint
composer dev:phpunit
./tools/phpcs
./tools/phpstan
./tools/phpunit
```

- PHP follows **PSR-12**, as configured in `phpcs.xml.dist`. Run PHPStan and PHPUnit as well as the style check.
- JavaScript follows the QUIQQER JSHint rules or the package’s `.jshintrc`. Follow the existing frontend conventions and check the browser console for affected UI changes.
- Use context-appropriate escaping for output and parameter binding for database queries. Execute external commands from PHP through `Symfony\Component\Process\Process`.
- Add or update documentation and translations when behaviour changes. Follow the existing locale file structure.
- Run the affected package’s own checks for package contributions. Report commands, results and any checks you could not run. GitLab CI results and review must be considered before integration.

## Open a merge request or pull request

Keep one problem per MR/PR. Explain the problem, cause, solution, affected packages, tests and compatibility implications. Link the issue and document manual reproduction and test steps. Use this description template; replace the example issue URL.

````markdown
## Problem and affected packages
<What fails, who is affected, and which packages change?>

## Related issue
https://dev.quiqqer.com/quiqqer/core/-/issues/123

## Root cause
<Why does the problem occur?>

## Solution
<How does this change address the cause?>

## Tests
- Automated: <command, regression covered and result>
- Manual: <steps, environment and result>
- Limitations: <checks not run and why, or none>

## Compatibility and documentation
<Public API, permissions, dependencies, translations, release notes or migration needs>
````

## Contributor checklist

- [ ] The issue is linked and the correct development branch is targeted.
- [ ] The change is focused; commits are atomic and their messages follow the standard.
- [ ] Reproduction steps were verified and regression coverage was added or updated, or the gap is explained.
- [ ] Relevant style, static analysis and test checks passed; results and limitations are recorded.
- [ ] Manual test steps and results are documented.
- [ ] Compatibility, permissions, sensitive data and public API changes were considered.
- [ ] Documentation, translations and release or migration notes are updated where needed.

## Reviewer checklist

- [ ] The issue gives enough information to reproduce the problem.
- [ ] The fix addresses the cause and is small enough to review.
- [ ] Tests cover the regression and relevant checks have passed.
- [ ] Compatibility, security, permissions and data handling were reviewed.
- [ ] The target branch and commit messages are valid, including GitHub merge/squash commits before import.

## Releases and further guidance

Maintainers manage protected branches, versioning, tags and publication. Contributors submit tested changes to the matching development branch. Maintainers prepare the release and merge into `main` or the appropriate maintenance branch; the release automation determines versions and release notes from commit messages.

Describe user-visible changes, deprecations and migration needs in the MR/PR. Add documentation or changelog notes where the affected repository requires them. Check its README and CI configuration before assuming another package uses Core’s exact tooling.

- [QUIQQER documentation](https://www.quiqqer.com/docs/)
- [Development workflow](https://www.quiqqer.com/docs/developer/development-workflow)
- [Coding standards](https://www.quiqqer.com/docs/developer/coding-standards)
- [Testing and quality](https://www.quiqqer.com/docs/developer/testing-and-quality)
- [composer.json](composer.json)
- [phpcs.xml.dist](phpcs.xml.dist)
- [locale.xml](locale.xml)
