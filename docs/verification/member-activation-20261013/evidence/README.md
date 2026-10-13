# C4c component receipts

This checkpoint preserves six explicitly released component packets. It is not a
final integrated suite, application approval, hosted preflight or publication
receipt. Actual confirmation/activation, nonempty authenticated history,
transaction rollback and native concurrency remain pending. Independent component
review has also requested the selection-join and policy/display corrections;
these original component receipts remain historical after those corrections.

| Released selection | Author source | Tests / assertions | Disposition |
| --- | --- | --- | --- |
| V3 and prior policy | a2e3b5ad | 82 / 201 | Pure pass |
| Authorization records | 3646d50c | 37 / 49 | Pure pass after test correction |
| Historical invoice and prior ledger | 3af459ff | 64 / 124 | Pure pass after test correction |
| Consume and required-history ledger | 3202ad04 | 75 / 139 | Pure pass after test correction |
| Added consume/cleanup method | 558e4ec2 | 1 / 6 | One selected method only |
| Activation records | 433b84f3 | 7 / 79 | Pure codec pass |
| Activation history | d0e81c46 | 8 / 39 | Pure codec pass after helper correction |
| Reader handles | feb05d22 | 5 / 16 | Pure pass |
| Reader queries | 33beeb02 | 18 / 34 | SQLite pass; earlier 16 / 32 retained |
| Installed-schema empty reader | feb05d22 | 1 / 23 | SQLite pass |
| Retained-original values/files | c8c76f82 | 30 / 82 | Synthetic local file pass |
| Existing original files | c8c76f82 | 40 / 157 | Existing file regression pass |
| Integration preparation | 4bc0e54c, 8a474163 | Unrun | Syntax/Pint/whitespace only |

Every passing PHPUnit invocation above recorded zero errors, failures and skips.
Selections overlap and span different source commits; do not add them as a unique
suite total. Seven prepared integration cases are neither executions nor skips.
Full 40-character sources, per-invocation exits/counts, exact commands, runtime
identities, source scopes and durations are retained through [index.json](index.json)
and the original receipts.

The packet retains the authorization assertion failure (2 / 10), historical
canonical-order failure (1 / 1), consume no-op mutation failure (20 / 47), history
helper fatal (exit 255, empty JUnit, no counts inferred), and initial Pint path
error (exit 255). Fail-fast leaves later selected cases unrun. The subsequent
passes do not erase those invocations. Formatting and setup are separate from
behavioral evidence.

The retained lane's `handoff.md` corrects the generic overlap phrase in its
unchanged receipts: the existing Files regression and Pint overlapped around
10:29:01–02 UTC. No performance claim uses those durations. Pure history uses a
synthetic sealed graph, not an actual ledger producer or current database
authority; its decoded-budget test directly sets a private accumulator and does
not prove a near-cap live graph. Reader source deltas are retained in the owner's
`source-carry.json`; its cross-source catalog is not one final-head execution.

Two capture limitations remain explicit. The seven history-core/reader bundles
omit the executed Laravel base `tests/TestCase.php`, so their captured named
inputs match Git but complete harness binding is partial. Policy/consume runners
observe tracked source before and after, excluding a complete ignored/untracked
inventory; autoload/runtime/runner identities are captured before execution only.
No retrospective read repairs either observation. Installed dependency identity
is the recorded lock plus local autoload/package metadata, not a hash inventory of
every installed dependency byte. Future integrated receipts use a strengthened
runner and will remain separate.

## Storage and reproduction

[release-plan.json](release-plan.json) records each owner's explicit immutable
allowlist, original runtime location and manifest digest. [releases.json](releases.json)
maps every original relative path to its SHA-256 object. Only named released UTF-8
receipts were admitted; no environment file, secret value, key, private payload,
generated PDF/audio or database bytes are archived. Synthetic fixture source is
bound through tracked Git paths rather than copied payload archives.

Objects store each distinct released byte sequence once. Small receipts use
deterministic gzip. Similar large JSON bundles can use a one-level line delta
against one full object; its base and exact copied/literal bytes reconstruct the
original, which is checked against the owner's digest and byte length. Archive
hashes are checked separately. This changes storage only, never receipt content.
The checkpoint contains 152 released members plus six manifests, represented by
123 unique objects: 3,151,616 original unique bytes in 611,479 archive bytes.

From the repository root:

```sh
# Check the complete committed checkpoint, including tools and indexes.
sha256sum --quiet -c docs/verification/member-activation-20261013/evidence/SHA256SUMS

# Reconcile archived bytes, allowlists, source/runner references and JUnit.
python3 docs/verification/member-activation-20261013/evidence/packet.py verify --output /tmp/c4c-archive-index.json

# Also compare captured named source inputs to reachable Git versions.
python3 docs/verification/member-activation-20261013/evidence/packet.py verify --git --output /tmp/c4c-git-index.json

# Inspect original bytes, or reconstruct one release into a new directory.
python3 docs/verification/member-activation-20261013/evidence/packet.py cat authorization authorization-release.md
python3 docs/verification/member-activation-20261013/evidence/packet.py extract retained /tmp/c4c-retained-receipts
```

Without `--output`, `verify` rebuilds the single derived `index.json`. The commands
above leave tracked files unchanged. No command above starts PHP, Laravel, a database
or an application test. `ingest release-plan.json` is the maintainer-only copy
step and needs the explicitly released runtime files for newly added releases;
existing releases are not reread or overwritten.

[source-map.json](source-map.json) binds all 16 source bundles and the three-file
static binding to root history reachable from `ec151b20a9af83118ab9d85e65eadf9c703c941d`.
The lead's author-to-integration mapping supplies each default root version;
16 explicit per-path overrides select earlier byte-equivalent root versions where
a lane's captured dependency differed. The offline verifier checks every named
path and every fallback's ancestry. This makes verification independent of
unpublished author branches. It is path-byte equivalence, not execution of the
root candidate, evidence for omitted inputs, or final source carry. Final source
mapping and newly released correction/composition receipts remain pending.
