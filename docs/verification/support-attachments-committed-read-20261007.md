# Attachment committed-read and migration closure — October 7, 2026

Frozen composed runtime: `a8586b20bfc478ebabe26aadc573870cd17edd7a`, tree
`e13377f132db3d3221eb37532ac623f41621561c`. This child repairs the three
actual source failures retained by root at registration source
`781a3b4397784ed2e2fc86447cb91fec82af84e3` / evidence-only base
`93e7d51a5fa2ea49eece072d6444ff5da35b2fe9`. Independent review of this
successor is required. Whole attachment HTTP acceptance remains open: the
separate actual final-commit session-owner canary still returns 200 instead
of 403 and belongs to the root registration/controller owner.

## Selective source and ownership

- Owned opaque interfaces: `13bb0bbb883d31e6138f843611117c9db132693d` adds
  `AttachmentCommittedReadAuthority::committedReadReceipt(proof, rows)` and
  `AttachmentCommittedReadReceipt::proveClosed(): void`. No public DTO contains
  the receipt or write authority.
- Service author children `598d10678627fd000be9a883d968f92ce4766d8d` and
  `8444f15ea3f5f3c74994fef48705b2a193de7cba` are selectively composed as
  `49866317cc876eb38e1d6e5f1181bea57ab44f9d` and
  `d81fbb4018d3b4e3ecfb8da6ec125b5344182b41`. Four service source/test paths
  are byte-exact against final author source 8444. The service author's separate
  metadata child is `e0d5cb1b6297924efdc3449baabfe28c55df8767`; its author checks
  are separate evidence, not this child's independent consumer checks.
- Owned consumer/inquiry/schema and dedicated tests:
  `a8586b20bfc478ebabe26aadc573870cd17edd7a` changes eight paths.
  No root registration, request identity, controller, route, provider, configuration,
  customer/service page, middleware, CI, lockfile or original registered test is
  edited by this child. The source map proves 58 carried registration paths and
  byte-exact original `intake`, `process` and `delete` method spans.

[`receipt-map.json`](support-attachments-committed-read-20261007/receipt-map.json)
binds all 14 changed source/test paths, the unchanged registration dependencies,
method spans, service source, and preserved raw receipts. This is a narrow
successor to the held registration, not a whole historical branch merge.
Earlier 1914 projection and cleanup failures, exact legacy source and bootstrap,
corrected dd18/c7 consumer results and native cleanup/race evidence remain in the
base's `support-attachments-20261007/terminal-projection/` artifacts and original
[`support-attachments-20261007.md`](support-attachments-20261007.md). They are
historical source-bound receipts; this successor's changed reads use the fresh
tests below. The unchanged durable deletion and cleanup policy is verified both
by the method-span hash and current ordinary/native consumer cases.

## Committed private reads

List builds its plain DTO in the original transaction, captures an opaque source
receipt and exact range/rows/policy/deadline, performs the existing final source
proof, allows Laravel to commit and dispatch its real events, then proves the
committed read before returning the DTO. Download closes both original read
transactions: first before snapshot I/O and second after the prepared immutable
anonymous snapshot. The result contract remains `stream: AttachmentSnapshot`
and `file: {name, mime, sizeBytes, sha256}`. A refused second closure closes the
prepared snapshot. No receipt permits another mutation or renews an expired
transaction token.

The source receipt is bound to the original primary Connection/PDO, permanent
schema, prefix, actor and raw source graph. It requires the original positive
outer commit and an idle primary; rollback cannot be adopted by an unrelated
later commit. Actual service Gate/MFA/provider getters run before the terminal
raw authority/source/config check. Inquiry staff panel getters likewise run
before its final raw user/inquiry check. No new transaction or fresh principal
is minted after commit. The service credential-key digest also closes the
author's confirmed after-commit `app.key` rotation failure.

The consumer then checks the exact registered concrete policy, full owned
attachment schema, retained row bytes and bounded source range, filesystem
configuration and original expiry, without another provider/decrypt/storage
callback. The expiry decision time is captured before DTO decryption and receipt
callbacks, so a callback crossing the deadline cannot convert it to an unlimited
deadline. The dedicated test advances the clock during the second authentic
scan-evidence decryption, before receipt construction.

Deletion's committed immutable tombstone and positive-commit original cleanup
remain byte-exact. A later actor credential withdrawal does not undo a deletion
already authorized and durably committed. Laravel commit events and bookkeeping
are preserved throughout; there is no global commit-event bypass.

## Strict 249 migration closure

The schema now inspects every relevant namespace object instead of accepting the
first dictionary row. A reserved guard name cannot hide a foreign table behind
an owned trigger; case variants and temporary shadows also refuse. SQLite
dictionary inspection uses permanent `main` and includes all objects. Native
dictionary selection compares schema identity with binary equality, verifies
exact names and protects both table and trigger namespaces.

Only the contiguous owned creation sequence (atomic complete table, then update
guard, then delete guard) can resume when empty. A missing earlier guard with a
retained later guard is not a resumable prefix. Missing guards around retained
rows refuse. Every successful DDL path finally re-proves the complete graph with
DDL disabled, including the path after the last guard. Rollback remains a refusal
before mutation; retained originals, evidence and tombstones are preserved.

