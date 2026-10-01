# Native catalog and queue select contrast

October 1, 2026. Small presentation candidate based on local commit `7e2b85fddc6e857d56ab183834847086811ae4ce`, tree `2bd8c3e9ff64067f1dcfeba9391e36ef9e9600e0`. This is a bounded rendered-accessibility correction, not complete design or launch acceptance.

## Observed defect and scoped correction

The actual retained `native-d30-browser` mobile WebKit `queue-section-loop.png` was inspected. The catalog **Sort tracks** control paints a light gray native field with a white selected label, despite its computed author background using the dark canvas token. This screenshot is pre-correction diagnostic evidence; it does not prove the proposed correction works.

The existing `.sort-field select` and `.queue-options select` receive element-scoped `color-scheme: dark`. This asks the browser to use a compatible native control palette on the approved dark storefront. Native select appearance, arrow, options and interaction remain browser-owned. The sort select also receives `min-height: 44px` so its mobile padding override cannot shrink the public touch target below the project requirement. Queue selects already have that minimum.

The repository's [active live-theme mapping](../brand/README.md) and `public/brand/theme.css` take priority over conflicting generic Edition 04 palette defaults in the brand UI skill. Canvas `--va-canvas`, white strong sort text `--va-text-strong`, silver queue text `--va-text`, boundaries and turquoise focus remain their approved roles. Font families, identity assets, imagery and provenance, native arrow geometry, surrounding layout and focus styling are retained. There is no new motif, asset, script, global color-scheme declaration, custom select or forced-colors opt-out.

WebKit's [dark-mode guidance](https://webkit.org/blog/8840/dark-mode-support-in-webkit/) describes element-specific color-scheme support for native controls. [CSS Color Adjustment Level 1, used color-scheme effects](https://www.w3.org/TR/css-color-adjust-1/#color-scheme-effect) specifies effects on browser-owned UI. These are primary-source design support; actual platform paint still requires rendered inspection.

## Evidence and acceptance boundary

The existing storefront navigation journey now captures `catalog-sort-unfocused.png` and `catalog-sort-focused.png` in Chromium desktop and mobile WebKit. It checks the native combobox remains visible, selected value remains unchanged, width/height reach 44 CSS pixels, and keyboard Tab from the adjacent Search button reaches it. The original filter-preservation, native single-audio-owner, seeking, playback and navigation assertions remain intact. Screenshots cover real built CSS and native controls against the existing isolated synthetic storefront transport; they do not establish backend catalog or commercial acceptance.

Executed focused checks: `npm run build` passed TypeScript and the production build; `git diff --check` passed; `npm run test:browser -- storefront.spec.ts --list` passed isolated SQLite bootstrap, operator setup and installation diagnostics and listed the existing four definitions across Chromium desktop and WebKit mobile. The initial screenshot was inspected locally. No new test, timeout, skip or retry was added.

Those are development checks. Native execution and inspection of both new screenshots are pending on the integrating candidate. Computed colors alone cannot close the defect. Queue-select rendered focus/option acceptance, real iPhone/iPad/Android, portrait/landscape, 200% zoom/320px reflow, forced colors, VoiceOver/NVDA and physical touch behavior remain unverified until actually run. Browser-defined dark control colors can vary by operating system and accessibility preferences; no universal native-paint contrast ratio is claimed.

The next integrating native run must inspect the selected text, native field, arrow, focus ring and control bounds in both screenshots, retain ordinary keyboard/options behavior, and confirm no horizontal overflow or obscured focus. The current parent-owned player controls journey supplies the separate queue/playback regression evidence. This child does not edit that spec or weaken its gates.

Rollback reverts this CSS and its screenshot assertions; no persisted data, provider configuration, prices, rights, playback logic or publication state is changed.
