# Private service and merchandise draft authoring

An authorized operator can now create and revise private service definitions and
merchandise variant manifests, review the exact contents before saving, and read
their retained history. Each family has its own concrete format, model and
tables. Authored declarations record supplied private text; they do not approve
a commercial policy. Explicitly unresolved declarations retain what is needed.

This increment advances T28/WP-10 and T29/WP-10. Their public product, purchase
and fulfillment criteria remain open.

## Supplied content

| Family | Retained private contents | Bounds |
| --- | --- | --- |
| Service | Title, description, ordered brief questions and explicit scope, deposit, revision and cancellation declarations | At most 20 distinct questions |
| Merchandise | Title, description, ordered variants and explicit source, shipping and returns declarations | 1–50 variants with distinct stable lowercase identities; each variant retains label, size, color, optional private source reference and availability declaration |

A declaration is exactly `status`, `text` and `reason`: `authored` requires
supplied text and a null reason; `unresolved` requires a reason and null text.
An unknown status, extra domain field, implicit approval, numeric price or stock
field is refused. Optional descriptive size/color fields may be empty; an absent
source reference is explicitly null. No operational availability is inferred
from authored prose. Ordered lists remain in the entered order.

The concrete formats are `service-private-draft-v1` and
`merch-private-draft-v1`, with the existing canonical JSON version. Every body
fits within 64 KiB. A draft retains at most 100 versions; reaching that bound
requires a deliberate follow-on migration rather than discarding old evidence.
Version bodies are encrypted, hidden from ordinary model serialization and
verified against canonical content hashes. The parent title, revision number,
timestamps and creator remain ordinary operator metadata. Retain the original
application key alongside backups of the rows and audit evidence.

## Review and save contract

`ServiceDrafts` and `MerchDrafts` implement these bounded APIs through the shared
`ReviewedPrivateDrafts` authoring helper:

```php
review(?PrivateDraft $draft, array $authored, User $actor, ?string $openedStateHash = null): array
applyReviewed(array $review, User $actor): PrivateDraft
snapshot(int $id, User $actor): array
```

Each operation owns its transaction and refuses an inherited transaction. It
locks the persisted actor before the concrete parent, versions and audit rows.
Fresh administrator status, verified email and required application MFA are
checked. The review captures the actor, family, parent identity, revision,
complete retained state, exact before/after bodies and a random nonce. An
application-key HMAC binds that capture. Editing after review, authority
withdrawal, changing the family or capture, and returning to identical content
through a later revision all require a fresh review.

An unchanged reviewed body writes no version or audit. Replaying the same
successful initial creation capture returns its original single-version draft;
replaying it after any later revision is refused. A changed save appends one
encrypted version, advances the parent and writes one minimized hash-only audit
atomically. After all callbacks, direct primary-PDO current locking reads prove
the actor, complete parent, all prior raw version/audit bytes, new body and new
audit identity. No Eloquent or `QueryExecuted` observer runs after this final
proof. Semantic history reads use current locks even if an earlier callback
established an old repeatable-read snapshot.

Both additive migrations explicitly select InnoDB. SQL guards retain parent
identity and version evidence, refuse deletion, and refuse existing primary or
unique-key collisions in `BEFORE INSERT`. This prevents SQLite replacement even
when recursive delete triggers are disabled. Existing, partially installed,
temporary or conflicting schema objects are retained for inspection; they are
not adopted or removed. Supported prefixes are handled as physical names while
system catalog reads remain unprefixed. The migration `down()` methods retain
all authored evidence deliberately.

## Operator journey

The actual Filament resources provide plain fields and repeaters, an escaped
before/after comparison, an explicit confirmation and read-only history. A
review binds the action tuple and opened record state; the locked capture is
consumed before action resolution or validation. Real add, delete and reorder
actions preserve the opened editor fence. Arbitrary action changes, direct
confirmation RPC, altered comparison contents or added submission arguments
cannot manufacture a save.

