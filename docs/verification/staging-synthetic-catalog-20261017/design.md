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

The design answers them this way.

- The content cannot be mistaken for real content.
- The command is off by default and admits only `local`, `testing` or `staging`.
- It refuses when the configured or the running environment is production, or when the two differ.
- It refuses on a catalog that already holds non-synthetic published tracks or approved licenses.

These are configuration and catalog checks, not proof of host identity. Running it on a production host would take
a production host misconfigured as staging, with an empty catalog. Every row goes through the real domain services,
so the rehearsal exercises the real commerce chain rather than a mock of it.

## Shape

| Piece | File | Role |
| --- | --- | --- |
| Command | `app/Console/Commands/StagingSyntheticCatalog.php` | `vasey:staging-synthetic-catalog --operator= --reviewer= [--attest] [--check]`. Runs admission, confirms both passwords, records attestations where required, takes a cache lock, calls the builder, prints the result. |
| Policy | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalPolicy.php` | Every admission check, all before the first write. |
| Definition | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalCatalog.php` | The fixed labelled catalog: six tracks, two licenses, marker and label constants. |
| Builder | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalBuilder.php` | Calls the domain services in admin order, with resume and idempotence. |
| Media | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalMedia.php` | Generates the WAV master (ffmpeg) and the PNG artwork (GD) in a private workspace. |
| Refusal | `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalRefusal.php` | Carries a refusal message. Messages contain no secrets or paths. |
| Flag | `config/staging-synthetic-catalog.php` | `enabled` ← `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED`, default `false`. |

No migration, schema change, route, Filament page or job was added. No existing service, guard or policy was edited.

## Admission (all before any write)

1. **Production first.** The command refuses if `config('app.env')` is `production`. That is the configured
   environment, which a cached configuration keeps. It also refuses if `app()->environment()`, the running
   environment, is `production`. The flag and Stripe mode make no difference.
2. **Agreement.** The command refuses if `config('app.env')` and `app()->environment()` differ. Artisan's `--env=`
   switch changes only the running environment, so `APP_ENV=production … --env=staging` is refused at step 1, and
   `APP_ENV=local … --env=staging` here. Review finding P2-1.
3. **Environment.** `TestEnvironment::admitsTestCommerce()` admits exactly `local`, `testing` and `staging`. Lookalike
   names (`Staging`, `staging-eu`, `development`, `prod`, empty) are refused.
4. **Flag.** `config('staging-synthetic-catalog.enabled') === true`. It is read through a new small config file, so
   no shared config file was edited. A string `"true"` or `1` set directly in config is refused. `env()` turns the
   `.env` value `true` into a boolean.
5. **Stripe test mode.** The same configuration-shape rule as the installation report's staging check:
   - `STRIPE_MODE=test`;
   - an `sk_test_` secret or none (restricted `rk_` and live keys are refused);
   - no `PRODUCTION_CHECKOUT_FUNDS_MODE=live` and no live production-checkout key.

   No provider is contacted.
6. **Synthetic-only catalog.** Two bounded existence reads against the exact definition slugs:
   - any track that is published, or was published (its URL is reserved), outside the six synthetic slugs;
   - any approved or published license version whose template is not one of the two synthetic templates.

   Either one refuses. Exact `whereNotIn` lists use no pattern matching, so collation and `LIKE` semantics play no
   part. A real draft that was never published does not block.
7. **Operators.** `--operator` and `--reviewer` name existing staff accounts, created with `vasey:create-admin` as
   the staging runbook already requires. Each account must:
   - have a name starting with `Synthetic `, so writes can never be attributed to Sean's or another real account;
   - pass the `administer-catalog` gate (administrator with a verified email);
   - satisfy `AdminMultiFactor::satisfiedBy()`, the panel's own enrollment rule. On staging that means an enrolled
     authenticator.

   The two accounts must differ, because license approval refuses the author.
8. **Media prerequisites.**
   - ffmpeg, ffprobe and prlimit are executable, and PHP GD is loaded.
   - The configured seller tag exists in private storage and matches `MEDIA_TAG_SHA256`.
9. **Staff identity (writes only).** Writes require an interactive console. Following `vasey:rights-scope`, the
   command confirms each account's password at a hidden prompt with no visible fallback, against the current
   password hash and catalog authority. Where the panel requires staff MFA (staging), the run also needs
   `--attest`. It then records one append-only `staff.console_attestation` per account through
   `StaffWriteAuthority::attest()`, recording:
   - the command;
   - the role (`synthetic_catalog.operator` or `synthetic_catalog.reviewer`);
   - both account IDs, in the scope key;
   - a keyed hash of a per-run reference;
   - whether the panel rule was required and the account enrolled.

   Both records are written before the first catalog write. No writer consumes them: they are audit evidence, and
   every domain service still checks each account's current enrollment. Review finding P2-2.
10. **Lock.** A cache lock refuses a concurrent run, after the passwords and before any attestation or write.

`--check` runs steps 1–8, prints the plan and writes nothing. It asks for no password and refuses `--attest`.

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

- **Published synthetic track.** Reported `already present` when three things hold:
  - its metadata still matches the definition;
  - its active offers are exactly the two TEST PRICE offers, at their defined prices;
  - it passes readiness.

  Otherwise it is reported in one of three ways:
  - `present, metadata differs from the definition; left unchanged`, after a staff edit (for example one that
    removed `[SYNTHETIC]`);
  - `present, offers differ from the definition; left unchanged`;
  - `present, not ready: <blockers>`.

  Nothing is written or reverted.
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

- **Rights scopes.** The command does not link scopes. Each link is a separate `vasey:rights-scope` write: its own
  password confirmation, its own single-use attestation that the writer consumes, and an operator's decision about
  which scope a revision belongs to. Duplicating that writer here would weaken its design. The command
  prints the follow-up, and the runbook gives the convention: one scope per synthetic track, keys starting
  `synthetic-rehearsal-`.
- **Console identity.** A console has no MFA challenge. The command confirms both passwords and, where MFA is
  required, records an attestation per account. It also relies on each account's current enrollment, which every
  domain service checks. One shell still drives both accounts, so the two-person rule for license approval is
  enforced between accounts, not between people. Shell access plus the two passwords stays the controlling
  credential.
- **Stems.** Stems ZIPs and the stems license role are not seeded. Both licenses deliver `download_mp3`, and the WAV
  license also delivers `master_wav`.
- **Environment templates.** `.env.example` and `ops/production/env.production.example` document the new key
  (default `false`). `tests/Unit/ProductionEnvironmentTemplateTest.php` requires every `config/` env key to appear in
  an ops template, and fails without that entry. The staging template `ops/staging/env.staging.example` belongs to
  lane O1 and was not edited.
