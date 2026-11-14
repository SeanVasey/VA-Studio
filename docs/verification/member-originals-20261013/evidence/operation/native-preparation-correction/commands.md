# Private member initialization correction — October 13, 2026

This is a separate correction release. The earlier 164-member operation release and 25-member diagnostic release remain immutable. Application and test source is `9399dd998cc3d972b94f6753e576e2c756438657`, integrated by the parent as `32f083d2`; a later root commit changes census comments only. No release activation, customer import, real provider call, production deployment or host acceptance was exercised.

## Correction and retained failure

The original uninstrumented root MySQL case at `f4732dbe` failed with 1 test, 16 assertions and one error. The first isolated diagnostic failed earlier during fixture setup because TMPDIR did not exist: 1 test, 0 assertions and one error. After creating the owned 0700 directory, the timing-only diagnostic at `2cfd9a0d` reproduced 1 test, 16 assertions and one error. The inner error was `IdentityException::committed_frame_required`, after the original 60-second frame budget elapsed. The commit completed at 59.141574 seconds; committed evidence refused at 60.366539 seconds. These are retained in the separate `native-preparation-diagnostic/release-manifest.json`; diagnostic overlays are not accepted passing application source.

The correction removes repeated constructor proofs only from the privately registered member capture. The exact non-null capture is bound into the consumed constructor permit; ordinary inspect and writer paths retain their existing proof. Immediate original deadline admission, producer locks, intent and ledger authentication remain. Public readiness forces the complete combined current proof before authority returns; the observer current proof, independent committed proof, single finish, and original post-finish admission remain. No deadline was extended or renewed.

## Commands and source binding

All commands run from `/workspace/VA-Studio-member-original-operation` after `source /workspace/.va-studio-toolchain/activate.sh`. The retained source receipts contain the exact expanded PHP argv, timestamps, non-production environment and complete executable-input inventories. The runners explicitly use this worktree's own Composer autoload. Public packet copies must use the established explicit environment projection and omit credential fields; no `.env`, keys, database files or raw private rows belong in the packet.

```sh
python3 /workspace/runtime/member-original-operation-evidence/native-preparation-correction/run-composed.py sqlite-owned-composition --stop-on-defect \
  tests/Feature/ProductionMemberOriginals/MemberOriginalIssuerOwnershipTest.php \
  tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationIntegrationTest.php \
  tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php \
  tests/Feature/ProductionMemberOriginals/MemberOriginalRetainedDeadlineTest.php
```

Passed: 22 tests, 509 assertions, zero errors, failures or skips; exit 0, wall 311.722 seconds. All 2,034 captured inputs stayed unchanged. This includes the two new real-decrypt withdrawal cases: first-capture terminal refusal and final-readiness refusal before a second commit. The new issuer test refuses caller-shaped pending captures for inspect, reserve, release and expire before transaction work. Existing original honor/post-finish deadline regressions remain synthetic cap-consumption tests.

```sh
python3 /workspace/runtime/member-original-operation-evidence/native-preparation-correction/run-composed.py sqlite-public-reservation-proof --stop-on-defect \
  --filter test_reservation_proof_is_issuer_owned_and_cannot_be_serialized_or_adopted_by_a_new_frame \
  tests/Feature/ProductionMembershipBilling/MembershipReservationOwnershipTest.php
```

Passed: 1 test, 20 assertions, zero errors, failures or skips; exit 0, wall 16.148 seconds. All 2,034 captured inputs stayed unchanged. This directly checks the preserved public reservation proof path after the constructor tuple changed. The earlier 17-case wrapper result remains historical at its recorded source; it is not relabelled as a current-source whole-file result.

```sh
python3 /workspace/runtime/member-original-operation-evidence/native-preparation-correction/setup-private-native.py
python3 /workspace/runtime/member-original-operation-evidence/native-preparation-correction/run-native-fixed.py \
  --name native-positive-final --driver mysql \
  --filter test_real_authored_sources_prepare_an_exact_repeat_without_original_or_credit_effects \
  tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationIntegrationTest.php
```

The setup independently verified the existing private MySQL 8.4.11 instance, owned database `vaseyaudio_member_original_operation`, host port 33413, socket and dedicated datadir. The database had no retained tables before the run. The runner verifies its owned TMPDIR is 0700. The positive/exact-repeat invocation uses no class overlay and the real original clocks and budgets. Passed: 1 test, 48 assertions, zero errors, failures or skips; exit 0, wall 421.294 seconds (JUnit 421.042574 seconds). All 1,324 captured inputs stayed unchanged. This same selected positive/exact-repeat test and fixture were used for the earlier diagnostic refusal; the captured native input sets differ only in the two corrected application files. The passing invocation loads no class overlays. The original 60-second frame and all workflow, honor, authority and physical caps remained unchanged. The duration covers fixture setup and repeated preparations; it is not an individual frame timing measurement.

## Static checks and limits

Final Pint on the four changed files and `git diff --check` passed; logs and exact hashes are retained. The first precommit Pint attempt reported only import ordering in the new issuer test; it was corrected before the retained final pass. That first style failure exists in the execution transcript rather than a separate standalone log. No test assertion was weakened.

These are focused component and integration checks, not a complete Foundation run. SQLite does not establish native MySQL behavior. The native positive case is distinct from the parent's separately scheduled owner-withdrawal native case. No timing guarantee, exclusive CPU claim, production host verification, real provider evidence, activation, or consumption is claimed.
