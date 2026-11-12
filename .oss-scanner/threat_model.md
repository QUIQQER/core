# QUIQQER Core

## Target and environment

The target is the `quiqqer/core` repository, a PHP and JavaScript framework with
user, group, permission, project, media and package management. Other QUIQQER
modules and third-party packages are dependencies, not additional scan targets.
Report issues in this repository, including mistakes in how Core uses its
dependencies. A separate module requires its own enrollment.

The image derives from the same PHP 8.5 testing image used by our CI. The target
checkout is available at `/src`, which is a symlink to the physical package at
`/var/www/html/packages/quiqqer/core`. These are the same files. The rest of the
testing installation is at `/var/www/html`. Composer uses the synthetic local
version `2.999.0` to resolve this checkout; that is not a released Core version.
Identify affected source by its Git commit, not by that synthetic version.

## Trust boundaries and priorities

- Treat HTTP request parameters, JSON bodies, headers, cookies, uploaded files,
  externally fetched resources and stored user content as untrusted.
- Distinguish unauthenticated visitors, ordinary users, users with specific
  delegated permissions, and privileged administrators/system users.
- Check authorization for the particular object and operation, including users,
  groups, projects, sites, media and settings. Authentication alone does not
  grant access to another user's resources.
- Prioritize authentication and session handling, CSRF protection, AJAX handlers
  under `ajax/`, REST endpoints under `src/QUI/REST/Core/`, filesystem access,
  uploads, URL fetching, database queries, and package/update operations.
- An administrator's intentional ability to install trusted executable code is
  not itself a vulnerability. Look for unauthorized access to that ability or
  a demonstrated violation of another intended trust boundary.

## Running tests offline

All dependencies and PHPUnit are installed during the Docker build. Run:

```sh
cd /src
bash .oss-scanner/run-tests.sh \
  --filter '^(?!.*testDefaultActionsStopBeforeSystemUpdateOnFirstInvocation)'
```

Pass normal PHPUnit arguments to run a focused test, for example:

```sh
bash .oss-scanner/run-tests.sh --filter PrintTemplateSecurityTest
```

`GITLAB_CI=true` selects the same test bootstrap as GitLab CI. The wrapper starts
a local MariaDB bound to `127.0.0.1:3306` and restores a prepared database snapshot
into `/tmp/quiqqer-oss-scanner/` on first use. The database is populated from the
testing image's CI dump and updated for the target checkout during the build.
The database and application use disposable CI fixture credentials. To exercise
the framework outside PHPUnit, start the database with
`bash .oss-scanner/database.sh start` and use `/var/www/html/bootstrap.php`.
The normal image entrypoint, cron and automatic package updates are disabled.
Tests can start local HTTP servers and child PHP processes on loopback.
PHP uses `variables_order=GPCS`, matching the web-style environment assumed by
the Composer regression fixtures; environment variables remain available through
`getenv()`, while application code explicitly populates `$_ENV` where needed.

The filter excludes one existing test in `tests/unit/QUI/System/Update/RunProcessorTest.php`.
It assumes a successful real Composer download before reaching a restart
boundary. Without internet it continues into the real system update, which then
fails to reach the license server. Omitting the filter reproduces this known
offline limitation. The production update code remains fully in scope; do not
exclude it from the audit or confuse a missing network connection with a new
security vulnerability.

This environment covers MariaDB on PHP 8.5, not the entire CI database/PHP matrix
or a production deployment. For issues depending on another database or other
installed applications, state the missing validation rather than assuming
equivalence. Do not use real accounts, external services or production data.

## Reports and severity

Include repository-relative source locations, the affected actor and resource,
required permissions/configuration, a minimal local reproducer or regression
test, concrete security impact, and a small proposed patch when possible.
Distinguish demonstrated behavior from assumptions. Deduplicate reports that
share the same root cause.

Use critical for broadly exploitable unauthenticated code execution or a
comparable system-wide compromise; high for substantial unauthorized access,
privilege escalation, arbitrary file access or code execution with meaningful
preconditions; medium for narrower boundary violations; and low for limited
security impact. Explain the actual consequence and prerequisites.
