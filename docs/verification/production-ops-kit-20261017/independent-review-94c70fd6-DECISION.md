DECISION: APPROVE at `94c70fd6009f0e41ee0ce25bd0fa4289244026ac`

Reviewer: independent Claude subagent. This was a read-only re-review of `git diff b8d5c59d..94c70fd6` (commits 48e3c6da, 9ebfd130 and 94c70fd6). I made no edits, commits, pushes or GitHub writes, and `git status --porcelain` was empty before and after. Probes and mutations ran only in scratch copies.

## Prior conditions

- **P2-3: closed.**
  - The refusal sits at `backup.sh:343-346`. `ship_hardened` runs it before any cleanup or transfer (397), and every `rclone_run` call is behind it (418-432, 498-513).
  - REQUIRE_ENCRYPTION is checked first (329-330), so it still wins.
  - End-to-end probe of the real script, with a fake rclone and real age. `check-offhost` and `ship` both refused, and rclone was never invoked, for each of these opt-in values: unset, `0`, empty, `true`, `yes`, `2`, ` 1`, `1 `, `01`, `+1` and `TRUE`.
  - An empty environment value defaults to 0 (line 54).
  - A `backup.conf` value of 0 overrides an environment value of 1.
  - REQUIRE_ENCRYPTION=1 with the opt-in is refused.
  - A recipients file with no key, or a missing one, is refused even with the opt-in.
  - A remote set only through the environment is refused.
  - `prune_remote` is refused too (498).
  - The default path, rsync with or without the opt-in, and encrypted shipping behave as before.
  - An environment-only opt-in is honoured, but only root can set it: sudoers has no SETENV and uses `env_reset`, and cron runs with a minimal environment. Every other setting behaves the same way, so this is not a bypass.
- **Stale `.ship.*` cleanup: closed and safe** (one caveat in P3-2).
  - `find` without `-L`, `-type d`, the exact `.ship.??????` name, `-mmin +1440`, and the backup directory is root 0700.
  - Swapping a symlink in between the check and the delete makes `rm` remove only the link; I confirmed this with GNU rm 9.4.
- **P2-1: closed.**
  - `env.staging.example:173-176,187-188` and runbook §7 (229-233) now match the gate (`validate-runtime.php:99-102`). The twelve flags are the ten plus two in `capability-boundary.php:26-47`.
  - No instruction to enable a flag remains in `ops/` or `docs/ops/`.
  - Two of the feature guides say local or testing only (`test-refunded-exception-resolution.md:7`, `test-unpaid-release.md:7`). That is consistent with the hold; the policies actually admit staging, which is an older understatement.
- **BOM: closed.**
  - `validate-runtime.php:43-46` reports `runtime.byte_order_mark` and still refuses.
  - I confirmed that the raw `Parser` throws on a BOM, while phpdotenv's reader strips it (`Str.php:59`).
  - The test asserts the exact error output, so removing the check fails it.
- **P2-2: closed, with one inaccuracy (P3-1).**
  - All four flags are named and every citation holds: `FreeGrantPolicy.php:12`, `InquiryNotificationWork.php:53`, `PublicDiscoveryController.php:16`, `ListeningRollout.php:13` and `SitemapConfiguration::assertEnabled` (31).
  - Item 6 of `ops/production/README.md` (144-152) records the hold as Sean's decision and does not implement it.
- **P3 wording: closed.**
  - `config:cache`: every artisan, composer and validator call runs under `env -i` (`release-step.sh:18,40`; `vasey-staging-ctl:380-385,431,452`; `forge-deploy.sh:77`).
  - Live keys: only `*_live_` is refused (`forge-deploy.sh:129`, `validate-runtime.php:51`). A `rk_test_` key fails `validate-test-commerce-profile.php:238-240` and `StripeSdkCheckoutGateway.php:135`.
  - The `production_checkout.php` note is accurate.
  - The ClamAV host check matches `install-clamd.sh:46-49`.

