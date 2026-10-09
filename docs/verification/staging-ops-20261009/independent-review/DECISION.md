# Independent review: PR #63 repaired Forge staging kit

**Decision: APPROVE for the authorized focused development merge.**

Exact reviewed and independently tested source: `2c91f8e620cd870a2e213b4af790a1dbfab8de9a`.

This completes the interrupted review at `1d4b8f4361bd2cfd2263e2f61a6a077b09eba681`. The [initial blocked decision](INITIAL-REVIEW.md) and all seven original red receipts remain unchanged. The reviewer read `AGENTS.md`, the actual provisioning, privileged helper, deploy, backup, runtime admission and normalization code, relevant templates and runbooks, canonical tests, and retained receipts. Only files in this independent-review directory were written; no product source, canonical test, commit, branch, remote, account or host was changed.

## Findings and resolution

| Finding | Assessed repair and evidence |
| --- | --- |
| F1: app activation cannot write the protected parent | Provisioning keeps root-owned activation/release parents. The helper allocates an app-owned checkout slot, seals privileged ancestry and performs the atomic `current` switch itself. Independent switch cases refuse running workers/web, missing maintenance or missing attachment before changing the pointer; the complete valid fixture switches successfully. |
| F2: detach follows mutable release paths outside the tree | Each ancestor is sealed before following its child; subsequent actions require canonical root-owned ancestry with no group/world write. Detach additionally refuses the current release, checks the persistent-store inode and uses `umount --no-canonicalize`. Independent probes reject a leaf symlink to `/proc`, an unrelated mount and writable ancestry before dispatching an unmount. Regular, outside and dangling invalid current references refuse quiesce rather than entering first-install fallback. |
| F3: grep admits Laravel-effective enabled production flags | The additional gate boots actual locked Laravel/Dotenv configuration with `env -i` and an isolated supplied file/cache path. It refuses duplicate keys, cache overrides, production flags/credentials, unsafe database overrides and key drift. Genuine PHP tests pass all 13 cases / 63 assertions; the independent quoted-true probe also refuses. Cache/migration commands use the isolated process environment. |
| F4: global charset substitution can conceal row changes | The byte normalizer only admits the immediate known utf8mb4 attribute of a supported string column in a matching top-level dump table block. Rows, defaults, comments and enum values remain exact. Canonical normalization cases and independent context cases pass; the genuine MySQL receipt confirms altered INSERT text remains distinguishable. |
| F5: failed reproof retains stale success | Reproof removes the prior marker before hash validation and publishes its new marker atomically after verification and cleanup. The original failed-hash probe now passes unchanged. No old success marker survives its failed attempt. |
| F6: phase flag falsely proves stopped writers | The EXIT handler reports unconfirmed service/writer state during failed activation/configuration attempts, and recovery instructions establish quiesce. The independent handler probe exits 73 and makes no stopped-writer claim. |
| F7: same-SHA refresh loses key/configuration recovery | Real admission and unchanged key precede quiesce. Snapshot/proof precedes replacement. One private candidate copy is admitted and installed, so a later Forge mirror edit does not replace the admitted profile. Recovery re-proves the selected backup, verifies `env.backup.sha256`, writes into the existing app-owned environment file, compares bytes and rebuilds cache before resume. Independent success and failing quiesce/snapshot/cache/key cases preserve the required ordering; key drift causes no control call or environment replacement. |
| F8: concurrent helper attach can rebind PRIVATE during root prune | Found independently at `ec3c54819395641194c00a7656e0d1881dd651bd`: two actual helper dispatchers allowed attach between prune's final identity read and recursive removal. The separate canonical root-owned `0600` helper lock now serializes complete actions and recursive prune→detach. The unchanged two-process safety assertion now records `rm-while-mounted=no`; competing attach waits. Nightly uses serialized `ctl snapshot`, whose stopped-writer proof and lock cover backup/proof/transfer. Independent resume blocks throughout that action. App and disposable MySQL children close descriptor 8; the provisioning fragment preserves the lock inode. |
| F9: routine/literal bytes were mistaken for table blocks | Found independently at `ec3c5481`, and a remaining literal `DELIMITER ;` reset was reproduced at `1e119e3f7f6c8d944637728814c7f3c4fc8ebe97`. The final parser tracks SQL quote/comment context and only accepts delimiter directives outside it; custom-delimiter bodies stay exact. All four independent cases now pass, including a delimiter-looking line inside a multiline routine literal. Explicit `NO_BACKSLASH_ESCAPES` is refused instead of guessing lexical boundaries. |

The implementer's additional native dump finding—aligned spaces in MySQL's `@saved_cs_client` setting prevented valid schema normalization—is addressed by the explicit whitespace rule and canonical coverage. It does not broaden normalization into data bytes.

No unresolved blocking runtime finding remains in the reviewed scope.

