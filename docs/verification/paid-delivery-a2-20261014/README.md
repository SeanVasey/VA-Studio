# A2 request-start budget and whole-order HTTP

Development batch on actual C4c merge `ae01c740`, carrying its receipt-only
`98bb9a91` checkpoint. Operative source is `bd5ec10a1ccbc55d3e65476232733867f96a8130`.
Separate non-author [final DECISION](independent-review-DECISION.md) approves with conditions at `f603f1a75bebc52fa04f25ed713d4f4f8ba89138`, with no open A2 P0/P1. Receipt-only carry, exact-head preflight and merge remain pending.
Paid operative/rehearsal flags and the new root mount switch ship false.

`PaidGrantRequestBudget` captures the monotonic start before identity proof and
deducts the validated server-start age once from the initial and overall bounds.
It owns its exact admitted instant; token validity is still judged at arrival.
The initial frame includes identity/locate. Snapshot uses the smaller captured
and current setting; commit and first byte retain the original overall ceiling.
Live min-only reductions reach final consumer admission and the first actual
chunk. Later chunks retain the separate transfer allowance. Existing observation
caps are unchanged. Refusals close the exact prepared descriptor and lease;
post-commit refusal truthfully retains its attempt.

The two-line HTTP journey uses an actual synthetic paid producer, real first
renderer child, a failure in the second renderer's process factory, and retry.
It retains the first original's row and PDF bytes, retries only failed work,
creates one fulfillment, and streams each line's master/PDF with four attempts.
This does not prove failure inside a running second child. The default synthetic
master fixtures share content; PDF/origin/authorization bindings remain line-specific.

Root bootstrap now supplies paid private intake and generic reporting/responses.
Only boolean `paid-grants.http_mounted=true` registers routes; shipped false.
The HTTP journey enables only this synthetic mount setting after configuration
loads, then uses real bootstrap rather than manually installing paid routes,
middleware or exception hooks. The guard is route-build/cache-time; changing
the mount configuration requires rebuilding cached routes. No host cache changed.

## Recorded checks

Selections overlap and were executed at named sources. Do not sum them into one
suite. Counts below are tests/assertions, with errors/skips zero in every listed
closed run; passing runs also have failures zero. Actual process/verifier exits
are recorded, including the original failures.

| Selection | Source | Counts | Process / verifier |
| --- | --- | --- | --- |
| Original whole request-instant file | `05f9d503` | 7 / 129 | 0 / 0 |
| Real 2 s identity-delay regression on old implementation | `910804af` | 1 / 17; 1 failure, expected 410 but got 200 | 1 / 1 |
| Whole request-instant file after first fix | `627743d0` | 12 / 222 | 0 / 0 |
| Final callback regression before reviewer repair | `8aaa6999` | 1 / 14; 1 failure | 1 / 1 |
| First-chunk regression before reviewer repair, separate invocation | `8aaa6999` | 1 / 14; 1 failure | 1 / 1 |
| Both repaired windows plus genuine three-chunk positive | `b682e121` | 3 / 56 | 0 / 0 |
| Disabled/strict root mount registration | `e2e75fde` | 5 / 47 | 0 / 0 |
| Initial new whole-order fixture | `64586317` | 1 / 13; 1 failure at intake 422 before preparation | 1 / 1 |
| Corrected whole-order HTTP fixture | `09890cdf` | 1 / 112 | 0 / 0 |
| Whole request-instant, transfer, HTTP and original root registration files | `09890cdf` | 45 / 1106 | 0 / 0 |
| Whole root registration plus genuine pre-paid-handler 500/503 cases | `3184877b` | 7 / 77 | 0 / 0 |
| Whole snapshot/commit/conjunct/refused-frame/download/preparation/completion files | `bd5ec10a` | 23 / 1720 | 0 / 0 |
| Private MySQL 8.4.11 elapsed identity, final callback rollback, whole-order HTTP | `bd5ec10a` | 3 / 155 | 0 / 0 |

The grouped old reviewer selection uses `--stop-on-defect`: only its first case
executed there. The first-chunk case has its own separate failure receipt; it is
not a skipped/executed case in that grouped invocation. The initial whole-order
fixture mistakenly sent JSON `[]` where the route requires `{}` and used the
wrong PDF hash-field name. The corrected fixture uses `{}` and `pdf_hash`; the
original failure is retained and proves no preparation/retry behavior.

Pint `--test` passed all 15 changed PHP files at `09890cdf` and `bd5ec10a`, actual
exit 0, with the selected inputs, head and status unchanged. No frontend source
changed; the cheap PR preflight supplies its required checks. No Foundation or
GitLab dispatch occurred.

## Commands and source binding

