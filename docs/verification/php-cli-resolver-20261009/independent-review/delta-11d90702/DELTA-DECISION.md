# M-16 delta review: `214256a4..11d90702`

- **Lane:** licensing / contract rendering. **Reviewer:** independent. I did not write the code under review.
- **Date:** 2026-10-09. **Host:** PHP 8.4.26 CLI and php-fpm 8.4.26 from the same build; `/usr/bin/php8.3` is also present.
- **Subject:** branch `harness/php-cli-resolver`, head `11d907026f42f2f85ae04adb1aaec98563718366`. The delta is three
  commits: `0f5890f` (docs ledger), `fc779d0` (`cli-server` and conditions) and `11d9070` (probe loader path).
- **Base decision:** `docs/verification/php-cli-resolver-20261009/independent-review/DECISION.md`, which approved
  `214256a` with conditions C1–C3 and requires a re-check of its §3 and §5 for any later product change.
- **Scope:** development-merge review only. This is not Foundation CI, final acceptance or launch evidence.

## Verdict

**APPROVE WITH CONDITIONS.** The `214256a` approval extends to **`11d90702`**.

- The delta's product changes are sound.
- The frozen pins are untouched.
- Genuine FPM at the exact commit renders the reviewer's stored CLI bytes.
- C1 and C2 are met. C3 (the PR body) was not checked.
- One new Low finding (L-D1) fails closed and does not block.

### Conditions

- **D-C1.** This approval covers the committed tree `11d90702` only. While I reviewed, someone else edited the
  worktree without committing: `app/Domain/Contracts/IsolatedContractRenderer.php` (+4 lines that set the sibling
  `LD_LIBRARY_PATH`), `tests/Feature/TestIsolatedContractRendererTest.php` (+27 lines), and two untracked files,
  `evidence/generic-libraries-{red,green}.txt`. That edit appears to address L-D1. Once committed, it is a product change
  outside this approval and needs its own short re-check: generic CLI hash unchanged, generic FPM bytes unchanged, the
  rootless stand-in renders generic. Alternatively, leave it out and record L-D1 as a known limitation.
- **D-C2.** The prior C3 still applies to the PR body: `af232a8` touches a guard outside M-16, and family 256 ships
  unbound. I could not see the PR body.

## 1. `PhpCliBinary::isCli()` treats `cli-server` as the CLI (`fc779d0`)

**Sound.** `php -S` and `artisan serve` run inside the CLI executable itself. `PHP_BINARY` there is that executable's
canonical path, and the child it spawns is the `cli` SAPI of the same version and build. So in the CLI and in
`cli-server`, the renderer child is exactly what main (`e5e500ca`) runs.

- **Real `php -S` (`evidence/20-*`).** Launched as `/usr/bin/php8.4 -S` and as `/usr/bin/php -S` (the alternatives link):
  - `PHP_SAPI=cli-server`, `PHP_BINARY=/usr/bin/php8.4`, `isCli()=true`, `path()=/usr/bin/php8.4`.
  - A child `-n -r` reports `cli 8.4.26`, rc 0.
  - The same process with the `fpm-fcgi` seam and the variable unset is refused with the fixed message.
- **Version and ABI.** Under `cli-server`, the child binary is the running binary, so a version or ABI mismatch is
  impossible by construction. If `PHP_BINARY` were empty (an undeterminable binary), the spawn fails, which is
  `render_failed`: fail closed, the same as the `cli` SAPI on main.
- **Scope of `isCli()`.** Only two places use it: `AppServiceProvider`, where `cli-server` gets the unmodified frozen
  renderers exactly as on main, and `path()`, which the generic renderer uses.
- **Staging admission is unaffected.** `ops/staging/validate-runtime.php` builds `PhpCliBinary(..., 'fpm-fcgi')`
  explicitly, so a host still has to configure and prove `VASEY_PHP_CLI_BINARY` whatever SAPI runs the validator.
- **Could production run under `cli-server` and be harmed?** Only if an operator served production with
  `php -S`/`artisan serve`, which the production plan does not do (FPM via Forge is planned). Even then, rendering
  behaves like the CLI workers: same binary, same bytes. The change does not widen what can be executed. It removes a
  requirement in a SAPI where `PHP_BINARY` is already correct. Other non-CLI SAPIs (`fpm-fcgi`, `cgi-fcgi`, `phpdbg`,
  `embed`) still need the configured, probed binary. The unit test now uses `cgi-fcgi` as the unconfigured non-CLI
  seam, and `cli-server` has its own positive case.
- **Prior L-2:** resolved. **Prior I-3** (the `PhpCliProcess` docblock): resolved; it now says family 256 ships unbound.

## 2. The probe runs under `PhpCliProcess::libraries($path)` (`11d9070`)

**Safe, and consistent with the pinned renderers' children. Not consistent with the generic renderer (L-D1).**

