# F9 paging composition on current main — source only

> Current result pointer (2026-11-03): the [final verification record](final-verification-20261103.md) records later exact-source tests, all four complete physical pools and the independent decision. This original source-preparation packet and its original UNRUN statements remain historical.

Base: `048244a6baf893a5d5f5581e466f353af24faaf7` (`origin/main`, verified locally).
Paging/probe source: `70765666858c8200ebf806c1e010112da0ee72c6`.
The initially supplied full precursor SHA used `f8200`; the local Git-resolved
object uses `c8200`. No fetch was required. No later history trial was carried.

## Bounded work contract

Owned paths are the customer index, membership controller and route, customer
index and native index tests, HTTP journey and mount tests, award race worker,
manual `MemberIndexLatencyProbeTest.php` and `HistoricalProofCounter.php`, and
this note. Only paging/support changes from the precursor are composed. HTTP
test trait discovery keeps main's `static::class` spelling. Accepted N7 limiter,
epoch/rate tests and worker, account/catalog code, shared identity/history
implementation, policy and mount configuration remain main's source.

The page displays ten periods and audits its sentinel plus an optional owned
anchor. The anchor is a retained period id, never caller-supplied ordering facts.
An unknown/foreign anchor refuses; the anchor gets the same complete history
closure as displayed periods and the sentinel. Continuations use the strict
`period_start DESC, id DESC` boundary, retain their cursor during pre-bytes,
and compare the complete audited scopes (including hidden anchor/sentinel).
The existing response allowlist is unchanged; the last displayed id supplies
`/production-memberships/periods/before/{period}` when `hasMore` is true.

Required acceptance: complete traversal without gaps/duplicates, timestamp ties,
newer insertions, older open reservations, tampered/changed anchor and sentinel,
real mixed recovered bindings, owner-lock and foreign-award ordering, native
100-versus-201 statement counts, READ ONLY temporary-table refusal, and exact
physical binding pools 1/2/10/12. These are requirements, not passing claims.
The original single 60-second read/pre-bytes deadline, `MAX_PERIODS=100`, all
current/committed/terminal proofs and proof density remain. Mounting stays
literal-false. This source introduces no activation or new business policy.

## Verification and dependencies

**UNRUN:** PHP, SQL, PHPUnit, lint, installs, generators, CI and physical probes.
ROOT owns independent ledger/native runtime work; this worktree ran no runtime
checks and did not inspect, poll or alter that runtime or its fixtures/drivers.
Static Git comparisons and `git diff --check` are the only verification here.
Earlier precursor results do not establish execution or acceptance on this main
composition. Independent sensitive-domain review of the exact new source and
fresh focused evidence are required before acceptance.

The manual physical probe is deliberately outside automatic test discovery.
Run only its named method, separately with `VA_MEMBER_INDEX_PHYSICAL_PROBE=1`
and `VA_MEMBER_INDEX_BINDINGS` equal to each of 1, 2, 10, 12. It advances fixture
time 3,601 seconds between real recovery requests without changing policy,
asserts unique observations and exact pool size, clears fixture clocks for
physical measurements, overlaps a real foreign award, records refusal and
sampled lock evidence, and traverses 100 periods with pre-bytes. Measurement
includes observer time. MAC instrumentation delegates the same primitive and
arguments and is active only in a separate unmeasured proof-count read.
This diagnostic harness and sampled wait observations prove no target-host SLA.

The prior requested twelve-binding attempt actually produced four bindings and
refused at 60.108562379 seconds; it is not a twelve-binding pass or headroom
acceptance. Shared identity/history access may still cause foreign writer waits
or strict refusal. If retained mixed bindings or physical pools fail on main's
accepted history/custody contract, report that dependency with exact evidence;
do not carry the unaccepted six-file history trial, relax guards, change indexes,
renew deadlines or reduce proof density to obtain a pass.

## Next focused selections (serialize native runtime)

- `MembershipCustomerIndexTest` (SQLite: complete paging/anchor/sentinel and mixed
  recovery regressions; SQLite-only resealing helpers prohibit selecting the
  entire file natively).
- `MembershipCustomerIndexAvailabilityTest`, `MembershipHttpTest`, and
  `MembershipHttpMountTest` (affected ownership/proof/privacy/mount checks).
- Native `MembershipNativeCustomerIndexTest`, one method/process, including
  `test_native_page_statement_count_stays_bounded_when_total_history_grows` and
  `test_native_committed_index_frame_refuses_temporary_table_creation_before_raw_proof`;
  preserve the existing owner-first, same-owner commit/release, foreign-award,
  fence/shared-fact and reverse-order checks.
- Manual probe `--filter test_physical_pages_with_real_recovered_bindings`
  in `docs/verification/member-mount-gates-20261101/MemberIndexLatencyProbeTest.php`,
  separately for the four exact pool sizes above.
- Main's accepted N7 selections remain required regression coverage when runtime
  is available; continuation calls use that same controller admission path.
- ROOT's account primary-key/progress selections remain independently owned and
  are not replaced by these paging tests.

## Exact metadata additions deferred to ROOT

No census, workflow, generated count, lockfile or shared log was edited.
Append these two native-only SQLite skip pairs when integrating metadata:

```
Tests\Feature\ProductionMembershipBilling\MembershipNativeCustomerIndexTest
  test_native_page_statement_count_stays_bounded_when_total_history_grows
Tests\Feature\ProductionMembershipBilling\MembershipNativeCustomerIndexTest
  test_native_committed_index_frame_refuses_temporary_table_creation_before_raw_proof
```

The native file is already represented by its existing skip entries/native
selection logic; verify exact selection after those append-only additions.
The manual probe has no automatic census entry. Any count regeneration remains
ROOT-owned and must follow actual integrated source, without an invented count.
No push, PR, merge, Foundation or live action is authorized by this note.
