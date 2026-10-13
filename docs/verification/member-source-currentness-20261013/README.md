# C4a member source admission and catalog currentness

This bounded development batch starts from C3 PR #114's actual merge
`7b467c3824e2da81174a5445b6ee639cf0a59d93`. Application/test integration is
`1b88c0548f45a2e95ddc025bdc9c1adb7a9aaa7b`; selection and explanatory CI comments
follow at `0095e46aefa85463268027d89c829a4886537ad6`. Focused verification is
complete, including the integrated native reservation check at that source.
Independent final DECISION and exact-head Development preflight remain pending.

## Changes

Member 258 admission now captures its own configured connection and driver,
independent of 257's provenance. Verified provenance requires MySQL. The new
`currentFor(MembershipRows)` and `proveCurrent()` additionally bind the exact
policy issuer, Rows, native PDO, active transaction and original configuration.
A configuration array alone supplies no connection or write authority. The full
capability set is checked before resolution and after each callback; terminal
checks read captured raw maps without virtual container getters. Capability
aliases refuse. Default-off and staging restrictions remain in place.

Catalog snapshots now omit only MySQL's volatile table-option `AUTO_INCREMENT`
counter from the opened definition comparison. An unrelated automatically
allocated row can advance that counter without changing the selected graph.
The new parser preserves all other definition bytes, including quoted/comment
text and the column's allocation attribute; malformed or ambiguous structures
refuse. Selected rows, private bytes, real definition changes, native engine and
local temporary-shadow checks remain significant.

These are preparation/currentness changes. They add no renderer, original
readiness implementation, consumption writer, activation, route or runtime
capability registration. C4b supplies member originals/readiness; C4c couples
credit consumption to activation. Frozen contract resources are unchanged.

## Focused evidence

[Commands](commands.md), [source map](source-map.md), and
[coverage reconciliation](coverage-review.md) retain exact commands, sources,
errors, skips and scope. Independent component receipts are not one integrated
final-head suite.

| Check | Recorded source / binding | Result |
| --- | --- | --- |
| Original configured-driver regression | `0d2f06c7` plus unchanged external probe | 1 test / 10 assertions, one intended failure, no errors/skips |
| Same corrected driver probe | `a4e37cdc` | 1/10 passed; predates the raw-container correction |
| Whole member frame/preparation/environment/staging SQLite selection | `15a65e07` | 50/322: 49 passed, one declared native-only skip, zero errors/failures |
| Four focused native member frame cases | `15a65e07` | MySQL 8.4.11, 4/14, zero errors/failures/skips |
| Pure catalog definition corpus | Five captured source hashes match `a58d53ef` | 63/73 passed, no database bodies |
| Actual old-source catalog counter comparison | `0d2f06c7` Evidence class plus `982f4ea6` test | 1/12, one intended `changed_catalog_source` error, zero assertion failures/skips |
| Fixed native catalog cases and existing neighbors | `982f4ea6` | MySQL 8.4.11, 5/46, zero errors/failures/skips |
| Whole existing eligibility SQLite file | `982f4ea6` | 16/123, zero errors/failures/skips |
| Integrated real-source reserve/retry/award replay | `0095e46a` | MySQL 8.4.11, 1/21, zero errors/failures/skips |

The integrated run used its own synthetic schema `vaseyaudio_member_currentness_root`
on private MySQL 8.4.11, with verified loopback port 33413, socket and datadir.
Its 279.991-second wall duration includes setup/teardown. The component native
selections and this one-case integration run remain separate source-bound receipts.

The catalog comparison retains one real original snapshot and deadline through
its committed receipt window. A distinct native connection inserts and commits
an unrelated draft row; the raw counter changes from 2 to 3 while the selected
plan rows remain identical. This does not prove that the insert completes while
the original locking footprint is still held. The initial attempt timed out at
that setup boundary (1/4, MySQL 1205, exit 2) and remains a setup error, separate
from the corrected intended refusal.

Initial parser failures, formatting failures and a SQLite teardown error remain
visible. The teardown correction releases a fixture's retained Rows before
VACUUM; it changes no product behavior. A separate 2.931-second SQLite staging
case overlapped C3's final native invocation and is explicitly disclosed; it is
not an additional C4 final-selection pass.

The first local shard-policy check found the expanded configuration test file
unclassified. The corrected census passed all 154 policy self-tests at
`ca0fe30b`. Pint passed eight changed PHP files at `cc612650`, carried by unchanged
file hashes. Body-free discovery selected 2,103 MySQL cases in 202 files from
8,245 cases in 602 files, over 24 nonempty disjoint shards. Thirty-nine files use
fallback timing weights. Later workflow changes only update explanatory
census/timing comments; no Foundation or GitLab workflow was dispatched.

## Remaining boundaries

Only four of the twelve new member-frame methods ran on MySQL; the whole SQLite
file includes its one declared native-only skip. The five native catalog cases and whole SQLite eligibility file have separate
receipts. The integrated reservation canary passed at `0095e46a`, with all
1,275 captured source hashes matching Git and remaining unchanged on disk.
Parser engine cases are pure corpus checks, not a native engine-transition
claim. Native test durations include complete setup and teardown, not operation
latency or throughput. Synthetic configured provenance and capability doubles
are not real production facts or host approval.

Existing latency/contention, queue coalescing, unexpected-trigger inventory,
physical clock/storage and activation conditions remain open. Sean's previously
requested commercial, legal, account and host inputs remain unanswered. No
provider activity, real money, active customer entitlements, deployment or
cutover is authorized or claimed.
