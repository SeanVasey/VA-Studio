# Paid origin preparation

This is a default-off, additive T24/T25 preparation in the isolated paid-grant
branch. It has actual customer library, preparation and native POST download code,
but its final producer committed-read receipt dependency is unfinished. It is not
production activation, dependency approval or completed commerce.

## Retained originals

A freshly authenticated original-session buyer can select an existing retained
paid order UUID. The server privately locates its original buyer, verifies current
ownership and the original historical identity prefix, then reads the producer's
typed on-time paid source inside the original captured transaction. It creates one
immutable owned order origin and one line origin per original source line; exact
replay returns those originals. Original assent, declared name, license terms,
asset identities, integer amounts and payment commitments stay frozen.

The consumer never changes previous paid orders, `license_grants`, free grants or
membership origins. Producer/order/line/payment UUIDs and hashes are owned values;
there are no external SQL dependencies on the producer's ten tables. Synthetic
rehearsal and verified production provenance remain distinct. Current development
fixtures use actual local SMTP mailbox proof and explicitly synthetic payment,
rights, terms and asset input. They certify no real merchant or legal facts.

Preparation claims have at most five attempts and a finite 300-second lease.
Rendering and exact captured-asset checks occur outside transactions. One original
monotonic budget covers the entire preparation, including all lines and fulfillment;
rendering or completion cannot reset it. Partial failures retain successful first
originals and expose no files until all original lines have passed exact physical
verification. Completed originals are restore-only; a missing original never
creates another document claim.

The new paid-purpose renderer, script, profile and append-only private storage keep
the original offline renderer's font/package/environment/path/timeout safeguards.
Paid files cannot reuse the free-purpose PDF authority. Neither test scanning nor
a physical file observation creates an operative grant by itself.

## Private buyer surface

The mounted `PaidGrants` page starts with no private props. Its bounded latest-20
index returns original locators; selecting one obtains the full original license
and assent projection after fresh ownership and retained-source proof. Preparing
the order exposes exact PDF and captured file hashes only after whole-order
fulfillment. Bounded download status contains no token or path, preserves consumed
attempts and reports preparation retry admission truthfully.

Download authorizations bind an explicit server delivery policy, account, line,
request UUID, immutable origin hash and exact frozen target. Exact request replay
does not extend expiry; changed input conflicts. A consumed authorization cannot
consume another attempt. An exact protected descriptor is copied into a bounded,
unlinked private spool between two fresh owner/source frames; only the second
frame can record one immutable attempted download. Native POST carries token and
CSRF in its body. Tokens never enter URLs or browser storage. The held response
rechecks current ownership before first bytes and closes its descriptor on failure.

Read authority denial clears every entered reference, private projection, pending
request and token. Departure/unmount aborts requests and ignores late results.
Unknown errors use a fixed sanitized reporter with only the exception class.
Every private response has no-store/noindex/no-referrer/nosniff and varies on Cookie.

## Parent registration contract

The parent owns mounting `routes/paid-grants.php` inside the web/CSRF group,
prepending `PaidGrantPrivacy`, generic private exception reporting/responses,
conditional customer navigation and robots exclusion. The route names are
`paid-grants.page`, `.index`, `.finalize`, `.show`, `.downloads`, `.document`,
`.authorize` and `.redeem`. Paths are under `/paid-grants`; unknown and malformed
private descendants must receive the same privacy envelope. The tests explicitly
mount this route/privacy configuration until the actual composed application is
independently reviewed.

## Remaining binding

The exact provisional fixture snapshot is recorded outside the repository under
`/workspace/.va-studio-dependencies/paid/f0a1615`. The final candidate must replace
it with the reviewed producer and T23 dependency, bind both one-use producer
committed-read siblings under the original budget and rerun affected original
canaries on the actual composed source. The current owned receipt closes the
current owner, assets and paid-origin graph; it does not yet close post-commit
producer financial drift. No final response/download authority approval is claimed.

Default configuration has both rehearsal and operative flags off and no delivery
policy. Activation requires actual approved terms/assets, supported producer
capability and an explicit technical policy; no prices, terms, provider credentials,
mail or live payment configuration are supplied here.