- **No path beyond the binary's own sibling directory.**
  - `$path` is already the `realpath` of the configured absolute path: no control characters, a regular executable.
  - `libraries()` returns only `realpath(dirname($path, 2).'/lib/{x86_64,aarch64}-linux-gnu')` when that directory
    exists. A symlinked lib directory is canonicalised; with a symlinked binary, the directory comes from the binary's
    canonical location (`evidence/30`).
  - Whoever can choose the binary path or write next to it already controls what is executed, so this adds no trust.
  - The rule is byte-for-byte the frozen renderers' own rule (`Free/Paid/ProductionFreeGrantRendererProcess.php`
    lines 59–67) and the factory's rule at `214256a`.
- **Scrub intact.**
  - With a hostile parent `LD_LIBRARY_PATH`, `LD_PRELOAD` and a secret marker, the probe child saw only `LANG`,
    `LC_ALL` and the canonical sibling `LD_LIBRARY_PATH` (`evidence/30`).
  - With no sibling directory, it saw only `LANG` and `LC_ALL` (`evidence/31`).
  - In genuine FPM, the pool's hostile `LD_LIBRARY_PATH` was replaced in both the probe and the child (`evidence/42`).
- **No new information leak.** Refusals keep the fixed message with no previous exception (`evidence/30`,
  `prev=false`; FPM `resolve` in `evidence/42`). The library path is never emitted.
- **Probe and pinned child agree.** `evidence/31` shows the factory child's `LD_LIBRARY_PATH` equal to the probe's rule
  in all three cases: `/usr/lib/x86_64-linux-gnu` for `/usr/bin/php8.4`, the canonical rootless directory, and unset
  (`false`) with no sibling. Genuine FPM `record=1` confirms the same (`evidence/42`, `43`).
- **Red/green (independent, real PHP behind a stand-in).** The stand-in is a wrapper that exits 127 unless
  `LD_LIBRARY_PATH` is its canonical sibling directory, then `exec`s `/usr/bin/php8.4`. The `214256a4` resolver refuses
  it (`probe_failed`) and the head resolver accepts it, both directly and through a symlink (`evidence/30`).
- **Edge (Info, I-D1).** A `:` in the install path splits `LD_LIBRARY_PATH` into an absolute prefix and a relative
  remainder (`evidence/31`, "colon split demo"). This is pre-existing in the frozen renderers' rule and the factory; the
  delta only adds it to the probe. It requires an operator to install PHP under a path containing `:`.
- **Cache key (Info, I-D2).** The cache key does not include the lib directory's identity. A swap of the lib directory
  after validation is not re-probed. Exploiting that needs write access next to the binary, which is the same trust
  as the binary itself.

### L-D1 (Low, fails closed): the generic `IsolatedContractRenderer` child does not get the loader path the probe validates

At `11d90702`, `IsolatedContractRenderer` scrubs the environment and sets only `LANG LC_ALL TZ TMPDIR` (its committed
source, lines 33–38). The probe now validates under the sibling `LD_LIBRARY_PATH`. With a rootless CLI that needs it:

- **In-process (`evidence/44`):** the resolver accepts the binary, but the generic child receives no `LD_LIBRARY_PATH`
  and the generic render is `render_failed`.
- **Genuine FPM, rootless pool (`evidence/42`, `43`):** free, paid and composed production-free render the stored bytes;
  generic gives `render_failed`.

This is not a regression. At `214256a` the probe refused such a binary, so every family failed closed. In the CLI SAPI,
generic has never set the path. Two consequences remain:

- Staging admission (`validate-runtime.php`) can now admit a rootless binary with which generic contract rendering
  fails.
- The new code comment, "Validate the binary under the loader path its renderer children get", and the README's
  "plus the renderers' sibling `lib/<arch>` loader path" are true for the pinned families only.

On standard Debian or Ubuntu (`/usr/bin/php8.4`), generic works: the sibling directory is a default loader directory
(pool a in `evidence/43`). The uncommitted worktree edit (D-C1) targets this finding.

## 3. Conditions of the prior review

| Condition | Status at `11d90702` | Basis |
| --- | --- | --- |
| C1 (README results row) | **Met** | `SUITES_PLACEHOLDER` replaced. All 9 newly cited evidence files exist in the commit. The shard files show `214256a474e6…` clean, 75 + 557 + 533 = 1,165 tests, 1 + 14 = 15 skipped, rc 0 each. See I-D3. |
| C2 (CHANGELOG + upgrade note) | **Met** | `[Unreleased] › Fixed`: FPM entry, **Upgrade note** (set `VASEY_PHP_CLI_BINARY` on FPM hosts; staging `runtime.php_cli_binary` admission; `env.staging.example` line 23 sets `/usr/bin/php8.4`, checked), `php -S` / `artisan serve` behaviour, plus the frozen-bytes guard entry. |
| C3 (PR body) | Not checked | I cannot see the PR body; carried as D-C2. |

## 4. Frozen pins

