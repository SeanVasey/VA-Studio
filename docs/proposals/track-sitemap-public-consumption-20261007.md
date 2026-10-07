# Bounded public consumption after private identity generations

Status: read-only engineering proposal. No runtime, route, migration, workflow or lock edit; no executed-test claim. Depends on the private generation API/store and its reviewed native proofs, which are pending. Source inspected: private proposal `4e0c4b5bfa60a3abd0da0104505ebdf8c0079dbd` and receipt `2024165df6100db0c00adfc2b80fa4d33fcbc173`; exact composed discovery/index `9f1bda29a20a6f6936efa9b86e9731194bd3ccf7` / tree `fe4be5659d1d769a0175e2a2c43da15cf1f7954d`. These source files retain approved PR23 index/robots behavior. Root owns integration and authoritative current native ancestry.

## Capacity decision and honest privacy

Use the proposed128 fixed slots,48 candidate identities per slot,6144 published-candidate capacity for the initial child. This is a proposed application cap; repository facts do not establish Sean's actual catalog size. Overflow6145 must prevent terminal completion and track advertisement, never silently truncate. Limits are independent of actual public eligibility: ineligible candidates remain private generation members, so time-only eligibility recovery can appear in a fresh page without regenerating identities.

A complete currently valid generation advertises exactly128 child locations plus the existing public-pages child. Empty slots remain present and return a successful empty urlset. Used-window count, published-candidate count, cursor, source IDs, graph epoch, private handle, ownership seal and progress remain absent. The fixed topology hides those private accounting facts; it does not conceal the number of eligible public URLs, nor crawler observation of slug changes. Public random generation identifiers necessarily reveal generation replacement, not private progress or a source hash.

The price is128 child requests even for an empty catalog, and potentially repeated crawl churn when a guarded eligibility-graph writer invalidates a generation. Existing public-discovery throttle60/minute is crawler advice/abuse control, not a throughput promise. Choose a deliberate child throttle prefix in implementation and verify that crawler requests cannot consume account/private-route budgets. Do not change the existing index/robots throttle as an incidental optimization. No automatic producer scheduling or request-driven refresh is introduced.

An alternative fixed64 slots/3072 candidates halves request overhead but lowers unverified capacity;256/12288 doubles capacity and crawl requests. A variable used-slot index would disclose private candidate partition counts and changes the approved privacy contract. Protocol ceilings50000 entries and52428800 uncompressed bytes do not justify scanning50000 catalog candidates. There is no source-backed reason to select a different cap now. Keep128 explicit and versioned; any future cap change must preserve old URL refusal and generation-format interpretation rather than reinterpret existing slot seals.

## Four-path public child and API dependency

| Proposed owned path | Responsibility |
| --- | --- |
| `app/Http/Controllers/TrackSitemapController.php` | Strict body/query/environment/path admission; invoke exactly one trusted generation consumer; return only fixed XML/header projection |
| `app/Http/Controllers/PublicDiscoveryController.php` | Existing index seam: keep editorial child independent and append fixed128 track locs only from a bounded authenticated complete current descriptor |
| `routes/public-discovery.php` | One session/Inertia-free GET/HEAD route `/track-sitemaps/{publicGeneration}/{slot}.xml`, with explicit matching limits and named throttle |
| `tests/Feature/TrackSitemapPublicConsumptionTest.php` | Public index/child privacy, admission, bounds, currentness, empty topology and byte-projection adversaries; reuse existing40 page/index/robots regressions |

Private producer ownership retains `TrackSitemapGeneration`, `CandidateWindow`, `TrackSitemapOwnership`, `CurrentEligibleTrackSnapshot` and fixed `TrackSitemapProjection`. No new public caller may mint identity windows, renew EligibleTrackSnapshot deadlines, supply custom serialization callbacks or expose start/step commands. Root registers focused/native selectors and shared metadata later. Final public API names require coordination after private source freezes; the table above grants no current file ownership.

The producer must expose a bounded complete descriptor and a final-consumed XML method, not a raw model or a permanently-current snapshot. A useful target is `currentIndexXml(canonicalOrigin, editorialChild): string` and `xmlForSlot(publicGeneration, slot, canonicalOrigin): string`. Both are trusted fixed projection operations inside the owned current transaction. If a descriptor is returned instead, it is only internal data and must be revalidated with the entire combined index XML already serialized before the final captured-PDO fence. The controller must not obtain a descriptor, commit its proof and then build128 locs outside that proof.

Index work reads only current pointer plus one authenticated completed header and bounded ownership metadata; no COUNT, catalog build, candidate hydration, or all-window reload. Child work reads those records plus at most one indexed sealed requested window, then performs at most48 unique current candidate decisions. Include empty-window interpretation in authenticated complete header semantics so an ordinal beyond used windows is a valid empty slot without exposing or loading the used count publicly. Raw SQL state/count alone is not terminal proof. Existing transitive offer/media relations can fan out:48 candidates alone does not establish a bound for all hydrated related rows or media hashing. Preserve and document that inherited limitation instead of claiming total request-memory bounds.

## Currentness and fixed serialization

