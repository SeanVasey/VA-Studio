# C4b native-budget followup quality receipt

Source: `32f083d28db0609a52cade793849a8767a855ae3`, root `/workspace/VA-Studio-member-originals`. This is formatting and test discovery only, not native or application behavior acceptance. The prior 88-file quality release remains byte-identical; this child directory is a new receipt.

## Exact execution

The retained runner was invoked from the root working directory as:

```bash
source /workspace/.va-studio-toolchain/activate.sh
PYTHONDONTWRITEBYTECODE=1 python3 /workspace/runtime/member-originals-quality-evidence/native-budget-followup/run-quality.py
```

It executed these exact validation argv sequentially, each recorded with head, source/runner hashes, nonsecret environment, exit and elapsed time in its command/result JSON:

```bash
vendor/bin/pint --test app/Domain/Grants/Member/MemberOriginalInspection.php app/Domain/Memberships/Production/MembershipReservationContext.php tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalIssuerOwnershipTest.php
python3 scripts/ci/phpunit-shards.py --shards 24 --prefix phpunit-ci-mysql --timings scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

The environment forces `APP_ENV=testing`, MySQL host `127.0.0.1`, port `1`, empty socket/URL/password and synthetic discovery-only database/user. No database operation or test body was requested. The generator invokes PHPUnit only with `--list-tests-xml`. Its native policy requires the `phpunit-ci-mysql` prefix; all 50 exact targets were absent and untracked before execution. Both proxies use the root's own Composer autoload metadata; seven proxy/autoload metadata hashes are retained.

## Actual results

- Pint: all 4 explicitly selected PHP files passed; exit 0, 0.265 seconds.
- Discovery: exit 0, 5.945 seconds; 2,145 distinct selected cases across 210 files, from 8,478 cases across 625 files, in 24 nonempty shards. Independent XML reconciliation finds no duplicate or missing selected identity.
- Compared with the prior discovery, the two new Composition methods are selected natively; the one new IssuerOwnership method is in the complete source census and is not selected natively. Exact identities are in `census-verification.json`.
- The warning for 47 files without measured MySQL timing remains visible. Fallback weights and shard runtime estimates are scheduling metadata, not executed durations or passing tests.
- All 2,360 captured source hashes and seven local runner/autoload hashes are unchanged. The prior 2,254-path source inventory differs on these four PHP files plus two previously changed census-comment files (`.github/workflows/final-verification.yml` and `.gitlab-ci.yml`); all non-comment lines in those two files are identical. The exact comment diff is retained. Otherwise the larger followup inventory additionally covers all tracked resources. The earlier 78-file Pint result carries for its 74 unchanged files. The prior 159 policy selftests were not rerun, as instructed; `prior-check-carry.json` verifies unchanged scripts/policy hashes without claiming a new run.
- All 50 generated configs/listings/manifest were copied, hash-verified, and removed by exact generated filename. No unrelated file was deleted. Root Git status is clean and HEAD is unchanged.
- PHP 8.4.26, Python 3.12.14, PHPUnit 12.5.34. Full version outputs are retained.

Both requested validation commands passed. Initial receipt sealing exited 1 because its historical-delta assertion expected only four changed paths and omitted the two census-comment files. `sealing-initial-error.json` preserves the observed tool output and `seal-receipts-initial.py` preserves that exact verifier. The corrected verifier checks the four PHP paths separately and verifies comment-stripped workflow equality. Pint and discovery were not rerun. No style correction, policy selftest rerun, application/database test execution or hosted dispatch occurred in this followup. No secret/private media/environment file is in the public allowlist. Historical failures in other packets are unaffected.

## Public archive scope

`public-artifacts.json` is the explicit relative-path allowlist for this child directory. `artifact-digests.json` hashes every listed artifact except itself, including the allowlist and this command note. The old parent packet is excluded from this new manifest and remains immutable. `seal-receipts.py` records the exact reconciliation/archive verification procedure.
