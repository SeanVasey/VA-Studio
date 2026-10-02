# T12-PUBLICATION-EVIDENCE-01: read-only track publication evidence

October 2, 2026. Implemented internal foundation with focused development feedback; full integrated acceptance remains pending. This is a partial T12 / WP-02 prerequisite for FP-032 track scheduling, not a scheduling policy or a completed parity requirement. [The verification record](../verification/track-publication-manifest-foundation.md) identifies the exact tested source and remaining gates.

## Capture boundary

`ReadTrackPublicationManifest::handle(int $trackId, User $actor): TrackPublicationManifest` captures the current ready track without publishing it or retaining a manifest row. An ambient transaction is rejected before private queries. The new transaction locks the persisted actor, applies the locking catalog Gate and current admin MFA check, then locks the current track. Missing or unauthorized actors never reach the private track lookup.

The service invokes ordinary `PublicationReadiness::blockers` and refuses every blocker. It uses the latest ready artwork and tagged preview by ID; damage to the current revision cannot select an older usable one. Each active offer must have a valid current immutable commercial revision. An additional `VerifiedLicense::available($version, $capturedAt)` check requires all those licenses to be effective at one recorded instant, using the existing inclusive start and exclusive end semantics. This prevents sequential readiness checks from accepting adjacent, nonoverlapping license windows as one capture. The application clock is not changed.

Lock order is actor → track. No source, asset or processing-run locks follow the track lock. Existing media writers use source → track → run, so introducing a reversed lock order here would be unsafe. The capture relies on verified media, completed processing runs, published licenses, commercial revisions and their immutable supporting evidence rather than locking them in reverse order.

## Identity and privacy

The immutable server-only value exposes `payload()`, `hash()`, `actorId()` and `capturedAt()`. Its scalar/array tree is defensively copied; floats and mutable objects cannot enter that tree. It is neither `JsonSerializable` nor `Arrayable`. The capture actor and UTC time are outside the content hash. Equal current evidence can therefore have the same hash when read by different authorized actors or at different times.

Payload schema 1 uses existing `CanonicalJson::VERSION` (`vasey-json-v1`). Object keys are canonicalized by that implementation; offers are ordered by numeric offer ID, deliverables by role then numeric asset ID, and descriptive tag order is retained. Current descriptive metadata, metadata/publication revisions and status are explicit. The latest rights declaration is bound through `OfferSnapshot::rightsHash`. Artwork and preview include exact derivative IDs, byte hashes, sizes, parent/run identities and the verified processing-profile fingerprint. Preview waveform/tag hashes bind the existing verified measurements without exporting their private proof.

`duration_seconds_reference` is the existing `MediaEvidenceValues::reference` result: an encoding identifier and SHA-256. Its `media-values-binary64-v1` representation preserves a finite measured integer or binary64 identity independently of PHP `serialize_precision`. It is an identity reference, not display seconds. The global integer-only commerce canonicalizer remains unchanged.

Each offer includes the immutable revision's hash, frozen commercial values, minimized license/review/effective-date identities and safe deliverable/recording-binding IDs and digests. The complete frozen license snapshot is bound by its identity hash and the immutable offer snapshot hash. Full license text, authored source, terms, private original names, storage paths, source/run dumps and raw `VerifiedMedia` evidence are excluded from the value.

## Currentness limits

The consistent ordinary child-read view described here is the supported MySQL Repeatable Read view begun after current actor and track locking reads. The service does not inherit a caller's earlier snapshot or change transaction isolation. SQLite feature execution is useful behavioral evidence but does not prove MySQL locking or Repeatable Read races.

Activated schema 2 offers preserve current activation/policy identity and explicit scope identity, link, control version, blocked state and exclusive-sale state. Existing test-only activation and inventory requirements remain in force. A successful capture records eligibility at that read point. Scope controls have independent writers that do not lock the track; a returned value cannot prevent a later block or sale. License expiry and later metadata, rights, media or active-offer changes also require a new current check.

File availability uses existing `VerifiedMedia` / `MediaIntegrity` behavior, including its bounded 60-second integrity cache. Cache entries can be populated while reading; the service writes no catalog, publication, audit or scheduling records. The value is not a fresh full-file digest proof for every capture, a signature, authorization token, retained approval or future commit fence.

## Next dependency

Retaining a reviewed manifest, comparing it with current dependencies and applying publication atomically remain separate work. That apply boundary needs a compatible lock/currentness contract for every mutable dependency, including exclusive-scope controls, and honest handling of physical media proof. This increment changes no manual publication API, counter, migration, UI, route, console command, queue or scheduler.

Track scheduling still needs explicit owner decisions for lead time, horizon, precision, grace and the disposition of a pending schedule after manual publication. The site-release limits and supersession choices in [D-24](D-24-scheduled-site-publication.md) are site-only and do not transfer to tracks. Those missing inputs do not prevent this policy-independent read-only foundation; they do prevent inventing a track scheduling contract from it.