Stale and uncertain results keep a copyable description of the entered contents
and require closing and reopening to inspect the saved state. The uncertainty
case is exercised with a real committed save followed by a lost response; a
second confirmation cannot replay effects. Exhausted confirmations force a
complete modal render so the visible save button is disabled while the readonly
copy field remains enabled and bound to the retained entered text. Rendered browser/device journeys
remain for final acceptance; the focused UI evidence exercises actual Filament
mount, form, compare and save requests.

## Focused evidence

The baseline affected SQLite selection passed all 86 cases with 393 assertions and no
skips after scoped Pint. The baseline affected native selection passed all 96 cases with
974 assertions and no skips on genuine MySQL 8.4.11, with normal durability in
a fresh disposable database. Its independent-process concurrency cases observe
exact `PRIMARY` record waits with requester, blocker, database, table and
identity, rather than substituting sleeps or SQLite behavior.

Independent review then found that a form-only render left the prior enabled
footer visible after a real committed save with an uncertain response. Both
actual rendered-footer failures were reproduced and retained. The narrow modal
render correction passed all 14 affected UI cases with 244 assertions on each
of SQLite and genuine MySQL, without skips. The tests check an enabled button
before saving, a disabled button after uncertainty or staleness, an enabled
readonly field with the actual wire-model binding, and refusal of a repeated
confirmation. The domain, schema and native concurrency source are unchanged
by that correction; its actual focused results are bound separately from the
baseline execution.

```sh
php vendor/bin/phpunit tests/Unit/PrivateProductDraftManifestTest.php \
  tests/Feature/PrivateProductDraftTest.php \
  tests/Feature/PrivateProductDraftAuthoringActionTest.php \
  tests/Feature/PrivateProductDraftSchemaTest.php

# In the supported disposable MySQL environment, add only this affected class:
php vendor/bin/phpunit tests/Feature/PrivateProductDraftConcurrencyTest.php
```

The ten native cases cover competing distinct-actor edits, duplicate initial
creation, role and required-MFA withdrawal, and an after-fence snapshot despite
an earlier callback creating an old MVCC snapshot. SQLite exclusions identify
those exact four methods and ten expanded cases; exclusions are not concurrency
proof. Unit/domain/UI/schema cases additionally cover encryption, strict shapes,
no-op/ABA/replay, changed captures, audit failure or rewrite, callback authority
withdrawal, SQL replacement/deletion, retained rollback, physical prefixes and
temporary schema collisions. Failed preparation runs remain in the external
source-bound receipt; final passing results do not erase them.

No workflow was dispatched for this increment. AGENTS.md's focused-development
policy applies, and complete verification remains reserved for the final exact
reviewed integrated candidate.

## Dependency-ready follow-ons

Service purchase needs reviewed immutable quote/assent identities, explicit
currency and deposit amounts, and separately approved milestones, revision
allowance and cancellation rules. A brief submission must capture the retained
service version, customer ownership and ordered answers; quarantine/scanning
must precede attachment access. Booking, payments and each milestone transition
need idempotent provenance and cancellation/refund consequences. Existing
contact/inquiry messages alone do not satisfy this workflow.

Merchandise purchase needs a published immutable product/variant snapshot,
explicit prices and stock or approved supplier availability, shipping-address
handling, tax and delivery pricing. Paid-order fulfillment needs an idempotent
fulfillment identity, approved manual or provider dispatch, tracking and an
explicit returns/refund lifecycle. The descriptive source references and
availability prose in this increment cannot authorize that dispatch.

Those downstream fixtures can be prepared against these retained concrete
identities, with unresolved owner/provider/legal choices represented explicitly.
Account/server configuration, real supplier or service obligations, provider
interoperability and final acceptance remain separate from completed private
authoring. No existing catalog, customer, order, delivery, notification or
payment writer was changed by this increment.
