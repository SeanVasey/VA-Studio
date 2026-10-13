# C2 prerequisite: immutable policy facts and billing interval ownership

This batch supplies immutable policy authoring and an actual retained-policy proof producer. It also addresses PR #109's deferred cross-ledger retrieval-endpoint finding [r4253341984](https://github.com/SeanVasey/VA-Studio/pull/109#discussion_r4253341984). It does not implement or enable the operative award/reserve/consume/release/expire writer. Commercial terms remain Sean's inputs; all exercised documents are explicitly synthetic fixtures.

## Policy preparation

The separate `authoring_enabled` switch defaults to false. Authoring requires a current verified staff member, the `administer-catalog` gate, enrolled MFA, and the caller's captured outer transaction. Staff and configuration are rechecked after callbacks. A strict versioned document supplies allowance, price/currency, original terms, expiry and late-payment semantics. Unknown or unsupported fields are refused; there are no store defaults. Identical documents return the existing immutable version without replacing its original author.

The retained record includes encrypted original terms and authoring evidence. Its purpose-bound HMAC covers every retained column using the application key; previous application keys can verify historical records. This is internal record integrity, not external provider evidence. The authoring result is provisional until the caller commits and proves its own commit boundary. No route, panel action or committed-success API is installed.

`SealedMembershipPolicyFacts` reads and verifies the actual retained record, requires the configured approved hash and provenance, and binds each proof to its issuing authority, exact `MembershipRows`/PDO, seal, configuration and original deadline. Revalidation cannot renew that deadline. Unsupported lifecycle/license hashes remain null, and the binding declares only the `paid_period_award` scope. The existing complete operative capability gate remains intact; this producer is not registered as an activation shortcut.

## Retrieval endpoints and upgrade

New guards on observations and diagnostic attempts refuse every start/end endpoint combination across the two ledgers. On MySQL, both paths lock their shared position rows in ascending order and use a current locking read of the opposite ledger. The trigger does not issue a locking read against its own subject table, which MySQL rejects with error 1442. Existing same-ledger guards remain unchanged; this batch does not close the separate carried CP-2 finding.

Migration `2026_10_13_261000_membership_billing_interval_ownership.php` recognizes the exact previous six-table graph and adds the two guards without rewriting retained rows. It inspects the full graph, serializes the retained-data check and DDL with billing-table write locks on MySQL (an immediate transaction on SQLite), and refuses historical endpoint collisions or foreign definitions. An interruption after either guard is recoverable; runtime admission requires both. Parent and namespace checks surround the native locked section; the locked inspection reads only tables admitted by that explicit lock set. Rollback refuses to discard evidence.

This upgrade boundary is the complete six-table graph from PR #109. Earlier stopped installations must first complete that predecessor; no arbitrary older installation interoperability is claimed. Inspect and resolve retained conflicts through a reviewed recovery process before retrying; do not delete or reseal evidence to force migration success.

## Verification boundary

Exact source identities, commands, counts, failures and skips will be recorded alongside the completed test receipts before review and merge. A source review is in progress; no approval or merge is represented by this working packet.

Native work uses a new private Oracle MySQL 8.4.11 instance: container `va-studio-private-mysql-policy-20261013`, datadir `/workspace/runtime/mysql-policy-data`, loopback port 33413 and socket `/workspace/runtime/mysql-policy-run/mysql.sock`. Policy and interval selections use separate disposable schemas. The predecessor container was absent in this execution environment; it was not silently reused. This is local synthetic evidence, never host or provider verification.

No real Stripe call, payment, customer import, mounted authoring workflow, browser journey, full Foundation matrix or GitLab dispatch is included. Carried CP-2/CP-3/P1B-1, R-4/R-5/A1-5, L2-1 and other activation conditions remain open. The next C2 batch needs transaction-bound paid-invoice facts with the provider's actual payment timestamp, an owned committed operation, semantic invoice idempotence and canonical credit-ledger writes. Award/last-credit native races and C4's shared activation/consumption transaction remain subsequent work.
