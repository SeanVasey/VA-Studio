# Current attestation consumption — October 12, 2026

This batch closes #95's deferred consumption-read finding and independent review L1/L2/L3. An existing
`ManageRightsScope::register()` caller inside an older outer REPEATABLE READ transaction could miss a consumption
committed on another connection, even though register itself locked the operator first. The consumption query
now reads the marker `FOR UPDATE`. The caller still locks the operator to serialize checking and inserting an
absent marker, and a refusal rolls its inner transaction back without committing or discarding the caller's outer
transaction. No table, migration, live account, key, payment or activation setting changes.

Both complete attestation suites are in the reviewed MySQL selection. The new native-only method is appended to
the SQLite skip census. The HMAC documentation now describes operation binding accurately; other audit records
retain an unkeyed reference hash, rights tables retain plain references, and key rotation invalidates an
attestation immediately, even within its 900-second lifetime.

## Source and environment

- Fixed application/tests: `abe2956bef770bf8bedbe02e0c49500e9b2065da`.
- Red application: unchanged from `cb69203e3c7947366ee8a75758cd2f0f927bcfed`; corrected test source
  `1c9b445243da2ae4fdb70d883e1bd67251d9341e`, identical to the fixed candidate's regression.
- PHP 8.4.26, PHPUnit 12.5.34. Private Oracle MySQL **8.4.11**, its own datadir, socket and loopback port 33396;
  synthetic disposable `vaseyaudio_mfa` schema. Server recipe is retained with the importer H1 evidence. No shared
  or production database is used. SQLite runs in a separate detached worktree of the same fixed source.
- Original `StaffWriteAuthority.php` SHA-256:
  `4910b74278cb232421f3982f8fcf9801d602c7b2338e42b2f4f28adc9979c36c`.
- Fixed class SHA-256: `cc51104c56eb77e30d95a0eb29eafe85190fcbea3e5b7eebbc7545c8343607e2`.
- Regression file SHA-256: `7739df0b3df14795e39d7b876c1c5a97b41ee6f18306f3b64694e2bc0a66155b`.

## Evidence

The exact commands and unsuccessful attempts are recorded in `commands.md`. Logs/JUnit are retained beside it;
paths and ANSI colour may be normalized when archived, with no test results removed. Application/test source
remains identical across subsequent merge-ledger, evidence and review-receipt commits.

The red run is **1 test / 8 assertions / 1 intended failure / zero errors or skips**, exit 1. It proves distinct
connections and an older snapshot, commits the real register writer and one marker on the second connection,
then demonstrates that the old source admits reuse rather than raising the required refusal. The initial
misnamed-connection setup attempt was stopped and is not part of the red evidence.

| Verification | Actual result |
| --- | --- |
| Private MySQL 8.4.11: new regression + complete staff-MFA and command-attestation files | **21 tests / 381 assertions**, zero failures, errors or skips; exit 0 |
| Focused SQLite: three MFA files, command, writer authority, staging admission, concurrency file | **83 cases / 918 assertions**, 72 passing cases and **11 declared native-only skips**, zero failures or errors; exit 0 |
| Receipt, sharder, focused-selection policy checks | **61**, **52**, **50** tests pass respectively; earlier filename error and census-order failure retained |
| Pint on changed PHP class and regression file | Passed, exit 0 |
| Native selection discovery | **1,846 cases in 166 files**, of 7,801 in 562 files; selection only, not execution |

The first setup attempt was stopped with exit 143, and its empty JUnit is intentionally preserved. The actual red
run used the corrected fixture and unchanged old application. The focused-selection failure was fixed by appending
the new census entry, preserving the existing first 150 entries and their guard; no check was weakened.

Independent source review: `../independent-review-abe2956b-DECISION.md` approves with completed-evidence/final-head
conditions; final confirmation is recorded separately. Green exact-head cheap preflight remains a merge gate.

## Limits and next work

This regression deliberately orders two real connections in one process. It proves current consumption
visibility through an older snapshot, not simultaneous worker lock-wait behavior. The existing same-operator
serialization remains a caller precondition. There is no unique marker key and this batch does not change the
public helper's API. A future caller must still lock the operator in its write transaction before admission.

The reviewed environment is a private local test instance. No real staging host, interactive console, browser
session, provider request, full native file, hosted Foundation matrix or GitLab pipeline is established here.
The documented console/host-access limitations remain. `PrepareExclusiveOffer` and panel-only writers remain
separate #92 work. The next scheduled batch is dependency triage, then C2's operative membership writer.
