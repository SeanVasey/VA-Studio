# Typed license usage terms

This WP-04 increment supplies a bounded, versioned usage-policy model. It does not supply actual seller terms or a complete buyer contract. No cap or permission is preselected for new admin drafts. Production policy and independent review remain U-05.

## Operator workflow

1. Create a license draft under its template. Choose exact deliverables, every usage mode and permission, and the producer-credit requirement. A limited mode needs a positive whole-number cap; unlimited/prohibited modes carry no cap. Credit text is required only when credit is required.
2. Write the actual source and include each variable shown beside the fields, plus `{{deliverables}}`. Each variable substitutes the complete generated statement, including its label. The same statement becomes a license-card summary. These variables are literal substitutions; PHP, Blade, expressions and buyer fields are unsupported.
3. Save and open **Preview**. Compare original source, substituted text, generated cards and the variable table. Correct the surrounding prose if it conflicts with the typed statements. Mechanical consistency does not interpret legal meaning or establish clearance.
4. Request review, obtain a separate authorized review bound to this exact preview/hash, and publish through the existing lifecycle. Every author/editor is excluded from approving their contribution. Advertised offers still require their own explicit commercial publication.

Use **New revision** to retain a predecessor's schema and content in a successor. For a v1 license, **Define usage rights** opens a deliberate mapping form with its retained source and delivery roles. Enter every new choice and variable; no values are inferred from old feature prose. Saving creates a linked draft requiring new review. The original remains unchanged.

## Version 2 vocabulary

| Field / source variable | Explicit supported values |
| --- | --- |
| `usage.audio_releases` | Prohibited, limited by count, or unlimited |
| `usage.copies_downloads` | Prohibited, limited by combined copy/download count, or unlimited |
| `usage.monetized_streams` | Prohibited, limited by count, or unlimited |
| `usage.non_monetized_streams` | Prohibited, limited by count, or unlimited |
| `usage.music_videos` | Prohibited, limited by count, or unlimited |
| `usage.live_performances` | Prohibited, limited by count, or unlimited |
| `usage.radio_stations` | Prohibited, limited by station count, or unlimited |
| `permissions.content_id` | Permitted or prohibited Content ID registration |
| `permissions.paid_advertising` | Permitted or prohibited |
| `permissions.sublicensing` | Permitted or prohibited |
| `permissions.standalone_resale` | Permitted or prohibited |
| `credit` | Required with an approved plain-text credit, or not required |
| `deliverables` | Generated from exact selected MP3, WAV master and/or stems ZIP roles |

Wrap each listed variable in double braces, for example `{{usage.monetized_streams}}`. All thirteen are required in the source. Repeated occurrences use the same value. Unknown, malformed, whitespace-altered or missing variables are rejected. Surrounding source stays literal and the HTML preview escapes both source and credit text; rendering has no remote fetch or template execution.

The root contains exactly `schema_version: 2`, `required_asset_roles`, `usage`, `permissions`, and `credit`. Each limited usage value is `{mode: "limited", limit: INTEGER}` with a cap of 1–2,147,483,647; other modes contain only `mode`. Permissions are enum strings. Credit is `{mode: "required", text: STRING}` (up to 120 characters) or `{mode: "not_required"}`. Unknown keys, floating-point/string/boolean caps, contradictory unused caps, unapproved role names and editable `features` are rejected by the domain. The admin form validates a whole-number input before converting its numeric string to an integer.

This vocabulary is deliberately limited. Territory, license duration, ownership, publishing, royalties, sample/clearance duties, renewal/overage/termination and versioned policy references remain explicit follow-up work. Availability dates do not represent license duration. Do not squeeze an unsupported condition into an unrelated field or claim this model represents every legal term. Buyer/order/seller variables and actual contract generation belong to WP-08.

## Compatibility and rollout

Apply `2026_09_10_000010_typed_license_terms.php` with the matching application release. It creates the successor state guard before dropping the previous guard, so an intermediate step does not remove lifecycle enforcement. All other SQL content/review/delete guards remain installed. There is no data backfill or schema reinterpretation.

The application retains literal v1 validation and preview output. Submission records the payload's schema; SQL accepts only schema 1/renderer v1 or schema 2/renderer v2. Changing a current authoring default cannot invalidate historical review evidence. Source/model/render hashes, offers and provisional quote snapshots remain immutable. The v2 renderer is separately pinned as `vasey-license-review-html-v2`.

Retain v2-aware validators, renderer and guard for retained v2 licenses. A rollback to v1-only application code makes those licenses unavailable. Restore a compatible release or deactivate affected offers while keeping their evidence; never rewrite published terms or snapshots. Migration down restores the old transition guard without deleting records and is appropriate only for disposable or verified-compatible environments. If deployment is interrupted between guard creation and removal, keep both guards in place while inspecting the migration state; do not remove the sole active lifecycle guard.

Synthetic examples and test values live only in test fixtures. The [verification record](verification/typed-licensing.md) and integrating PR distinguish executed checks from production acceptance.