## New findings: P3 only, none blocking

- **P3-1: the production-gap record says no template states the four flags. It does.**
  - `ops/production/README.md:147` says "no template line states them either", and the packet README (378) says "stated by no production template line".
  - In fact `ops/production/env.production.example:210,217,223,226` sets all four to `false`, and `compose-env.py` will not let a later layer change them.
  - The real gap is that no validator refuses a `true` value.
  - *Remedy:* cite those lines and restate the gap as "no validator hold".
- **P3-2: the cleanup comment overstates the lock.**
  - `backup.sh:402-403` says shipping "runs once a day under the deploy lock". In fact deploys also ship, and a manual `ship DIR` or `predeploy` takes no lock.
  - A directory's mtime does not change while `tar | age` or rclone streams into a file that already exists. So a concurrent unlocked manual ship could remove a transfer that has run for more than 24 hours.
  - That fails closed: the local set stays intact, but the remote may be left with a `.tar.age` and no `.sha256`.
  - *Remedy:* hold a non-blocking `flock` around cleanup and transfer, or correct the comment.
- **P3-3: two cleanup mutations survive the tests.** Broadening the name to `.ship.*` and adding `find -L` both pass. Neither is harmful in practice, since `rm` never follows a link given as its argument.
  - *Remedy:* add a 7-character `.ship.ABCDEFG` decoy, and set the symlink target's mtime old as well.
- **P3-4: the packet calls all three guides "local and test enablement".**
  - `docs/service-projects.md:9-11` (and `docs/free-grants.md:19-20`) say staging admits the feature. That is accurate for the policy, but neither mentions the deploy hold.
  - *Remedy:* add one line pointing to the gate.
- **P3-5: the CHANGELOG omits two new behaviours:** `runtime.byte_order_mark` and the automatic deletion of stale `.ship.*` directories. *Remedy:* add both to the existing entries.
- **P3-6: one template comment is imprecise.** `env.staging.example:174` says "the twelve capability flags below", but two of them are on lines 98 and 100, above it.

## Commands and counts (at 94c70fd6, public/build absent)

- `python3 -m unittest discover -s tests/ops -p 'test_*.py'`: 280 OK (packet: 280).
- Both validator files with the SQLite command:

| File | Result | Packet |
| --- | --- | --- |
| `StagingRuntimeValidatorTest` | 80 tests, 464 assertions OK | 80 / 464 |
| `TestCommerceProfileValidatorTest` | 63 tests, 578 assertions OK | 63 / 578 |

- `shellcheck -S warning` over the staging, ctl, production, monitoring and ClamAV scripts: exit 0. Default severity on `backup.sh`: exit 0. `bash -n` and `php -l`: clean.
- `build-kit.py --check`: ok, 13 files.
- End-to-end probe: 34 cases with a fake rclone and real age (script in the scratchpad `rereview/probe.sh`).
- Ten mutations of `backup.sh` against the off-host tests (45 tests):

| Mutation | Result |
| --- | --- |
| refusal removed | 2 failures |
| refusal also when recipients are set | 5 failures |
| opt-in default flipped to 1 | 2 failures |
| opt-in overrides REQUIRE_ENCRYPTION | 1 failure |
| age guard (`-mmin`) removed | 1 failure |
| `-type d` removed | 1 failure |
| name broadened to `.ship*` | 1 failure |
| validity `case` removed | 1 failure (the `!= 1` test still refuses) |
| name broadened to `.ship.*` | passes (P3-3) |
| `find -L` | passes (P3-3) |

## Not verified

- Anything on a real host: Forge, cron, sudo, AppArmor or clamd, and any real rclone or rsync remote.
- Interleaving with a real concurrent transfer longer than 24 hours.
- The BOM mutation: I could not edit the worktree, so it rests on reasoning plus the confirmed `Parser` exception.
- Native MySQL.
- The age restore drill with a real key.
