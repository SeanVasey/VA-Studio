# Production amount requirements focused verification

Executable source `eab36455dff392e429d751488ae4c17f076e6296`, tree `5f6281c0be8a8243ef8f49c7681912be95d40d0c`. Base proposal `bd97f170a3822d19f01d0bcf916c5ee33d04215b`. The source commit owns only two classes, two tests and D-28. Earlier proposal source bindings remain separate. Integrate owned leaf commits onto fresh main; do not publish this local proposal ancestry blindly.

Actual PHP 8.4.26, own Composer autoload and copied immutable retained dependency bytes; all 158 installed version/reference pairs exactly match the unchanged candidate lock. `runtime-graph.json` and `autoload-origin.txt` record these identities. No dependency configuration, lock, operational migration, route, workflow, Contracts or Discovery source changed. Existing packet authority/evidence/policy/buyer reader source bytes are unchanged from the reviewed proposal base.

Executed selections, all final source bytes matched to the executable commit:

| Command selection (`php vendor/bin/phpunit`) | Cases | Assertions | Errors/failures/skips |
| --- | ---: | ---: | --- |
| `tests/Unit/ProductionAmountRequirementsTest.php tests/Feature/ProductionAmountRequirementsAccessTest.php` | 52 | 151 | 0/0/0 |
| `tests/Unit/ProductionTrackPreparationSnapshotTest.php tests/Feature/ProductionTrackPreparationPacketTest.php` | 40 | 107 | 0/0/0 |
| `tests/Unit/ProductionTrackMachinePolicyTest.php` | 93 | 97 | 0/0/0 |

Total distinct selections: 185 cases / 355 assertions, all clean. Exact raw outputs and JUnit accompany this record. Final four-file Pint `--test` and `git diff --check` pass. The first uncommitted iteration (`initial.xml`/`.txt`, 43/142 clean) is retained only as iteration evidence; it is not attributed to the final source or added to acceptance totals. Initial Pint formatting precedes the tested freeze. There were no observed test failures in this leaf.

New access cases run real authenticated D-26 reads, actual retained synthetic packets and real encrypted D-27 reports, with no HTTP calls. Successful reads run under SQLite-enforced query-only mode. Full database rows (including audits, migrations and every commerce table), schema/trigger SQL and sequences are equal before/after success and refusal. Five adversarial late mutations happen after decryption during final authority query callbacks: actor, retained packet, line, packet audit and source audit. The existing captured-primary proof rejects each; the transaction rolls back the injected mutation and every snapshot remains equal. Damaged-restore fixture triggers are removed before each canary snapshot; shipped guards are unchanged.

No migration is added, so the evidence is preservation of bookkeeping rather than a new rollback/down implementation. Native MySQL concurrency is untested here. Laravel's query observer does not log the reader's direct PDO SQL, hence enforced query-only mode and full snapshots are required complementary evidence. No external provider, buyer, location, tax, exemption or assent proof is claimed. No full hosted/native/browser matrix, customer import, mail, live provider action, deployment or publication was performed. Sensitive independent authority review and fresh-main composition remain required before publication.

The next bounded engineering prerequisite is a separately reviewed trusted amount-observation identity/binding contract. Actual provider/exemption observations still require external merchant/tax and independently verified buyer/location/current-order/assent facts; staff JSON cannot create those facts. Current requirements do not authorize that writer or any execution.
