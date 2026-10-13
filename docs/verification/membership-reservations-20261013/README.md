# C2 membership reservation lifecycle — development in progress

This batch follows PR #112, merged at `bd230c52a605e05aa89158ab161309091afd17e2`.
It is not an activation, host, provider, commercial-policy or release claim.

The coherent change adds explicit v2 redemption terms, current catalog/license
proof, complete retained credit-ledger verification, owned reserve/retry,
honor-expiry release, and available-credit expiry. V1 policy bytes and the original
first-award record format remain retained; award replay audits later valid events.
Consume remains a later C4 operation coupled to actual original activation in the
same transaction. Runtime registration and routes remain absent.

The v2 documents explicitly name exact catalog revisions and license/asset/terms
hashes, credit costs, honor duration/cap, release destination and available-credit
expiry. Only authored synthetic fixtures currently supply those facts. Sean's
commercial and legal inputs remain outstanding; no defaults were inferred.

A request key is scoped to the original buyer origin and reservation purpose.
The selected paid period belongs in the authenticated intent. Thus the same key
cannot silently select another period. Retry preserves original retained IDs and
honor deadline; it returns current audited balances and never revives a terminal
reservation. Release is owner-triggered cleanup at/after retained honor expiry,
not voluntary cancellation. It returns credit to available before period expiry,
or expired at/after that boundary, exactly as explicitly authored by v2.

The complete audit checks seals, every retained column, payload/version/purpose,
original owner, policy/cost/selection intent, sequence/prior seals, balance
conservation, redemption joins, orphan rows and one terminal event per reserve.
New reserves preserve capacity under the 10,000-event bound for every live
reservation's later release and a finite period's available-credit expiry.

Owned entry consumes an exact one-use operation permit before source callbacks
or writes. A distinct internal mint permit cannot authorize additional writes.
Read-only reservation inspection uses current invoice/policy/catalog evidence and
never receives a mutation permit. All operations preserve normal Laravel callback
phases, one original admission budget and the committed read-only receipt frame.
A refused postcommit response does not imply rollback or authorize automatic retry.

## Verification status

Integration and native verification are pending. Component receipts are retained
under `/workspace/runtime/membership-reservation-evidence`,
`/workspace/runtime/membership-reservation-policy-evidence`, and
`/workspace/runtime/membership-ledger-evidence`; final source-bound evidence will
replace this in-progress section before review. Earlier setup/test failures remain
part of those receipts and are not passing checks.

The private local MySQL instance reports 8.4.11, uses its owned datadir/socket and
loopback port33413, and has separate disposable reservation and race schemas.
SQLite does not prove native races. No Foundation or GitLab workflow was dispatched.
