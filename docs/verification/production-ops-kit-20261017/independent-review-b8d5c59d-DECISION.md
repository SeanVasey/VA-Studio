DECISION: APPROVE WITH CONDITIONS at `b8d5c59dfa7d41d23ee938465ea4ee30dad61d7a`

Reviewer: independent Claude subagent, read-only. No edits, commits, pushes or GitHub writes were made. `git status --porcelain` was empty before and after. Mutations ran only in a scratch copy of the tree. Imported verbatim by the lead.

## Summary

No P0 or P1 findings. The K3 gate is fail-closed. It reads the effective configuration by booting Laravel with the candidate file under `env -i`, which is how `config:cache` runs too. That makes its parsing equivalent to Laravel's by construction, and every divergence I could produce fails closed.

Backups:
- Encryption uses public recipients only.
- Only sets with a restore proof are shipped on the hardened path.
- The default path is unchanged in behaviour.

The production kit enables nothing live by default and weakens no staging control. The conditions below are P2 follow-ups for the per-lane batch.

## Findings

**P2-1: the docs contradict K3.**
- `ops/staging/env.staging.example:185` says "Lane B may turn one on with its policy line".
- `docs/ops/staging-runbook.md:229` tells the operator to set `VASEY_TEST_FREE_GRANTS_ENABLED=true`.
- The gate now refuses any of the twelve flags unless it is exactly false (`ops/staging/validate-runtime.php:93-96`).
- The failure is safe, but an operator following the runbook gets a refused deploy or `ctl refresh`.
- *Remedy:* state that enabling one is a reviewed change to `scripts/ops/capability-boundary.php`, or add an explicit reviewed allow-list.

**P2-2: the production gap is recorded incompletely.**
- The packet README (line 377) says the production surface is "the two ungated flags … and any lane switch".
- It omits `DISCOVERY_SITEMAP_ENABLED`, which is served publicly only in production (`PublicDiscoveryController.php:16`).
- It also omits `VASEY_LISTENING_V2_PROMOTION_ENABLED`, a one-way promotion with no environment gate (`ListeningRollout.php:13`).
- Neither flag is held by `ops/production/validate-runtime.php`.
- The "Open reviewed changes" list in `ops/production/README.md:128-143` has no capability-hold item at all.
- *Remedy:* name all four flags and add the item to the production README.

**P2-3: plaintext off-host shipping is possible.**
- With `VASEY_BACKUP_REMOTE` set and no recipients, the rclone branch ships the whole set in plaintext with only a warning (`ops/staging/backup.sh:417-419`).
- That set contains masters, contracts, customer data and `env.backup` with `APP_KEY`.
- `REQUIRE_ENCRYPTION` defaults to 0 (line 51).
- *Remedy:* make a remote without age recipients refuse to ship unless an explicit `VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1` (or similar) is set.

**P3 notes**
- **Overstated risk in the K1 record.** README:154 and the CHANGELOG say an inherited value "would be baked into `config:cache`". In fact every artisan, composer and validator call already runs under `env -i` (`release-step.sh:18,40`, `vasey-staging-ctl:380-385`, `forge-deploy.sh:77`) or through sudo's `env_reset`. The scrub is defence in depth. Reword.
- **Checklist claim too broad.** `docs/ops/staging-provisioning-checklist.md:49` says "a restricted key (`rk_`) is refused by the deploy". I probed the gate: `STRIPE_TEST_SECRET_KEY=rk_test_…` passes. Only `*_live_` keys are refused. Correct the sentence.
- **"Cannot be tripped from an `.env`" is not quite true.** README:342 says this of the lane check, but `production_checkout.php` is among the 16 scanned files and is env-driven. The extra refusal is harmless. Reword.
- **BOM handling.** A `.env` that starts with a UTF-8 byte-order mark fails the gate as `runtime.unavailable` (the raw `Parser` throws), although Laravel loads the file. This is fail-closed, but the message gives no hint of the cause.
- **Remote pruning works by set name only** (`backup.sh:484+`). Two hosts sharing one remote path would prune each other's sets. Document one path per host.
- **Leftover temporary directories.** `.ship.*` directories (line 393) left by a killed run are never cleaned.
- **ClamAV least privilege.** The AppArmor block grants clamd read access to the private trees (`install-clamd.sh:49`), although `--fdpass` should not need it. Check on the host and drop the rule if it is unnecessary.

## Assessed items

