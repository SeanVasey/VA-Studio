# Sound-kit license (BL-66)

Status: **built, default-off, not selected for any issuance.** There is no kit storefront or kit checkout yet (FP-007 is
postlaunch by design, DD-05); this is the license kind, its renderer and its pins, ready for when kits are sold. It is part
of the production contract work in [production-contract-profile.md](production-contract-profile.md) and follows Sean's decision
of 2026-11-10 ([decision register](architecture/decision-register.md), "Sean's answers, 2026-11-10").

## Decision: one generic kit license

Sean's current "Sample Pack License Agreement" (revision 2026-09-18, sold with a drum kit) becomes **one generic sound-kit
license**, not one license per kit. The kit name, purchase date, license fee and licensee are filled per purchase. The
alternative, a template per kit, would multiply private texts that must each be legally reviewed and republished whenever
the wording changes, and the facts that differ between kits are all in the placeholders anyway. A kit with genuinely different
terms gets a successor license version (or a second template of the same kind) authored privately, not a code change.

The text is authored privately by staff as a license version, exactly like the six beat templates, and never enters Git. The
repository holds only the template kind, its placeholders, the renderer and the profile pins.

## The kind

`App\Domain\Rights\SoundKitLicenseKind`. A license template of `type` `sound-kit` (the fourth type, beside `non-exclusive`,
`exclusive` and `free`; it is selectable when staff create the template) is **not a beat tier**:

- A beat offer can never carry it. `OfferDraftInput` refuses a kit license version with a `license_version_id` error, `Offer`
  refuses it again on create and on re-pointing (defense in depth), the Filament offer form does not list it, and publication
  already requires a `non-exclusive` (or prepared `exclusive`) template.
- A kit can carry nothing else. `SoundKitLicenseAttachment::assertKitAttachable()` refuses any beat template version. No
  kit offer exists yet, so the guard is the attachment point the future kit offer must call.

## Placeholders

A kit license text declares **exactly** these six `{NAME}` placeholders, each at least once. An unknown name (a beat
placeholder such as `{CONTRACT_DATE}` included), a missing one or a stray brace refuses. Nothing is substituted or defaulted
silently, and a value that contains braces is refused, so a buyer-supplied name can never look like a placeholder.

| Placeholder | Value | Source |
| --- | --- | --- |
| `KIT_NAME` | the kit or pack as titled on the store | the kit, at purchase |
| `PURCHASE_DATE` | UTC calendar date, `November 7, 2026` | the purchase instant (`Y-m-d\TH:i:s\Z`) |
| `LICENSE_FEE` | `$25.00`, from integer minor units and an explicit currency (USD only, as for beat contracts) | the paid amount |
| `LICENSEE_NAME` | the licensee's confirmed legal name | the purchase |
| `ORDER_REFERENCE` | the store's order reference | the order |
| `LICENSOR_NAME` | the licensor's display name | configuration: `VASEY_KIT_LICENSE_LICENSOR_NAME` (`contracts.vasey_kit_license.licensor_name`) |

`SoundKitLicenseBinder::bind()` is pure and validates the shape of each value (printable in the profile's faces, bounded,
no controls or braces). `LICENSE_FEE` prints its own currency symbol, so a text that writes `$`, a currency word or code
before it, or `dollars` after it, refuses as `doubled_symbol` (`USD` after the figure is accepted, as for the beat contracts).

## Rendering

Profile **`vasey-kit-license-pdf-v1`** (`VaseyKitLicenseProfile`, `VaseyKitLicensePdfRenderer`, `SoundKitLicenseDocument`) is a new
profile beside `vasey-license-pdf-v1` and `-v2`, which are untouched and stay byte-identical (their `--check` scripts and
`ProductionFreeGrantFrozenBytesTest` are unchanged). It reuses, without modification, the same visual system as the generated
license contracts: the v2 faces, geometry, colors and sizes, the header band with the unmodified lockup, the block grammar
(title, uppercase headings, justified paragraphs), the PRODUCER / LICENSOR and LICENSEE acceptance block with Sean's signature
and the date under both, and the document identifier footer. There is no business-representative line (a kit license has no
postal address or representative; the licensee is the confirmed legal name).

- **Signature:** the same private PNG as every contract, read only from the configured private path
  (`VASEY_LICENSE_SIGNATURE_PATH`, relative to `storage/app/private`) and pinned by `VASEY_LICENSE_SIGNATURE_SHA256`. A missing,
  public, linked or changed file refuses. The signature pin is part of the recorded profile identity.
- **Pins:** `resources/contracts/vasey-kit-license-v1/profile-assets.json` pins the 26 font and logo files of the v2 profile
  (shared by hash and size, none copied), the tc-lib package graph and the implementation closure of the kit renderer.
  `VaseyKitLicenseProfile::MANIFEST_SHA256` pins the manifest. `php scripts/build-contract-profile-vasey-kit-license-v1.php --check`
  rebuilds it and compares byte for byte (it runs in the preflight and final-verification workflows beside the v1 and v2 checks).
  The golden render hash is `tests/Fixtures/vasey-kit-license/golden.json`.
- **Re-pinning:** when a tc-lib update (BL-55) or any reviewed change to a pinned file re-pins v1 and v2, the kit profile
  needs one more step, and it is one command: `bash scripts/repin-vasey-kit-license-v1.sh` (after the v1 and v2 `--check`
  scripts pass). It rebuilds the manifest, rewrites `MANIFEST_SHA256` and re-renders the synthetic golden. Review the diff.
- **Not wired:** no issuer, dispatch or policy names the profile (a test checks this), and it renders in-process only; the
  isolated-child path that issued v2 originals use would be added with the kit checkout.

## What Sean authors privately

1. In the staff license-template form, create a template of type **Sound kit license (not a beat tier)**.
2. From the "Sample Pack License Agreement" PDF, write the contract text as plain text using the layout grammar of
   `vasey-license-text-v1`: the first block is the title, a one-line block of uppercase words is a heading, every other
   block is a paragraph, blocks are separated by blank lines. Replace the facts that vary per purchase with the six
   placeholders above (the kit name, the purchase date, the fee, the licensee, the order reference and the licensor), and
   use each at least once. The producer-clearance clause, cure period, general terms and notice stay as the text.
3. Keep it out of Git: the text lives in the license version (and the private contract-text store), like the six templates.
   Run `SoundKitLicenseKind::templateProblems()` on it (or render it locally from private paths) before review; counsel review
   applies as for the six templates.
4. The venue named in the supplied kit license (the Fifth Judicial District of Polk County, Iowa) is for Sean to confirm as
   the venue for all VASEY.AUDIO terms (decision register).

## Tests

`tests/Unit/VaseyKitLicensePdfRendererTest.php` (all text is `[SYNTHETIC] TEST KIT LICENSE`, written for the test, none of
Sean's wording): placeholders bind, unknown and missing placeholders refuse, doubled symbols refuse, the PDF carries the shared
header, acceptance block and footer, the signature is read only from the configured private path and its pin, the pins and
tamper refusals, determinism and the golden hash. `tests/Feature/SoundKitLicenseKindTest.php`: the kit type, beat-offer and
kit cross-attachment refusals.
