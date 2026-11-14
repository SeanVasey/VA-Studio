# Tax255 V2 consumer prerequisites — 2026-10-14

Operative source `128f423d59316b530996c8d2cec8622d8b216601`, based on actual A2 PR #118 merge `cfce548b4f0893a3634422062099d930428856a3` and its receipt-only carry `291a0d90`. [Design](design.md) and [bounded independent source review](independent-source-review.md) describe the scope. Separate non-author final approval/carry and exact-head preflight completed; PR #119 is merged. See [the actual merge receipt](merge-receipt.md) for current status. The bounded source note remains the original earlier checkpoint.

The V2 adapter now requires the caller's current reader and expected order ID, proves the original held source is current, then binds the exact line and order before acceptance. It returns unchanged producer bytes. Its pure self-consistency helper is explicitly internal and supplies no authentication. The actual checkout journey now covers five previously reviewer-only cases: PaymentIntent livemode, metadata and on_behalf_of, line currency and a non-succeeded PaymentIntent while the session is paid. Each refuses503 without retained reviewed payment, leaves payment unverified, then admits the original unchanged positive fixture.

## Actual executed checks

Counts are tests/assertions. Each command, exact executed source, original log, JUnit, observed PHP process exit, recorder result and source/runtime/loader/runner hashes is preserved in [the portable archive](recorded-evidence.tar.gz) and [its index](recorded-evidence-index.json). All four runs have zero errors/skips and unchanged source/head/status/runner/loaders. Later Linux zombie exit observations match each original recorder PID/start ticks/UID and explicitly confirm its OS exit; no outcome is inferred from green JUnit alone.

| Selection | Actual head | Tests/assertions | Failures | PHP / recorder result / observed recorder OS exit |
| --- | --- | --- | --- | --- |
| Closed-frame adapter regression on old source | `0f8e16b19f68eb55b3d390cb69d174ccd6d0c59f` | 1/4 | 1 intended | 1/1/1 |
| Wrong expected-order adapter regression on old source | same `0f8e16b1` | 1/2 | 1 intended | 1/1/1 |
| Complete Admission, SourceV2, Adapter and Journey files on SQLite | `128f423d59316b530996c8d2cec8622d8b216601` | 55/267 | 0 | 0/0/0 |
| All four admission methods and five new money datasets on private MySQL | same `128f423d` | 9/47 | 0 | 0/0/0 |

The old-source regressions really accepted the genuine closed/wrong-target source and failed the refusal assertion; they did not fail on an API error. PHP permits extra positional arguments to the original four-argument method, so the test's appended reader/order arguments are ignored on that source and required after the fix. Tests preceding their failure assertion ran; assertions after it did not. SQLite and MySQL selections overlap and are not one full suite.

