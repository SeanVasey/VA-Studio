# Collection and album draft authoring

Current main `13d7474f` includes immutable composition authoring and explicit
reviewed member snapshot refresh from PRs #7–#13. The [remaining assessment](remaining-code-assessment-20261006.md)
credits those children and retains public licensing/checkout/delivery as actual
remaining code. This guide is private authoring, not evidence of a published
collection or a completed T26. Current AGENTS.md governs focused development
merges; complete manual verification belongs to the final integrated candidate.

This is a T26 / WP-10 authoring increment. An authorized administrator can open **Collections and albums**, create a collection or album, choose existing tracks, arrange their order, edit the description, review retained versions and use a retained composition as a new current draft version.

These are private drafts. This increment does not publish a collection, offer a bundle, set a price, assign a license, take payment or authorize delivery. T26 remains open for those integration requirements. Track storefront and checkout behavior remain unchanged.

## Saved composition

Each draft has an immutable identity and kind (`collection` or `album`) with a current optimistic version number. A changed save appends a version and ordered membership. A version contains a plain title and description, one to 100 unique track identities, and each track's descriptive title, metadata revision and publication revision at save time. It does not contain private media locations or claim that a draft track is ready for publication.

The `collection-album-draft-v1` manifest uses the existing `vasey-json-v1` canonical encoding and a SHA-256 digest. Independent membership rows retain track foreign keys and positions. Reads validate their exact correspondence to the manifest and digest. ORM guards and database triggers refuse modification or deletion of retained versions and members. Track foreign keys prevent deletion of a referenced source; ordinary track editing remains possible.

**Use as new draft version** copies the chosen historical manifest exactly into a new version and records its `source_version_id`. It does not refresh old titles or overwrite an earlier version. A later edited save takes a fresh source snapshot. Choosing contents already current, or saving an unchanged manifest, is a no-op. An outdated editor or selection is refused and must be reopened.

## Authority, concurrency and evidence

Every `ProductDrafts` service entry point reloads and locks the actor, checks current verified staff authority and checks currently required MFA enrollment using a locking read. Mutation lock order is actor, existing product draft, retained evidence and ascending individual source track identities. Versions and membership are read with current locks, including when an outer MySQL repeatable-read transaction already has an older view.

Version, membership, current parent revision and minimized audit evidence commit in one database transaction. Audits record actor, numeric product/version identities, action, kind, member count and before/after manifest digests; titles and descriptions are not duplicated into audit context. There is no filesystem, provider, price, license or payment mutation in this service.

The additive `000041` migration supports MySQL and SQLite and checks the prospective table and trigger identities before its first write, including SQLite's shared index namespace. Existing permanent/temporary objects and interrupted MySQL DDL prefixes are refused for explicit inspection. They are never silently adopted, dropped or repaired. MySQL DDL errors may leave a partial prefix; a retry preserves it for investigation. Operational `down()` retains the tables and evidence; rolling back its migration journal therefore does not authorize a blind re-run. Schema/journal recovery requires a reviewed plan. Disposable test databases use their existing isolated wipe lifecycle.

## Open product-family work

T26 still needs approved collection/album license scope, bundle pricing and allocation, tax/discount/refund behavior, partially unavailable members and exclusivity rules, publication/readiness, exact purchased composition, asset versions, historical contracts and authorized delivery. Draft selection does not decide any of these policies. Actual legacy product and obligation evidence remains a migration dependency.

**T27 sound kits remains required and is not implemented by this increment.** Current media assets belong to a track and support artwork, tagged preview, MP3, WAV master and WAV-only stems ZIP. A recording's stems archive is not a general sample/loop/MIDI/preset kit. The next kit increment needs an explicit private kit ownership and intake contract, media roles, permitted formats and dependencies, safe archive and scanner profile, file counts and digests, demos, and rights/redistribution/attribution evidence. Approved kit license suitability and versioned purchase/delivery policies remain separate requirements. No kit selector or fabricated inventory is introduced here.

## Verification

Focused tests cover actual domain and Filament creation/edit/reorder/removal/history selection, stale editor rejection, strict IDs, authority and MFA withdrawal, immutable database evidence, atomic rollback and migration object preservation. Native MySQL workers prove actual record waits before competing edit/selection, MFA withdrawal and committed source metadata changes. SQLite is not concurrency evidence.

Recorded on 2026-10-06 with PHP 8.4.26:

- SQLite: `php vendor/bin/phpunit tests/Feature/ProductDraftTest.php tests/Feature/ProductDraftMigrationTest.php tests/Feature/ProductDraftEditorTest.php --stop-on-error --stop-on-failure` — **45 tests, 298 assertions, zero failures/skips**, 5.393 seconds; synthetic test application key supplied through the environment.
- Native MySQL 8.4.11: the same three files plus `tests/Feature/ProductDraftConcurrencyTest.php`, invoked through the session's isolated `mysql-runtime/run-tests.py` wrapper with `--stop-on-error --stop-on-failure` — **54 tests, 674 assertions, zero failures/skips**, 59.914 seconds. The wrapper creates a private disposable server with default durability. Native process/lock tests contribute nine cases; assertion counts include observed wait polling.
- `php vendor/bin/pint --test` restricted to the 13 new PHP files and `git diff --check` — passed.

Independent source review covered the ten persistence/domain/test files; their author separately reviewed the three Filament/editor-test files written by the UI author. No blocking findings remained. Shared required-CI registration and full composed-tree verification belong to the integrating lead.

The admin browser and mobile journey are not claimed by Livewire tests. Public collection/album commerce and all kit behavior remain pending.
