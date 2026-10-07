# New bounded discovery sitemap worker — October 7, 2026

This is **new implementation**, not recovery. The unpublished predecessor was inaccessible and was not recovered. The two earlier proposal documents were design inputs, not recovered executable source or passing evidence.

Base: `da70eba95c46522cd4084db4e79f764ba401fd7c`. Contracts: `bc4ef6ffff624a7e2e964a4d972edbda0aa54662`. Frozen executable: `97d5db7895bfdb56e9de3d77cce36b6c9156902d`, tree `f2e24738de8e76cd606db75b5dd657f912fefc6c`. Root independent review and public registration are outstanding. No push, hosted CI, production execution, DNS change, redirect admission or source-site crawl occurred.

The byte map and all 48 local receipts, including failed setup and controlled failure proofs, are in [receipt-map.json](discovery-sitemap-20261007/receipt-map.json). Only the explicitly labelled frozen receipts establish results for this executable. Earlier unfrozen runs are diagnostic history. The first native schema smoke failed because my metadata comparison expected `INTEGER` where MySQL reports `int`; the repaired and frozen native runs pass. The first command harness failed on a missing Artisan import; the unchanged command runtime passed after the harness correction.

## Result and ownership

The worker persists an immutable private generation header and immutable candidate-ID windows, with a hash chain, authenticated row seals and unique ordinal ownership. Only a guarded singleton publication pointer mutates. The pointer carries a signed terminal completion certificate. It is published with an expected revision after checking every ordered window, cursor and prior hash, and the final no-more condition. This certifies a complete partition of published track identities at the captured source epoch. It does not retain or certify page eligibility.

The technical bounds are 48 IDs per window, 128 fixed slots, 6144 total published candidates and an immutable one-hour generation lifetime. A 6145th candidate leaves the entire generation in overflow, with no completion or pointer publication. These are implementation bounds, not an assertion about the real catalog or approved publication capacity. All-ineligible candidates still advance the cursor. Empty source and unused fixed slots return valid empty XML. The index always names all 128 slots, preserving those fixed semantics without exposing private window counts, epochs, deadlines or source data. Each chunk has at most 48 public URLs and 128 KiB XML, within the sitemap protocol's 50,000 URL bound.

Every candidate window is freshly compared against the source's bounded next-ID scan before append. Merely calling the internal `CandidateWindow::captured()` factory with an encrypted partial list is insufficient. Exact ordinal replay proves the committed context and returns the same progress. Changed context, gaps, stale epochs, expiry and changed configuration refuse. Unknown commit acknowledgement does not trigger a new ordinal or pointer revision. Start replay reports current committed progress instead of resetting the displayed state.

The only coordinated shared edit is imports plus `CurrentEligibleTrackSnapshot::captureIdentities(CandidateBuildRequest): CandidateWindow` and `consumeIdentities(DiscoverySitemapConsumption): string`. The original `capture`, `currentPaths`, `captureCurrent`, `configuration` and `decode` method bodies are byte-exact; hashes are recorded in the map. Existing publication, license, inventory and time-boundary rules remain in that engine. No historical CI, lockfile, base migration, legacy redirect or product URL was copied.

## Public proof and clock behavior

Preparation authenticates the current pointer, immutable header and exactly one requested indexed window. Public preparation never counts candidate rows, reads all windows or loads the catalog graph. Consumption makes one fresh bounded call to the existing shared eligibility engine for the exact window IDs. Authenticated evidence IDs must equal all private candidate IDs; eligible paths are a different subset. A missing hydrated ID therefore cannot turn a missing public destination into silent completeness. An legitimately ineligible ID can produce empty XML.

The original fresh decision deadline is the minimum of ten seconds, license boundaries, held inventory expiry and generation expiry. The second transaction performs no source graph hydration. It serializes fixed XML, then proves exact pointer/header/window, schema, source epoch, configuration, primary identity and that unchanged deadline. An additional raw post-commit check follows Laravel commit events before XML is returned. It does not start another transaction, renew evidence or bypass Laravel bookkeeping. Actual final `TransactionCommitted` epoch, configuration and pointer withdrawals are refused. Source and store PDO transaction markers also reject direct commit/reopen during retained proof.

The epoch is additive and monotonically invalidated by the existing discovery dependency guards. Native bookkeeping takes pointer, generation and window locks before the final epoch lock; catalog writers do not take sitemap bookkeeping locks. The same-ordinal and competing-publication race probes observed two independent MySQL connections waiting on the held pointer. Same ordinal committed one window; two distinct complete generations produced one pointer winner and one conflict.

XML uses the configured canonical origin helper, never request Host. Paths come only from the shared current track eligibility decision, match canonical `/tracks/{slug}`, and are XML escaped. No private storage path, master, contract, customer path, title, ownership token, timestamp claim, `lastmod` or guessed product destination is included. There is no public builder, stale fallback, public progress endpoint, response cache or request-driven rebuild.

## Schema and runtime wiring

