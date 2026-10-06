# Refund browser fixture marker correction

The refund journey in [PR #8 run 37472116788](https://github.com/SeanVasey/VA-Studio/actions/runs/37472116788) failed during `prepare-test-refund-resolution.php prepare chromium-desktop`, before opening the operator page. Its original generic catch exposed no exception class or line, and the browser trace could not identify the PHP failure. Those artifacts alone did not establish a cause.

The unchanged fixture was reproduced on local source `75722ac914e550f1b9421f3ecfa81adb13e7ab62` with PHP 8.4.26. A disposable diagnostic copy retained its complete preparation and guards, changing only relative source-path resolution and the final catch to report exception class, source line and argument-free stack metadata. It reported **`ArgumentCountError` at line 119, in `array_merge()`**. Both retained records and the manifest had already been written; the original `verify ... prepared` command subsequently passed every retained-row, original-payload and guard check with zero provider reads or resolution events.

The two records have string keys `refunded` and `partial`. `array_map()` preserves those keys, so unpacking its result into `array_merge()` supplies unknown named arguments. The one-line correction supplies `array_values($records)` to the mapping operation. This preserves record order and all six provider-identity privacy markers while making the unpacked arguments positional. Preparation clocks, application services, policies, guards, browser assertions and deadlines are unchanged; the original privacy-preserving catch remains.

## Focused verification

The guarded CLI probe used the existing empty-commerce bootstrap mode in a fresh private SQLite installation, including real migrations, interactive operator provisioning, installation diagnostics, and the original fixture directory/configuration/operator guards. It did not invoke a browser or substitute a malware scanner. Ordinary customer/scanner bootstrap and native Chromium/WebKit journey acceptance remain required hosted checks.

- Original preparation: exit 1 in **3.084s**, with the safe exception above. Original prepared verifier: exit 0 in **0.281s**.
- Corrected Chromium identity: preparation exit 0 in **3.476s**; unchanged prepared verifier exit 0 in **0.234s**.
- Corrected WebKit identity in the same isolated installation: preparation exit 0 in **3.314s**; unchanged prepared verifier exit 0 in **0.297s**.
- Each corrected output retained **10 privacy markers**, including the exact **six distinct provider markers in retained record order**. Both verifiers proved unchanged original rows/payloads/guards, no rights or money effects, zero resolution history and zero provider reads.
- PHP lint and `git diff --check` passed. The unchanged frontend passed TypeScript and production build. An initial probe correctly stopped because this new worktree lacked its frontend build; the real build was generated before repeating the probe.

Source SHA-256: original fixture `6654e8856af647b40868b18042f9802d3c1a84356e3e82222a90e839df8982c8`; corrected fixture `823767ad71605e0f3dc7fe71c05d362a7162bcc61031209a82b73768e9e68edc`. The integration workspace retains `refund-preparation-guarded-original.json`, `refund-preparation-guarded-corrected.json` and their scratch probe for source-bound review. These diagnostic receipts are not browser acceptance.
