# Bounded current track discovery

This internal child supplies a current, bounded eligibility decision for later
track sitemap/index work. It adds no public endpoint, persisted full manifest,
index/robots edit, live sale, rights grant or source/legacy-URL acceptance.

`CurrentEligibleTrackSnapshot::capture` hydrates at most 49 published candidates
and checks at most 48. An entirely ineligible page still advances its encrypted,
epoch-bound keyset continuation. The projection contains only canonical relative
track paths. Internal encrypted evidence contains candidate IDs, paths, epoch,
configuration identity and an expiry; no private media is copied into it.
`currentPaths` validates and rechecks retained captures before consumption. Paths
returned by the value object describe the capture instant and are not permanently
current attestations. Consumers must use `currentPaths`; a future sitemap must
separately prove complete generation/index semantics.

The rule reuses `PublicationReadiness::blockers` exactly: every active offer must
retain intact published license/rights/media evidence. Only then does the rule
require at least one offer available through `SelectionInventory`. The only
shared API changes are trailing optional decision-time arguments in those two
classes; existing callers retain their normal default clock. PublicCatalog,
VerifiedLicense, pricing and publication writers are unchanged.

The additive singleton epoch is invalidated transactionally by INSERT, UPDATE and
DELETE guards on the full current transitive database graph: users, tracks,
rights declarations, offers/revisions, license templates/versions/review evidence,
media assets/processing runs/stems attestations, rights scopes/links, exclusive
activations/sales and inventory reservations/claims. Users are included as the
foreign-key parent whose deletion may affect retained attribution. Raw SQL and
ABA cannot evade the generation; failed parent writes roll back the epoch too.
The singleton permits a same-value update solely to acquire SQLite's database
writer fence; it permits no reset, identity edit or deletion. Counter exhaustion
refuses parent writes and capture rather than wrapping.

Capture refuses caller-owned transactions. Native MySQL sets READ COMMITTED for
the next owned transaction only, uses ordinary current graph reads, and takes the
epoch row fence last through its captured primary PDO. Existing actor/track/scope
writer ordering therefore remains intact. SQLite takes its database writer fence
before graph reads. Guard/schema/connection/depth ownership and epoch are proved
again after callbacks, encrypted evidence construction and graph reads. There is
no automatic hidden retry or public rebuild scan.

The epoch does not detect clock passage or independent file changes. Every
candidate's active-license boundaries and linked held-claim expiries bound the
decision window, as does a ten-second maximum lifetime. Consumption repeats the
current shared eligibility checks and rejects changed paths, epoch, configuration
or elapsed deadline. Recapture carries the authenticated original deadline
forward as a maximum, so consumption cannot renew a retained decision window. Private-media checks retain existing safe-path/ancestry/hash
verification and the non-sliding 60-second MediaIntegrity cache. This is not an
atomic filesystem freeze, a sandbox for arbitrary application lifecycle/crypto
callbacks, or stronger media durability than the existing public route.

Installation checks collisions and connection-local shadows before DDL, requires
native transactional dependency tables, and verifies exact owned definitions and
guards. Operational down always throws before database reads/DDL, preserving both
retained data/guards and Laravel migration bookkeeping. Real empty/populated
Artisan rollback and genuine subsequent forward migration are focused regressions.
The guarded epoch adds a serialization point to existing writer transactions;
focused native contention tests and independent review are required before merge.

T36/FP-014 remains open for full track sitemap consumption, retained-generation
completeness, source URL provenance/redirects, production content and final crawl.
Those acceptance facts do not block implementation of this internal prerequisite.
Focused local checks and independent sensitive review apply; hosted full matrices
remain reserved for exact final acceptance under the existing low-cost policy.
