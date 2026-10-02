# GitLab rights-writer continuation

October 2, 2026. Development destination: [private VA-Studio](https://gitlab.com/vaseydev/va-studio), project ID `87181037`. This carries GitHub PR #106's preserved rights candidate and corrects review `4169421712`; it does not accept or merge that candidate.

## Source continuity

The 96 GitLab branch heads exactly matched the preserved source snapshot. Main was `cae053efbc2019c849269605df030a36a620484e`; `codex/rights-writers-20261002-08690e2` was `8fdc341ea639257318a87433b65830df0ab6b3eb`. Every root entry of the latter tree matched local Git objects, including unchanged agent instructions and both lockfiles. The original branch remains preserved; new work uses `codex/gitlab-catalog-writer-locks-20261002`. Destination tags were empty. Historical review, artifact, billing and protection state do not follow Git refs automatically.

## Correction and verification

The three remaining participating catalog writers now acquire fresh persisted actor and locking catalog authority inside their mutation transaction, before track locks and actor-attributed audit foreign-key writes. Valid authority is checked before payload validation. The APIs retain caller-owned outer transactions and their existing publication/pricing behavior. This is a correction to the identified cycle, not a claim of universal deadlock freedom or a standalone publication-apply boundary.

`CatalogWriterAuthorityTest` covers five create/update/deactivate paths, unavailable or withdrawn persisted authority, transaction-local authority reads, audit attribution and nested rollback. On the unchanged preserved runtime it reproduced five failures among 35 cases. After correction all 35 passed with 105 assertions. The integrated SQLite suite executed 182 cases / 1,708 assertions; its 24 exact MySQL-only cases were skipped, not executed. Original red, green, discovery and integrated reports are retained separately with their own hashes.

The first real MySQL execution covered all 24 races. Fifteen passed; nine failed because a newly inserted model's in-memory attribute order differed from a fresh database row. The worker now returns `fresh()->getAttributes()` for mutation results, retaining strict type/value assertions. This corrects the observed worker serialization defect rather than loosening comparison. The original 18 definitions and six new both-order catalog cases remain present. The final MySQL result and its source hashes belong in the merge request evidence.

Local feedback was run on the modified working tree derived from `8fdc341`. It is bound by changed runtime/test file hashes; it must not be represented as a hosted acceptance run on the preserved commit. The GitLab job subsequently binds its own actual checkout SHA/tree, project, pipeline and job.

## Disposable MySQL

Official MySQL Community Server `8.4.11` minimal Linux distribution was obtained over HTTPS. Its published MD5 `3358302178e35d893f14fc49be542847` matched; the archive SHA256 is `383f54e124d5f325d67f0c6912a8f96814eedc761a17ea30112e52fa4cc6b143`. PHP is `8.4.26`. `performance_schema` is enabled and workers exercise genuine InnoDB waits under `REPEATABLE-READ`.

The execution environment refuses Unix sockets and isolates network listeners between command invocations. `scripts/dev/with-mysql-test-server.sh` starts a separate instance in a unique private temporary directory, disables Unix sockets and the X protocol, binds only `127.0.0.1`, sets an ephemeral random root password, and runs the test command and its children in the same invocation. Its exit trap stops that instance and removes only its own synthetic database directory. No existing database is used.

After obtaining an official MySQL 8.4 distribution and its required libraries:

```sh
MYSQL_TEST_BASEDIR=/absolute/path/to/mysql \
  bash scripts/dev/with-mysql-test-server.sh \
  php vendor/bin/phpunit tests/Feature/RightsDeclarationWriterConcurrencyTest.php
```

`MYSQL_TEST_LIBRARY_PATH` optionally locates local shared dependencies; `MYSQL_TEST_PORT` optionally selects a different loopback port. PHP must already be on PATH. The launcher deliberately overrides database/mail/session/queue settings with isolated testing values. Installation does not modify the Mac checkout or provide a persistent production service.

## GitLab pipeline boundary

`.gitlab-ci.yml` adds three candidate feedback jobs: quality and the selected nine-class writer suite on SQLite and genuine MySQL 8.4. It runs for merge requests, main pushes and explicit web/API runs; ordinary branch pushes do not duplicate merge-request execution. Setup installs PHP extensions and Composer with installer checksum verification. Jobs retain discovery, original JUnit and native GitLab source/runtime evidence for seven days.

`gitlab-writer-feedback.py` checks exact project identity and checkout SHA/tree, a clean checkout, locked installed dependencies, PHP/MySQL versions, all selected classes and every discovered case. MySQL skips are refused; SQLite skips must match the existing exact policy. Existing inventory/JUnit validators are reused without synthesizing GitHub environment variables. Adversarial tests cover foreign project/event/configuration, bad numeric identities, changed SHA, untracked source, missing/duplicate/unknown/failing cases and unreviewed skips.

These are explicitly **feedback, not full acceptance**. The original GitHub receipt collector remains unchanged. Equivalent four-MySQL/two-SQLite full partitions, frontend/native browser jobs, a reviewed GitLab artifact/provenance collector and independent review of the actual candidate remain merge gates. Source-hashed local feedback and a green subset pipeline cannot replace them. Continue the port before reviewed publication-manifest compare/apply or scheduling.