- `git diff origin/main 11d90702 -- resources/contracts`: empty (rc 0).
- `git diff origin/main -- resources/contracts` (worktree): empty (rc 0).
- `git diff 214256a4 11d90702 -- resources/contracts app/Domain scripts`: empty (rc 0).

`origin/main` was re-fetched at `e5e500cadae6…` and is an ancestor of the head. Only four product or config files
change in the delta: `PhpCliBinary.php`, `PhpCliProcess.php` (docblock), `config/app.php` (comment) and `.env.example`
(comment) (`evidence/01`).

## Other findings

| ID | Severity | Finding |
| --- | --- | --- |
| L-D1 | Low | Generic renderer and probe disagree on the loader path; fails closed (§2). |
| I-D1 | Info | `:` in the install path splits `LD_LIBRARY_PATH`. Pre-existing rule, now also in the probe (§2). |
| I-D2 | Info | The cache key omits the lib directory's identity (§2). |
| I-D3 | Info | The README calls the 15 skips "all MySQL-only native cases", but the shard files do not list the skipped tests, so the committed evidence does not substantiate that. The suite row is at `214256a`. The delta's code paths are covered by the focused files and FPM runs below, and they do not change CLI-SAPI test behaviour. |
| I-D4 | Info | The author's delta evidence is recorded on "`0f5890f`/`fc779d0` + working tree", not on the exact commit. My runs below are on the exact `11d90702` and agree: 54 tests, and FPM bytes equal to the stored CLI hashes. |
| I-1 (prior) | Info | Still open (allowed follow-up): `PhpCliBinary.php:77` still says "long-lived FPM worker". |

## Runs

Everything is in `scratchpad/m16-delta/`: raw output in `evidence/`, scripts in `probes/`. The `rc` is the PHPUnit or
command exit code.

| # | Source | Command / selection | Result | rc |
| --- | --- | --- | --- | --- |
| 00 | `11d90702`, worktree clean (porcelain 0 at 10:09:17Z) | source check | — | — |
| 10 | same | `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress <file>`, one process per file | PhpCliBinaryTest 14/100 OK; FreeGrantCliRendererTest 3/14; PaidGrantCliRendererTest 3/45; ProductionFreeGrantCliRendererTest 3/15; StagingRuntimeValidatorTest 17/79; ProductionFreeGrantFrozenBytesTest 3/103; ProductionFreeGrantApprovalTest 11/74 (includes the H-1 default-off guard). Total **54 tests, 430 assertions, 0 failures, 0 skips** | 0 each |
| 20 | `11d90702` classes | real `php -S` (`/usr/bin/php8.4`, `/usr/bin/php`) + `probes/cli-server-router.php` | `cli-server` resolves to `PHP_BINARY=/usr/bin/php8.4`; child `cli 8.4.26` | 0 |
| 30 | `214256a4` vs `11d90702` resolver | `probes/loader-probe.php`, rootless stand-in, hostile parent env | old: `probe_failed`; new: OK (direct and symlink); probe env `LANG LC_ALL LD_LIBRARY_PATH=<canonical sibling>` | 0 |
| 31 | `11d90702` | `probes/consistency.php` | probe rule equals the factory child `LD_LIBRARY_PATH` (system, rootless, none); colon split demo | 0 |
| 40, 41 | — | FPM attempts | **not counted.** 40: socket path over 108 chars, no request served. 41: my export had group-writable `storage` (`tar` 775), so the product guard refused it and every family gave `storage_failed` | — |
| 42 / 43 | `git archive 11d90702` export (storage set to 755 to match the checkout), committed reviewer harness and payloads | genuine php-fpm8.4 pools a, b, c | **a** (`/usr/bin/php8.4`): free `c4e812cf…`, paid `20841190…`, composed production-free `024b4fc9…` (= stored CLI hashes), generic `790fd796…`, shipped production-free `render_failed`; child env `LANG LC_ALL LD_LIBRARY_PATH TMPDIR TZ`, argv0 `/usr/bin/php8.4`. **b** (rootless stand-in): resolves; free, paid and composed production-free give the same stored hashes; **generic `render_failed`** (L-D1). **c** (unset): `unconfigured`, all families `render_failed` | 0 each pool |
| 44 | export of `11d90702` | `probes/generic-env.php` | generic child env `LANG LC_ALL TMPDIR TZ`, no `LD_LIBRARY_PATH`; `render_failed` | 0 |
| 01 | `11d90702` / `origin/main e5e500ca` | pin diffs and product scope | empty / empty / empty; 4 product or config files | 0 |

**Not run:** the full affected selection at `11d90702` (the author's 1,165-test run is at `214256a`), the contract
family, the Paid family, MySQL, browser specs and Foundation. I did not create `public/build`. I did not modify, commit
or push anything in `/home/user/wt-m16`. The foreign worktree edits in D-C1 appeared at 10:11:30–10:11:51Z, after all
of run 10 finished (10:10:09Z). Runs 42–44 used a clean `git archive` export of the commit.