1. **Secrets.**
   - The added lines contain only placeholders, synthetic values and RFC 5737 addresses.
   - Recipient lines must be native `age1…` keys (`backup.sh:323`), and `AGE-SECRET-KEY` lines are refused (321).
   - No `age -i` call exists.
   - The hardened ship requires `RESTORE_VERIFIED` (386-387).
   - Tiered retention protects the newest proven set (463).
   - Remote pruning needs `REMOTE_PRUNE=1`, a remote and tiered retention (350). It runs only after a successful nightly ship, keeps at least one set, and never touches rsync destinations.
   - Default-path diff: the only additions are a loader that does nothing when `backup.conf` is absent, defaulted variables, two false-by-default branches (426, 509) and a `prune_remote` that returns immediately (485). Behaviour is unchanged.
2. **K3 gate.**
   - Duplicate keys are refused (`runtime.unique_keys`, lines 41-43). In Laravel the last duplicate wins.
   - I ran 34 crafted `.env` files through the real gate under `env -i` and compared each with an `env -i` Laravel boot. `export`, interpolation (`${X}`, quoted and unquoted), trailing and whole-line comments, multi-line swallowing, CRLF, whitespace, case variants, blank, `null`, `0`, `off`, a non-breaking space and a lowercase key all agree with Laravel, or the gate is stricter.
   - All twelve config defaults are `env(KEY, false)`. A default flipped to true, or a removed key (read as null), is refused, so an absent line is safe by construction.
3. **K2 policy citations hold.**
   - `FreeGrantPolicy.php:12` (operative arm, no environment test) and `InquiryNotificationWork.php:53-54`.
   - No `FreeGrantIdentity` or `InquiryAlertTransport` binding exists in app, bootstrap, config or routes.
   - Spot-checks: `ServiceProjectPolicy:11`, `LocalConsentRuntime:11`, `FixtureAttachmentPolicy:20`, `UnpaidReleasePolicy:35`.
   - I reproduced six admission-matrix cells, including production plus a bound adapter, which is ADMITTED.
4. **K1.**
   - The scrub list is hand-typed but checked against every `env()` key in config (196, confirmed).
   - The PHP floor check is correct, including Ubuntu suffixes (`8.4.1-1ubuntu…` passes, `8.4.0-…` fails).
5. **Production kit.**
   - Paid-delivery locations, pool and FPM pool are identical to staging; no private-storage mode changed.
   - The production validator refuses live keys and `PRODUCTION_CHECKOUT_*`, and the preflight forces test mode and log mail.
   - The builder is deterministic: two builds were identical, and `--delta` matches the committed 223-line diff.
6. **Open items.** `trustProxies` (absent from app, bootstrap and config), the `DB_HOST` relaxation and production SMTP are recorded as not done. The production flag gap is the incomplete record in P2-2.

## Commands and counts (at b8d5c59d, public/build absent)

- `python3 -m unittest discover -s tests/ops -p 'test_*.py'`: 275 tests OK, none skipped (packet: 275).
- PHPUnit (the brief's SQLite command):
  - `StagingRuntimeValidatorTest`: 79 tests, 457 assertions OK (packet: 79 / 457).
  - `TestCommerceProfileValidatorTest`: 63 tests, 578 assertions OK (packet: 63 / 578).
- `build-kit.py --check`: ok, 13 files. Two builds produced identical output, and `--delta` matches the committed snapshot.
- `shellcheck -S warning` on the staging, production, monitoring, ClamAV and derived scripts, plus default severity on `backup.sh`: clean. `bash -n` and `php -l`: clean.
- Mutations in the scratch copy, each restored and compared afterwards:

| Mutation | Result |
| --- | --- |
| scrub family removed | 1 failure |
| PHP floor raised | 1 failure |
| `flagsNotOff` made strict-true | 16 failures (matches packet) |
| gate flag loop removed | 30 failures (matches packet) |
| hardened restore-proof check removed | 2 failures |
| retention `protect` removed | 2 failures |

## Not verified

- Anything on a real host: Forge, DigitalOcean, systemd, AppArmor enforcement, clamd, nginx reloads, cron, or a real rsync or rclone remote.
- Native MySQL and the migration proof.
- I did not re-run `nginx -t`, `php-fpm -t` or clamd myself; the Python tests cover them as recorded.
- The age restore drill with a real key.
- The CI preflight and the effect on shard timing.
- The remaining mutation claims beyond the six I re-ran.
