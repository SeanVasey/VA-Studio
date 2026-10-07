# Private service scope journeys

An enrolled synthetic customer can submit a private service brief, inspect an
operator-authored quote, explicitly accept or decline that exact quote, and
follow milestone scope reviews and included revisions. The staff service-project
resource authors the quote and records milestone progress and cancellation
review. These are mounted buyer and operator workflows, advancing T28/WP-10.

The workflow is disabled by default. `VASEY_TEST_SERVICE_PROJECTS_ENABLED=true`
activates it only in `local` or `testing`, and requires existing synthetic customer
accounts. The buyer page is `/services/projects`; staff opens `/admin/service-projects`.
The default-off flag and production environment barrier apply to reads and writes.
Retained records are never deleted when access or preparation is withdrawn.

The operator first supplies a private service definition through the existing
Service drafts resource. The buyer chooses its current immutable revision and
answers its exact ordered questions, with a required project summary. Creating
another definition revision cannot alter a submitted brief's original snapshot.
A changed definition requires a fresh selection before a new brief can submit.

The quote requires supplied title, scope, three-letter uppercase currency,
integer total and deposit minor units, aggregate revision allowance, cancellation
text and ordered stable milestone identities/scopes. No amount, currency, policy,
revision allowance or commercial term is defaulted. Quoted amounts are synthetic
authored requests, not authoritative payment/tax totals or approved actual terms.
The deposit must be explicitly zero or within the authored total. A declined quote
can be followed by another retained quote; superseded quotes stay intact.

Acceptance binds the project's current quote UUID and canonical hash, freezes
that supplied scope and prepares its milestone states. It cannot create an order,
charge, collect a deposit, grant rights, authorize attachments or delivery, issue
an invoice, or mark the project paid/completed. Every customer/staff projection
continues to say `paymentState=not_collected` and `deliveryAuthorized=false`.

Staff begins a pending milestone and then records it ready for customer review.
The buyer supplies a reason to approve that milestone's scope review or request
an included revision. Revisions share the exact accepted quote's aggregate
allowance; exhaustion refuses another request. Approved scope reviews become
`scope_reviewed` after every milestone is approved. That status means scope
review only and supplies no paid completion or deliverable access. Milestone
scope review can occur independently for the quoted milestones; no unsupported
booking dates or sequential-payment policy is inferred.

Before acceptance, the buyer can withdraw a brief with a retained note. After
acceptance, the buyer requests cancellation review. Staff records cancellation
with an explicit note. These commands never infer a refund, alter existing rights
or apply unapproved operative cancellation policy.

## Authority, retries and history

Every command owns its transaction, fences the persisted customer user/account
or administrator/required MFA before the project, and takes a current project
lock. The browser cannot supply an owner key, account identity, audit actor or
role. Customer access/version/password withdrawal, current staff role/email/MFA,
configuration withdrawal and a stale journey version refuse the action. Terminal
proof uses direct primary PDO locking reads so a final Eloquent/QueryExecuted
callback cannot mutate authority before a stale model is returned.

A server actor, exact command body and request UUID bind each append. Repeating
that successful key/body returns the saved journey without another event; changing
the body/actor for an existing key conflicts. The UI retains the exact request/key
and entered text after an uncertain save and offers an exact retry. Stale operator
forms require reopening the current project rather than overwriting its journey.

Original briefs, complete selected service snapshots and every event/quote body
are encrypted and verified against canonical hashes. The full event chain is
verified against the sole transition table before projecting it. Audit events
retain only operation/version/evidence hashes and explicit actor attribution.
Raw SQL cannot update/delete retained parents/events or collide by INSERT IGNORE
or REPLACE, including SQLite with recursive delete triggers disabled. Keep the
application encryption key with private backups; encrypted bodies are hidden
from ordinary model serialization.

The index displays at most 25 newest owned project summaries and 50 available
private service definitions. A project displays its ten most recent authored
quotes and 100 most recent scope events; every original row is retained and the
projection includes total retained counts. The current/accepted quote is always
among these displayed quotes. A project admits at most 1,000 events; exceeding
that bound requires deliberate follow-on handling rather than dropping history.

JSON requests are limited to 64 KiB and depth 16. A bounded token scan rejects
repeated decoded keys at every object depth, including escaped aliases. Private
routes reject query/method/range/content-encoding ambiguity and cross-origin
requests, keep web sessions/CSRF, and return no-store/noindex/no-referrer responses.
GET intake probes at most one body byte. Unknown private descendants receive the
same privacy policy through the integrated global middleware.

## Schema and actual limits

Migration `2026_10_07_244000_service_projects` adds two InnoDB tables with immutable
SQL guards and refuses any existing, temporary, conflicting or interrupted schema
object before its first change. A failed native DDL install can leave owned empty
tables/guards: it is deliberately unlogged and refuses automatic adoption on
retry. Preserve those objects for inspection and an explicit reviewed recovery;
this migration does not silently remove or adopt partial installation state.
Actual rollback refuses and retains both application rows and migration ledger.
The focused tests execute these failure/rollback paths on actual engines.

There is no real customer/service source import, private file intake/scanning,
service payment/deposit collection, deliverable entitlement, operative booking,
invoice/tax calculation, notification delivery or production launch in this
increment. Historical service/customer obligations, approved terms, privacy
retention policy, attachment/payment retry proof and final browser/device/release
acceptance remain separate T28/WP-10 criteria. This document does not claim full
service replacement acceptance.

Focused evidence and exact commands are in
[the verification record](verification/service-project-journey-20261007/README.md).
