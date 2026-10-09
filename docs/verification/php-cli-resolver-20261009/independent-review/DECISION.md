# M-16 independent review: CLI PHP for renderer children under PHP-FPM

- **Lane:** licensing / contract rendering. **Reviewer:** independent. I did not write the code under review.
- **Date:** 2026-10-09. **Host:** PHP 8.4.26 CLI and php-fpm 8.4.26 from the same build. A different CLI, `/usr/bin/php8.3` (8.3.6), is also present.
- **Scope:** development-merge review only. This is not Foundation CI, final acceptance or launch evidence.

## Decision

| Commit | Verdict |
| --- | --- |
| `dac1a79` (first subject) | **REQUEST CHANGES**, now superseded. Its provider binding made `ProductionFreeGrantApprovalTest::test_shipped_configuration_is_literally_default_off` fail (finding H-1). That test passes on main. |
| **`214256a`** (branch head after the coordinator's update: `af232a8`, `912f1e2` and the merge of main `e5e500ca`) | **APPROVE WITH CONDITIONS** for a development merge. This decision **explicitly covers `214256a`**. |

The conditions are documentation and record items, not product-code changes (§Conditions). Any further product change
after `214256a` needs a re-check of §3 and §5.

## 1. Frozen pins

- `git diff 86e22f1a dac1a79 -- resources/contracts app/Domain/Grants scripts` is empty (`evidence/01`).
- The pinned renderer classes and child scripts have identical git blobs at `86e22f1a`, `e5e500ca`, `dac1a79` and in
  the worktree (`evidence/02`).
- At `214256a`, `git diff e5e500ca 214256a -- resources/contracts app/Domain/Grants scripts/render-*` is empty
  (`evidence/03`). The only product delta from `dac1a79` is `AppServiceProvider.php` (`evidence/04`).
- `ContractRenderProfileRegistryTest` passes at `dac1a79`: 17 tests, rc 0. The `ProductionFreeGrantFrozenBytesTest`
  frozen-bytes and manifest cases pass on both commits. Its third case was red on main `86e22f1a` as well (`evidence/12`,
  `25`). `af232a8` fixes it; see §6.
- `IsolatedContractRenderer` is not pinned. `test-v1` and `test-v2` pin only `ContractText`, `Tcpdf*ContractRenderer`.

## 2. Resolver (`App\Support\PhpCliBinary`)

The following was verified in a real FPM pool (`evidence/60`–`70`, verdicts from a `family=resolve` request) and in unit
tests.

| Configured | Verdict |
| --- | --- |
| unset | `unconfigured` |
| `php8.4` (relative) | `invalid_path` |
| dangling symlink, or a directory | `invalid_path` |
| 0644 copy of php8.4 | `not_executable`, never run |
| `/usr/bin/php8.3`, or a symlink to it | `version_mismatch` |
| `/usr/sbin/php-fpm8.4` | `probe_failed` (exit 64) |
| `/usr/bin/php`, a symlink to php8.4, or `/usr/bin/php8.4` | resolves to `/usr/bin/php8.4` |

- **Privacy.** Every refusal has the fixed message "A validated CLI PHP binary is unavailable." and no previous
  exception. Renderers convert a refusal to `render_failed`. With a configured path carrying a unique marker, the
  staging validator prints only `FAIL runtime.php_cli_binary` and never the marker (`evidence/75`).
- **Environment scrub.** The FPM pool set hostile `PHPRC`, `PHP_INI_SCAN_DIR`, `LD_PRELOAD`, `LD_LIBRARY_PATH`, a
  secret marker and an `HTTP_X_*` FastCGI parameter. An env-dumping wrapper configured as the CLI recorded exactly
  `LANG=C LC_ALL=C` (plus the `PWD` that `/bin/sh` sets itself) for every probe. Renderer children received only
  `LANG LC_ALL TZ TMPDIR` (`evidence/71`, `71-env-dump.log`). Symfony Process passes an explicit `envPairs` array to
  `proc_open`, and keys set to `false` are dropped, so nothing else can be inherited.
- **Bounds.** The probe is limited to 10 s and at most 128 bytes of output. Any stderr byte causes refusal. Unit tests
  cover 1 MiB of output, stderr, a non-zero exit, extra tokens, a trailing newline, a wrong SAPI and a wrong build. All
  12 unit tests pass (`evidence/13`).
- **Cache identity.** In one long-lived process, an atomic rename-over and a symlink retarget are re-probed and
  refused (`evidence/73`).
- **TOCTOU.** The spawned path is the realpath that was probed. Swapping it between the probe and the spawn requires
  write access to a root-owned binary, and anyone with that access already controls every PHP execution. The probe
  guards against misconfiguration; it is not a trust boundary. The realistic risk is low.

## 3. Factory (`App\Support\PhpCliProcess`) and bindings

- **Refusals.** Commands not starting with `[PHP_BINARY, '-n']` are refused with `unexpected_command`: `/bin/sh -n`,
  `php-fpm -r`, a bare binary and an empty command were all tested. Inside a renderer, a refusal becomes
  `render_failed` (`evidence/73`).
- **What changes.** Under real FPM, the argument vector the factory built differs from the CLI renderer's only in
  `argv[0]`. The other 40 arguments (every `-d`, the extension paths, open_basedir and the script) are identical, as
  are the cwd, the stdin SHA-256, the 60 s timeout and the child environment, including `LD_LIBRARY_PATH`. This holds
  for free, production-free (composed) and paid, at `dac1a79` and `214256a` (`evidence/54`, `99`).
- **LD_LIBRARY_PATH.** A hostile pool `LD_LIBRARY_PATH` is replaced. When the CLI has no sibling `lib/<arch>`, the
  variable is unset, which is equivalent to the renderer not setting it.
- **CLI path is unchanged.** In the CLI the factory is null and `argv[0]` is `PHP_BINARY` (`evidence/53`, `98`). The
  generic renderer's CLI command is unchanged (unit test).
- **Test bindings still win.** Tests that `instance()` or `bind()` their own renderers, or use `offsetUnset`, still take
  precedence. The free, contract and production-free suites pass.
- **Binding red/green.**
  - `dac1a79`: 9/9 binding tests fail without the loop (`evidence/30`) and pass with it (`evidence/16`).
  - `214256a`: with main's provider, the 6 Free/Paid tests fail (2 errors, 4 failures); the 3 family-256 tests and the
    default-off guard pass by design (`evidence/94`). With the shipped provider, everything is green (`evidence/90`).

## 4. Extensions and ABI

`-n` children load FPM's `extension_dir` `.so` files. On Linux, the extension build ID is
`API<api>,NTS|TS[,debug]`. The API number follows the PHP version, so checking version, ZTS and debug is equivalent to
checking the build ID. A mismatch makes PHP warn on stderr, and the renderer then fails closed. Here the CLI and FPM
binaries report identical API numbers and build IDs and link the same `libz.so.1`, which feeds `gzcompress` and so the
PDF bytes (`evidence/74`).

**Limit:** byte identity is proven only for a CLI/FPM pair from the same build. A same-version CLI from a different
vendor (with a different zlib, PCRE or configure flags) could produce different but still valid bytes, or crash, which
fails closed. Not tested: AppArmor, Forge, nginx in front of FPM, an actual 60 s child timeout.

## 5. Genuine FPM smoke (my own harness: `probes/run-fpm.sh`, `review-fpm-smoke.php`, `fpm.conf.tpl`)

Payloads were captured independently in the CLI (`probes/ReviewCaptureProbeTest.php.txt` → `capture/`, `evidence/40`).

| Pool | Source | `VASEY_PHP_CLI_BINARY` | free | production-free | paid | generic | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| red | `86e22f1a` | unset | render_failed | render_failed | render_failed | render_failed | 50, 51 (spawn `argv[0]=/usr/sbin/php-fpm8.4`, raw exit 64) |
| green | `dac1a79` | `/usr/bin/php8.4` | `c4e812cf…` | `024b4fc9…` | `20841190…` | `790fd796…` | 52; all identical to the CLI (53) and to stored artifacts (54) |
| green | `214256a` | `/usr/bin/php8.4` | `c4e812cf…` | shipped: render_failed (unbound by design); composed: `024b4fc9…` | `20841190…` | `790fd796…` | 96, 98, 99 (identical) |
| unconfigured | `dac1a79` / `214256a` | unset | render_failed | render_failed | render_failed | render_failed | 55, 97 |
| wrong version / FPM binary / relative | `dac1a79` | 8.3 / php-fpm / `php8.4` | render_failed | render_failed | render_failed | render_failed | 56–58 |

The author's hashes (`d3fb73c9…` and others) come from their own payloads, so they are not directly comparable. My
CLI, FPM and stored hashes agree for my payloads.

## 6. Coordinator update (`af232a8`, `912f1e2`, `214256a`)

### `912f1e2`: family 256 left unbound

This is correct under the guard, which says "Nothing registers, binds or mounts family 256". No shipped route,
provider, console command or Filament code reaches family 256: outside its own directory, `git grep` finds only
comments. Leaving it unbound therefore changes no shipped behaviour. Under FPM, the shipped container renders it
`render_failed`, which fails closed. The composed binding works and is byte-identical to the CLI. Residual risk: if the
future composition step forgets the binding, family 256 fails closed under FPM rather than misbehaving. The runbook §7
and the README tell that step to add the binding.

### `af232a8`: frozen-bytes guard

This preserves the guard's enforceable intent. The removed `assertDirectoryDoesNotExist(app/Domain/Grants/Paid)` was
made obsolete by the independently reviewed #56 composition, and main had been red on it since then. The import checks
are unchanged. Mutation runs (`evidence/95`):

| Mutation | Result |
| --- | --- |
| byte copy of a Paid file | fails (the import check catches it) |
| byte copy of a Free file | fails (only the new hash check catches it, because `namespace ...\Free;` has no trailing backslash) |
| Free or Paid file copied with only the namespace rewritten | passes |

The last case passed the original test too. The family-256 implementation files remain hash-pinned by
`production-free-v1`.

### Merge cleanliness

`dac1a79` merges with `e5e500ca` cleanly. The same tree, `76837d4b`, was tested before `214256a` existed
(`evidence/80`, `81`).

## Findings

| ID | Severity | Status at 214256a | Finding |
| --- | --- | --- | --- |
| H-1 | High | **Fixed** by `912f1e2` | At `dac1a79`, binding `ProductionFreeGrantRendererProcess` in `AppServiceProvider` made the family-256 default-off guard fail. It passes on main (`evidence/23`, `24`, `20`). Reproduce: `probes/phpunit.sh --filter test_shipped_configuration_is_literally_default_off tests/Feature/ProductionFreeGrants/ProductionFreeGrantApprovalTest.php`. |
| M-1 | Medium | Open (condition C1) | The evidence README's tests table still says `SUITES_PLACEHOLDER` and cites `evidence/suites-*.txt` files that do not exist (at `dac1a79` and `214256a`). The committed evidence claims an affected-suite result it does not contain. |
| L-1 | Low | Open (condition C2) | No CHANGELOG entry or upgrade note. `validate-runtime.php` now fails deploy admission (`runtime.php_cli_binary`) when an existing staging env lacks `VASEY_PHP_CLI_BINARY`, and non-CLI SAPIs now need it. |
| L-2 | Low | Open (follow-up) | Behaviour change under `cli-server` (`php artisan serve`, the Playwright `webServer` `php -S`). On main these rendered, because `PHP_BINARY` there is the CLI binary. On the branch they render `render_failed` unless the variable is set (`evidence/76`–`78`). This is documented in `.env.example` and fails closed, but local dev rendering regresses. CI is the only evidence for browser specs; the specs I read mock render routes. Suggest treating `cli-server` like `cli` or setting the variable for dev and Playwright. |
| I-1 | Info | Open (nit) | Under FPM, static state resets per request, so the "cached per process / long-lived FPM worker" claim does not hold: there is one probe per render request (3 probes for 3 requests on 1 worker, `evidence/72`). That is safe and costs one short process; the docblock and runbook wording is inaccurate. |
| I-2 | Info | Open | The cache key uses one-second `stat` ctime and mtime. An in-place, same-size rewrite with mtime restored, within the same second as the validation, reuses the cached verdict (`evidence/73` case 2b). Only relevant in long-lived non-CLI workers, and only to someone who can already write the binary. |
| I-3 | Info | Open (nit) | The `PhpCliProcess` class docblock still lists `ProductionFreeGrantRendererProcess` as receiving the factory "when this process is not the CLI". At `214256a` it does not until composition. |
| I-4 | Info | — | ABI and byte identity hold only for same-build CLI/FPM pairs (§4). |

Adjacent issue (not M-16; listed, not fixed): main `86e22f1a`/`e5e500ca` is red on the frozen-bytes directory
assertion. `af232a8` resolves it in this branch.

## Conditions for the development merge of 214256a

- **C1.** Replace the `SUITES_PLACEHOLDER` row with the real affected-suite commands, results and evidence files, or
  remove the row. Do not cite missing files.
- **C2.** Add a CHANGELOG entry with an upgrade note: set `VASEY_PHP_CLI_BINARY` (exact running version) on every
  non-CLI host before deploying, because the staging admission check now fails without it. Mention the `cli-server`
  change.
- **C3.** In the PR body, state that `af232a8` changes a guard test outside M-16's product scope to fix main's red, and
  that family 256 ships unbound and renders `render_failed` under FPM until its composition adds the binding.

L-2, I-1 and I-3 may be follow-ups. None of them blocks a development merge.

## Runs

The `rc` is the PHPUnit or command exit code. Full raw output, command and timestamp are in `evidence/<n>-*.txt`.

| # | Source | Selection | Result | rc |
| --- | --- | --- | --- | --- |
| 10 | dac1a79 | ProductionFreeGrantFrozenBytesTest | 3 tests, 1 failure (pre-existing directory assertion) | 1 |
| 12 | 86e22f1a | same | same failure (pre-existing on main) | 1 |
| 11 | dac1a79 | ContractRenderProfileRegistryTest | 17 OK | 0 |
| 13 | dac1a79 | PhpCliBinaryTest | 12 OK, 97 assertions | 0 |
| 14 | dac1a79 | StagingRuntimeValidatorTest | 17 OK | 0 |
| 15 | dac1a79 | TestIsolatedContractRendererTest | 22 OK | 0 |
| 16 | dac1a79 | 3× *CliRendererTest | 9 OK | 0 |
| 20 | dac1a79 | tests/Feature/ProductionFreeGrants | 164: 2 failures (H-1 guard, pre-existing), 2 skipped | 1 |
| 25 | 86e22f1a | same | 161: 1 failure (pre-existing), 2 skipped | 1 |
| 23 / 24 | 86e22f1a / dac1a79 | default-off guard | OK / FAIL | 0 / 1 |
| 21 | dac1a79 | FreeGrant* | 46 OK, 1 skipped | 0 |
| 22 | dac1a79 | contract family (6 classes) | 94 OK | 0 |
| 82 | dac1a79 | PaidGrant Document/Source journeys, PreparationBudget | 19 OK | 0 |
| 30 | dac1a79, provider loop removed | *CliRendererTest | 9: 3 errors, 6 failures (red) | 2 |
| 81 | e5e500ca + dac1a79 merged tree 76837d4b | new tests + approval | 71: 1 failure (H-1) | 1 |
| 90 | **214256a** | *CliRendererTest, PhpCliBinary, generic, staging validator | 60 OK | 0 |
| 91 | **214256a** | tests/Feature/ProductionFreeGrants | 164 OK, 2 skipped (native-MySQL-only, evidence 93) | 0 |
| 92 | **214256a** | FreeGrant* | 46 OK, 1 skipped | 0 |
| 94 | 214256a with main's provider | *CliRendererTest + guard | 10: 6 Free/Paid red, family 256 and guard pass | 2 |
| 95 | 214256a | frozen-guard mutations | see §6 | 0 |
| 50–59, 60–72, 96–97 | 86e22f1a / dac1a79 / 214256a | real php-fpm 8.4.26 pools | see §2 and §5 | 0 |
| 53, 54, 98, 99 | dac1a79 / 214256a | CLI vs FPM bytes, argv and env | identical | 0 |
| 73–78 | dac1a79 | cache, factory, ABI, validator privacy, cli-server | see findings | 0 |

**Not run:** the full Paid family (slow), MySQL, browser, Foundation. At `214256a` the contract family and the paid
journeys were not re-run: their product code is unchanged from `dac1a79`, where they passed.

## Files

- Probes (raw harness, including the probe sources): `probes/` (`run.sh`, `phpunit.sh`, `run-fpm.sh`, `fpm.conf.tpl`,
  `review-fpm-smoke.php`, `ReviewCaptureProbeTest.php.txt`, `compare*.php`, `cache-identity-probe.php`,
  `abi-compare.sh`, `validator-privacy.sh`, `cli-server.sh`, `frozen-guard-mutations.sh`, `binding-red.patch`,
  `php-env-dump.sh.txt`).
- Raw outputs: `evidence/`. Captured synthetic payloads: `capture/`. Failed or partial attempts: `failed-attempts/`.
- Scratch worktrees (not product): `/home/user/rv-m16-main` (86e22f1a) and `/home/user/rv-m16-red`. The latter is now
  clean at `214256a`; it was earlier used for the binding-red patch, the capture probe and the merged-tree run, all
  reverted.
