# Customer native-delivery fixture correction

The WebKit job `112085661592` in GitHub run `37406659061` reported HTTP 503 for the separate-login customer authorization test. This is distinct from Chromium's earlier response-body capture race. The 33,921,222-byte WebKit artifact exceeded the workspace transfer limit, so no trace-body claim is made.

A fresh execution of the existing guarded browser bootstrap reproduced the backend cause: under `local`, the original contract was authorized and the purchased `master_wav` failed with `target_unavailable`; changing only the application environment to `testing` authorized that same master. Bootstrap had retained `test-only` media scan evidence while the actual HTTP server correctly remained `local`. `PrepareTestDeliveryStream` intentionally rejects that evidence outside testing.

The repair keeps the product guard, local HTTP environment, CSRF middleware, private stream adapter and customer browser assertions unchanged. Ordinary bootstrap now requires canonical `/usr/bin/clamscan`. It explicitly passes the real `MalwareScanner` through the existing customer, inventory, quote and media fixture helpers before processing, publication or order snapshots. The default helper arguments retain the test-only scanner; `finally` restores its binding after either success or failure. Scanner-path configuration is restricted to customer construction and restored before local checks. Ordinary HTTP uploads retain their absent-scanner quarantine behavior.

The existing media pipeline performs three genuine scans per customer fixture: master source, approved WAV tag and artwork source. `VerifiedMedia` requires the purchased master hash to equal the input hash, so the delivered master is the exact scanned source bytes. This does not claim separate antivirus scans of every encoded derivative. Two project fixtures perform six scans without adding retries or changing application scanner budgets.

After restoring `local`, bootstrap prepares and closes real private snapshots of both retained originals before writing its customer manifest. This preflight issues no authorization tokens or redemption attempts. The Python native startup check preserves its two ordinary positive cases, paid/activated graph census, exact original file sizes and hashes, and adds genuine ClamAV source/tag provenance checks. The entire fixture subprocess has a 600-second total bound with a 15-second termination grace and 620-second outer ceiling, matching the existing related-track preparation pattern.

## Local evidence

- `php vendor/bin/phpunit tests/Feature/CustomerAccountCommerceTest.php tests/Feature/TestPreparedDeliveryStreamTest.php`: 43 tests, 178 assertions, zero failures or skips. This includes explicit scanner propagation/restoration and fail-closed purchase prevention, plus existing local/production synthetic-scan denial tests.
- Shared default fixture regression, `QuoteTest` and `SharedInventoryTest`, with a fresh synthetic application key: 49 tests, 215 assertions, zero failures or skips. An initial keyless run was an environment setup failure; no test or application code was changed to repair it.
- Disposable MySQL 8.4.11, `CustomerAccountCommerceTest`: 8 tests, 65 assertions, zero failures or skips, including real owner delivery and adversarial withdrawal checks.
- The eight scanner-independent `scripts/ci/test-related-browser-stage.py` checks passed. They retain malformed-marker rejection and the related empty-commerce baseline.
- With `/usr/bin/clamscan` absent, ordinary bootstrap refused before any migration or fixture write.
- PHP formatting, JavaScript syntax, Python compilation and diff checks passed.

The genuine ordinary positive startup case, ClamAV execution duration, and native Chromium/WebKit downloads require hosted execution with the real scanner/signatures and browser engines. Those tools are unavailable in this workspace; no native success is inferred from the focused checks. CI prerequisite installation is a separate coordinated change. External payment/contract-renderer inputs remain explicitly synthetic, and no production commerce or delivery enablement is claimed.

## Hosted checkpoint

Run `37410220669` on published head `12a5c70bc4b9dbb94353c290e031975803d24fa7` installed ClamAV 1.5.4 with official signature database 28144, dated October 5, 2026. Both ordinary browser jobs completed the genuine fixture bootstrap and passed the separate-login customer case, including original contract/asset byte and hash checks. Chromium passed all 55 cases; WebKit passed 53 with one existing intentional skip and an unrelated kit-navigation page error. The related startup job stopped before its native journeys because its frontend build prerequisite ran too late; that ordering receives a separate correction. These hosted results prove the ordinary fixture and customer-download behavior for this source, not acceptance of the complete run or any successor.