## Independent execution at the exact final source

All listed final commands exited **0** from `/workspace/VA-Studio-ops`:

| Command / receipt | Actual result |
| --- | --- |
| `python3 .../independent-review/ctl-concurrency-probe.py` → [ctl-concurrency-final.txt](ctl-concurrency-final.txt) | 1 test passes; competing actual helper waits; removal sees no mount |
| `python3 .../independent-review/lock-boundaries-probe.py` → [lock-boundaries-final.txt](lock-boundaries-final.txt) | 6 tests pass: lock tampering, inode preservation, app/MySQL descriptor closure, snapshot/resume exclusion, nightly success/failure flow |
| `python3 .../independent-review/normalizer-context-probe.py` → [normalizer-context-final.txt](normalizer-context-final.txt) | 4 byte-context tests pass |
| `python3 .../independent-review/repair-boundaries-probe.py` → [repair-boundaries-final.txt](repair-boundaries-final.txt) | 9 tests pass, with current-reference, switch and refresh subcases |
| Direct genuine PHP 8.4.26 / PHPUnit 12.5.34 runner, `tests/Unit/StagingRuntimeValidatorTest.php` → [runtime-validator-final.txt](runtime-validator-final.txt) | 13 tests / 63 assertions, no failure or skip |
| `python3 tests/ops/test_staging_dump_normalization.py` → [normalizer-canonical-final.txt](normalizer-canonical-final.txt) | 12 tests pass |
| `python3 tests/ops/test_staging_deploy_refresh.py` → [refresh-canonical-final.txt](refresh-canonical-final.txt) | 1 test passes |

`git diff --check` exited zero. No long domain matrix or additional hosted CI was launched by this reviewer.

New independent reds remain [ctl-concurrency-red.txt](ctl-concurrency-red.txt) (1 failure at the original repair source), [normalizer-context-red.txt](normalizer-context-red.txt) (2 of 3 failures), and [normalizer-context-intermediate.txt](normalizer-context-intermediate.txt) (1 of 4 failures after the first delimiter repair). They were not overwritten by final greens. The implementer's frozen-profile and lexical-context red receipts were also inspected.

Harness adjustments are explicit: the full-helper fixtures simulate root ownership only in disposable ancestry, and substitute mount/unmount/removal authority; genuine helper dispatch, canonical path logic and flock remain executed. The new combined UID/mode lock query required the same fixture identity adapter. Function-level policy probes exclude trusted bootstrap, which the separate full-helper drivers test. Preserved harness attempts document the sandbox's mapped root UID, an extracted provisioning shell not loading its authority adapter, and a function-only extraction initially including the newly added lock bootstrap. Those harness failures are not claimed as product reds.

## Native receipts and limits

The prior independent [nginx/FPM receipt](nginx-fpm-routing.txt) contains **26 passing checks** on genuine nginx **1.26.3** and PHP **8.4.26 FPM**, owned loopback TLS configuration and a synthetic FastCGI responder. The nginx/pool templates are unchanged from its reviewed source. This proves its routing/auth/method/body/header boundaries, not Laravel payment processing or Forge activation.

Inspected implementer native receipts show MySQL **8.4.11**, the schema-scoped `vasey_app@127.0.0.1` account, trigger refusal **1419** with trust off, success with trust on, **79 migrations / 184 tables / 567 triggers**, and repeat migration no-op. The final native dump receipt at this source reports raw dumps different, normalized dumps equal, restored row exact, altered INSERT refused and one retained trigger. These are implementer executions, not additional independent MySQL runs. The dump evidence driver should receive the same explicit disposable-environment guard as the migration evidence driver before publication; its recorded execution used the disposable helper correctly.

Actual Forge/DigitalOcean access remains unavailable. No real provisioning, root bind/unbind, sudoers installation, service restart, AppArmor activation, DNS/ACME operation, backup shipping/encryption, credentials, upload, Stripe request or live/production payment was performed. The authority substitutes cannot prove real host permissions or daemon integration. Seal/attach/switch/quiesce/resume and an actual paired-key restore must still be exercised on the private host with Sean's inputs.

The private-copy refresh proof covers concurrent edits to the Forge mirror; it is not an attestation against a compromised application OS user. First install assumes a genuinely unserved staging baseline. Runtime gates, seller/rights/catalog inputs, two staff accounts/MFA, secure secrets, encrypted backup destination, final integrated Foundation acceptance and the first real Stripe TEST purchase remain separate acceptance requirements. Unsupported dump lexical modes fail closed and may require a reviewed extension before a host restore proof can pass.

Approval is limited to this exact focused development source. The integrator must satisfy current preflight/Codex findings and merge with the expected head. An evidence-only or base-integration successor requires an explicit equivalence check; changes to the reviewed runtime or canonical coverage need re-review.
