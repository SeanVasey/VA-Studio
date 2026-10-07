# Bounded private notification recovery

T32/WP-11 has a local/testing recovery child. The auto-discovered command
`php artisan vasey:recover-test-notifications --after=0 --limit=10` examines
one ID-ordered page of at most 25 retained notices. Resume with `nextCursor`;
`hasMore` observes the current page, not a global fixed snapshot. Restart from
zero on a later recovery pass to reconsider earlier failures. Unavailable
records remain visible and do not prevent cursor progress through the page.

The operator must have local application filesystem access and explicitly enable
the existing test customer/access/activation/private-capture policies. Production,
default-off, outer transactions and malformed decimal/overflow/bounds are refused.
This adds no HTTP, queue or scheduled entrypoint and sends no mail. A nonzero
command result indicates an invalid/global refusal or an unavailable item; the
bounded JSON projection contains only test flags, notice UUIDs, states and an
internal row cursor. No email, payload, receipt, credential or exception is output.

Pending notices use the existing capture dispatch. Only proven pre-write storage
refusals retry, subject to the existing three-attempt cap. Active leases are only
observed. Expired/uncertain leases inspect original bytes through `reconcile`;
absence never proves non-delivery or authorizes a new claim. Accepted replays
recheck original bytes and current authority. The retained notification service,
its encryption, audit transitions and observer-free final proof are unchanged.

Executable source `9ca32a4b2a90f3cacfeba1ad61c90c7a61add40e`, tree
`79c8f0651798345669a63619d1190f18f22284e3`, passed 21 selected SQLite cases /
92 assertions with PHP 8.4.26: 10 scanner cases and 11 actual registered Artisan
command cases, no failures/errors/skips. Pint passed for all four new PHP files.
Commands, JUnit, raw outputs and SHA-256 bindings are retained in
[the receipt](notification-recovery-20261007/receipt.json).

The earlier combined run is retained as a failed attempt: 62 cases / 373
assertions, 61 passed and one media-processing fixture error under concurrent
local test processes. Its 52 unchanged notification cases passed 334 assertions;
the new two-customer scanner fixture failed before recovery invocation. The
exact final scanner rerun subsequently passed all ten cases. Command-only files
were added during that earlier run, so it is source carry evidence, not an exact
whole-tree combined pass. Initial test-harness identity comparisons and account
withdrawal version mistakes were corrected; no database guard was weakened.

No new native concurrency, browser or complete Foundation acceptance is claimed.
T32 remains open for delivered transactional mail, consent/preferences, CRM,
provider policy and applicable integration dispositions. A real transport needs
separate admission, recipient/consent policy and ambiguous-delivery reconciliation.
