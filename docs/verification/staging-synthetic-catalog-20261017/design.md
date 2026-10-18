# Design: labelled synthetic staging rehearsal catalog

Lane C1, 2026-10-17. Sean approved the work that day: "build the labelled synthetic staging catalog".

## Purpose and boundary

Testers need to rehearse the hosted staging journey before Sean's 3–10 real tracks (input S-7) arrive:

- catalog and player;
- quote;
- Stripe test checkout and verified payment;
- contract PDF;
- download.

`AGENTS.md` still governs, so four of its rules shape the design:

- "Empty catalog is safer than fabricated catalog data."
- "No invented product prices or license terms."
- "Development fixtures must be isolated from production."
- "Never report a scaffold or mock as implemented commerce."

The design answers them this way. The content cannot be mistaken for real content. The command is off by default,
runs only in `local`, `testing` or `staging`, and cannot run in production. Every row goes through the real domain
services, so the rehearsal exercises the real commerce chain rather than a mock of it.

## Shape

| Piece | File | Role |
| --- | --- | --- |
| Command | `app/Console/Commands/StagingSyntheticCatalog.php` | `vasey:staging-synthetic-catalog --operator= --reviewer= [--check]`. Runs admission, takes a cache lock, calls the builder, prints the result. |
| Policy | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalPolicy.php` | Every admission check, all before the first write. |
| Definition | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalCatalog.php` | The fixed labelled catalog: six tracks, two licenses, marker and label constants. |
| Builder | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalBuilder.php` | Calls the domain services in admin order, with resume and idempotence. |
| Media | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalMedia.php` | Generates the WAV master (ffmpeg) and the PNG artwork (GD) in a private workspace. |
| Refusal | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalRefusal.php` | Carries a refusal message. Messages contain no secrets or paths. |
| Flag | `config/staging-synthetic-catalog.php` | `enabled` ← `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED`, default `false`. |

No migration, schema change, route, Filament page or job was added. No existing service, guard or policy was edited.

## Admission (all before any write)

1. **Production first, unconditionally.** `app()->environment('production')` refuses even if the flag is on and
   Stripe is in test mode.
2. **Environment.** `TestEnvironment::admitsTestCommerce()` admits exactly `local`, `testing` and `staging`. Lookalike
   names (`Staging`, `staging-eu`, `development`, `prod`, empty) are refused.
3. **Flag.** `config('staging-synthetic-catalog.enabled') === true`. It is read through a new small config file, so
   no shared config file was edited. A string `"true"` or `1` set directly in config is refused. `env()` turns the
   `.env` value `true` into a boolean.
4. **Stripe test mode.** The same configuration-shape rule as the installation report's staging check:
   - `STRIPE_MODE=test`;
   - an `sk_test_` secret or none (restricted `rk_` and live keys are refused);
   - no `PRODUCTION_CHECKOUT_FUNDS_MODE=live` and no live production-checkout key.

   No provider is contacted.
5. **Operators.** `--operator` and `--reviewer` name existing staff accounts, created with `vasey:create-admin` as
   the staging runbook already requires. Each account must:
   - have a name starting with `Synthetic `, so writes can never be attributed to Sean's or another real account;
   - pass the `administer-catalog` gate (administrator with a verified email);
   - satisfy `AdminMultiFactor::satisfiedBy()`, the panel's own enrollment rule. On staging that means an enrolled
     authenticator.

   The two accounts must differ, because license approval refuses the author.
6. **Media prerequisites.**
   - ffmpeg, ffprobe and prlimit are executable, and PHP GD is loaded.
   - The configured seller tag exists in private storage and matches `MEDIA_TAG_SHA256`.
7. **Lock.** A cache lock refuses a concurrent run before it writes.

`--check` runs steps 1–6 and prints the plan without writing.

## Writes: only through the existing services

For each license (`synthetic-rehearsal-mp3-license`, `synthetic-rehearsal-wav-license`), the services run in this
order:

1. `SaveLicenseTemplate::create`;
2. `CreateLicenseDraft::handle` (v1 terms: features and required roles);
3. `ReviewLicense::submit` by the operator;
4. `ReviewLicense::approve` by the reviewer, with the exact submission hash;
5. `PublishLicense::handle`.

For each track (`synthetic-rehearsal-01` … `-06`):

1. `SaveTrackMetadata::handle`.
2. `SaveRightsDeclaration::create`, then `VerifyRightsDeclaration::review` and `verifyReviewed`.
3. For each source (the WAV master and the PNG artwork):
   - generate the file into a `PrivateMediaFiles::workspace()`;
   - `IngestMediaUpload::handle`, which copies it to quarantine, hashes it and records the audit;
   - remove the workspace;
   - `QueueMediaProcessing::handle`, then `MediaProcessor::handle`.
4. For each license: `SaveOfferDraft::handle`, then `PublishOffer::handle`. The deliverables are the exact outputs of
   the current preview's processing run.
5. `PublishTrack::reviewManifest`, then `publishManifestReviewed`. This is the reviewed path: it re-hashes every file
   and binds the manifest hash into the publication audit.

Each service still runs its own checks: fresh locked actor, catalog gate, panel MFA rule, rights evidence, media
integrity and scan, license review evidence, offer readiness and publication readiness. The builder writes no row
directly. A test asserts the exact audit census: 94 domain audits, attributed only to the operator, or to the
reviewer for approvals alone.

**Media processing in-process.** `QueueMediaProcessing` dispatches `ProcessMedia` after commit, exactly as the admin
does. The command then calls `MediaProcessor::handle($runId)`, the service the job calls. Its claim token makes the
command and a media worker mutually exclusive: whichever claims the run first processes it. If a worker holds the
claim, the command polls the run's status for up to `media.claim_lease_seconds`. This keeps the command
self-contained on a host without a running worker. It also works with the staging media worker, because both
processes use the same configuration.

**Generated media.**

- Masters are 30–60 s, 44.1 kHz, 16-bit stereo PCM WAV, made from ffmpeg's `sine`, `aevalsrc` (chords, tremolo) and
  seeded `anoisesrc` (pink and brown noise) sources. They are bit-exact, so the same definition gives the same bytes.
- Artwork is a 600×600 PNG drawn with GD's built-in font: diagonal stripes in a plain colour and the text
  "SYNTHETIC TEST / NOT FOR SALE / STAGING ONLY / TRACK NN". No font file, brand asset, logo geometry or theme token
  is used.
- Nothing is read from the repository or the network, and nothing generated is committed.

**Seller tag.** The pipeline has no tag-less path: master processing fails `tag_not_configured`, and publication
requires `preview_tagged`. The task allowed generating a synthetic tag, but the design does not:

- the tag is host-wide configuration, so it would tag every master processed while set, not just synthetic ones;
- `config/media.php` states that no synthetic seller tag exists outside the test suite;
- a runtime config override in the command would make the command's profile fingerprint differ from the worker's,
  and a worker that claimed the run first would fail it with `profile_changed`.

So the command requires the configured tag, refuses early without it, and never generates, replaces or overrides one.
If S-6 has not arrived, Sean decides whether a synthetic tag may be configured on staging.

## Labelling and marker

| Row | Label |
| --- | --- |
| Track | Title `[SYNTHETIC] Rehearsal tone NN — …`. Artist and genre "Synthetic Test Content". Mood "Synthetic rehearsal". Description starts with the label. Tags `synthetic-rehearsal`, `not-for-sale`, `staging-test-only`. Slug `synthetic-rehearsal-NN`. |
| Rights declaration | Provenance `SYNTHETIC-REHEARSAL-NN: generated at run time …`. Sample disclosure starts with the label. |
| License template | Name `[SYNTHETIC] TEST PRICE MP3/WAV license — Synthetic rehearsal content — not for sale; staging test only`. Slug `synthetic-rehearsal-mp3-license` or `synthetic-rehearsal-wav-license`. |
| License version | First feature is the label. The source says it grants no rights and that the price is a TEST PRICE. Approval reference `SYNTHETIC-REHEARSAL-NOT-FOR-SALE`. |
| Offer | 111 or 222 integer USD cents (1.11 and 2.22, obviously test values). The license name in each frozen snapshot carries "TEST PRICE" and the label. |
| Media sources | Original names `synthetic-rehearsal-NN-master.wav` and `synthetic-rehearsal-NN-artwork.png`. |

The single marker is `synthetic-rehearsal`. Tracks and license templates carry it as a slug prefix; offers, revisions,
rights and media inherit it through their track or license. That is enough to audit every synthetic row without a
schema change.

## Idempotence, resume and refusals

Each step first looks for the row a previous run left:

- **Published synthetic track.** Reported `already present` if its metadata still matches the definition and it
  passes readiness. Otherwise it is reported `present, not ready: <blockers>`, or `present, metadata differs from the
  definition; left unchanged` after a staff edit (for example one that removed `[SYNTHETIC]`). Nothing is written or
  reverted.
- **Withdrawn track** (`published_slug` set, status `draft`). Reported `withdrawn by staff; left unpublished`. It is
  never republished, so the ordinary unpublish action stays a reliable way to hide synthetic content.
- **Draft synthetic track whose metadata matches the definition.** Resumed. Verified rights, available media
  derivatives and active offers are reused; only missing steps run (`resumed and published`).
- **License.** A published, available version whose source and terms match is reused. A draft, in-review or approved
  version is carried through the rest of its lifecycle.
- **Offer.** An active offer for the track and license version is reused; its price must match. An inactive draft is
  reused rather than duplicated.
- **Mismatch.** A marked row whose metadata, provenance, license content or price differs from the definition is
  refused and left unchanged.

The command never creates quotes, orders, payments, grants or contracts, so a re-run cannot duplicate a grant or a
charge. Tests assert those tables stay empty and that a second run leaves every table's row count unchanged.

## Removal story

There is deliberately no purge. Published licenses, offer revisions, order snapshots, contracts and audit rows are
immutable evidence. Two ways out are documented in `docs/ops/staging-runbook.md` section 5a:

- **Hide:** unpublish the synthetic tracks with the ordinary staff action. Re-runs keep them hidden.
- **Replace before real content:** reset staging to an empty schema and fresh private storage. This reuses the
  runbook's existing schema-recreate and private-storage move-aside steps, followed by migrations, the seller tag,
  staff accounts with MFA, and the flag set back to `false`.

## Deliberate limits

- **Rights scopes.** The command does not link scopes. `vasey:rights-scope` requires a password-confirmed,
  single-use attestation for each write on staging, and duplicating that writer would weaken its design. The command
  prints the follow-up, and the runbook gives the convention: one scope per synthetic track, keys starting
  `synthetic-rehearsal-`.
- **Console MFA.** A console has no MFA challenge. Like every staff-attributed console write (see
  `StaffWriteAuthority`), the command relies on the accounts' current enrollment, and shell access to the host stays
  the controlling credential.
- **Stems.** Stems ZIPs and the stems license role are not seeded. Both licenses deliver `download_mp3`, and the WAV
  license also delivers `master_wav`.
- **Environment templates.** `.env.example` and `ops/production/env.production.example` document the new key
  (default `false`). `tests/Unit/ProductionEnvironmentTemplateTest.php` requires every `config/` env key to appear in
  an ops template, and fails without that entry. The staging template `ops/staging/env.staging.example` belongs to
  lane O1 and was not edited.
