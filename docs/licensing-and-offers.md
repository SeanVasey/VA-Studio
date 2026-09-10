> Typed usage terms are now available for new drafts. See [typed license terms](typed-license-terms.md) for the current editor, required variables, explicit legacy mapping and compatible migration. The v1 workflow below remains supported for retained revisions; it is not silently upgraded.

# Licensing and published offers

Status: first exact-review and commercial-revision increment. This guide describes the implemented workflow and the operator steps needed to use it. Test results belong to the integrating PR and verification record. Production legal policy, buyer-specific contracts, quotes, orders and payment processing remain separate work; checkout returns an unavailable response.

## Records and authority

| Record | What it establishes | What stays separate |
| --- | --- | --- |
| License draft | Editable source text, summary/role schema and optional effective window | No approval or saleable rights |
| Submitted license version | Frozen server-generated payload, canonical SHA-256 and deterministic review preview | Human review outcome |
| Review evidence | A different authorized reviewer attested to the exact submission and recorded a review reference | Legal qualification, enforceability and seller policy approval |
| Published license version | Reviewed version available within its effective UTC window | Product price, media revisions and actual purchases |
| Offer draft | Proposed track, license, integer price/currency and deliverable IDs | The published offer remains unchanged until explicit publication |
| Published offer revision | Immutable commercial snapshot with exact license/review/rights/media evidence | Buyer quote, paid order, contract, entitlement or grant |

The license preview is escaped, deterministic HTML generated offline. It shows the authored source literally, feature summaries, required delivery roles and effective dates. It performs no variable substitution or legal interpretation and is explicitly marked nonbinding. Its renderer/version and byte hash make the submitted preview reproducible; it is not a buyer PDF contract.

Schema version 1 requires exactly `schema_version`, `features` and `required_asset_roles`. The schema version is integer `1`; feature summaries are 1–20 distinct plain strings, each up to 240 characters; required roles are a distinct nonempty selection from `download_mp3`, `master_wav` and `stems_zip`. The schema rejects extra keys and unsupported roles, but it does not encode every license limit or prove that a sentence matches the authored legal source. Review source, summaries and deliverables together. Do not invent terms, prices, caps or approval references to clear a validation error.

The source accepts plain UTF-8 text up to 100,000 bytes. HTML/script-like input is displayed as literal text in the preview. Template identity is included in the review; templates with submitted/reviewed versions cannot be casually renamed or repurposed after submission.

## Review and publish a license

1. Create or choose the intended license template, then create a license version. The server assigns its next version number. Enter the actual source, feature summaries, required deliverables and any effective dates. The admin supplies the supported schema version.
2. Save the draft and open **Preview**. Inspect source, summaries, roles and dates together. For a successor, use **Compare changes** to inspect differences from its predecessor. The comparison reports changed content fields; it is not a legal analysis.
3. Choose **Request review** when the content is ready. The server freezes a submission payload containing version/template identity, author/predecessor, source and structured terms, effective dates, source/model hashes, canonicalization version and preview evidence. Further content edits require a new revision.
4. A different authorized account opens the same version's **Preview**, completes the actual review, then selects **Approve**. Supply the completed review evidence reference and affirm that feature summaries match the authored source. The approval submits the displayed SHA-256; the server rejects an absent, stale or different submission hash and rejects approval by any recorded content contributor.
5. Choose **Publish** after approval. The service verifies the submission and immutable review evidence again before publishing. Publication of a license does not publish a track or an offer.

The server retains the original creator and every actual source, summary or date editor as content contributors. Successors inherit contributors from their predecessor. None of those contributors can approve the submission; a separate authorized account must review it. An account separation check does not by itself verify a person's legal qualifications or authority to approve the seller's production policy. Keep the actual review reference meaningful and retrievable through the operator's authorized records.

Dates are normalized to UTC at whole-second precision. A start is inclusive; an end is exclusive. With no start, a reviewed license can become available once published. With no end, there is no scheduled expiration. An end must follow a supplied start. A future or expired version may remain retained and published while failing offer availability. There is no automatic switch of an offer to another license when a date arrives.

For corrections after submission, use **New revision**, edit the resulting successor draft, compare it and repeat review. Published and reviewed predecessors remain retained; creating or publishing a successor does not modify their source, evidence or the offer revisions that reference them. It does not automatically deactivate the predecessor or migrate offers.

## Publish an offer revision

A track needs verified rights, an available published license and real verified media before its offer can be published. See [Media processing](media-processing.md) for ingestion and immutable media revisions.