Exact local invocation form (documented PHP8.4 toolchain activated first):

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/tax-v2-integration-evidence-20261014/launch-durable.py --name sqlite-closed-frame-old-source --driver sqlite --filter test_a_source_from_a_closed_transaction tests/Feature/ProductionTaxCheckout/ProductionTaxPaidSourceAdmissionTest.php
python3 /workspace/runtime/tax-v2-integration-evidence-20261014/launch-durable.py --name sqlite-expected-order-old-source --driver sqlite --filter test_the_current_source_is_bound tests/Feature/ProductionTaxCheckout/ProductionTaxPaidSourceAdmissionTest.php
python3 /workspace/runtime/tax-v2-integration-evidence-20261014/launch-durable.py --name sqlite-tax-v2-affected --driver sqlite tests/Feature/ProductionTaxCheckout/ProductionTaxPaidSourceAdmissionTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxSourceV2Test.php tests/Feature/ProductionTaxCheckout/ProductionTaxPaidLineAdapterV2Test.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutJourneyTest.php
python3 /workspace/runtime/tax-v2-integration-evidence-20261014/launch-durable.py --name mysql-tax-v2-admission-and-payment --driver mysql --timeout 5400 --filter 'test_a_source_from_a_closed_transaction|test_a_fresh_transaction|test_the_current_source_is_bound|test_a_current_source_cannot|test_a_paid_session_with_a_mismatched_money_fact.*(livemode|metadata|on_behalf_of|line currency|processing)' tests/Feature/ProductionTaxCheckout/ProductionTaxPaidSourceAdmissionTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutJourneyTest.php
```

These are the actual original commands, not instructions to overwrite their receipt names. Each launch refuses existing output. The recorder retains its exact PHP argv and environment scope; the old commands apply to their old source only.

Changed-file Pint: five PHP files pass `vendor/bin/pint --test` with observed exit0 and identical before/after digests after activating the toolchain. The original recorded formatting invocation exited127 because its fresh shell lacked PHP; its stderr and zero file changes remain in `pint-128f423d.json`, separately from the corrected `pint-128f423d-reconciled.json`. Formatting records are not PHPUnit runs.

## Private runtime and preserved setup error

PHP8.4.26, PHPUnit12.5.34. Private MySQL8.4.11 uses owned container `va-studio-private-mysql-tax-v2-20261014`, named datadir `va-studio-private-mysql-tax-v2-data-20261014`, loopback port33515, socket `/workspace/runtime/mysql-tax-v2-run/mysql.sock` and schema `vaseyaudio_tax_v2_integration`. UTC +00:00/strict SQL mode were observed. It was stopped after completed tests with `docker stop --timeout 10 ...`, actual exit0, on2026-10-14 at21:42:35UTC; datadir/evidence retained and earlier private servers untouched.

The initial helper's post-SQL host socket probe raised PermissionError despite precreating its directory mode0755: the Docker entrypoint restricted the directory. The initial SQL stdout/exit was not recorded; no initial SQL success is inferred. Only this owned socket directory was changed to0755, then a fresh read-only probe observed the existing schema/version with exit0. CREATE DATABASE was not repeated. `mysql-server-ready.json` preserves the failure and correction. Future initialization must record SQL outcomes before any fallible host probe and account for the Docker socket-directory mode.

## Evidence and limits

The archive seals 4 actual PHPUnit invocations (2 current passes,2 intended old-source failures),3 reachable Git source bundles and54 members. All2340 current declared inputs include16 routes;13 loader hashes and all archived member hashes reconcile. The packaging helper itself and original bounded review note are archived unchanged. Archive SHA256 `ef51972fa3af3a5a620070e53f9283006be52b66dc09777e8e8ea5ddf38c542b`; bytes `381258`.

These changes address the adapter and Journey portions of recorded A1-1/A1-2 within this focused scope. A1-1's terminal after-use consumer proof, V2 committed-read receipt and NEW-write physical commit admission, forward Paid252 consumer/schema wiring and root registration remain subsequent reviewed work. Tax255 stays unregistered, unmounted, test-funds only and default-off. The refusing transport remains the shipped default; fixture payment figures are synthetic and establish no provider/merchant/tax/host facts. The native selection is nine cases, not the full Tax255 or paid family. No new migration, frozen V1 source, contract/profile member, logo, frontend, lockfile or workflow change. No Foundation/GitLab, live credentials/payment, active customer import, deployment, DNS/cutover or activation claim. Adjacent ordinary membership afterCommit P2 remains frozen for its related batch.

## Independent final development decision

Separate non-author APPROVE WITH CONDITIONS at exact clean `59026e00c7b3a94d7ce8709d2bfc0358bbae2a9a`, no open P0/P1 or required correction. The unchanged [DECISION](independent-review-DECISION.md) and [verification report](independent-packet-verification.json) independently bind all54 archive members,3 reachable Git bundles,2340 actual current inputs/16routes/13loaders, four actual PHP and independently observed recorder OS exits, both current passes and original failures/setup/formatting limits. Receipt-only final-head carry and exact published-head preflight/protections remain merge gates. All terminal consumer/NEW-write/V2 wiring/host/provider/activation conditions and adjacent ordinary afterCommit P2 remain open.

Actual publication/merge observations and unchanged receipt-only carry are recorded in [the merge receipt](merge-receipt.md). Historical pending statements above are superseded by that actual receipt.
