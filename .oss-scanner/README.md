# Anthropic OSS Scanner enrollment

This directory prepares **QUIQQER Core** for Anthropic's
[OSS Scanner](https://red.anthropic.com/oss-scanner/), using
`quiqqer/testing:v1-php8.5`. Reports are addressed to `security@quiqqer.com`.
The scanner runs at Anthropic after enrollment is accepted.

## Check the environment

From the Core repository root:

```sh
docker build -f .oss-scanner/Dockerfile -t quiqqer-core-oss-scanner .
docker run --rm --network none --cpus 2 --memory 4g \
  quiqqer-core-oss-scanner bash .oss-scanner/run-tests.sh \
  --filter '^(?!.*testDefaultActionsStopBeforeSystemUpdateOnFirstInvocation)'
```

Build from a clean checkout. The build context must not contain local secrets,
an unrelated `vendor/`, or host configuration. The image contains only a
disposable testing installation; do not mount a deployed QUIQQER installation.

The build installs dependencies, restores the testing image's CI database and
applies the checkout's setup. The test wrapper starts an internal MariaDB on
loopback from the prepared snapshot; it needs no separate GitLab service or
internet access. See [threat_model.md](threat_model.md) for its limits.

Dependency setup uses public package sources. The IP-restricted licensed package
repository inherited from the testing image is removed before Composer runs,
so external scanner runners do not need access to the testing license's feed.

The filter explicitly excludes one existing update-runner test that assumes a
successful real Composer download. With no network it proceeds into a system
update and fails to contact the license server. The wrapper itself does not skip
tests: omit the filter to run the complete suite and reproduce that limitation.
All Core production code, including updates, remains in scope for the scanner.

## Enroll the project

1. Commit these files to the `next-2.x` branch and make them publicly available.
2. In a fork of [anthropics/oss-scanner](https://github.com/anthropics/oss-scanner),
   create `projects/quiqqer-core/` and copy **only** this directory's
   `project.yaml` into it. The Dockerfile and threat model remain in Core.
3. Run `python3 tools/validate.py` and `tools/check quiqqer-core` there. The latter
   clones the published branch and opens an offline test shell; run
   the filtered offline test command shown above in that shell.
4. Open a pull request as a Core maintainer, explain Core's security relevance
   (an internet-facing framework with authentication and permission management),
   and complete Anthropic's enrollment checklist and CLA process.

The contact address must receive mail, and Anthropic verifies that the applicant
and contact belong to the maintainers. Enrollment is subject to their review.
Additional module repositories need separate enrollments and a matching build
environment. This Core enrollment does not cover all installed modules.