1. Create an offer or choose **Edit draft**. Select its track and license version, enter the intended positive USD price in integer cents, and select the exact verified deliverable asset IDs. An existing offer cannot be moved to a different track.
2. Match the license's required roles exactly once each. Every selected asset must belong to this track and the same verified source recording as its current tagged preview. A role appearing in a selector does not make its processing path available: stems/ZIP processing is still pending, so a stems promise cannot be published without a supported verified stems revision.
3. Save the draft. The admin shows **Draft price** beside **Published price** and the current revision number. Saving changes to price, license or files does not change the current public commercial revision.
4. Choose **Publish revision** to make the complete draft available. The server rechecks readiness inside the publication transaction, captures its evidence and atomically switches the offer's current revision pointer. Publishing the identical snapshot reuses that revision rather than creating a duplicate.
5. Open **History** to inspect retained revisions, their prices, license versions, asset identities and snapshot hashes. Resolve the track's other readiness blockers and publish the track when ready. Confirm its public license cards and share link.

The current publication boundary permits positive USD non-exclusive offers only. Free/exclusive commerce, other currencies and license upgrades require their later workflows. A published offer is an advertised commercial revision; it does not authorize payment collection or issue rights.

Each immutable snapshot captures the product identity, commercial amount/currency, exact authored license source and structured terms, review identity/hashes, effective dates, rights-declaration identity hash, current preview lineage, and exact deliverable asset IDs/roles/SHA-256/MIME/size. Private storage keys are excluded. The snapshot is server-authored and is not accepted from the browser.

Use **Deactivate** to stop advertising an offer while retaining its current pointer and history. To make it available again, publish a ready draft; if its snapshot is unchanged, the existing revision is reactivated. If the draft has changed, that action publishes a new revision. This is not an arbitrary history rollback control.

Current availability rechecks the frozen evidence. Expired licenses, changed rights declarations, replaced previews or missing/modified deliverable bytes can make an active offer unavailable. An invalid active offer can block its track's public readiness; resolve or deactivate it rather than deleting evidence. A draft edit by itself does not alter the current snapshot.

The storefront cart pins the advertised commercial revision and discards a selection when that revision no longer matches the available offer. It does not silently substitute a changed price, license or recording. This is client-side selection hygiene; a future server quote must independently validate all pricing and rights before checkout.

## Upgrade existing development records

This migration deliberately preserves historical rows without inventing review evidence or commercial history. Before applying it to a retained environment, take the normal database backup and identify records whose availability will change. Apply the additive migrations with the reviewed application release:

```sh
php artisan migrate
```

Then:

1. Inventory license versions without a submission payload/hash and review-evidence row. A previous `approved` or `published` label does not satisfy the new verification contract. Keep those rows as historical evidence; do not fill their missing fields with fabricated hashes or reviewers.
2. Create a successor for each version needed by current offers. Inspect the old source and map it deliberately into the supported summary/role schema. **New revision** supports compatible predecessor content; unsupported legacy terms may require a new draft prepared through the supported service/editor. Do not silently discard a legacy condition to make it fit schema version 1.
3. Submit, independently review and publish the successor. Source, dates and summaries need an actual review even when much of the content was copied.
4. Inventory active offers with no current commercial revision. Their old draft fields remain stored but are not treated as a published snapshot. Edit each intended offer to select the reviewed successor, current verified recording revisions and approved intended price, then choose **Publish revision** explicitly.
5. Resolve or deactivate obsolete active offers, re-run track readiness and verify the public catalog/cart state. No automatic migration grants access, creates orders, executes contracts or imports BeatStars customer rights.

When old content does not satisfy the current schema, Preview displays an escaped retained-source view explaining the mismatch. That view creates no new review evidence. Unsupported old fields still need deliberate conversion and review in a successor; retained source data must not be rewritten. Database guards also reject update/delete operations on protected submitted content, review evidence and offer revisions through ordinary SQL write paths. Production database privileges, backups and migration access still require their separate operational controls.

## Evidence and remaining gates

The integrating PR records the tested commit, runtime/database environments and actual results for exact review binding, author separation, immutable write paths, effective windows, commercial publication, stale cart selections and deliverable verification. No test count or production acceptance is implied by this guide.

The following remain open within WP-04 and its dependent packages:

- Seller identity, approved production license matrix, exact source terms, actual review evidence and rights/refund/exclusive policies under U-05.
- The remaining territory/duration/ownership/royalty/policy-reference fields and contract-specific variables. Typed usage/permission/credit summaries now share source variables; the surrounding prose still requires human review.
- Buyer-specific contract inputs, deterministic rendering, archival/PDF format, rendering isolation and reproducibility evidence under U-06/WP-08.
- Server quotes and frozen purchase snapshots, exclusive reservations, verified payment finalization, grants and entitlements under WP-06–WP-08.
- Historical contract/order reconciliation, customer obligations and migration/cutover acceptance. Immutable new revisions do not by themselves establish continuity of an old purchase.

For operational rollback, deactivate affected offers or unpublish affected tracks while retaining every referenced version/revision. Correct content through a new reviewed successor and new commercial revision. Do not roll back evidence migrations or replace a snapshot in place as a shortcut.