Each unmodified `command.json` in `recorded-evidence.tar.gz` contains the exact
PHP invocation, selected files/filter, public environment, source head, runner
hash, start time and timeout. Each `result.json`, JUnit, log and `process.exit`
contains the actual completion and reconciled case counts. Durable launch
records retain recorder PID/startticks/UID and launcher/runner hashes.

Local launch recipe:

```sh
source /workspace/.va-studio-toolchain/activate.sh
PYTHONDONTWRITEBYTECODE=1 python3 /workspace/runtime/paid-delivery-a2-evidence-20261014/launch-durable-v2.py --name UNIQUE_RUN_NAME --driver sqlite TEST_FILES
```

For the native selection, `--driver mysql` used the recorded two test files and
filter `test_elapsed_identity_work|test_a_final_committing_callback|test_two_line_http_order`.
The server is `va-studio-private-mysql-paid-a2-20261014`, its own datadir volume
`va-studio-private-mysql-paid-a2-data-20261014`, loopback port `33514`, socket
`/workspace/runtime/mysql-paid-a2-run/mysql.sock` and disposable schema
`vaseyaudio_paid_delivery_a2`. Runtime records PHP 8.4.26, PHPUnit 12.5.34 and
MySQL 8.4.11. Previous C4 server/schema/datadir were untouched.

The inherited v1 recorder captured app/bootstrap/config/database/contracts/scripts/
tests/locks, but omitted routes from its inventory. Its receipts remain unchanged
with that limit. V2 adds all 16 route files: 2,339 source inputs at current source,
committed/observed before and after. No v1 receipt is upgraded retroactively.
Actual source, head/status, runner and autoload hashes remained unchanged in each
closed invocation; failed test/verifier exits remain failures. No overlays used.

All operative PHP/bootstrap/routes/config/resources/database and the other three
primary test files are exact from `09890cdf` through `3184877b` to `bd5ec10a`.
Only root test additions and ops documentation changed. The root file's 7/77
receipt binds its exact current bytes; the other primary file receipts carry by
byte identity. Source manifests bind to reachable Git blobs, not only a claimed
SHA. Archive member digests and roundtrip verification are in the index.

The initial private-server helper ran its SQL, then exited 1 at a host socket
existence probe: Docker created the new socket parent mode 0700/UID 999. No initial
SQL stdout/exit receipt was saved. Only this fixture socket parent was changed to
0755; a fresh read-only probe observed the existing schema/version with exit 0.
The original error and correction are disclosed in `mysql-server-ready.json`.
A later narrow read showed active SQL/no pending metadata locks at that instant;
the separate CPU-child `/proc/.../children` diagnostic was unavailable, so no CPU
witness or platform-error cause is inferred.

## Independent review and remaining conditions

Separate non-author review approved design `05f9d503`; source `627743d0` received
REVISE for two P2 windows, repaired and approved at `b682e121`. Bounded root/whole-
order and required error-handler/source-carry notes approve `09890cdf` and
`bd5ec10a`. Original notes are copied unchanged. The final coherent-batch DECISION assesses the actual frozen candidate and complete native/portable receipts; unchanged independent verification is recorded in `independent-packet-verification.json`. Published-head preflight and receipt-only carry still precede merge.

Existing C2/C5-C8 and host portions of C11 remain. C11(k)'s source budget does
not interrupt SQL or prove proxy/FPM/queue limits, correct per-request SAPI/host
clock, slow-client behavior, worst-case 128-verification latency, route-cache
operation or representative throughput. Missing/implausible server start retains
stricter token admission fallback but cannot prove proxy elapsed time. A trusted
domain caller without the HTTP budget retains its existing entry-time allowance.

Runbook figures for document/other routes are post-identity application work
allowances; excluded identity/middleware/queue/response costs require measured
host margins. Only redeem's request budget includes its captured start/identity.
Numeric caps and timeout reference values are unchanged; no whole-request host
termination guarantee is inferred.

This is synthetic development evidence, not provider/payment/customer/host or
legal acceptance. No live credentials, payment, active customer import, deployment,
DNS or cutover. Frozen contract/profile members and the logo remain unchanged.
Tax255 V2 consumer wiring and the next dependency-ready plan item remain subsequent.

Portable archive verification completed: 13 actual PHPUnit invocations, 12 source bindings, 160 archived members; SHA-256 `6745b6426fd90de5763ead2e29af206f6a80d1a1c9b472d0137027dce0f2f896`. Two separate Pint receipts are included; they are not PHPUnit invocations. All archived member digests and reachable Git source bindings verified.

Actual PR #118 merge and final preflight/review inventory are recorded in [the merge receipt](merge-receipt.md).
