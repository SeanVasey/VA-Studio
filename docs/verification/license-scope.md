# WP-04 territory and duration verification

Base: merged PR #32, main `b48466bf69dbddf6896bde8f5a80a4005b0565c3`, tree `3c7fc1676d92ce18e454502d5b017af6b9f2f99c`. Local base `1b681b7` has that same tree. Scope: explicit licensed-use territory and duration, generated source/card values, v3 review output and admin mapping, with retained v1/v2 evidence. Issue #4 remains open.

## Acceptance coverage

- `ScopedLicenseTermsTest`: strict bounded modes/fields, country vocabulary/list/type/duplicate rejection, fixed-month limits and grant-only anchor, contradictory unused fields, deterministic sorted display without stored reordering, exact shared statements and version-specific source variables.
- `ScopedLicenseTest`: review/publication and immutable evidence, static historical v2 preview bytes, missing variable rejection, schema/renderer pair enforcement, admin explicit mapping and visible field errors, mode-change cleanup, integer coercion, schema-preserving successors, mounted authorization, availability independence and retained historical offer/quote snapshots.
- `ScopedLicenseMigrationTest`: reviewed v1 and v2 records exist before the forward migration; all retained attributes and review evidence survive unchanged, v3 publication succeeds afterward, and published-state/deletion guards remain enforced.
- Existing tests retain v1 compatibility, v2 lifecycle, access controls, media integrity, immutable commerce snapshots and MySQL races. The existing operator browser suite is a regression gate, not new territory/duration browser or physical-device acceptance.

## Execution evidence

The integrating PR records the actual remote candidate SHA/tree, test counts, environments and CI links. PHP and Composer are unavailable in the editing workspace; executed backend checks must come from CI. Definitions alone are not passing evidence. Independent review must identify the examined candidate and any unresolved findings; no legal qualification or production approval is inferred.

Local `npm test -- --maxWorkers=1` passed 39 tests across 6 files; `npm run build` passed TypeScript and Vite. `git diff --check` passed. A separate implementation reviewer inspected schema dispatch, immutable v1/v2 output, admin mapping/dehydration, snapshot consumers and the forward state guard without finding a blocking issue; the integrating PR records confirmation against the committed and tested candidate. The regression author worked independently in a separate worktree. The static v2 golden fixture is 3,982 bytes with SHA-256 `a679a412fee6c6023fba7a1851b6023aec61df2f87dd3234b298baf3bff50798`, captured from the prechange renderer.

## Limits and next dependency

This implementation defines scope fields and their exact review presentation. It does not calculate a grant endpoint, enforce geographic use, create contracts or entitlements, or decide actual production terms. Grant-time clamping/leap-year/end-exclusive calculations and fulfillment enforcement belong to WP-08. Real policy, historical obligations, complete legal-model consistency and device acceptance remain open.

Continue ownership/publishing/royalty concepts and versioned policy references in WP-04, then WP-05 detail/device work. Keep all 14 original work packages and unresolved production dependencies in the [ordered handoff](../development-order.md). See [upgrade/recovery](../license-territory-duration.md#upgrade-and-recovery); preserve v1/v2/v3 evidence and compatible validators/renderers.
