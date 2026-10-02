# Read-only track publication manifest foundation

October 2, 2026. T12-PUBLICATION-EVIDENCE-01 supplies an internal read-only identity before FP-032 scheduling. Corrected source and focused PHP/SQLite feedback are recorded below. Full integrated MySQL/SQLite CI and final acceptance remain pending. This record does not accept PR #104, complete T12 / WP-02 or claim track scheduling, production publication or launch readiness.

## Implemented contract

`App\Domain\Catalog\ReadTrackPublicationManifest::handle(int $trackId, User $actor)` returns `TrackPublicationManifest`. It rejects ambient transactions before private queries, then locks fresh persisted actor → current catalog authority/MFA → current track. Ordinary actual readiness must pass. All active immutable revision licenses additionally pass existing `VerifiedLicense::available` at the same recorded `capturedAt` instant: effective start is inclusive, effective end exclusive. Latest ready artwork/preview are selected without fallback to older assets.

The readonly value exposes `payload(): array`, `hash(): string`, `actorId(): int` and `capturedAt(): CarbonImmutable`. Returned arrays cannot alter its retained content, and caller-owned references or mutable objects cannot survive construction. It has no automatic JSON/array serialization interface. Actor and capture time are outside the canonical content hash; this hash identifies content and is not a signature or authorization proof.

| Payload field | Exact retained identity |
| --- | --- |
| `schema_version`, `canonicalization_version` | `1`, existing `vasey-json-v1` |
| `track` | ID, status, metadata/publication revisions, title, slug, reserved published slug, artist, BPM, key, genre, mood, ordered tags and description |
| `rights` | Current declaration ID and `OfferSnapshot::rightsHash` |
| `artwork` | Asset ID/role, SHA-256, byte size, parent asset ID, processing run ID and verified profile fingerprint |
| `preview_tagged` | Same derivative identity plus waveform SHA-256, tag SHA-256 and `duration_seconds_reference` |
| `offers` | Numeric offer-ID order; current revision ID/number/schema, snapshot hash/canonicalization, frozen commercial values, minimized license identity, deliverables and applicable exclusive identity |

`duration_seconds_reference` contains only `encoding` and `sha256`, using existing `MediaEvidenceValues::reference` / `media-values-binary64-v1`. Fractional duration is represented losslessly for identity rather than rounded or formatted as display seconds; changing PHP `serialize_precision` does not change this reference. Raw waveform peaks and private media proofs are absent. Global `CanonicalJson` remains integer-only.

License projection contains exact version/template IDs, number/type, source/model/render/submission digests, renderer identifier, review-evidence ID/hash, effective dates and a hash of the full frozen license snapshot. Deliverables are ordered by role then numeric asset ID and retain role, byte/MIME identity, parent/run IDs and, for stems only, the exact recording association/master/preview/source IDs and evidence hash. Editable offer fields cannot replace the frozen commercial promise.

For activated schema 2, `exclusive` includes current eligibility, activation ID/hash/canonicalization, the current validated test policy/hash and explicit scope/link identity, control version, blocked state and exclusive-sale ID. Readiness requires an unblocked unsold scope, so the successful capture records `blocked: false` and a null sale ID. Offer revision schema 1 uses `exclusive: null`. Full license text/terms, authored source, private names/paths, scope evidence references and raw source/run/`VerifiedMedia` dumps are excluded.

Ordinary blockers or invalid typed projections produce `ValidationException` under `publication`; stale/missing authority or unsatisfied MFA produces `AuthorizationException`; a missing track produces `ModelNotFoundException`; caller transaction nesting produces `LogicException`. There is no test-mode bypass of this service's standalone or authority boundary.

## Evidence at the actual source

The original domain source is `6acb3109e5cf38c5fb3ccf9dc0cf4bcc24c9f0e5`; the corrected domain follow-up is `8e5ef40d7fe00942b706ae9beca8d582f22f43d3`, tree `71a43c15d4b467cc987fb2484506265715953686`. Its integrating equivalent is `9d2a1301d7dd522f6030e4ae6c5b0943499c636a` with that identical tree, independently reviewed after the clock and numeric-identity findings were corrected. Both owned PHP files passed syntax and Pint checks, and staged diff checks passed.

The final executed test source is `2d372ffaf743e7c0583d615ffa5a8ed66cebb30d`, tree `613ae887b3584ad7ffa4e7506e320ecf95c48d1a`. Integrating source `290976e7386147df352df06e30567971b8b5bae2` has that identical tree. The run reported **28 cases: 21 executed, 292 assertions, seven exact MySQL-only skips, zero failures/errors**. It used PHP 8.4.26 via the isolated runtime path, SQLite, genuine FFmpeg-derived fixture media, the existing test-only scanner and an ephemeral synthetic testing application key; no committed environment configuration changed.

```bash
php artisan test tests/Feature/TrackPublicationManifestTest.php tests/Feature/TrackPublicationManifestConcurrencyTest.php --compact --log-junit /tmp/vasey-publication-manifest-corrected.xml > /tmp/vasey-publication-manifest-corrected.log 2>&1
```

The behavioral cases cover fresh authority/MFA, forged/deleted actors, transaction rejection before private queries, minimized immutable identity, stable actor/time hashes, metadata/publication/active-offer changes, frozen editable-offer compatibility, exact rights and stems binding, latest damaged derivative refusal, license expiry, schema 2 policy drift and the later mutable-scope limitation. Damage cases changed genuine private derivative bytes and waited past the existing cache bound through the isolated test clock; they did not weaken verified-row guards or fabricate production media proof.

