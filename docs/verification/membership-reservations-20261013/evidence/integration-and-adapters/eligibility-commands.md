# Current eligible-license adapter evidence

Isolated worktree `/workspace/VA-Studio-membership-eligibility`, branch `harness/membership-eligibility-20261013`, base `670f9caa158e436746ed9aed85667c10b9905f35`.

- Binding dependency: `a1a492c2a40e2301147573d4d45fd9ab9840d01c`.
- Adapter and tests: `0c4bc2c40963aa90fb000a5af45c1e71fcaaeef8`.
- Own Composer autoload metadata and bin copies; package directories are symlinked. Reflection resolved the changed binding class to this isolated worktree.
- PHP 8.4.26; PHPUnit 12.5.34. No native MySQL test was run by this lane.

## Binding unit check

```sh
source /workspace/.va-studio-toolchain/activate.sh
vendor/bin/pint app/Domain/Memberships/Production/MembershipLicenseBinding.php tests/Unit/MembershipLicenseBindingTest.php
php -r 'require "vendor/autoload.php"; echo (new ReflectionClass(App\Domain\Memberships\Production\MembershipLicenseBinding::class))->getFileName(), PHP_EOL;'
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Unit/MembershipLicenseBindingTest.php --log-junit /workspace/runtime/membership-reservation-evidence/license-binding-unit.xml > /workspace/runtime/membership-reservation-evidence/license-binding-unit.txt 2>&1
binding_unit_status=$?
printf '%s\n' "$binding_unit_status" > /workspace/runtime/membership-reservation-evidence/license-binding-unit.exit
```

**7 tests, 7 assertions; zero errors/failures/skips, exit 0**, 00:00.002. Covers legacy all-null IDs, complete exact bounded identities, and partial/gap/zero/negative/overflow refusal. Source hashes are recorded in `license-binding-unit.sources`. Pint and diff check passed; initial Pint output was returned by the execution tool rather than saved separately.

## Intermediate callback/bytes reproduction

Root identified that a later Carbon callback could mutate actual private bytes after `CurrentSelection::proveBytes` while database hashes remained unchanged. Two focused regression cases were run against the intermediate adapter with only the final native-byte loop deliberately omitted from `MembershipCatalogEvidence::assertCurrent`. This is a reproduction against new uncommitted implementation, **not a regression claimed against the pre-adapter base**.

Exact intermediate helper is `catalog-evidence-clock-bytes-red.php`; fixed helper saved before the run is `catalog-evidence-native-byte-fixed.php`. Four application/test source hashes were captured in `eligibility-clock-bytes-red.sources`. The fixed helper was restored immediately after the run; no other source changed during this reproduction.

```sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php --filter '/test_clock_callback_after_file_proof|test_committed_snapshot_checks_native_bytes/' --log-junit /workspace/runtime/membership-reservation-evidence/eligibility-clock-bytes-red.xml > /workspace/runtime/membership-reservation-evidence/eligibility-clock-bytes-red.txt 2>&1
eligibility_status=$?
printf '%s\n' "$eligibility_status" > /workspace/runtime/membership-reservation-evidence/eligibility-clock-bytes-red.exit
cp /workspace/runtime/membership-reservation-evidence/catalog-evidence-native-byte-fixed.php app/Domain/Memberships/Production/MembershipCatalogEvidence.php
```

**2 tests, 5 assertions, 2 intended failures, zero errors/skips, exit 1**, 00:07.509. Both methods failed with `Expected current catalog proof refusal.`: ordinary proof and committed snapshot incorrectly returned after the later clock callback changed private media bytes.

## Final focused SQLite file

