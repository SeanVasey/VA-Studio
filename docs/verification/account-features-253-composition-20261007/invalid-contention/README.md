# Invalid native runs (environment contention), preserved

These receipts are kept for honesty. None of them is a 253 result.

| Receipt | Server | Cause |
| --- | --- | --- |
| `native-admission-mysql84.*` | shared 127.0.0.1:3306, MySQL 8.4.11 | A sibling agent's schema `vaseyaudio_member257` holds triggers that reference `production_track_preparation_packets`. Root migration 238 (`CapabilityMigrationOwnership`) scans `information_schema.TRIGGERS` across the whole server and refuses: "Unexpected production capability external or additional table guard". 3/3 errors in `setUp` `migrate:fresh`. |
| `adversarial-mysql84-isolated.*` | isolated 127.0.0.1:3407 | The reviewer created a second schema (`vaseyaudio_features253_review`) on the isolated server while another run was active there. That reproduces the same server-wide trigger refusal (6/6 errors in fixture `migrate:fresh`). The second schema was then dropped. |
| `native-checkpoint-mysql84-isolated.*` | isolated 127.0.0.1:3407 | This run overlapped the second-schema mistake above: 4 errors are the trigger refusal, and 6 are fail-closed 503s. A clean sequential rerun is `../native-checkpoint-clean-mysql84.*`. |

Rule learned: run native suites against a MySQL server that holds exactly one VA-Studio schema.
