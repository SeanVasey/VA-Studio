# Explicit free grant origins

This preparation implements an explicitly authored free license definition, a
separate staff review, current buyer review and affirmative assent, an immutable
free grant origin, its first private PDF, and exact owner-authorized file delivery.
It uses migration `2026_10_07_245000_free_grant_origins`. It creates no paid order,
payment, marketing assent, or historical `license_grants` record.

The executable author candidate is recorded in the
[source-bound receipt](verification/free-grant-origins-20261007/README.md).
Independent review and the final application's registration proof remain
separate prerequisites. This child does not certify a production launch or close
all WP-08, T18, T23, T24, or T25 requirements.

## Admission and identity

Both `VASEY_TEST_FREE_GRANTS_ENABLED` and
`VASEY_OPERATIVE_FREE_GRANTS_ENABLED` default to false. The current author and
review commands mint only `test_only: true` definitions in `local` or `testing`.
Their buyer binding explicitly says `synthetic-local-account` and
`legal_identity_verified: false`. Enabling the test flag in production cannot
admit this provenance.

The operative policy has an explicit `FreeGrantIdentity` adapter seam and an
`approved-free-terms-v1` definition prerequisite. The current source contains no
operative definition writer, production customer adapter, approved commercial
terms, or production storage activation. T23's independently reviewed original
session/verification contract and actual approved free terms must precede those
successors. A historical buyer record alone is not current feature authority.

`FreeGrantIdentity::lock` captures current authority; `proveCurrent` completes
callback-capable checks; `provePrimary` must make the final qualified, callback-free
comparison on the captured PDO. The test adapter refuses forged principals,
cross-account actors, staff acting as buyers, unverified accounts, role/access
changes, and stale credential/session stamps. Framework and policy resolution
finish before the terminal raw actor/account/source fence. A permanent withdrawal
cannot be hidden by a same-name temporary `users` table.

## Authoring and assent

The Filament Free Definitions resource exposes real author, review, open, and
close commands. Each author supplies the exact license version, track, rights
scope, original explicit terms/reference, total-origin capacity, committed download
attempt cap, and token lifetime. There are no default prices or invented terms.
The reviewer is a different verified administrator, outside the original license
contributors, and records three explicit confirmations and a reference.

The source is the reviewed published non-exclusive license/template, a verified
rights scope and clearance, and exact processed master WAV/download MP3 asset
revisions. Paths stay private. New admission checks current publication,
availability, blocking controls, and inventory claims. Original retained readers
verify the original scope, clearance, license, media graph and bytes rather than
substituting the current title or later revision.

The customer page deliberately fetches private data. It presents the exact free
definition and license, sends the entered name for server review, then requires a
separate affirmative checkbox. Acceptance binds the complete displayed definition,
review/version, full terms, caps, declared name and explicit free purpose. It
freezes assent and scope, with no paid status. Changed/stale payloads conflict;
an exact authenticated request retry can recover its original result after
availability closes. One buyer gets one origin per definition, and the locked
scope/definition serialize the explicit total-origin cap.

## Immutable evidence and retry

Eight additive owned tables retain definitions, reviews, availability events,
origins, bounded document work, first originals, authorizations and committed
download attempts. Encrypted payloads have canonical hashes. SQL update/delete
and birth guards protect originals against ordinary SQL, `IGNORE` and `REPLACE`.
Only the document-work technical claim can transition under its bounded state
machine; it cannot replace a completed original. Application replay does not
rewrite original terms, assent, owner binding or PDF manifests.

Migration admission verifies dependency key compatibility, object namespace,
column/index/foreign-key/guard definitions, shadows and external dependencies
before writes. An exact empty contiguous prefix from this migration can resume
by appending only missing steps. Native `CREATE`, foreign-key and index statements
are separate commit boundaries. Fully formed unlogged installation can acquire
only its missing migration ledger entry while preserving retained rows. Drift,
foreign objects, or rows in incomplete tables refuse before retry writes.
`down()` refuses and preserves historical tables, evidence and the ledger.

The first original uses a separate pinned `free-v1` purpose/profile and full
original input. It preserves the retained PR24 font/package safeguards while
running its own offline child, cleared environment, bounded input/output/time,
fixed shared extensions and private filesystem boundary. It passes no database,
provider or production credentials to that child. Physical checks, rendering and
exclusive private file storage happen outside database transactions; publication
rechecks the exact winning claim and all original authority afterward.

A live claim stays closed for its 300-second lease. The current token-free status
read reports whether its exact deadline permits retry, up to five claims. The
browser requires that fresh status before retrying a claimed preparation. A
completed PDF that loses its bytes cannot be recreated or overwritten; restore
the exact original bytes to its retained hash/path.

## Owner delivery and privacy

Authorization accepts only a current owner, origin hash, fixed asset role, exact
request UUID and ephemeral nonce. A short-lived token is bound to the original
PDF or exact purchased asset and stored only as a hash. Its browser state is
ephemeral. Current ownership is checked before and after exact private descriptor
preparation, and the attempt is committed once under the authored cap. The held
descriptor prevents a later path substitution. Refusal closes it. Native attachment
POST includes the token and CSRF field in a strict bounded form, with no token
in a URL, object path, browser storage or history.

`GET /free-grants/origins/{origin}/downloads` is a deliberate owner read. It returns
the aggregate committed attempt count and latest 20 authorization statuses
(`unused`, `expired`, `attempted`) with fixed roles/timestamps, plus preparation
retry admission. It returns no token, nonce, path, encrypted body or actor secret.
Database status does not assert current physical file health or successful receipt
by the browser.

Private requests reject duplicate JSON/form keys, escaped aliases, oversized
bodies, unexpected fields, GET bodies, query parameters, ranges, foreign origins
and method overrides. Generic errors and unexpected controller failures retain
private headers; the reporter logs only a fixed message and exception class.
The mounted page clears all entered name/review/selection/origin/status/token and
pending native form state on authority denial, departure or unmount. A late result
cannot initiate an attachment transfer.

## Shared registration contract

The parent composition owns these shared changes:

- Include `routes/free-grants.php` inside the existing web routes.
- Prepend `FreeGrantPrivacy` before framework parsing/CSRF exceptions and apply
  its generic `error`/`protect` response to all matching private path failures,
  including malformed and unknown paths. Call its sanitized `report` for
  unexpected matching errors rather than the generic private-body reporter.
- Add the conditional customer navigation link using `FreeGrantPolicy::enabled`
  and exclude `/free-grants` from robots. Preserve all existing private modules.
- Allow the existing Filament discovery to find `FreeDefinitionResource`.

Route names are `free-grants.page`, `.index`, `.review`, `.accept`, `.show`,
`.downloads`, `.document`, `.authorize`, and `.redeem`. Intake attributes are
`_free_grant_checked` and `_free_grant_body`. The isolated author HTTP tests install
the route file, middleware and equivalent generic exception privacy explicitly;
they do not prove the final parent's real registration.

## Remaining decisions

The separate operative identity/approved-terms adapter, production storage and
backup/restore proof, production scope accounting before exclusive activation,
retention policy and complete integrated customer journey remain dependencies.
Paid and membership-credit grant purposes require separate origins and consumer
contracts. This free source cannot certify payment or relabel old records.
