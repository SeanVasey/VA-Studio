# [WP-01] Foundation, repository governance and reproducible CI

Status: **Current increment adds audited operator provisioning, installation diagnostics and fresh-run browser CI. Exact-head runtime results and independent review determine acceptance; production operations are separate.** See [ordered development status](../development-order.md).

- Suggested issue title: `[WP-01] Foundation, repository governance and reproducible CI`
- Phase: 0
- Dependencies: None.
- Suggested branch: `work/wp-01-foundation-repository-and-ci`
- Implementation paths: composer.json, composer.lock, package.json, package-lock.json, .github/workflows, AGENTS.md, README.md, config, tests and local runtime setup.

## Problem

Agents need a reproducible shared application and honest evidence before parallel feature development can be trusted.

## First reviewable increment

Verify the current scaffold rather than recreate it. Complete reproducible local installation, environment examples, baseline quality commands and repository handoff.

## Scope

- Laravel 13/PHP 8.4, Inertia 3 React/TypeScript and Filament 5 dependency compatibility; MySQL 8.4 production semantics and explicit local SQLite support.
- CI with backend tests against MySQL and SQLite where supported, frontend type/build checks, formatting and dependency/security checks appropriate to the changed surface.
- Repository instructions, branch/PR discipline, secrets exclusions, feature flags and a development/test/production distinction. Publish issue bodies only after the exact GitHub repository and access are observed.

## Acceptance criteria

- [ ] A clean checkout installs from committed lockfiles and documented prerequisites; environment examples contain no credentials.
- [ ] The application migrates and boots; diagnostic output identifies missing optional providers without enabling fake production fallbacks.
- [ ] CI definitions invoke reproducible commands and clearly separate configured workflows from actually observed GitHub results.
- [ ] README identifies implemented functionality and current blockers; repository origin and remote state are verified if a GitHub write succeeds.

## Verification

Run dependency resolution, fresh migrations and project checks; record commands, versions and actual results. Do not claim remote checks ran from local output. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

No live traffic or paid history. Revert configuration/code while keeping the lockfile and migration history consistent.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-01 against the existing scaffold. Read AGENTS.md, docs/architecture/README.md and current lockfiles. Preserve other agents' work. Make local setup and checks reproducible, keep production checkout off, and report actual commands/results plus remote publication evidence if available. Do not invent a GitHub repository or CI run.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.

## Operator acceptance increment — 2026-09-09

The [operator guide](../operator-setup-and-verification.md) documents `vasey:create-admin`, `vasey:doctor [--json]` and `npm run test:browser`. Provisioning requires hidden interactive password input and commits the account/audit event together. Diagnostics change nothing but a scratch directory that the scanner limits check makes in private storage and removes and, when the scanner fails that check, a warning in the application log; they are redacted, with required failures distinguished from optional setup warnings. The isolated browser job installs lockfiles, migrates an empty temporary SQLite database, invokes the actual operator command, passes required diagnostics and starts the built application over HTTP.

Local `npm ci`, 39 frontend tests, TypeScript/Vite build, npm production audit and whitespace checks passed. PHP/Composer were unavailable locally. The PR records exact-head MySQL/SQLite and rendered Chromium/WebKit results; do not infer a browser pass from unit tests or configuration. Independent authorization review and production operator MFA/recovery remain open. Next dependency: WP-03 archive/stem safety, with the remaining original order retained.
