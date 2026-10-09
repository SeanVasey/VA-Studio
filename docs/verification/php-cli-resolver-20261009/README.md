# M-16: renderer children run a validated CLI PHP under PHP-FPM

Development evidence from the Claude Code harness, 2026-10-09. Not Foundation or final acceptance.
Branch `harness/php-cli-resolver`: WIP `53f8ef60` (resolver, generic renderer, unverified), merged with `main` at
`49489697` and then `86e22f1a` (#56, the Paid family), plus this round's commits.

## Problem

Contract and grant PDFs render in a bounded child PHP process. Free, production-free and paid grant documents render
inside the web request. Under PHP-FPM, `PHP_BINARY` is the FPM daemon, so `[PHP_BINARY, '-n', ...]` prints php-fpm's
usage text (exit 64) and every such render fails closed with `render_failed`. Reproduced on a real php-fpm 8.4.26 pool
against `main`'s product source (`evidence/fpm-red-main.txt`, `evidence/fpm-red-paid-main.txt`).

## Change

- `App\Support\PhpCliBinary` (WIP, hardened here). In the CLI, and in its built-in server (`cli-server`: `php -S`, `artisan serve`, the browser-spec server; independent review L-2), it returns `PHP_BINARY` unchanged. Outside the CLI it
  requires `VASEY_PHP_CLI_BINARY` (`config('app.php_cli_binary')`): an absolute path without control characters whose
  canonical target is a regular executable file, proved by a `-n -r` probe with a scrubbed environment (`LANG=C`, plus the renderers' sibling `lib/<arch>` loader path
  when it exists; Codex P2 on #67), a
  10 s limit, **at most 128 bytes of output and no stderr** (new), reporting `cli`, exactly the running `PHP_VERSION` and
  **the same thread-safety and debug build** (new). Success is cached per path, device, inode, size, mtime, ctime,
  version and build; failures are not cached. Failures are fixed reason codes; no path or child output leaves.
- `App\Support\PhpCliProcess` (new). The pinned renderers (`FreeGrantRendererProcess`,
  `ProductionFreeGrantRendererProcess`, `PaidGrantRendererProcess`) are frozen by `resources/contracts/*-v1/
  profile-assets.json` and are **not edited**. Each already accepts an optional process factory. Outside the CLI the
  container passes this factory to the Free and Paid renderers. **Family 256 (production free) is not bound:**
  `ProductionFreeGrantApprovalTest::test_shipped_configuration_is_literally_default_off` requires that nothing ships
  binding it ("root composes it after review"), so the step that composes and mounts it adds the same binding. Tests and
  the FPM smoke prove that binding for it explicitly. The factory: it refuses any command not built around this process's own `PHP_BINARY` with `-n`,
  replaces only `command[0]` with the validated CLI path, and re-derives the renderer's fixed sibling library rule
  (`dirname(binary, 2)/lib/<arch>`) from that binary. Flags, script, working directory, scrubbed environment, input and
  the 60 s limit pass through; each renderer still verifies its pinned profile and bounds its own output. In the CLI the
  container builds them exactly as before (no factory).
- `IsolatedContractRenderer` (generic, not pinned): uses the resolver directly (WIP).
- `ops/staging/validate-runtime.php`: new `runtime.php_cli_binary` check runs the same validation on the deploy's
  candidate environment. `ops/staging/env.staging.example` sets `VASEY_PHP_CLI_BINARY=/usr/bin/php8.4`;
  `docs/ops/staging-runbook.md` §7 describes the behaviour and the pre-enable host check.
- Pins: `git diff origin/main -- resources/contracts` is empty.
- **Main was red after #56** on `ProductionFreeGrantFrozenBytesTest::test_no_paid_lane_or_old_free_family_file_is_copied_or_imported`,
  which asserted `app/Domain/Grants/Paid` does not exist (reproduced at `6dca2379`). Its intent is kept: no family-256
  file imports the paid or old free family, and (new) none is a byte copy of one of their files. A copied paid file in
  the family-256 directory fails it (mutation run, not retained).

## Results

PHP 8.4.26 (CLI and FPM from the same ondrej/php build), PHPUnit 12.5.34, SQLite in memory, `public/build` absent.

### Genuine FPM → CLI smoke (`harness/`, `evidence/fpm-*.txt`)

A private php-fpm 8.4.26 pool (system `php.ini` and `conf.d`, `clear_env`, private unix socket) serves
`harness/m16-fpm-smoke.php`, which boots the application's HTTP kernel and calls `app(<renderer>)->render()` with the
exact `{input, profile}` payload a CLI test run sent to that renderer (captured by `harness/M16Capture*ProbeTest.php.txt`;
the generic family uses its fixture input and the current profile). `cgi-fcgi` sends one request per family.

| Pool | Source | `VASEY_PHP_CLI_BINARY` | Result |
| --- | --- | --- | --- |
| red | `main` product (`49489697`; Paid at `6dca2379` = #56 head) | unset | `PHP_BINARY=/usr/sbin/php-fpm8.4`; raw spawn exit 64 with usage text; free, production-free, generic and paid all `render_failed` |
| green | this branch | `/usr/bin/php8.4` | free, paid, generic and production-free **with its composition binding** render; SHA-256 **identical to the CLI render of the same payload**: free `d3fb73c9…`, production-free `2e5f8b94…`, paid `c30389d3…`, generic `790fd796…`. Production-free through the shipped container (unbound) stays `render_failed` (`fpm-green-final2.txt`) |
| unconfigured | this branch | unset | all `render_failed` (`fpm-unconfigured-final2.txt`) |
| wrong version | this branch | `/usr/bin/php8.3` | `render_failed` |
| FPM binary configured | this branch | `/usr/sbin/php-fpm8.4` | `render_failed` |
| alternatives link | this branch | `/usr/bin/php` (currently → php8.4) | renders; accepted only because the probe proves the current target. The runbook still says not to configure it. |

CLI hashes: `evidence/*.cli-sha256.txt` and `evidence/cli-generic.txt`.

### Tests

| Run | Result | Evidence |
| --- | --- | --- |
| Binding tests red: final tests with `main`'s provider (no bindings) | 9 tests: the 6 Free/Paid tests fail (2 errors, 4 failures); the 3 family-256 tests pass by design; rc 2 | `evidence/binding-red-final.txt` |
| Same with the final provider, plus the family-256 guards | 13 tests, 183 assertions, rc 0 | `evidence/binding-green-final.txt` |
| Affected selection (103 files: Free, ProductionFree and Paid families, contract tests, resolver, validator), 3 shards, at `214256a4` | 75 + 557 + 533 = **1,165 tests, 0 failures**; 15 skips, all MySQL-only native cases; rc 0 each | `evidence/suites-214256a-shard-{0,1,2}.txt` |
| `cli-server` treated as the CLI (review L-2), red | new unit case fails on `0f5890f0`, rc 1 | `evidence/cli-server-red.txt` |
| Same, green, plus the focused M-16 files (resolver, Free/Paid/production-free CLI renderer, runtime validator, family-256 frozen-bytes and approval guards) | 7 files, 53 tests, 429 assertions, rc 0 each | `evidence/focused-cli-server-final.txt` |
| Real `php -S` (cli-server) with `VASEY_PHP_CLI_BINARY` unset | free, paid and generic render with the same SHA-256 as the CLI | `evidence/cli-server-green.txt` |
| Probe under the renderers' sibling library path (Codex P2 4228948830), red | a CLI that needs its sibling `lib/<arch>` is refused (`probe_failed`) at `fc779d06`, rc 2 | `evidence/probe-libraries-red.txt` |
| Same, green: the 7 focused files | 54 tests, rc 0 each | `evidence/probe-libraries-green.txt` |
| Genuine FPM smoke after that fix, with the independent reviewer's captured payloads | free, paid and composed production-free render with **exactly the reviewer's stored CLI SHA-256** (`c4e812cf…`, `20841190…`, `024b4fc9…`); generic `790fd796…`; shipped production-free and every family with the binary unset stay `render_failed`. A first attempt in this round had no capture directory mounted (harness `M16_CAPTURE_DIR`) and failed before rendering; it is not counted | `evidence/fpm-green-probe-libraries.txt` |

## Independent review

`independent-review/DECISION.md`: **APPROVE WITH CONDITIONS** for a development merge of `214256a4` (the `dac1a79`
REQUEST CHANGES for binding family 256, H-1, is fixed by `912f1e2`). Conditions C1 (this results row), C2 (CHANGELOG
and upgrade note) and C3 (PR body) are met in the following commit, which also takes L-2 (`cli-server`) and fixes the
I-3 docblock. Not taken: I-2 (an in-place rewrite of the binary within one second can reuse a cached validation; the
binary is root-owned on the host) and L-1's remaining wording is covered by the upgrade note.

## Not tested

- A Forge host, nginx in front of FPM, AppArmor, or a separately packaged CLI/FPM pair. The probe compares version and
  build, not the extension API number directly; the renderers load the FPM pool's extension `.so` files into the CLI.
- MySQL, browser, Foundation.
- An actual 60 s child timeout under FPM.
