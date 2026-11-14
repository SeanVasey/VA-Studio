# Final reservation discovery at 45d12994

Exact source: `45d1299406821627f4ba02af186e324a70c6ade0`. Discovery and receipt finalization both exited 0. The source worktree is clean.

Run command: `bash /workspace/runtime/membership-reservation-selection-evidence/final-45d12994/discovery.sh`.
Finalization command: `python3 /workspace/runtime/membership-reservation-selection-evidence/final-45d12994/finalize.py`.

The discovery script invokes the unchanged partition tool, which runs only PHPUnit `--list-tests-xml` for the complete source and each of the 24 generated selections. No PHPUnit test body or database workload executes. MySQL driver discovery uses an unreachable `127.0.0.1:1` endpoint with no socket; it is not pointed at the private runtime-test database.

Result: 1,973 selected native cases / 187 files from 8,047 discovered cases / 587 files, across 24 nonempty shards. Skip policy remains 231 SQLite native-only methods / 16 MySQL SQLite-only methods. These are listed identities and policy expectations, not observed runtime passes or skips. The 24 selected files without measured timings continue using the unchanged fallback; estimates are not new timing evidence.

All 2,091 inventoried source files match before/after. Relative to defe9580, exactly MembershipEligibleLicenseProofTest.php and MembershipNativeReservationRaceTest.php changed; their method counts are unchanged. Product sources did not change. All nine policy/script/policy-test hashes match their previously passing 565f19d1 policy receipt. A fresh static driver-branch census has zero errors. Prior 52/61/32 policy-suite passes carry on identical bytes; those suites were not rerun.

All 50 generated configuration/listing/manifest files are retained under generated/, with SHA-256 and byte counts in discovery-generated-artifacts.json. The compact manifest, census, source hashes, source delta, exact scripts, logs and exit files are included. This packet supersedes final-defe9580 only for the final test-source discovery claim, preserving all earlier evidence as historical.
