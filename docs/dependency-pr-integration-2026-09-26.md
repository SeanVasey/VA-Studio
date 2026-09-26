# Dependency integration — 2026-09-26

This batch reviews Dependabot PRs #53–#60 against main `5e53c2cc57938bfc1b55bfdff4d8b22c1c70fb08` (local equivalent `82822df845e89a7bc926defbd31720e2516103d4`). It retains PHP 8.4.0, Node `>=24.15.0 <25`, current application behavior and the existing full CI gates.

## Disposition ledger

| PR | Actual change | Disposition | Original Foundation CI run |
| --- | --- | --- | --- |
| #53 | Stripe PHP 21.3.1 → 21.3.2 | Include. The application already rejects absent/invalid webhook secrets before SDK verification. | 36101391112: all three jobs passed |
| #54 | Ten Filament packages 5.8.1 → 5.8.4, Laravel 13.30.1 → 13.33.0, Livewire 4.4.3 → 4.4.6 and shared transitives | Include the actual resolved graph, subject to integrated PHP validation and tests. | 36101409622: all three jobs passed |
| #55 | Inertia React and core 3.7.0 → 3.7.1 | Include matched adapter/core patch. | 36101416860: all three jobs passed |
| #56 | Laravel 13.30.1 → 13.32.0 and shared transitives | Superseded by #54's 13.33.0 graph; do not overwrite it with the older lock. | 36101425549: all three jobs passed |
| #57 | Vite 8.2.2 → 8.3.0 | Include. Existing React and Laravel plugins accept Vite 8; current Node 24 satisfies engines. | 36101433834: all three jobs passed |
| #58 | React 19.2.8 → 19.3.0 and React typings 19.2.18 → 19.3.0 | Correct before integration: also update React DOM to 19.3.0. The original lock leaves DOM 19.2.8. | 36101450871: frontend and browser failed; backend passed |
| #59 | Node typings 24.13.3 → 26.6.2; undici-types 7.18.2 → 8.9.0 | Decline for the Node 24 baseline. Retain existing 24.x typings and undici-types. A passing compile does not establish runtime API availability. | 36101457423: all three jobs passed |
| #60 | Vitest/mocker/spy 5.0.0 → 5.0.1 | Include matched patch packages. Preserve resolver-compatible magic-string 1.4.2 rather than the bot's incidental downgrade to 1.4.1. | 36101476799: all three jobs passed |

The React failure was confirmed in frontend job `107964601617`: React reports incompatible React 19.3.0 and React DOM 19.2.8 versions. React's runtime requires exact matching versions even though the installed DOM package's npm peer range accepts the newer minor version. Keep these runtime packages together in future updates.

## Lock provenance and compatibility

The Composer candidate combines the exact solver-produced #54 lock changes with #53's Stripe-only lock change. No version, source reference or integrity value is invented. `composer.json` is unchanged. Composer is unavailable locally, so this candidate still requires strict manifest/lock validation, installation, dependency audit and complete backend/browser CI. Original component CI is evidence about those exact bot heads, not proof of the integrated candidate.

The broader #54 graph includes brick/math 1.0.0, anourvalar/eloquent-serialize 1.3.12, carbon-doctrine-types 3.2.1, livewire-rate-limiting 2.3.0, doctrine/lexer 3.0.2, Guzzle 8.2.0, serializable-closure 2.1.0, CommonMark 2.10.3, Monolog 3.12.0, Carbon 3.14.0 and Ramsey UUID 4.9.4. Laravel 13.33.0 and Ramsey UUID 4.9.4 explicitly accept brick/math 1.0. No direct Brick API usage was found in application or test code. Brick 1.0 equals 0.20; compared with 0.18 it removes deprecated `UnsupportedPlatformException` and changes invalid zero-denominator string parsing to `NumberFormatException`. Database/model/UUID behavior remains covered by full backend verification.

npm generates the combined lock with `--package-lock-only --ignore-scripts` on Node 24.19.0/npm 11.9.0. The matched React/DOM graph and Vitest patch must pass clean installation, all frontend tests, TypeScript/build, Chromium/WebKit tests and full npm audit. All installed platform packages and integrity fields come from npm resolution. No runtime or CI audit relaxation is part of this batch.

Superseded bot PRs should close with an explicit link to the integration only after accepted updates reach main. #59 should receive a compatibility disposition rather than a merged claim. The integrating PR records exact final commit/tree and integrated test results; this document does not claim those gates already passed.

## Local verification

On Node 24.19.0/npm 11.9.0, `npm ci --ignore-scripts --no-audit` installed the combined lock successfully. All 91 frontend tests across ten files passed. `npm run build` passed strict TypeScript checking and the Vite production build. Full `npm audit --audit-level=high` reported zero vulnerabilities, including development packages. These checks do not replace normal CI's installation with lifecycle scripts or its PHP-backed browser coverage. PHP and Composer are unavailable in this local environment; integrated Composer, MySQL/SQLite and Chromium/WebKit gates remain pending.

## Primary references

- [React exact-version requirement](https://react.dev/errors/527)
- [Brick Math 1.0 changelog](https://github.com/brick/math/blob/1.0.0/CHANGELOG.md)
- [Laravel 13.33 dependency constraints](https://github.com/laravel/framework/blob/v13.33.0/composer.json)
- [Stripe PHP 21.3.2 release](https://github.com/stripe/stripe-php/releases/tag/v21.3.2)
- [Inertia 3.7.1 release](https://github.com/inertiajs/inertia/releases/tag/v3.7.1)
- [Vite 8.3 release](https://github.com/vitejs/vite/releases/tag/v8.3.0)
- [Vitest 5.0.1 release](https://github.com/vitest-dev/vitest/releases/tag/v5.0.1)


## Accepted integration — 2026-09-26

The candidate described above merged in [PR #61](https://github.com/VASEYDEV/VASEYAUDIO/pull/61). Accepted remote candidate `4c9613f56b662ac2e48d9034861d36aa00f3a766` is tree-equivalent to local `27d0f39d467600266a3686eb12b932002122e076`, tree `55f78d8816363fa877fe051987450cb21b0398fa`; merge commit `4e3fd31aa60085a3724ac722f905b52e47e7c192` was read back with no file differences from the candidate. [CI 36215400805](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36215400805) passed 797 MySQL tests / 6,451 assertions, 745 SQLite tests / 5,303 assertions with 52 intentional MySQL-only skips, 91 frontend tests, 10 Chromium/WebKit browser cases, TypeScript/build, strict Composer validation, Pint version smoke and full dependency audits. Independent dependency/integration and final-source Codex reviews accepted the candidate.

All eight original PRs #53–#60 are closed with explicit dispositions. #53/#54/#55/#57/#58/#60 are incorporated through #61, #56 is superseded by the accepted Laravel 13.33 graph, and #59 is declined for Node 24 compatibility. None is represented as an individually merged bot branch. The pending language above records the pre-integration evidence state; this dated acceptance supersedes it. Subsequent checkout PR #62 passed the expanded application suite on the accepted dependency graph.