Migration `2026_10_07_248000_create_discovery_sitemap_evidence.php` creates `discovery_sitemap_generations`, `discovery_sitemap_windows` and `discovery_sitemap_current`. Each CREATE contains its primary key, checks and foreign key atomically. Recovery accepts only the exact global creation prefix: table, insert/update/delete guards, then next table. Empty holes before later guards or tables are refused. All reserved namespace objects are inspected; a same-name foreign table cannot mask a valid trigger. Native case variants, temporary shadows, changed definitions, additional indexes/constraints and retained missing guards refuse before writes. The final complete graph is re-proved after the last DDL. Operational down throws before reads or mutation, preserving evidence and migration history. Synthetic prefixed fixtures prove DDL behavior. Runtime explicitly requires an empty connection prefix because the unchanged discovery epoch engine does not support prefixed source tables.

Root owns global registration and activation. Exact hooks:

- Register `routes/discovery-track-sitemaps.php` outside web/session/CSRF middleware. It declares GET/HEAD `/track-sitemaps/{generation}/{slot}.xml`, name `discovery.tracks`, with the existing `public-discovery` throttle.
- The production-only controller refuses query data, actual request bytes, unsupported methods, malformed generation IDs and noncanonical/out-of-bound slots. It returns empty unavailable errors, private no-store headers, nosniff and no cookies.
- Root index integration can invoke the complete prebuilt `SitemapStore::currentIndexXml()`. It includes the existing site-pages child and all fixed track children only for an authenticated complete current generation; stale, partial, corrupt or expired state refuses.
- `config/discovery-sitemap.php` defaults `enabled` to false. This is capability preparation; no real production binding was activated.
- `discovery-sitemap:build --new-request=PATH` creates a mode-0600 private capability before enqueue/start. `--request-file=PATH` plus exactly one of `--start`, `--ordinal=N`, `--status` or `--publish-revision=N` performs one operation. No capability or source identity is echoed. The real command test exercises private creation, invalid ordinal refusal, start, one-window completion, publication and status.
- `BuildDiscoverySitemapWindow` is a private encrypted queue job for one exact ordinal, with three attempts and bounded backoff. Semantic stale/policy failures stop; transport/unknown acknowledgement errors retry the same ordinal. No host runner or queue transport was configured or tested.

## Frozen validation

PHP 8.4.26; native MySQL `8.0.46-0ubuntu0.24.04.4` on the existing loopback daemon, using a dedicated least-privilege synthetic task database. Locked dependencies were reused through package symlinks, with independent Composer metadata; no new dependencies or full vendor copies.

```sh
source /workspace/.va-studio-toolchain/activate.sh
vendor/bin/phpunit tests/Feature/DiscoverySitemapWorkerTest.php tests/Feature/DiscoverySitemapSchemaTest.php tests/Feature/CurrentEligibleTrackSnapshotTest.php --log-junit /tmp/discovery-frozen-sqlite.xml
```

37 defined, **36 executed / 218 assertions**, zero errors/failures; one named native-only case-variant test skipped. Includes existing shared discovery behavior, exact 6144/6145 bounds, ineligible partitions, partial factory refusal, actual command, corrupt certificate, property-missing hydration, clock-only license expiry and retained deadline, pointer CAS and unknown actual window/publication commit acknowledgements.

```sh
source /workspace/.va-studio-toolchain/mysql/discovery_sitemap-task.env
vendor/bin/phpunit tests/Feature/DiscoverySitemapSchemaTest.php tests/Feature/DiscoverySitemapConcurrencyTest.php tests/Feature/DiscoverySitemapWorkerTest.php --filter 'test_(fresh_schema|atomic_first|all_existing|retained_missing|raw_updates|connection_local|down_refuses|empty_hole|reserved_guard|native_case|native_same|actual_final_transaction_committed|raw_commit_and|actual_window_commit)' --log-junit /tmp/discovery-frozen-native.xml
```

**16 / 68 assertions**, zero errors/failures/skips. Includes native DDL partial/retry, exact/case-variant dictionary admission, reserved-name masking, immutable guards and retention; observed two-process contention; actual final epoch/config/pointer commit-event withdrawals; raw commit/reopen; actual window commit acknowledgement loss and replay. No broad native matrix ran. Execution required explicitly granted loopback network access; the private env file and credentials are not evidence artifacts.

Focused Pint and whitespace checks pass. A controlled test-only bootstrap removes only the frozen new seam's post-commit closure; the three unchanged actual final commit withdrawal tests then produce **3 failures / 9 assertions / zero errors**. The transformed source, bootstrap and raw receipt are retained and identified as fault injection, not a historical or recovered implementation. Frozen source passes the same three cases on both SQLite and native MySQL.

## Remaining limits

This child is not complete T36, SEO parity, launch acceptance or an admitted URL inventory. The real first-party/BeatStars URL export, current DNS/host ownership, live crawl, legacy mapping, actual catalog capacity and additional product URL adapters remain content/human gates. Only the existing track family has an authoritative public eligibility adapter; services/kits/merch URLs are not invented. Root public registration, exact composed-source independent review and affected integration HTTP tests remain required.

No host queue scheduling, failure monitoring, garbage collection, production capacity, external media/scanner/DNS transport or final full CI was exercised. Evidence records expire for public use but are retained; destructive retention requires a separate migration. Shared media integrity's existing bounded cache and external filesystem mutations are inherited limits; this worker does not claim an atomic filesystem snapshot or invalidate unrelated approved media behavior.
