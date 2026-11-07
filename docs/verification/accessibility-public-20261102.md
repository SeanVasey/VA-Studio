# Public UXA source preparation — 2026-11-02

Prepared from main `a7bc63cc5c5785353f13e882f49d0ab9dcff55b3`; application checks **unrun**.
Owns `tests/browser/accessibility-public.spec.ts`, `accessibility-helpers.ts`, this note,
`package.json` and `package-lock.json`. No UI/CSS, brand or fixture admission changed.

Seven cases use the existing labeled synthetic storefront transport with the actual
built React/CSS: populated catalog/detail, empty results, license error/retry and focus
containment, 320px terms/cart reflow, native player/queue keyboard operation, reduced
motion and forced colors. Each scan follows assertions of its real mounted state.
Full-document axe scans use `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`;
there are no excluded elements, disabled rules or filtered violations. Reports retain
engine/version, selected tags, violations and incomplete checks. Incomplete results
need human review and are not passes. Forced-colors emulation must actually match.

Pinned `@axe-core/playwright` **4.14.0** (locked `axe-core` **4.14.0**) supplies the supported builder/tags API; registry metadata requires Node >=22.12.0 and playwright-core >=1.0.0, compatible with this project's Node >=24.15.0 <25 and pinned Playwright/core 1.63.0. Reviewed [wrapper release](https://github.com/dequelabs/axe-core-npm/releases/tag/v4.14.0) and [engine release](https://github.com/dequelabs/axe-core/releases/tag/v4.14.0): engine-floor changes and new/changed findings remain visible; no rules are disabled.

Actual dependency preparation used Node 24.19.0/npm 11.9.0. `npm install --package-lock-only --ignore-scripts --save-dev --save-exact @axe-core/playwright@4.14.0 --no-audit --no-fund` exited 0: only the two new package entries and root declaration were added; all **163 existing package entries** and manifest pins are unchanged. No `node_modules` was created.
`npm audit --package-lock-only --ignore-scripts --json` exited **0**, zero vulnerabilities.
`npm outdated --json --long` and its explicit `--package-lock-only --include=dev` variant both exited **1**, reporting seven absent production dependencies with wanted/latest values but no installed-current values; this uninstalled-checkout result does not establish development dependency freshness. No unrelated upgrade was made.

ROOT owns execution after contained dependencies, real browser/scanner prerequisites
and `public/build/manifest.json` are ready in this isolated checkout. Proposed focused
commands, **not executed**:

```sh
npm run typecheck
npm run build
node tests/browser/run.mjs tests/browser/accessibility-public.spec.ts --project=chromium-desktop
```

Use the existing runner without weakening its build, scanner, database or browser
admission. Record genuine failures before UI fixes. WebKit/emulated mobile execution,
admin/customer/member states, further editorial/product families, human screen readers,
native forced-color paint and physical devices remain open. This transport does not
establish backend publication, payment, entitlement or WCAG/launch acceptance. Integration
remains in D after B/C; no install, typecheck, build, browser/product test, CI or publication ran here.

## Claude harness checks at `bcb08f67` (2026-11-04)

| Check | Result |
| --- | --- |
| `npm ci` (Node 24.22.0) | 123 packages; `@axe-core/playwright@4.14.0` resolved from the committed lock |
| `npm audit --audit-level=high` | 0 vulnerabilities |
| `npm run typecheck` (includes `tests/browser`) | clean |
| `npm test` (vitest) | 70 files, 1177 tests passed |
| `npm run build` | built |
| `accessibility-public.spec.ts` via `npm run test:browser` wrapper, chromium-desktop, preinstalled Chromium 1194 through an untracked config | not executed: the wrapper's isolated fixture setup refused in phase `customer_purchase` with `MediaFailure` before any browser started. This container cannot fetch ClamAV signatures (known limitation), so the browser spec needs CI or a host with a working scanner |

No WCAG result is claimed from this harness.
