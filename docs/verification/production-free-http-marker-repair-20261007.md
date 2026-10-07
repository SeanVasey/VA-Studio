# Production free HTTP authority repair

Executable source: `233f0864028156faeb70c63259910214e47762cb`, a three-path child of `652635e7493143aabd38e10cc42b296260fe56bf`. The historical `3f49d2a2a4f853778afa66db8f0f61442e2ab586` lineage source remains preparation; its original HTTP adapter was not accepted by this check.

The independent original probe creates a real synthetic SMTP enrollment and T23 session, then forgets `_production_customer_identity` in the first actual identity-source `TransactionCommitted` callback. Both original SQLite and native runs returned authority: each recorded **1 test, 7 assertions, 1 failure, 0 errors**. The probe and red receipts are retained unchanged from independent evidence `c28cea2bfefa5ba03da37598ca36b0472efd74ff`. Probe SHA-256: `44f495bbc052587020f65bcf4c71685d360a84ef412075eb85078136ce988424`.

The adapter now captures a sealed `ProductionFreeGrantHttpBinding` before the callback-capable T23 source read and compares the same request/session, marker, login marker, cached customer guard/user, user/auth resolvers, actor attributes, key and configuration afterward. The final comparison reads cached framework properties and invokes no resolver, guard-provider, container binding or query callback. Initial user-resolution callbacks also finish before the original policy is admitted. The existing `forRequest()` tuple interface is preserved. A future production-family consumer can retain `binding(Request)`, use its typed `principal()`/`actor()`, and call `proveCurrent()` after extensible work and before its final fixed raw source/identity proof. That proof does not mint a fresh identity.

The cached-property approach follows the reviewed support request-context pattern; no attachment actor or identity family is reused. Core T23 sessions, principal, current/historical access and the legacy free test adapter remain byte exact. No HTTP mount, config binding or production activation is included.

Exact final checks:

- SQLite: **34 tests / 202 assertions**, zero failures, errors or skips. This includes 26 new actual-SMTP HTTP-binding cases, seven unchanged lineage/current-authority cases and the byte-identical independent marker probe.
- Native MySQL **8.0.46-0ubuntu0.24.04.4**: **4 tests / 26 assertions**, zero failures, errors or skips. The unchanged original marker probe, actual positive owner, source-commit marker withdrawal and retained request-resolver withdrawal all passed against the isolated author schema.
- Pint: three owned PHP paths passed. Original external probe bytes are preserved.

The own regressions cover marker deletion/replacement, session replacement/id change, removed guard, replaced cached actor, changed request/auth resolver, key/purpose withdrawal, actor attributes and login marker. They exercise both the actual identity-source commit callback and later retained consumer checks, with zero replacement-resolver invocations. This remains bounded local verification; it does not establish full integrated acceptance, real mailbox receipt, approved production free terms/assets or production fulfillment.

Commands used PHP from the existing private toolchain. The native command sourced the isolated private identity database environment and set `VA_IDENTITY_ISOLATED_NATIVE=1`; no credentials are stored in this evidence.

```text
APP_KEY=<synthetic> vendor/bin/phpunit --no-progress --log-junit /tmp/free-http-marker-sqlite-final.xml tests/Feature/ProductionFreeIdentity/ProductionFreeHttpBindingTest.php tests/Feature/ProductionFreeIdentity/ProductionFreeIdentityTest.php docs/verification/production-free-http-marker-repair-20261007/ProductionFreeHttpCurrentMarkerIndependentTest.php
APP_KEY=<synthetic> vendor/bin/phpunit --no-progress --filter '(test_actual_smtp_current_owner|test_actual_source_commit_withdrawal.*marker_forget|test_retained_same_request_binding.*request_resolver|test_actual_current_source_commit)' --log-junit /tmp/free-http-marker-native-final.xml tests/Feature/ProductionFreeIdentity/ProductionFreeHttpBindingTest.php docs/verification/production-free-http-marker-repair-20261007/ProductionFreeHttpCurrentMarkerIndependentTest.php
vendor/bin/pint --test app/Domain/Grants/Free/Production/ProductionFreeGrantHttpBinding.php app/Domain/Grants/Free/Production/ProductionFreeGrantHttpIdentity.php tests/Feature/ProductionFreeIdentity/ProductionFreeHttpBindingTest.php
```

The [receipt map](production-free-http-marker-repair-20261007/receipt-map.json) binds the exact source, eight unchanged dependencies, every raw/JUnit artifact and all executed method tuples. Final SQLite/native evidence supersedes neither original red receipts nor the earlier lineage evidence. Independent review of this exact repair is required before root composition. Next owned work is the disjoint historical original-commit receipt, then the separate production free family reserved at migration `256000`.
