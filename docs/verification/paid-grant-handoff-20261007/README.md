# Held paid252 checkpoint evidence

The owned runtime is frozen at `813a8f7` / `e8a2b93`; the portable fixture and
fresh-recovery test are `e27ca2dd22fad413cc3aff4e080bbe740fc181b3`. These are
preparation and development evidence. Paid252 has not received final independent
consumer/registration/fulfillment approval and is not merged or activated here.

`checks.json` records exact source/engine/counts for each phase. The source map
under `tests/Fixtures/paid-development/2ec1854-e8f7441` hashes 69 exact attributed
files. Canonical integrated Composer classes take priority. The original source
and the repaired witnesses have separate review status; none is inferred from
the consumer's successful focused checks.

| Selection | Recorded / passed / assertions | Result and practical limit |
| --- | --- | --- |
| e8 affected SQLite |52 /52 /786| Development runtime selection against provisional producer 2ec. |
| e8 original parent successor, SQLite |1 /1 /14| Refuses callback-capable parent before reading it. |
|813 parent/committing selection, SQLite |13 /13 /173| Current-owner capsule and parent admission. |
|e8 affected native8.0.46 |6 /4 /86| One HTTP409 failure and one pre-recovery authorization410 error remain. |
|e8 original parent successor, native8.0.46 |1 /1 /15| Meaningful original native refusal; does not close delivery. |
|af explicit development fixture smoke, SQLite |3 /3 /76| Attributed explicit fallback and runtime smoke. |
|af canonical Composer smoke, SQLite |3 /3 /61|69 exact canonical overlays, invalid fallback ENV; normal Composer wins. |
|e27 fresh recovery, SQLite |1 /1 /35| Actual loopback SMTP recovery, fresh authorization, unchanged old authorization and originals. |
|e27 fresh recovery, native8.0.46 |1 /1 /36| Same genuine recovery/byte preservation; fixed constructor traces only. HTTP failure is separate. |

Mounted frontend 9, TypeScript/build and scoped Pint pass on unchanged product UI
and runtime. Those checks do not replace the native delivery cases. The build's
ordinary chunk-size warning remains in its raw artifact. No hosted matrix or
payment/provider operation was launched.

## Original and staged native denials

`native-original-selected.{txt,xml}` retains the actual e8 six-case result:
four positives, HTTP native master redemption 409, and a 410 from `live()` when
recovery tried to reuse its old short-lived authorization. No original result
was overwritten. The original last-committing 403/one-durable-origin red and
configuration-parent 1 FAIL / 7 remain in their earlier append-only receipts.

Stage-one native diagnostic uses the same original HTTP assertion body with two
temporary instrumentation overrides. It records only fixed status/reason and
argument-free stack locations on consumer require or caught producer refusal.
It produced a 403 at redemption, 1 failure / 36 assertions /zero errors, 292.066
seconds, with no instrumented require/producer refusal emitted. That stage did
not establish the cause of the original 409 or close the failure. Its changed
instrumentation is archived separately from accepted runtime and successful
test results.

The final narrow HTTP constructor trace reproduced 409: 1 failure / 36 assertions,
288.893 seconds, with an actual authorization array and correctly shaped token.
Fixed producer reason `committed_read_frame` originates at
`OriginalCommitDispatcher.php:95`, called through `historicalReceipt` while minting
the original receipt in the second redeem `Commands.run` (`Downloads.php:132`).
That compound predicate checks phase/deadline/same dispatcher; the trace does not
identify a conjunct. Authorization-return to refusal was 40.251 seconds.
The fixed-reason trace and temporary instrumentation are separate from accepted
runtime. No further diagnosis loop was launched; this remains a held delivery
failure. The already-queued corrected recovery case completed and passed actual
native 1 / 36 in 290.131 seconds. Its only diagnostic exceptions were the expected
old-principal 403; the freshly authenticated same account requested a new
authorization and received the exact bytes, with all original rows/PDF retained.
The process completed and no further selection was launched. Original
authority budgets remain 60 seconds for delivery operations and 300 seconds for
whole-order document preparation. The test correction issues a new authorization
after actual mailbox recovery and fresh same-account authentication; it never
extends or rewrites the old one.

## Reproduction and exact engine

Use the standard dependency installation in the repository README. For the
isolated development branch only:

```sh
export VA_PAID_DEVELOPMENT_DEPENDENCY="$PWD/tests/Fixtures/paid-development/2ec1854-e8f7441"
php vendor/bin/phpunit -c phpunit.xml \
  --bootstrap tests/Support/paid-development-bootstrap.php \
  tests/Feature/PaidGrantDownloadJourneyTest.php \
  --filter test_actual_mailbox_recovery_keeps_original_paid_origin_and_pdf_and_allows_only_fresh_same_account_download
```

On the existing cloud workspace, source rootless activation and the privately
allocated paid-grant task env before the same command. Existing DB env values
override PHPUnit's nonforced SQLite defaults. Invoke the shell with tool network
additional permissions for loopback; never print/commit env credentials. Select
only the testing/disposable schema supplied by the operator. Rootless PHP is
8.4.26 and the native server is 8.0.46-0ubuntu0.24.04.4 Ubuntu. This proves no
MySQL 8.4 result or unobserved concurrency. Native full-matrix acceptance remains
deferred to the final integrated source under the repository CI cost policy.

`owned-runtime-source-manifest.json` hashes owned application paths at e27.
`artifact-manifest.json` hashes committed durable evidence only. Raw stdout/JUnit,
source/probe maps, known harness failures and actual product failures are kept
distinct. Temporary diagnostic PHP copies are inert archival `.php.txt` files,
not production runtime or automatic test registration.

## Native fixture cleanup

The one explicitly authorized membership foreign-schema probe used the existing
daemon and idle reviewer account, one users(id) fixture, and table-only
SELECT/REFERENCES. Original b635 admitted the foreign FK and created a missing
guard: actual native 1 FAIL / 6. Exact 16e successor on frozen reviewer d05 refused it
before writes: 1 / 7. Both lifecycle JSON files confirm schema/grants/cross-schema FK
absent and reviewer sessions 0. No author schema was reused or relabeled.

The first proposed persistent fixture/grant action was rejected by automatic
approval review; no mutation occurred. Its reason was a shared schema/permanent
cross-schema REFERENCES/SELECT setup without trusted membership-fixture authority
and the no-new-environment restriction. Root then selected a one-process
try/finally fixture on the existing daemon; the user explicitly approved any
membership test setup. That bounded lifecycle needed no new environment or
production configuration. The separate first rootless PHP boot attempt failed
because the wrapper omitted libargon2's activation path; the fixture was removed,
and the actual probe never ran. The corrected lifecycle retained PHP 8.4.26 and
both required library paths. These harness/approval events are not the genuine
original or successor product results.

Continue from the detailed checkpoint in
`docs/handoffs/paid252-native-ops-and-payment-readiness-20261007.md`. The queued
payment/reconciliation/refund/restore readiness batch was handed off without
starting downstream implementation or enabling an external instrument.
