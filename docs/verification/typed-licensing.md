# WP-04 typed usage terms verification

Base: merged PR #31, main `914f182c8f6f35ff4f9e8c390715f7a5ea69fb16`, tree `3c2790bb0a27586e83bdce3603080c4205855a7c`. Scope: typed usage/permissions/credit, shared source variables and card statements, v2 review output, explicit operator authoring/mapping and compatible lifecycle enforcement. Issue #4 remains open.

## Acceptance cases

- `TypedLicenseTermsTest`: strict bounded integer caps, explicit modes, complete vocabulary, role/type/key/credit validation, contradiction rejection, exact expected card/source text, stable field ordering and missing/unknown/malformed variable rejection without evaluation.
- `TypedLicenseTest`: complete review/publish lifecycle, contributor separation and exact review hash, source/credit escaping and private preview headers, immutable model/SQL evidence, schema/renderer pair guards, typed admin authoring/editing/mapping and field errors, preserved schema in successors, unauthorized/mounted action rejection and unchanged old offer/quote snapshots after newer typed policies.
- `TypedLicenseMigrationTest`: isolated SQLite upgrade from the previous guard with a real synthetic reviewed v1 record; no inferred v2 data or changed approval fields; both schemas remain verifiable and protected. Full MySQL CI exercises the forward guard and both lifecycles separately.
- `tests/Fixtures/license-review-v1.html`: exact synthetic prior-renderer bytes derived from the pre-change source at local tree matching merged PR #31. The regression checks byte identity, not a regenerated expected result.

## Execution evidence

`npm test -- --maxWorkers=1` passed 39 tests locally; `npm run build` passed TypeScript and Vite. PHP and Composer are unavailable in the editing workspace. The integrating PR records the actual remote tested commit, MySQL/SQLite counts and intentional MySQL-only skips, frontend/build, dependency audits and existing Chromium/WebKit operator results after CI executes. Test definitions alone are not passing evidence.

Initial CI at `f0b8980146607c27457f501956abfca6c8db1c3b` passed frontend and the existing browser suite, but MySQL found two failures: the new editor error adapter called a protected Filament method, and a new catalog assertion used the wrong JSON path. The correction uses the mounted schema's public accessor and the existing `licenseTiers` contract. Source-error tests cover both creation and editing. The same protected call was present in the stems association action; it is corrected with a regression for a master whose bytes change after mounting. The PHPUnit configuration now explicitly discovers the new Unit suite, which was absent from the initial run. These findings are not treated as passing acceptance evidence.

This increment adds no new concurrency protocol. Existing MySQL races remain part of the full gate. A self-review is not independent review; an independent assessment of this tested licensing/authorization/migration candidate remains required.

Physical-device typed-form interaction, actual production terms, semantic/legal consistency, seller rights clearance, territory/duration/ownership/royalty policy fields, buyer contracts, PDF/archive rendering, production payment/delivery and migration continuity are not established by synthetic fixtures or the existing operator browser suite.

## Recovery and next dependency

Preserve historical v1/v2 validators, pinned renderers and all reviewed content. The forward migration creates the successor state guard before removing the old one and rewrites no records. Retained v2 evidence needs a compatible application during recovery; never coerce it into v1 or delete it. See [operator and schema guide](../typed-license-terms.md).

Continue remaining WP-04 policy fields and representative consistency fixtures, then WP-05 detail/device work, as recorded in the [ordered plan](../development-order.md). Production U-05/U-06 decisions remain separate from reversible implementation.