## Focused verification

Use the existing rootless toolchain via
`source /workspace/.va-studio-toolchain/activate.sh`. Frozen SQLite command:

```sh
vendor/bin/phpunit tests/Feature/SupportAttachmentRegistrationTest.php \
  tests/Feature/SupportAttachmentSchemaTest.php \
  tests/Feature/SupportAttachmentsTest.php \
  tests/Feature/ServiceSupportAttachmentsTest.php \
  tests/Feature/ServiceProjectAttachmentAuthorityTest.php \
  tests/Feature/ServiceProjectCommittedReadTest.php \
  tests/Feature/SupportAttachmentCommittedClosureTest.php \
  tests/Feature/SupportAttachmentSchemaClosureTest.php \
  --log-junit /tmp/support-read-frozen-sqlite.xml
```

Result: 91 defined, **90 executed / 673 assertions**, zero failures/errors;
one explicitly named native case-variant skip. This includes the unchanged
original root three canaries, existing ordinary consumer paths, the service
receipt selection and owned final-commit/expiry/direct-PDO/schema tests.
The independent eight new consumer canaries also pass **8 / 29**. Frozen source
Pint and whitespace checks pass.

Frozen native selection: **16 / 89**, then only its four omitted original service
consumer cases **4 / 21**; all have zero failures/errors/skips. These are 20 unique
cases / 110 assertions. Together the two disjoint selections freshly execute all
eight original service consumer cases / 56 assertions, including scanner-time
account withdrawal, storage-time credential withdrawal, final staff callback
policy withdrawal and changed-graph replay/current-version behavior. The first
selection also covers the unchanged original root commit/hole failures, owned
source/row/filesystem/expiry/list/rollback/direct-PDO closures and native reserved
dictionary/case variants. Exact argument arrays and raw JUnit/text receipts are
bound in `receipt-map.json`.

Native execution uses the existing Oracle MySQL 8.0.46 daemon on loopback and the
new dedicated `vaseyaudio_support_closure` synthetic schema/account. Its private
environment is sourced locally without printing or committing credentials; exec
grants loopback network access. No root/service/attachment-author schema is reset
by this lane. `ATTACHMENT_NATIVE_ISOLATED=1` explicitly permits the test-only fresh
migration setup on this dedicated database. This is affected consumer/native
schema coverage, not a hosted matrix or new production binding.

Original red evidence remains durable under
`cloud-support-registration-20261007`: the real final download commit callback
withdraws fixture policy (one failure / six assertions), and the two actual
schema admission failures total two failures / five assertions, all with zero
errors. Frozen root tests are unchanged in the green successor selection.

All intermediate receipts are preserved. The first registration run began before
service receipt composition and its project-list failure does not establish a
final-source defect; the corrected composed four-file selection passes 49 / 500.
One initial owned inquiry probe attempted a version-only update that the existing
immutability trigger correctly rejects; the retained error was corrected to an
actual `InquiryAdministration` archival transition. The resulting real nested
commit count is three, and the ready original and exact manifest remain retained.
Autoload/Pint setup and empty interrupted output are diagnostics, not acceptance.
The first broader command also named a nonexistent test file and executed no
tests; its empty JUnit and diagnostic are retained. Corrected and final selections
use the actual `ServiceSupportAttachmentsTest.php` definition.

## Open root HTTP boundary and remaining gates

`SupportClosureSessionBoundaryTest.php.txt` and its original raw red receipts are
preserved in this directory. Against the actual root registered routes/provider,
the final download's second `TransactionCommitted` callback changes the real
server session `_inquiry_owner` marker from one fixture secret to another. The
captured AttachmentActor remains the prior server owner. No database row, policy,
fake model, DTO or forged context is changed. Actual HTTP returns **200**, expected
**403**: one genuine failure / six assertions / zero errors on this frozen runtime.
The unchanged probe was re-run after commit a858 was frozen; its second genuine
red is `support-read-frozen-session-boundary-red.{txt,xml}`, in addition to the
original pre-freeze receipt. Its exact source bytes are preserved and hashed.

Root owns the cached request/session actor comparison after domain source receipt
callbacks and before JSON or stream release. List/download return seams above
are unchanged. A final refusal must explicitly close a prepared snapshot. A pure
comparison of the already captured request/session/auth context is sufficient;
calling a fresh owner or resolver after the domain's terminal proof would add
callbacks and renew authority. This child does not change root shared source.
The new owned-domain greens must not be reported as resolving that HTTP blocker.

Independent review, the actual composed root successor and unchanged session
canary, affected registration checks, and the remaining approved production
attachment type/retention/size policy, verified production source adapters, real
host scanner/transport/storage and deployment acceptance remain gates. Technical
synthetic fixture limits and default-off execution do not approve production
policy. Historical inquiry provenance is preserved without automatic relabeling.
No production or configured external writes, external files/PII, hosted CI,
push/merge, credentials or environment/dependency changes occurred in this child.
