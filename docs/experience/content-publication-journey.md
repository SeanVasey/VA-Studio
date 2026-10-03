# T11: content preparation and reviewed track publication

Source contract prepared 2026-10-03 UTC against `6c55b4637ec858716c40055b2646e69da69230e1`, tree `5859b94e570aefaa1dacd9116b4c27868e5b3155`. This document describes existing controls, the expected experience, and explicit gaps for one content-authoring journey. The source candidate still requires integrated acceptance. This is neither a new interface nor completion of T11, T12, media acceptance, or launch.

## Goal and ownership

Sean or an authorized operator prepares a private track, resolves its actual publication blockers, reviews the current track/files/offers, and explicitly publishes it. A customer receives only the resulting eligible public presentation. Publication does not activate production checkout or prove that a sale can complete.

The publication lane owns this journey. The media lane owns processing and durable storage; the rights/commerce owners supply the reviewed license and offer behavior. Business choices stay in the [decision register](../architecture/decision-register.md); actual content inputs stay in the [onboarding packet](../content-onboarding-readiness.md). This contract creates no seller tenants, helper roles, license terms, prices, or permissions.

## Screen and state map

These are journey states, not a proposed database enum or a new linear wizard. Operators can return to the relevant existing resource; independent preparation can happen in a different order.

| State / existing surface | Operator sees and can do | Successful transition and boundary |
| --- | --- | --- |
| No prepared track — Tracks | Create a private draft or use the existing named metadata presets. Empty catalog remains truthful. | Metadata saves create a draft; no media, rights, or offer readiness is implied. |
| Draft metadata — Tracks / Edit | Title, URL, artist and descriptive fields; measured duration is read-only. Bulk editing has its separate current-page review. | Save through `SaveTrackMetadata`; a stale edit must be reviewed again. The first-published URL remains reserved through later withdrawal. |
| Private upload — Media assets | Select the track and role, then upload a supported original. The upload control exposes no stored-file download or preview. | Intake remains private; the next explicit action is **Process / retry**. Upload completion is not processing completion. |
| Processing pending, completed, or failed — Media assets | Separate asset status and latest processing status, **Processing details**, and applicable retry control. The table polls every five seconds. | Only verified completion supplies usable revisions. **Already processed** and **Processing requested** are distinct outcomes; neither publishes the track. |
| Stems need recording association — Media assets | **Associate recording**, an eligible verified master, a verification note, and explicit same-recording confirmation. | A retained association permits the corresponding licensing checks. Correcting the association requires a new stems revision. |
| Rights and license preparation — Rights declarations / License versions | Pending declaration edit/verification; license draft preview, **Request review**, separate approval evidence, and publication. | The latest declaration must be verified. License approval requires an authorized noncontributor reviewing the exact submission; a software status is not legal clearance. |
| Offer preparation — Offers | **Edit draft**, separate draft/published prices, exact verified deliverables, and **Publish revision**. | Saving an offer draft leaves its current published commercial revision intact. Publishing creates the immutable evidence later checked for track publication. |
| Private inspection — Tracks / **Review track** | Metadata, named publication blockers, available artwork, and the tagged preview. The only modal completion is **Close**. | Inspection preserves publication state. **Ready to publish** is a present readiness result, not a saved approval or a guarantee about a later operation. |
| Publication review — Tracks / **Publish** | A confirmation only when fresh review succeeds. Incomplete evidence produces **Publication blocked** before the confirmation opens. | The server retains actor/track/revision/manifest identity. Cancel or a changed table context discards that review; submit must never silently recapture it. |
| Applying the review — Publish confirmation | One explicit confirmation of the reviewed track, files, and offers. A blocked submission requires a new review. | `publishManifestReviewed` checks current authority, versions, commercial/rights/media evidence, physical files and license eligibility, then atomically writes publication and audit. |
| Published — Tracks / **Share**, **Unpublish** | Share opens the public track; withdrawal uses its own confirmation. | Reviewed withdrawal returns the track to draft while retaining the reserved URL and historical evidence. It does not revoke purchased rights. |

## Permissions and information boundaries

- Existing staff access and domain authorization remain authoritative. This source still uses the current operator boundary; granular helper/reviewer roles and production recovery are unfinished T12 work. A visible action is not permission to execute it.
- Strict publication review/apply owns its transaction, checks the persisted actor and applicable MFA requirement, and refuses a review belonging to another actor. Browser state cannot authorize a stale or withdrawn operator.
- The publication confirmation contains the minimized identity only. It must not expose private storage paths, full license source, raw media proof, or rights evidence. It is not an anonymous sharing token.
- Private review does not change visibility. Preview/artwork failures use unavailable states; they must not substitute a master, an untagged delivery file, or an older revision that appears usable.
- Public sharing follows actual publication and eligibility. Private records remain excluded from public detail/catalog behavior. Production payment, customer recovery, and historical-entitlement boundaries are separate from this journey.

## Failure, retry, and interruption contract

