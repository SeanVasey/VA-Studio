# Member policy source admission verification

Base: `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`, isolated `/workspace/VA-Studio-member-policy-admission`, owned four-file delta (MembershipPolicyEnvironmentTest unchanged). Private vendor autoload/composer/bin copied; installed dependency directories symlinked; `composer dump-autoload --no-scripts --no-interaction` exited0. Reflection resolves the owned MemberGrantPolicy path. Synthetic `.env` copied privately; no values emitted. No runtime capabilities are registered.

Initial static PHP lint passed each owned PHP file. Initial `php vendor/bin/pint --test <four owned files>` exited1 on formatting of two tests; retained pint-initial.txt. Targeted Pint formatting then recheck and `git diff --check` passed.

Configuration-only selection (no migrations, Rows or database bodies):
```sh
source /workspace/.va-studio-toolchain/activate.sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 DB_URL= php vendor/bin/phpunit --do-not-cache-result --colors=never --log-junit /workspace/runtime/member-policy-admission-evidence/configuration.xml tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationTest.php tests/Feature/ProductionMembership/MembershipPolicyEnvironmentTest.php
```
Exact source hashes recorded before this selection in configuration-source.json. Output/exit and first failures retained.

Results: configuration selection **22 tests / 56 assertions**, zero skips/errors/failures, exit0. All recorded source hashes remained unchanged afterward.

A subsequent single staging method was mistakenly treated as configuration-only during command selection, but the class inherits FinalizationDatabaseMigrations. This was recognized after starting it; it had completed before termination could find a running process. This is explicitly **SQLite migration-backed** evidence, **1 test / 4 assertions**, zero skips/errors/failures, exit0, reported duration2.931s. It overlapped root's deadline-sensitive private native8 queue; no native connection was opened (forced SQLite `:memory:` and unreachable native port1). Root notified promptly; no further DB bodies were run.
```sh
source /workspace/.va-studio-toolchain/activate.sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 DB_URL= php vendor/bin/phpunit --do-not-cache-result --colors=never --filter test_staging_never_admits_verified_production_membership_or_member_grant_provenance --log-junit /workspace/runtime/member-policy-admission-evidence/staging-configuration.xml tests/Feature/StagingEnvironmentAdmissionTest.php
```

Separate old-source existing-current() reproduction is retained in baseline/README.md: 1 test / 10 assertions / 1 intended failure / 0 errors / 0 skips. Old source admitted verified258+rehearsal257+SQLite and resolved all three nonoperative factories. No app bootstrap or database.

At initial reviewable commit: new MemberGrantSourceAdmissionTest has not executed; native behavior, the Rows/PDO regressions and native positive admission remain pending coordinated slots. No CI/provider/host calls or claims.

Reviewer found terminal virtual bound()/isAlias() callback gap in a4e37cdc before frame tests began. Corrected only capability presence to captured raw maps, explicitly refusing capability aliases, in3424190f7974b38048d65128c359570c2df45cf2. Added pure alias and real-frame armed virtual getter tests. No claims against unseen restored callback history.

Root released exclusive heavy local slot after native8 completed. First frame execution on frozen3424190f:
```sh
source /workspace/.va-studio-toolchain/activate.sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 DB_URL= php vendor/bin/phpunit --do-not-cache-result --colors=never --stop-on-failure --log-junit /workspace/runtime/member-policy-admission-evidence/frame-initial.xml tests/Feature/ProductionMemberOriginals/MemberGrantSourceAdmissionTest.php
```

First full frame selection at3424190f:12 tests,51 assertions,10 passes,1 explicit MySQL-only skip,1 error,0 assertion failures, exit2. The transient nested-admission fixture retained its Rows in the factory closure; all assertions completed but SQLite teardown VACUUM refused the still-open statement. Fixed only fixture lifetime in15a65e0761382a9ad50c4b279605a644a4eeb285 by replacing test factories in finally; no product change.

Final whole frame plus affected whole compatibility files (all SQLite; frozen15a65e07):
```sh
source /workspace/.va-studio-toolchain/activate.sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST=127.0.0.1 DB_PORT=1 DB_URL= php vendor/bin/phpunit --do-not-cache-result --colors=never --stop-on-defect --log-junit /workspace/runtime/member-policy-admission-evidence/sqlite-final.xml tests/Feature/ProductionMemberOriginals/MemberGrantSourceAdmissionTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationTest.php tests/Feature/ProductionMembership/MembershipPolicyEnvironmentTest.php tests/Feature/StagingEnvironmentAdmissionTest.php
```

Final complete SQLite selection passed: **50 tests / 322 assertions**,49 passed,1 explicit MySQL-only skip,0 errors/failures, exit0, reported72.150s. All 10633 tracked source hashes unchanged. Dedicated sqlite-final-verification.json records class counts and skip identity. Heavy local slot released to catalog agent after completion; no native R4 body has executed yet.

Native plan approved by root is four focused cases, not the whole12-case file. Runner `run-native.py` is prepared/static-compiled only until exclusive private native slot release. Cases: verified MySQL provenance with rehearsal257; frame reproof after each physical PDO-changing callback; terminal raw container getters; same-PDO commit/reopen refusal. Own proposed schema `vaseyaudio_member_policy_admission` on existing isolated MySQL8.4.11, socket/port/datadir identity checked before schema setup. No schema is created until execution.

The same external R4 existing-current() probe passed1/10 ata4e37cdc. Its original configured-driver refusal precedes the later helper change; component-probe-carry.diff records the exact a4→final product delta (only raw capability presence correction). This is a component receipt, not an assertion that the external probe executed at15a65e07. Final whole Preparation file independently includes that original driver/factory refusal case and passed at15a65e07.

Root explicitly reordered the idle private native slot while catalog repaired its fixture. Ran only the four authorized cases after verifying MySQL8.4.11, private socket/container-internal port/datadir and own policy autoload path. Exact executable command:
```sh
cd /workspace/VA-Studio-member-policy-admission
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/member-policy-admission-evidence/run-native.py
```
Runner SHA-256: `e5f2c1f8ec7830d9b1bad99b89233f1136832f3f9f389fe878fc4297c86ef52a`. The actual PHPUnit argv/environment/selection and all10633 source hashes are in native-four.source.json; server identity in native-four.metadata.json. Synthetic database only; this is not host/provider verification.

Native final result: **4 tests / 14 assertions**, all passed,0 errors/failures/skips, exit0, reported330.916s. MySQL8.4.11 / PHP8.4.26. Exact source15a65e0761382a9ad50c4b279605a644a4eeb285; all10633 tracked hashes and runtime runner unchanged. Setup passed on first attempt. Native-four.verification.json binds the exact four case identities and actual JUnit durations. No additional native tests ran. Native slot released to catalog agent immediately after process/cleanup exited.

Two read-only status observers during this run are retained separately as native-status-observer*.json. They recorded own-schema Execute statements at age0s, respectively closing tables and waiting for handler commit. They are progress observations, not tests or evidence of a passed case. No instrument settings were changed.

Remaining scope limits: eight other methods in the12-case new frame file were not selected on MySQL; one is deliberately SQLite-only. All12 were selected on SQLite with the one explicit MySQL-only skip. The unchanged reservation canary and actualC3-merge ancestry, combined catalog evidence, fresh final independent DECISION, cheap preflight/publication are root integration responsibilities and are not claimed by this lane. No capability registration, activation, renderer, consumption writer, provider/host verification or real member facts are supplied.
