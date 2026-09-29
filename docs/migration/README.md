# Migration and parity control records

Baseline prepared 2026-09-04 from all three owner-provided attachments, public VASEY.AUDIO text, and current official documentation. This folder is a planning baseline for phased implementation. It does not report an authenticated account audit, imported catalog, completed payment integration, or production cutover.

| File | Purpose |
| --- | --- |
| `source_ledger.csv` | Attachment hashes, public source URLs, dates, methods, verified scope and limitations |
| `research_summary.md` | Reconciliation, corrected assumptions and architectural consequences |
| `feature_parity_matrix.csv` | 103 requirements with stage, cutover condition, target behavior and acceptance evidence |
| `migration_field_map.csv` | 50 source classes with acquisition, transform, validation and rollback obligations |
| `known_unknowns_and_validation.csv` | 27 unresolved account, rights, operational and brand facts |
| `authenticated_studio_audit_checklist.csv` | 14 read-only audit areas; all remain unperformed |
| `legacy_route_inventory.csv` | 20 proposed legacy route mappings requiring actual browser/catalog verification |
| `cutover_runbook.md` | Acquisition, dry-run, reconciliation, launch gates and rollback procedure |

## Reading the records

- `Decision`: explicit owner instruction, currently the repository/build/brand scope.
- `Verified`: the narrow source observation documented in the ledger; never implied private account access. Attachment possession and hashes are verified, while most claims inside their research remain secondary evidence.
- `Inference`: a proposed benchmark inferred from attachment research without independent current primary confirmation.
- `Recommendation`: target behavior or implementation choice pending the project decision process.
- `Unresolved`: absent, stale, conflicting or insufficient evidence. A dependent production gate can be blocked while development continues.

The feature matrix's `evidence_status` concerns the benchmark/source claim. `target_evidence_status` makes clear that the detailed implementation behavior remains proposed. `status=planned` is a requirements baseline, not a claim that repository implementation has not begun. Update it only with an implementation reference and actual acceptance evidence. Source and mapping identifiers remain stable across updates.

`foundation` and `store_vertical_slice` sequence the first coherent implementation: upload, publish, preview, license, checkout, contract and secure delivery. `full_replacement` retains every additional in-scope site function before declaring complete replacement. `enhancement` is genuinely additive postlaunch work. A first vertical slice is not permission to switch the live domain. Memberships, services and merch cannot lose active customer obligations during a transition; audited absence or an approved continuity plan is required if any such module is deferred from a rollout.

All imported material requires source identity, acquired-at time, raw hash or value, transform version, target identity, batch and reconciliation outcome. Values such as `not_acquired` and blank resolution fields are intentional. No customer list, executed contract, original attachment binary, provider credential or raw account export belongs in git. Acquire those through approved private storage and reference them by opaque identifier and hash.

The route file contains proposals, not deployed redirects. Preserve paths when useful and avoid redirecting authenticated routes into public pages. BeatStars-owned hostnames cannot be redirected without verified control.


## Editorial implementation checkpoint — September 29, 2026

[D-23](../architecture/D-23-editorial-content.md) and the [editorial guide](../editorial-content.md) describe a bounded implementation candidate. The integrating PR and WP-09 issue #9 record acceptance against tested source. The source observations, `planned` baseline rows and acquisition fields in these ledgers are not completion evidence and are not converted to verified migration by this code.

| Stable requirement | Implemented contract | Still required for full acceptance |
| --- | --- | --- |
| FP-011 — Blog and updates | Plain-text entries with private complete-release drafts, published listing/detail routes and rollback. | Source slug/date acquisition, homepage feature policy and broader editorial/embedding acceptance. |
| FP-012 — Video gallery and related video | Typed YouTube/Vimeo IDs and curated provider watch links in published listing/detail routes. | Consent-aware embeds, related-track links and verified media/source rights. |
| FP-013 — About, credits and contact | Persisted about/contact copy and an encoded public mail link. | Owner-reviewed production copy, real inquiry delivery and spam protection. |
| FP-014 — Search/social metadata | Server-derived canonical/social route metadata for active editorial pages. | Complete sitemap/structured-data coverage and migration/canonical reconciliation. |
| M-041 — Blog/video/about/contact source | Target schema and publication workflow exist; no import runs. | Authorized original content, source identities/slugs/dates, raw hashes, fresh-content decisions and reconciliation. |
| URL-010 — Legacy `/blog` | Current implementation uses `/blog`; its detail paths reflect active entries. | The older `/updates` target remains an unimplemented proposal; actual legacy routes and redirects still need verification and approval. |

No seller original, customer record, external media, inbox or legacy redirect is acquired or activated by this increment. Keep unknown source facts unresolved and preserve IDs when later updating these rows with actual acceptance evidence.