For each valid child, private ownership/header/window/configuration/epoch checks precede shared PublicationReadiness and SelectionInventory decisions at one explicit current clock. Reuse the approved READ COMMITTED owned transaction, captured primary PDO, connection identity/depth checks and last epoch row fence; do not introduce HTTP query-listener callbacks after the final fence. XML canonical paths, UTF-8/entity escaping, final string and length validation must be complete before final epoch/config/time/PDO proof. The service returns that already-built string after successful commit; the controller adds only predetermined headers. The ordinary framework lifecycle is not a sandbox for arbitrary callbacks, and a later source writer may make the already-completed response historical.

A fresh CandidateWindow consumption may establish a fresh eligibility decision; it has no retained eligibility deadline to extend. Any consumption through existing EligibleTrackSnapshot must preserve its authenticated original maximum deadline. Equality/crossing at ten-second, license-start/expiry or held-expiry boundaries refuses. Do not read a second independent clock to serialize optional dates; no lastmod/changefreq/priority claims are introduced. Configuration identity must include canonical origin, environment and inherited eligibility/config/key identities so an APP_URL change cannot reuse a generation selected for another origin.

Retain existing non-sliding60-second private media integrity caching; generation metadata is not an atomic filesystem freeze. A current file loss/restoration must obey inherited current eligibility behavior, and DB graph change invalidates every old generation URL. No cache/304/stale XML fallback or request-driven producer rebuild follows refusal.

## Public admission and refusal matrix

| Condition | Public result |
| --- | --- |
| Complete authenticated current generation, slot1..128, eligible paths |200 XML containing only same-origin canonical track locs |
| Same valid generation, empty/all-ineligible valid slot |200 empty urlset; same fixed index topology |
| Unknown/stale/incomplete generation, malformed ID, leading-zero/nondecimal/out-of-range slot, nonempty GET/HEAD body, any query |Uniform empty404 with no-store/nosniff/noindex; no internal reason/count/ID and no expensive candidate work |
| Nonproduction child request |Uniform404; existing index empty and robots Disallow:/, no generation lookup/hydration |
| Invalid canonical configuration or broken owned installation/database service |Fail closed; empty503 with no-store/nosniff/noindex, no private diagnostics; never fabricate an empty valid slot |
| No complete generation selected or legitimately stale descriptor |Index retains existing editorial child only, no private progress disclosure |

Canonical origin must reuse the exact existing strict HTTP(S) origin helper, not request Host/forwarded/cursor/query values. Public ID format/length must match the approved producer encoding exactly;32 lowercase hex is an example, not an invented finalized API. Reject nonempty bodies through an actual one-byte stream probe, including false Content-Length0; GET and HEAD use the same service admission. Disable web/session/Inertia and verify no Set-Cookie or request-token echo. Record trusted internal reason codes only through approved minimized operational diagnostics; do not echo arbitrary exception messages. Distinguish a domain-authenticated absent/stale generation from operational corruption; do not catch all Throwable and pretend the catalog has no complete generation.

Uniform404 paths need root-coordinated response construction rather than default exception HTML that can vary by middleware or debug configuration. Route-not-found malformed IDs and controller refusals must have the same bounded body/header shape. Framework throttle429 may remain a distinct standard response; this is not a constant-time or indistinguishable timing promise.

## Meaningful tests before publication

- Carry existing18 public-page and22 index/robots cases unchanged; add production fixed129 index entries, unknown/stale/empty/nonproduction cases, hostile Host/forwarded/cookie/bearer/Inertia no echo, canonical configuration injection and exact XML namespace/onlyloc fields/size limits.
- Reject GET/HEAD actual1/65536-byte bodies despite false Content-Length0, every query, malformed/oversized public ID, zero/129/leading-zero/negative/overflow slots; prove refusal before candidate hydration and consistent empty refusal headers/bodies. Empty GET/HEAD still works.
- SQL instrumentation proves no COUNT/build/all-window scan in index, one-window-only indexed child reads and at most48 candidate decisions; fixed empty/all-ineligible windows preserve topology. Forged seals/cross-generation windows/installation shadows or missing guards refuse.
- Serialize first, then native competing epoch/config/deadline changes at the final proof; record actual separate connections/waits. Snapshot retained original expiry minus-one/equality/crossing stays green. Fresh CandidateWindow start/expiry/held changes use the new explicit decision clock without reusing retained eligibility evidence.
- Verify no cache/304 fallback, old random generation URLs refuse after new epoch/config, corrupted service never yields a successful empty slot, and valid successor index replaces all fixed locs together. Filesystem evidence remains bounded by inherited cache limitations.

These are definitions, not execution evidence. Private generation API/native proofs and coordinated sensitive review are engineering dependencies; actual source inventory, legacy URL provenance, deployed crawl and final exact integrated acceptance remain external/release criteria. T36/FP-014 is not closed. Preserve focused local checks, independent source review and one cheap preflight per coherent batch; full Foundation/native/browser acceptance stays manual exact-candidate only. Primary protocol references: https://www.sitemaps.org/protocol.html and https://www.rfc-editor.org/rfc/rfc9309 (previously inspected for the implemented root index/robots child).
