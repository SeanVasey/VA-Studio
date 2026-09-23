# Dependency PR integration — 2026-09-23

Sean requested review and appropriate integration of every open PR. The connector's blank-query PR listing omitted ten open Dependabot PRs. The explicit `is:open` query recovered them. Do not use the blank-query listing alone as proof that the PR backlog is empty.

Feature PR #48 changed neither dependency manifest nor lockfile and passed its own complete CI before merging. These older dependency PRs are separate package updates, not missing feature prerequisites. PR #49 adds atomic resource coordination; PR #50 carries the reviewed compatible dependency updates on top of that application state.

## Complete disposition ledger

| Original PR | Proposed update | Integration decision |
| --- | --- | --- |
| #16 | Collision 8.9.4 → 8.9.5 | Include in PR #50 with solver-selected Symfony Console 7.4.19; see PHP compatibility below. |
| #17 | TypeScript 5.9.3 → 7.0.2 | Include exact target; native compiler optional platform packages retained. Repository uses CLI typechecking, not the removed programmatic compiler API. |
| #18 | jsdom 28.1.0 → 30.0.1 | Include exact reviewed target; declare Node 24.15+ within Node 24. This record does not claim 30.0.1 is the newest release overall. |
| #20 | jest-dom 6.9.1 → 7.0.1 | Include exact target; explicitly declare existing DOM peer 10.4.1 and use Vitest matcher types. |
| #34 | Laravel Pao 1.1.4 → 1.1.5 | Include patch; verify the actual test runner with full backend CI. |
| #37 | Node typings 24.13.3 → 26.5.0 | Do not merge as proposed. Retain 24.13.3 for the declared Node 24 runtime; revisit only with an intentional runtime migration. |
| #38 | Vitest 4.1.11 → 5.0.0 | Include exact target; verify existing mocks, DOM assertions, tests and typechecking. |
| #45 | Inertia Laravel 3.3.3 → 3.3.4 | Include patch, which improves HTML escaping of initial page JSON used by the application. No application exploit is claimed. |
| #46 | Pint 1.30.5 → 1.32.1 | Include minor update; version smoke check only, without unrelated formatter edits. |
| #47 | Filament 5.7.8 → 5.8.1 | Include all ten synchronized Filament packages; preserve admin/upload/authorization and real browser verification. |

Superseded bot PRs should link to PR #50 and close only after their accepted updates reach main. #37 requires an explicit compatibility disposition, not a false merged claim. Current PR states and exact acceptance evidence are recorded on GitHub.

## Package-manager resolution and protected behavior

Composer generated the combined lock with the five explicit PHP target versions, `--with-all-dependencies --minimal-changes --no-install --no-scripts --no-plugins`. Its strict manifest validation and full locked dependency audit passed in the dedicated generation run. This retains the current Laravel, Livewire and other unrelated framework versions rather than importing overlapping stale bot lockfiles wholesale.

Collision 8.9.5 accepts Console `^7.4.14 || ^8.1.1`. The repository deliberately resolves for PHP 8.4.0, while Console 8.1.1 requires at least 8.4.1. Composer therefore selects compatible Console 7.4.19, supported by current consumers. The PHP floor is unchanged. Full Artisan/test/browser behavior remains a merge gate; a successful solve alone is insufficient.

npm generated the existing lock format using the exact four JavaScript upgrade targets and explicit `@testing-library/dom` 10.4.1, with lifecycle scripts disabled during resolution. The Node engine is `>=24.15.0 <25`. Node typings remain on 24.x. Its full dependency audit reported zero vulnerabilities in the generation run. The TypeScript config names Vitest's matcher declarations and Node types explicitly; strict checking remains enabled.

Temporary branch-restricted lock-generation workflows are removed from the final tree. Neither lockfile was hand-edited. Normal CI retains read-only contents permissions. No provider, production runtime, database, theme, licensing, payment or customer-data change is part of this maintenance batch.

## Verification and remaining limits

The integrating PR records exact source/tree, clean install, MySQL and SQLite suites, all frontend tests, TypeScript/build, Chromium/WebKit browser cases, strict Composer validation, Pint version smoke check and full Composer/npm audits including development packages. Independent reviewers inspect the actual integrated lock/config/source delta before merge. Historical component review or generation audits do not establish a passing integrated candidate.

Rollback uses a reviewed revert of the dependency integration while preserving feature work and historical commerce evidence. Do not replace main with an old bot branch. The broader WP-06 exclusive integration, WP-07 orders/payment finalization, WP-08 delivery and production/migration/device gates remain unchanged.

## Primary references used in review

- [TypeScript 7 announcement](https://devblogs.microsoft.com/typescript/announcing-typescript-7-0/)
- [Vitest migration guidance](https://vitest.dev/guide/migration/)
- [jsdom releases](https://github.com/jsdom/jsdom/releases)
- [jest-dom releases](https://github.com/testing-library/jest-dom/releases)
- [DefinitelyTyped version policy](https://github.com/DefinitelyTyped/DefinitelyTyped#how-do-definitely-typed-package-versions-relate-to-versions-of-the-corresponding-library)
- [Collision 8.9.5 constraints](https://github.com/nunomaduro/collision/blob/v8.9.5/composer.json)
- [Symfony Console 8.1.1 constraints](https://github.com/symfony/console/blob/v8.1.1/composer.json)
- [Inertia Laravel 3.3.4 release](https://github.com/inertiajs/inertia-laravel/releases/tag/v3.3.4)
- [Filament 5.8.1 release](https://github.com/filamentphp/filament/releases/tag/v5.8.1)