Two concrete boundary regressions failed on the old source, then passed on the corrected source. On `c3107453a35353865f83b9177fc06d83dcf44d7a` (tree `d6d5021f9dbc1c2889a6eb1c2f5dfad0f64207f2`), changing `serialize_precision` changed the duration identity; actual clock movement between license reads accepted adjacent nonoverlapping effective windows. The red run executed two cases, ten assertions and two failures:

```bash
php artisan test tests/Feature/TrackPublicationManifestTest.php --filter='duration_identity_is_stable|active_licenses_with_adjacent' --compact > /tmp/vasey-publication-manifest-boundary-red.log 2>&1
```

The corrected full run above includes both cases. Original artifact identities were read directly:

| Artifact | SHA-256 |
| --- | --- |
| `/tmp/vasey-publication-manifest-boundary-red.log` | `7e66c8af7d18a5ca4ad9a75558420761b7d27d9958483a9a1b27014042fca846` |
| `/tmp/vasey-publication-manifest-corrected.xml` | `b73438a1e2d91b71b7b2fbe4acc8aa96b8ff9464bb71ca461262a01a8d8024b4` |
| `/tmp/vasey-publication-manifest-corrected.log` | `526b45f7b70b32aa920fe711796b97bbbdba31f6181d533e57e62993d029f429` |

These are local source-bound receipts, not hosted CI or durable remote artifact links. The seven MySQL cases are four exact methods: actor lock order (two datasets), stale Repeatable Read authority (three datasets), metadata writer first (one), publication writer first (one). Their independent-process workers require observed exact actor/track row waits. They all skipped in this SQLite run; no MySQL concurrency result is claimed from their definitions or skip-policy registration.

## Registered focused feedback

Registry source `e857ca190076127ad774261af5eea8172048f989`, tree `ccb1f161a2665250a7854c759745b4d71ca67b9b`, parent `290976e`, adds both manifest classes to the fixed operator selector and the four exact MySQL methods/seven cases to the engine skip policy. Actual full discovery contained 2,519 cases / 159 classes; the complete policy contained 77 methods / 185 exact SQLite skips. Focused safeguard and receipt-parser checks passed 26 and 24 cases respectively.

```bash
FOCUSED_SUITE=operator FOCUSED_ENGINE=sqlite python scripts/ci/focused-tests.py
```

The unchanged 20-file operator runner passed on this clean source: **246 reported, 210 executed, 2,409 assertions, 36 exact SQLite skips, zero failures/errors**. All seven manifest skips matched actual discovery and the existing receipt parser. This run used the isolated PHP 8.4 runtime path and an ephemeral synthetic testing application key. Its original `/workspace/scratch/e32b681ab527/publication-manifest-registry/focused-ci-evidence.json` records the exact source/tree, clean tracked worktree and counts; SHA-256 is `998b0dc35022d11f9ebb53203407b56cc20725d620ae68ae8a3e43ea4eff6cbd`.

This receipt belongs to `e857ca1` and precedes later PR #104 privacy/related-fixture repairs. It is focused development feedback and supplies no MySQL, browser, hosted CI or merge acceptance.

## Integrated repaired authoring base

The later clean executable source `349c0832cf64b1f5af66b1a9a19d341aaa473799`, tree `3bcf3e0f736650a55d81cad8b15c33dc0660a7aa`, combines this foundation and its registry with all three PR #104 repairs. The same fixed 20-file operator command passed **249 reported cases, 213 executed, 2,485 assertions, 36 exact MySQL-only skips and zero failures/errors**. Fresh full discovery contained 2,522 cases / 159 files; all 77 reviewed skip methods were discovered and expanded to exactly 185 cases. The unchanged strict receipt parser verified every focused result and all 36 skip identities against fresh focused discovery, including all seven manifest concurrency cases.

Original `focused-ci-evidence.json` SHA-256 is `045d79751508b3a2b96d27e97d9f32ed257cc081cd4f1bc6c4fff30accca42de`; original `focused-ci-results.xml` SHA-256 is `d9e7ce2ee76009eb853042aba4ec5299bcd86f8caf559a15f05b54e0b32a2dcc`; `/tmp/vasey-manifest-integrated-operator.log` SHA-256 is `eb4b7543bb16687f98e1b9ba0f77f5b11392bf89d74ea380f3bc24eed54bbce7`. Subsequent documentation changes do not change those executable bytes. No native browser or MySQL execution is claimed for this source; all seven new concurrency cases still require actual hosted MySQL execution before acceptance.

## Remaining boundary

Supported MySQL Repeatable Read provides the ordinary consistent child view only after fresh actor/track locking reads; the service does not set isolation. No source/run lock is taken after the track. Physical-byte availability retains existing `MediaIntegrity` behavior and its 60-second cache, so this is not an unconditional fresh file-digest proof. Independent exclusive-scope writers can change eligibility after capture. [The architecture note](../architecture/T12-PUBLICATION-EVIDENCE-01.md) records those limits and the next apply dependency.

No manifest persistence, reviewed compare/apply command, atomic publication fence, schedule record/job, UI, controller, console command, migration or counter change is supplied. Track scheduling still needs owner inputs for lead time, horizon, precision, grace and pending-schedule disposition after manual publication. D-24's approved site-release limits do not apply to tracks. Full integrating acceptance must use the final combined source and preserve all existing verification gates before this foundation is marked accepted.