```sh
sha256sum app/Domain/Memberships/Production/CurrentEligibleLicenses.php app/Domain/Memberships/Production/MembershipCatalogEvidence.php app/Domain/Memberships/Production/MembershipCatalogSnapshot.php app/Domain/Memberships/Production/MembershipEligibleLicenseToken.php app/Domain/Memberships/Production/MembershipLicenseBinding.php tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php > /workspace/runtime/membership-reservation-evidence/eligibility-sqlite.sources
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php --log-junit /workspace/runtime/membership-reservation-evidence/eligibility-sqlite.xml > /workspace/runtime/membership-reservation-evidence/eligibility-sqlite.txt 2>&1
eligibility_status=$?
printf '%s\n' "$eligibility_status" > /workspace/runtime/membership-reservation-evidence/eligibility-sqlite.exit
sha256sum -c /workspace/runtime/membership-reservation-evidence/eligibility-sqlite.sources > /workspace/runtime/membership-reservation-evidence/eligibility-sqlite.source-check
```

**14 tests, 108 assertions, zero errors/failures/skips, exit 0**, 00:49.570. All six captured source hashes remained unchanged. Exact source copies retained in `eligibility-sqlite-source/`. The source was then committed unchanged at `0c4bc2c40963aa90fb000a5af45c1e71fcaaeef8`.

Coverage: real synthetic reviewed/published catalog and media, exact trusted IDs and all hashes, canonical locator and stale revision, missing publication/current rights, issuer/copy/serialization/owner/frame refusals, changed private bytes, changed membership configuration, original readiness deadline and effective-until cap, callback-mutated raw graph, temporary shadow, pure terminal resolver exclusion, and actual committed read-only frame. Both callback/bytes reproduction cases pass with the final native closure. No period, redemption, or event is written by any adapter case.

```sh
vendor/bin/pint --test app/Domain/Memberships/Production/CurrentEligibleLicenses.php app/Domain/Memberships/Production/MembershipCatalogEvidence.php app/Domain/Memberships/Production/MembershipCatalogSnapshot.php app/Domain/Memberships/Production/MembershipEligibleLicenseToken.php tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php > /workspace/runtime/membership-reservation-evidence/eligibility-pint.txt 2>&1
pint_status=$?
printf '%s\n' "$pint_status" > /workspace/runtime/membership-reservation-evidence/eligibility-pint.exit
git diff --check
```

Both passed; Pint standalone receipt retained. An earlier exploratory SQLite canary (`eligibility-canary.*`) passed 1 test/12 assertions before the effective-until monotonic cap and native-byte closure were added; it is superseded by the complete final file and is not used as final-source evidence.

## Internal hash and evidence contract

The locator is the exact canonical positive decimal offer ID; expectedRevision is its immutable current offer-revision ID. The producer derives track/license IDs from captured current rows and revalidates them through `CurrentSelection`. The supported catalog is the existing narrow non-exclusive, positive USD offer shape; paid price never sets membership cost.

- `selectionHash`: `CurrentSelection::load()['selection_hash']`.
- `licenseManifestHash`: canonical hash of the selected offer snapshot's full `license`.
- `assetManifestHash`: canonical hash of the selected offer snapshot's `assets`.
- `originalTermsHash`: canonical hash of `purpose=membership-license-original-terms-v1`, `authored_source`, `structured_terms`, `renderer_version`, `render_fixture_hash` from that authenticated license. This member-license original contract is distinct from membership-plan terms and must match eventual C4 evidence.

`CurrentEligibleLicenses::snapshot()` requires its original issuer proof and exact Rows/PDO/principal/actor before returning readonly non-authority evidence. `MembershipCatalogEvidence::proveBytes()` is callback-capable. Its terminal `assertCurrent()` uses captured native file paths/stat identities/hashes and qualified raw database plans; `locking=false` is for the caller's actual committed read-only frame. The complete predicates and exact graph used by `CurrentSelection` are compared before capture, including audit contexts. Catalog source identities and temporary shadows are checked before unqualified loading and again in terminal closure. Both wall and monotonic readiness deadlines retain the original cap, including earliest effective-until; the owned operation must additionally retain and enforce its own original deadline.

Untested here: native MySQL metadata/engine/shadow behavior and native concurrency; full reservation operation composition; C4 consume/activation; physical deadline timing and external filesystem mutation races; real seller commercial inputs, host/provider readiness, and runtime activation. The callback mutation tests are explicit development regressions, not host or production claims. No runtime capability registration, provider request, active customer import, CI dispatch, or push occurred in this lane.

