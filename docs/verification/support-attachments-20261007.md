# T17/T28 bounded private attachment child — 2026-10-07

This child adds real server-bound intake, private immutable originals, malware-scan
work, retained-owner/operator downloads, expiry, and explicit tombstone/physical
cleanup. It is development preparation, not production attachment activation or
completion of WP-10/T17/T28. No production policy, customer migration, transport,
host scanner acceptance, terms, prices, or delivery obligations are invented.

## Source and integration ownership

Base: `ca3b1fa7eb60edd2228b73742da33179228a277e`. Isolated branch:
`codex/private-support-attachments-20261007`, worktree
`/workspace/VA-Studio-support-attachments`. Contract baseline:
`1a178fd711e008fb8feda9ccf4c7bfa325d89f06`. Final runtime and validation hashes
are recorded by the subsequent frozen receipts, rather than implying that the
contract baseline includes the executable pipeline.

Owned paths: `app/Domain/SupportAttachments/**`, the four new
`SupportAttachment{Controller,Privacy,Request,Response}` HTTP classes,
`database/migrations/2026_10_07_249000_support_attachments.php`,
`routes/support-attachments.php`, `resources/js/components/SupportAttachments.tsx`,
dedicated `SupportAttachment*` PHP tests, the race worker, frontend/browser test,
and this verification directory. No CI, lockfiles, legacy migrations, shared
controllers/pages/routes/config, credentials, or existing product files changed.

Root owns ordinary route registration under `web`, prepending
`SupportAttachmentPrivacy` before CSRF, protecting exception responses for its
path family, central source/policy registration, default-off config, and mounts.
Register `AttachmentRegistry` as a server-created singleton with:

- `inquiry` → `InquiryAttachmentAuthority`; policy family
  `original_inquiry_session_v1` → `FixtureAttachmentPolicy` for the explicitly
  disposable local/testing child only.
- `project` → the independently reviewed
  `App\Domain\Services\Projects\Attachments\ServiceProjectAttachmentAuthority`;
  family `test_service_project_v1` → the same fixture policy.
- `support-attachments.fixture_enabled` strictly `false` by default. Its fixture
  implementation also requires local/testing. These checks are repeated after
  callback-capable source work. Real production activation requires a separate
  approved registered policy, source adapter and identity resolver.
- Optional `AttachmentActorResolver` binding for a separately reviewed identity
  family. The default `RehearsalAttachmentActors` retains the existing test
  customer principal, and never upgrades it to T23 production provenance.

New route families are `/private-support/inquiries/{source}/attachments`,
`/private-support/projects/{source}/attachments`, and their
`/private-support/operator/...` counterparts. GET lists; POST `/upload` accepts
one bounded octet-stream with `X-Attachment-Name` (canonical UTF-8 base64),
`X-Request-Key` (canonical UUIDv4), `X-Source-Version` (bounded canonical integer).
POST `/{attachment}/process`, `/download`, `/delete` accept strict flat JSON;
process requires `{sourceVersion,attempt}`, the other commands require `{}`.
Duplicate escaped keys, queries, ranges, encodings, method overrides, foreign
origins, invalid/missing credentials and CSRF fail closed with private headers.

Mount `<SupportAttachments sourceKind="inquiry" sourceId={receipt}
renderScope={freshServerPageScope} />` inside the current owned inquiry view.
Customer project mounts set `sourceKind="project" audience="customer"` and an
actual fresh account/page scope; operator mounts use `audience="operator"`.
The key includes page scope, audience, source kind and source ID. On scope change
the old subtree unmounts before a new private read. Before any explicit refresh,
on denied access, pagehide and unmount, selected files, private rows and drafts
are erased and pending requests aborted. A denied scope cannot revive through
refresh or a late callback. Root must verify these mounts on the composed page.

## Authority and storage contract

Source IDs are locators. `AttachmentActor` comes from the server's original
`InquiryOwner`, trusted customer principal/user or current staff user. A source
adapter locks persisted actor/account/source/graph rows before attachment rows.
It privately issues a nonserializable token; `authorizeMutation` reuses that
token rather than creating a second live savepoint. `proveCurrent` fences exact
raw rows, current authority, primary PDO and transaction generation after I/O
and framework callbacks. `AttachmentRows` qualifies current-primary reads,
rejects temporary table shadows and limits closure reads. No catalog scans or
cross-source fallback occur. Root's service bridge must include the independently
reviewed late-policy repair, not its superseded predecessor.

Original inquiry family remains `original_inquiry_session_v1` with
`original_unclassified` provenance. The existing inquiry policy can enable real
production visitors; those historical records are never labelled synthetic or
adopted into a production account by email. Only the newly attached local files
and their technical policy in this rehearsal are synthetic. Durable binding
contains origin/ownership commitments and source hashes, not browser owner keys,
credentials or emails. The sealed source-family/policy ledger and registered
identity/source/policy interfaces permit additive reviewed production adapters
for explicit new origins without relabelling old sources.

