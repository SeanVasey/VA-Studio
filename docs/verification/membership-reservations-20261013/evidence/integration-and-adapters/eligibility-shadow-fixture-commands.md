# Native temporary-shadow fixture correction

Test-only commit: `222b6e3d901c2c08a20a8e99180c2955d4a1cb62`, parent `a54ffb6a8ef3084a35754412af7d6dcfe4efc6fa`, isolated eligibility worktree.

Root's native focused run at `defe9580` stopped with MySQL 1066 at `CREATE TEMPORARY TABLE tracks LIKE tracks`. Its original 4 tests/58 assertions, 1 setup error, zero failures/skips receipt remains separate. This correction does not relabel that run as passing.

Existing repository precedent: `tests/Feature/PersistentCatalogDraftImportTest.php` temporary-shadow branch copies `SHOW CREATE TABLE`, removes foreign-key clauses unsupported by temporary tables, and substitutes the `CREATE TEMPORARY TABLE` header. The corrected membership fixture uses that same pattern, inserts the previously captured durable rows, and explicitly asserts both a temporary definition and strict equality of all returned rows/types. Only the MySQL branch changed; no application code or SQLite branch changed.

Before committing, the exact replacement SQL pattern was exercised in the separately authorized disposable schema `vaseyaudio_membership_shadow_probe` on the existing private server:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php /workspace/runtime/membership-reservation-evidence/eligibility-shadow-sql-probe.php > /workspace/runtime/membership-reservation-evidence/eligibility-shadow-sql-probe.txt 2>&1
probe_status=$?
printf '%s\n' "$probe_status" > /workspace/runtime/membership-reservation-evidence/eligibility-shadow-sql-probe.exit
cat /workspace/runtime/membership-reservation-evidence/eligibility-shadow-sql-probe.txt
exit "$probe_status"
```

The exact PHP/SQL script is retained as `eligibility-shadow-sql-probe.php`. MySQL **8.4.11**, socket `/workspace/runtime/mysql-policy-run/mysql.sock`. The script created a small InnoDB parent/track pair with an FK and two explicit synthetic rows including JSON, null and timestamp values. It reproduced the original SQL's **1066**, then verified replacement creation, **two exactly equal rows including PDO types**, and the durable source unchanged after dropping the temporary shadow. It finally dropped its owned probe schema. **Exit 0**. It did not bootstrap Laravel, run migrations, read/write the race schemas, or execute a fixture/feature-test workload.

Root's native race workload was concurrently running on the same private MySQL instance. No elapsed-duration instrument was used for this probe; do not present filesystem timestamps as a benchmark. Filesystem receipts place it at **2026-10-13 05:05:26 UTC**:

- Script creation: `2026-10-13 05:05:26.269586396 +0000`; last modification `05:05:26.274274455`.
- Output creation: `2026-10-13 05:05:26.274274455 +0000`; last modification `05:05:26.353587377`.

Formatting, syntax and whitespace checks:

```sh
vendor/bin/pint --test tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php > /workspace/runtime/membership-reservation-evidence/eligibility-shadow-fixture-pint.txt 2>&1
pint_status=$?
printf '%s\n' "$pint_status" > /workspace/runtime/membership-reservation-evidence/eligibility-shadow-fixture-pint.exit
php -l tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php > /workspace/runtime/membership-reservation-evidence/eligibility-shadow-fixture-syntax.txt 2>&1
git diff --check
```

All passed. Final test/probe SHA-256 values are retained in `eligibility-shadow-fixture.sources`. No full/native/SQLite test selection was run by this lane for this correction; root will run the two remaining native eligibility cases after its races. Previous SQLite results retain their original source binding, with unchanged SQLite branch semantics here. No product change, CI dispatch, push, or production operation occurred.
