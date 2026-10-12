# Handoff to Codex — 2026-10-11

> Current continuation: [October 12 checkpoint](../2026-10-12/CODEX-CHECKPOINT.md). PRs #96, #98, #99, #108 and #109 have merged. The original state below is preserved as history; C2’s operative writer remains next.

Sean asked Claude to reach a stopping point and hand the development lead to Codex. This file is the entry point.
Read it first, then root `AGENTS.md` (which governs), `docs/handoff/2026-10-07/CLAUDE-PLAN.md` (§3 phased plan
and §5 state ledger) and `docs/handoff/2026-10-07/codex-deferred-20261011.md`. The prompt Sean uses to start the
next session is in `CODEX-PROMPT.md` beside this file.

## 1. State at handoff

- **`main` = `592241a`** (PR #95 merge). No Foundation CI has run since run `38077166247` passed on `0f23e63f`.
  Everything merged after that has focused local evidence only (cost policy in AGENTS.md). No final acceptance is
  claimed.
- **Nothing is live.** Commerce, contracts, delivery, promotions, memberships and identity paths remain
  default-off behind local/testing policies. No credentials, payments, deploys or DNS changes were made.

### Merged in this session (2026-10-11)

| PR | What | Merge | Evidence and conditions |
| --- | --- | --- | --- |
| #89 | BeatStars export normalizer (M-09, M-10) | `9856fa85` | `docs/verification/beatstars-normalizer-20261010/`; waits on Sean's S-9 export and mapping |
| #90 | MySQL catalog draft importer | `60aee090` | `docs/verification/mysql-draft-importer-20261010/`; one P2 → #96 |
| #91 | Launch documents (PRIVACY, TERMS drafts) | `fdbf0300` | `docs/verification/launch-documents-20261010/`; legal review and Sean's "Sean to decide" items open |
| #92 | Domain-level staff MFA (#68 C2) | `4434407d` | `docs/verification/domain-mfa-20261010/`; still open: `PrepareExclusiveOffer` and panel-only writers |
| #93 | Ledger and the Codex freeze-and-batch rule | `2f1cacc` | docs only |
| #94 | Launch-documents follow-up batch (the four #91 P2s) | `6954ffd` | citation pass 17: 272 citations, RESULT PASS |
| #95 | MFA follow-up batch: console attestations bound to the full operation (HMAC of the reference, blocked state, expected version) and spent on one write | `592241a` (head `ef59507`) | SQLite lane suites 26 and affected suites 158 OK; red run on old source failed 6 cases; mutants M20–M30 killed, M31 survives; **not run on MySQL**. Independent review APPROVE WITH CONDITIONS: `docs/verification/domain-mfa-20261010/independent-review-ef59507-DECISION.md` |

### Open work in flight

| Item | State | Next step |
| --- | --- | --- |
| **PR #96** importer whole-schema pin (`harness/followup-importer-20261011`, `2aba5be`) | **Draft. Independent review REJECT** (H1 High: second `information_schema` listing reads the transaction snapshot, so a MyISAM change during the opens is admitted; regression for the epoch table). Preflight green, but that does not matter | Follow `importer-h1/README.md`. Fix, add the red/green MySQL race test, get a fresh independent review, merge at the exact head |
| Dependabot #71–#80 | Open since 2026-10-09 | Handle per CLAUDE.md §6 / AGENTS.md: patch/minor bumps with the focused gate. **#71 `stripe/stripe-php` 22.0.0 is a major bump**; the code pins SDK 21.3.2 and API 2026-08-26.dahlia, so it needs its own reviewed change, not a merge. **#76 `@types/node` 26** does not match the Node 24 runtime; close or pin |

### Review conditions carried forward (from the #95 decision)

1. Before staging relies on console attestations, run `RightsScopeStaffMfaTest` and
   `RightsScopeCommandAttestationTest` once on native MySQL 8.4, or add them to
   `scripts/ci/database-mysql-selection.json` (L3). Until then the evidence says "MySQL not tested".
2. Any new caller that passes an attestation must lock the operator row `FOR UPDATE` before any non-locking read in
   a top-level transaction. Otherwise, first make the consumption check a locking read or a unique key (L1, the
   same issue as the deferred Codex P2 on #95).
3. Optional: correct the HMAC wording in the MFA docs (L2). The existing `control_changed` audit stores an
   unkeyed sha256 of the reference, and the rights tables store it in plain text.

## 2. Working rules that carry over

- **Freeze and batch (Sean, 2026-10-11).** A PR merges at its exact SHA once preflight is green, its independent
  review approves and no P0/P1 is open. A pending Codex re-review does not block. P2s get one thread reply and are
  listed in `codex-deferred-20261011.md` for the lane's next batch. No single-finding pushes.
- **Independent review** (AGENTS.md) for payment, licensing, authorization, migration and irreversible data
  changes. The lead must not review its own change. Use a separate reviewer session, record its DECISION in the
  lane's `docs/verification/` folder, and name the reviewed SHA.
- **CI cost policy** (AGENTS.md): focused local checks plus the cheap preflight for development merges.
  Foundation CI is a manual, exact-SHA final verification only. GitLab dispatch needs Sean's cost go-ahead.
- **Marking a PR ready cancels its first preflight run.** A "Development preflight result: failure" whose jobs
  are all `cancelled` is that artifact; wait for the next run on the same head.
- **Never** edit or re-pin frozen `resources/contracts/*/profile-assets.json` members, claim host verification,
  overwrite unrelated work, turn synthetic fixtures into real host/provider claims, or put secrets in Git or chat.

## 3. Environment recipe (cloud container)

The setup commands are in CLAUDE.md "Working facts" (Node 24 via nvm, apt packages, `composer install`,
`npm ci`, `.env`). Keep `public/build` absent while running PHP tests.

Direct PHPUnit (avoids the Artisan Collision issue):

```
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <files> [--filter X]
```

For MySQL, replace the DB variables with `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=<port> DB_USERNAME=root
DB_PASSWORD= DB_DATABASE=vaseyaudio_catalog_import`. Use a private server on its own datadir, port and socket,
never a shared instance:

```
D=<scratch>/mysql-data; mkdir -p /run/mysqld-x
mysqld --no-defaults --initialize-insecure --user=root --datadir=$D
mysqld --no-defaults --user=root --datadir=$D --port=<port> --bind-address=127.0.0.1 \
  --socket=/run/mysqld-x/mysqld.sock --mysqlx=OFF --pid-file=<scratch>/mysqld.pid --log-error=<scratch>/server.err &
mysql -S /run/mysqld-x/mysqld.sock -uroot -e 'CREATE DATABASE vaseyaudio_catalog_import'
# ... tests ...
mysqladmin -S /run/mysqld-x/mysqld.sock -uroot shutdown
```

This session had Ubuntu's MySQL **8.0.46**; CI uses **8.4.11**, so record which version produced each result.
Run long MySQL suites in the foreground; background runs were lost twice in this session.

MySQL native selection count (update the comments in `.github/workflows/final-verification.yml` and
`.gitlab-ci.yml` after test changes, then delete the generated files):

```
python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql \
  --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
rm -f phpunit-ci-mysql-*
```

At `2aba5be` it reports 1,821 selected tests in 164 files of 7,796; main `592241a` has 1,820 of 7,795.

## 4. Recommended order for the next sessions

1. **Finish #96** (section 1). It is the only in-flight product change.
2. **MFA condition 1:** add the two attestation suites to the MySQL selection, or run them natively, plus the
   deferred locking-read hardening (#95 Codex P2, review L1), as one MFA batch.
3. **Dependabot triage** (section 1).
4. **Then the CLAUDE-PLAN §3 queue**, in dependency order:
   - **C2:** the operative 257 membership writer. Not started; it blocks C4.
   - **A2:** the remaining HTTP whole-order delivery and root mount, then A5 refunds/disputes.
   - **B4:** root HTTP mount for 253. **B5:** email operations preflight.
   - **D1:** mount conditions (A6-2 and the others in its ledger row).
   - **#92 leftovers:** `PrepareExclusiveOffer` and the panel-only writers.
   - **F2:** fixture normalization.
5. **One manual Foundation CI run** on the final integrated SHA when Sean asks for a candidate (F3).

## 5. Waiting on Sean (blocks activation, not development)

- **S-9:** the BeatStars export and mapping (normalizer and importer dry run).
- The "Sean to decide" items in the launch documents, and legal review of PRIVACY and TERMS.
- **Owner decisions:**
  - the importer trigger-target residual (`importer-h1/README.md` item 4);
  - the peer-name over-refusal (#50);
  - the membership design threads (#51);
  - the identity cache proposal.
- **Merchant/Stripe, tax, host and storage, mail:**
  - the merchant/Stripe account, mode and tax registration;
  - plan prices and terms;
  - the production host and storage (U-02);
  - the sender domain and SMTP provider.
- **Codex review on every push:** whether to turn it off is set in Sean's Codex settings. Whether to add Codex
  review guidelines to AGENTS.md needs his yes.