## Independent-review follow-up

Separate reviewer identified two further admission-order issues in `0c4bc2c40963aa90fb000a5af45c1e71fcaaeef8`: the last Carbon callback occurred after the last customer proof, and terminal path/directory `lstat` preceded native stream admission. A one-shot replaced file wrapper could run its stat callback, restore the native wrapper, and pass the later open check.

Added two regressions to the existing test file and ran this exact focused selection against **unchanged 0c4bc2c4 application source**:

```sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php --filter '/test_final_clock_callback_cannot_withdraw|test_terminal_file_admission_refuses/' --log-junit /workspace/runtime/membership-reservation-evidence/eligibility-review-red-v2.xml > /workspace/runtime/membership-reservation-evidence/eligibility-review-red-v2.txt 2>&1
eligibility_status=$?
printf '%s\n' "$eligibility_status" > /workspace/runtime/membership-reservation-evidence/eligibility-review-red-v2.exit
```

Clean red: **2 tests, 4 assertions, 2 intended failures, zero errors/skips, exit 1**, 00:07.602. Both returned where refusal was required. Exact source hashes and hash-verified source copies are in `eligibility-review-red-v2.sources` and `eligibility-review-red-v2-source/`.

The first run (`eligibility-review-red.*`, same command with that prefix) recorded **2 tests, 4 assertions, 1 failure, 1 error, zero skips, exit 2**, 00:07.307. The wrapper had restored itself, then test cleanup attempted a second restore; Laravel converted its warning into an error masking an underlying expected-refusal failure. Only cleanup was corrected before the clean red above. Initial test bytes are retained in `eligibility-review-regressions.php` and its source-hash receipt; the initial error evidence is not replaced or called a clean red.

Product corrections move the final clock before the final customer proof and open/admit a plainfile/STDIO stream before all terminal path/directory stats. A substituted stream is refused before its data could pass as native evidence. The test restores global wrapper state on every exit.

The complete corrected 16-case file first passed as `eligibility-review-green.*`: **16 tests, 123 assertions, zero errors/failures/skips, exit 0**, 00:56.174. All six recorded hashes were unchanged; exact matching source is retained in `eligibility-review-green-source/`.

Then only the existing MySQL temporary-shadow test branch was strengthened: `CREATE TEMPORARY TABLE ... LIKE` previously copied no rows; it now copies all captured durable rows, so the native branch really tests an identical-row shadow. MySQL was not run in this lane. The full file was rerun to bind evidence to final test bytes:

```sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php --log-junit /workspace/runtime/membership-reservation-evidence/eligibility-review-final.xml > /workspace/runtime/membership-reservation-evidence/eligibility-review-final.txt 2>&1
eligibility_status=$?
printf '%s\n' "$eligibility_status" > /workspace/runtime/membership-reservation-evidence/eligibility-review-final.exit
sha256sum -c /workspace/runtime/membership-reservation-evidence/eligibility-review-final.sources > /workspace/runtime/membership-reservation-evidence/eligibility-review-final.source-check
```

Final: **16 tests, 123 assertions, zero errors/failures/skips, exit 0**, 00:55.850. All six source hashes unchanged; exact source copies in `eligibility-review-final-source/`. Final follow-up commit **`a54ffb6a8ef3084a35754412af7d6dcfe4efc6fa`** contains only the two application fixes and updated proof test file. No native execution occurred here.

Final formatting command passed and has standalone text/exit receipts:

```sh
vendor/bin/pint --test app/Domain/Memberships/Production/CurrentEligibleLicenses.php app/Domain/Memberships/Production/MembershipCatalogEvidence.php tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php > /workspace/runtime/membership-reservation-evidence/eligibility-review-pint.txt 2>&1
pint_status=$?
printf '%s\n' "$pint_status" > /workspace/runtime/membership-reservation-evidence/eligibility-review-pint.exit
git diff --check
```