New intake/process close on archival, withdrawal or cancellation. Previously
accepted originals remain available to a still-authorized owner/operator under
the retained policy, including after intake closure. Exact request replay binds
actor, origin, filename, MIME, bytes and digest. It cannot create new bytes after
closure, restore a deleted file, change a manifest, or infer an account owner.

The concrete policy is **5 MiB per file, 10 lifetime manifests per source,
24-hour access expiry; PNG/JPEG/PDF/UTF-8 text only**. These are technical fixture
bounds, not Sean-approved production type/size/retention policy. Archives,
executables, script signatures, controls and selected active PDF constructs are
rejected. Downloads are attachment-only octet streams with nosniff and sandbox
CSP; there is no inline preview, public storage URL, private capability in a URL,
service worker, offline cache, automatic relabel or automatic retention purge.

The browser DTO contains only attachment ID, original source version, state,
confirmed received/total bytes, name/MIME/size/SHA-256, expiry, attempt, retry and
download flags. List adds current source version, intake flag and public technical
limits. It has no private path, owner/account ID, credentials, source proof,
scanner path or URL. The component validates exact shapes and rejects extra keys.

## Effects, retry and retention

The server inspects one bounded transport descriptor, then positively commits a
`receiving` manifest reservation before preserving any original. Reservations
count toward the source limit. Original bytes live only in
`support-attachments/originals/{stableUUID}/original.bin`, private directories
0700 and immutable files 0400, separate from track `MediaAsset`. Unknown manifest
or file acknowledgements preserve a single identity and exact bytes; only an
explicit exact retry may finish it. Invalid input creates no original. Replaying
a retained/tombstoned request never recopies its bytes.

Scan claims use an exact attempt and hashed private lease token, at most three
attempts, 60-second lease and a 30-second whole-scanner budget. Work executes
outside database locks against one of three cross-process 5-MiB snapshot slots.
Crash residue fails closed. A clean accepted engine/digest, unchanged snapshot
and original, current scanner-policy commitment and current source/actor are
required before ready. Late workers, ambiguous handoffs, source changes,
signature/config changes and scanner failures cannot overwrite a later winner.
Synthetic `test-only` scans are accepted only by the testing fixture policy;
local rehearsal outside testing requires actual ClamAV. No ClamAV binary is
available here. Missing-real-scanner refusal is exercised; no successful real
scanner or production host fact is claimed.

Download prepares and unlinks a verified private descriptor outside transactions,
then re-proves source authority and exact retained manifest before returning it.
Three snapshot leases bound concurrent preparation. Changed bytes, hardlinks,
symlinks, public aliases and schema drift fail closed. Every response is private,
no-store/noindex/no-referrer/nosniff, with same-origin resource policy and sandbox
CSP. No capability or filename is persisted in browser storage.

Expiry cuts download/scan access at the immutable deadline. Explicit owner/operator
deletion positively commits a terminal tombstone first, then removes only the
exact original. Unknown commits never cause physical deletion. Failed cleanup
reports `pending`; explicit deletion retry can finish it. Encrypted original
manifest and source history remain. This is not automatic deletion after 24 hours
and is not a production-approved legal retention policy.

Migration249000 uses one atomic CREATE with inline uniques/checks, followed by
owned immutable-update/delete guards. Each valid empty DDL prefix can resume;
complete unlogged installations are proven before adoption. Types, nullability,
defaults, collations, indexes, checks, triggers and persistent table ownership
must match. Unknown drift or retained rows missing guards refuse before mutation.
Configured prefixes are validated and honored. Operational rollback always
refuses; source history and tombstones are not dropped. Runtime performs the same
read-only ownership proof, and never repairs missing guards.

## Evidence and remaining acceptance

Development receipts preserve the initial missing-test-key fixture errors,
MySQL CHECK-literal canonicalization mismatch, and GET/empty-JSON test harness
errors. The corrected source has fresh SQLite domain/schema/actual HTTP checks,
mounted component privacy/replay tests, and native schema/consumer/lock-wait
proof. Exact frozen source selections, counts and hashes are supplied in the
final receipt map; development results are not silently promoted to those heads.

The real native final-slot reservation proof observes
`performance_schema.data_lock_waits` between two independent PHP processes on
MySQL8.0.46 / REPEATABLE-READ. One succeeds, one receives409; ten manifests and
ten unchanged originals remain. SQLite does not stand in for this evidence.

`tests/browser/support-attachments.spec.ts` is explicitly selected with
`VASEY_BROWSER_SUPPORT_ATTACHMENTS=true` only after root has composed the mounts
and disposable policy. It uses real inquiry ownership/upload/replay/CSRF/private
headers and the intentionally unavailable real scanner, intercepting only lost
acknowledgement and final denied-read responses. It is defined but **not yet
executed in this isolated unregistered child**. A skipped definition is not a
passing browser acceptance result.

Open parent gates: independently reviewed final service/attachment composition,
actual mounted customer/operator/inquiry page checks, approved production
type/size/retention/terms, production identity provenance, transport/session/MFA
acceptance and host ClamAV/storage/worker evidence. No full hosted matrix, push,
merge, configured production write, network scanner or real customer file occurs
in this author lane.
