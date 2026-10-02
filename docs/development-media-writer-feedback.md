# Media writer authority and requester identity feedback

October 2, 2026. This is bounded local feedback for the future media-writer worktree, rooted at `bd1fd374b4c284d172e4f10e2c2921f81726f54f`. It is not full hosted acceptance, a merge record, production scanner certification or deployment evidence. The [agent queue](development-agent-queue.md) retains integration ownership, remaining groups and launch gates. The [private-server preparation](private-server-readiness.md) describes Sean's authorized migration/hosting planning separately from an inspected or deployed host.

## Frozen source scope

Nine changed runtime/test files are bound by SHA-256 in `/workspace/scratch/2c1d12ebc985/media-writer-source-hashes.json`:

- `app/Application/Media/IngestMediaUpload.php`
- `app/Domain/Media/MediaWriterActor.php`
- `app/Domain/Media/QueueMediaProcessing.php`
- `app/Domain/Media/BindStemsToRecording.php`
- `app/Domain/Media/MediaProcessor.php`
- `tests/Feature/MediaWriterAuthorityTest.php`
- `tests/Feature/MediaWriterConcurrencyTest.php`
- `tests/Feature/MediaWorkerIdentityTest.php`
- `tests/Support/media-writer-worker.php`

The hash inventory is a local source binding, not a claim that these bytes already have a hosted commit. Test-selection and exact SQLite skip registration accompany integration; their policy must remain strict. Independent review and subsequent full acceptance must assess the actual composed source.

## Corrected boundary and retained limits

Interactive queue, intake and stems-binding mutations obtain the persisted actor through a locking read inside the mutation transaction before resource locks and actor-attributed foreign-key/audit writes. The current catalog authority check uses the transaction's current actor. Missing or withdrawn interactive authority cannot mutate media or audits. Caller-owned nested transactions and rollback remain supported. Intake retains its preliminary authorization/input/file checks; the write boundary separately rechecks current authority.

Background processing preserves a different contract: a queued request retains requester attribution rather than requiring the requester to retain an interactive staff session. Completion/failure fences lock that requester before their resource/audit writes. Later staff-role or verification withdrawal does not automatically cancel retained worker work. A missing retained requester fails closed. Current completion checks reject changed requester/source/track identity rather than silently switching actor attribution or deriving success from an untrusted changed run. This does not create a general production revocation or cancellation policy.

These changes address the tested actor/resource and foreign-key attribution interactions. They do not prove universal deadlock freedom across all commands. In particular, nested stems binding retains inherited ordinary readiness reads that may observe an older repeatable-read snapshot; a current actor fence does not make every media/readiness query a locking current read. Cross-actor source/track ordering retains inherited limitations outside the exact tested requester/actor interleavings. Publication-manifest compare/apply must define its complete current-evidence contract and participating-writer fence separately.

The scanner used in the new media tests is an explicitly synthetic scanner fixture. Passing it proves the relevant local writer/worker behaviors under controlled inputs, not real ClamAV signatures, production isolation, clean/hostile-media acceptance or a deployed scanner configuration.

## Original red and final feedback

Original reports remain separate; corrected results do not relabel the predecessor:

| Run | Exact outcome | Original report |
| --- | --- | --- |
| Baseline authority regression | 21 reported; 16 passed, four assertion failures and one deleted-actor exception mismatch; 51 assertions | `media-writer-authority-red.xml` / `.log` |
| Final SQLite integrated feedback | 85 reported; 66 executed/passed, 19 exact MySQL-only skips; 397 assertions | `media-writer-sqlite-final.xml` / `.log` |
| Real MySQL authority | 29 passed; 122 assertions | `media-writer-mysql-authority.xml` / `.log` |
| Real MySQL actor/resource races | 10 passed; 847 assertions | `media-writer-mysql-races.xml` / `.log` |
| Real MySQL worker identity | Three passed; 34 assertions | `media-worker-identity-mysql.xml` / `.log` |

Reports are under `/workspace/scratch/2c1d12ebc985/`. The three genuine MySQL runs total **42 executed cases / 1,003 assertions**, without skips. MySQL is `8.4.11` with `performance_schema` enabled, loopback TCP and `REPEATABLE-READ`; independent workers observe real InnoDB waits. The SQLite skips do not supply concurrency evidence. The original failures demonstrate transaction/current-authority gaps and deleted-actor exception behavior; no pre-fix executed production deadlock is claimed.

## Hosting preparation and hosted acceptance

Private-server planning is authorized, and the preparation documentation/environment template are copied into this future worktree. The secret-free template was validated against 72 configuration keys: secrets remain blank and activation flags remain false. It is not an installed host, configured SMTP service, migrated database, live webhook or enabled selling path. Review actual host/OS/network/operator/storage/scanner/backup evidence before migration; preserve keys, originals and paid history.

The separate GitLab candidate abbreviated `6de`, tree `a28`, is associated with [pipeline 2908136664](https://gitlab.com/vaseydev/va-studio/-/pipelines/2908136664). At this checkpoint its SQLite shard 1 failed with a contract-renderer failure under investigation. That pipeline is not recorded as passed or merged, and its status cannot accept these later media bytes. Resolve the actual failure and obtain exact-source full acceptance rather than reusing the local subsets or weakening the native disposable-runner/provenance guards.

## Next contracts

After compatible participating writers and full acceptance are reviewed, complete reviewed publication-manifest compare/apply with a proved current-evidence fence; track scheduling follows its explicit timing decision. In parallel, prepare the source/obligation and private-server evidence. Commerce exception resolution, authoritative unpaid release, refunds/disputes and production provider reconciliation remain next domain contracts before production selling. Customer recovery/library, durable original delivery, historical continuity, operational restore and authorized cutover remain separate launch gates in the current queue.