| Condition | Observed source behavior | Experience requirement / remaining work |
| --- | --- | --- |
| Incomplete metadata, rights, media, preview measurements, or offers | `PublicationReadiness` supplies named blockers. Private review lists them; publication mount sends a persistent blocker notification and cancels the action. | Show the actual blocker and retain a path back to the relevant resource. Do not replace missing evidence with a success flag. Contextual cross-resource navigation remains a design opportunity, not a shipped guided wizard. |
| Processing not requested or unsuccessful | **Processing details** distinguishes no request from a retained run and displays its status, attempts, and safe failure message. Retry invokes the domain service. | Keep the distinction between requested, completed, and available evidence. Real scanner, approved tag, worker, storage, and representative-media acceptance remain operational gates. |
| Metadata, offer, rights, scope, file, or license eligibility changes after review | Apply revalidates the captured identity and current evidence; a rejected attempt does not publish. The mounted review is consumed. | Explain that review must be reopened. Never auto-retry publication against newly captured evidence. |
| Cancellation or resource/table context changes | `ManageTracks` clears the corresponding confirmation state. | Restore a usable focus position and require a fresh mount for another publication attempt. Browser verification must cover context changes and cancellation. |
| Authorization or MFA withdrawn | The strict command rejects current authority failure; an old confirmation does not override it. | Preserve authorization failure. Do not convert denial into a readiness success or use a hidden-control check as enforcement. |
| Database/transport result cannot be confirmed | The component consumes its review before calling the command. The editor regression includes a commit followed by a thrown result. A general exception currently propagates rather than producing a dedicated publication-recovery message. | Reload authoritative track state before deciding what to do next. Do not claim that nothing changed. A specific safe uncertainty notification and browser recovery journey remain an implementation follow-up. |
| Artwork or audio fails after private review renders | The private-review view replaces the media with **Artwork unavailable** or **Tagged preview unavailable**; audio does not preload. | Keep meaningful text and native labeled controls. An available UI element does not guarantee subsequent file availability. |

## Reusable controls, mobile, and accessibility

Reuse the existing resource forms, `TrackResource::metadataFields`, `metadataModalAttributes`, Filament confirmation patterns, and private-review sections. Do not introduce a parallel publication controller or duplicate domain readiness in presentation code.

The existing private-review layout wraps long values, adapts its metadata grid, constrains images/audio to the container, uses named groups and a definition list, labels native audio, and announces media failure with `role="status"`. The metadata modal's transition fallback avoids stealing focus when a field already owns it. These source features are not a complete screen-reader or physical-device acceptance result.

Acceptance for future changes to this journey must include:

1. Keyboard entry, visible focus, contained modal navigation, cancellation and focus restoration; no pointer-only action.
2. Narrow-screen reflow without horizontal overflow or hidden confirmation/close controls; long titles, tags, blocker messages and license names remain readable.
3. Distinct loading, empty, blocked, unavailable, successful and uncertain-result communication. A spinner alone must not report completion; repeated submission must not reuse a consumed review.
4. Persistent actionable validation where no form field exists, field-associated metadata errors where one does, and no private values leaking into public responses or unrelated notifications.
5. Native labeled preview controls with no forced playback; actual mobile Safari/audio and assistive-technology checks recorded separately from desktop emulation.

Use the project's [active brand contract](../brand/README.md) and [semantic theme](../../public/brand/theme.css), preserving the existing admin treatment. The source identity is `public/brand/vasey-audio-logo.png`, 420 × 100, SHA-256 `7b0ffd26d5ddffab9695fdd3e0a4bb306d50642dc6a51e25233fd1626ff07571`, recorded in the [asset manifest](../brand/asset-manifest.json). Preserve its aspect ratio. An official SVG master is unverified in that repository evidence; do not trace, replace, or enlarge the raster to simulate one. This contract changes no visual assets, fonts, or palette.

## Policies and journeys not supplied here

Track scheduling needs explicit lead time, horizon, precision, grace, and disposition after manual publication; the existing site-release schedule choices do not automatically apply. Anonymous/unlisted review, helper permissions, MFA recovery, resumable upload/storage policy, free-download licensing, and production commerce require their separate contracts and acceptance. A future screen must not present these as enabled controls simply because they are in the plan.

The immediate implementation follow-up identified by this map is a truthful publication-uncertainty recovery message, preserving the consumed review and current authorization handling. This document specifies its acceptance boundary but does not implement or accept it. The larger T11 screen/state map and reusable experience work across customer, service, membership, and other product journeys remain open.

## Source references and verification boundary

| Boundary | Exact repository source at the stated base |
| --- | --- |
| Track UI and confirmation lifecycle | [TrackResource](../../app/Filament/Resources/TrackResource.php), [ManageTracks](../../app/Filament/Resources/TrackResource/Pages/ManageTracks.php) |
| Readiness and strict application | [PublicationReadiness](../../app/Domain/Catalog/PublicationReadiness.php), [PublishTrack](../../app/Domain/Catalog/PublishTrack.php), [manifest reader](../../app/Domain/Catalog/ReadTrackPublicationManifest.php), [fresh file verifier](../../app/Domain/Catalog/VerifyTrackPublicationFiles.php) |
| Media, declaration, license, offer controls | [MediaAssetResource](../../app/Filament/Resources/MediaAssetResource.php), [RightsDeclarationResource](../../app/Filament/Resources/RightsDeclarationResource.php), [LicenseVersionResource](../../app/Filament/Resources/LicenseVersionResource.php), [OfferResource](../../app/Filament/Resources/OfferResource.php) |
| Protected display | [Private review view](../../resources/views/filament/catalog/private-track-review.blade.php), [private review evidence](../verification/private-track-review.md) |
| Publication regressions and browser journeys | [Editor cases](../../tests/Feature/TrackPublicationManifestEditorTest.php), [ordinary browser guard](../../tests/browser/track-publication-guard.spec.ts), [genuine-media related browser](../../tests/browser-related/editorial-related-tracks.spec.ts) |
| Acceptance and outstanding work | [Publication increment evidence](../verification/track-publication-manifest-apply.md), [WP-02](../work-packages/WP-02-catalog-and-admin-authorization.md), [task register](../remaining-development-tasks.csv), [active queue](../development-agent-queue.md) |

Validation of this document consists of source comparison and repository-link checks. No new application, browser, device, accessibility, real-media, or provider execution is claimed. Earlier tests remain evidence only for their recorded source and scope. The integrating PR owns current runtime acceptance; this document does not weaken those gates or close a parent task.
