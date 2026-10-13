# Ownership-case census update
Working directory `/workspace/VA-Studio-membership-award`; source HEAD `9ec090737e66e7f89075600cca96a6174ef2076d`. Only the additional selection entry was uncommitted during discovery. The workflow census comment was changed after actual counts were known; no workflow trigger changed.

Exact commands (each output saved to its matching selection-ownership log and actual shell exit saved):

```sh
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-phpunit-shards.py
source /workspace/.va-studio-toolchain/activate.sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_DATABASE=selection_discovery_only DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync python3 scripts/ci/phpunit-shards.py --shards 8 --prefix phpunit-ci-mysql --mysql-native-selection --timings scripts/ci/phpunit-timings-mysql.json
```

All three observed exits were 0. Policy suites: 50 and 52 tests. Discovery only: 1927 selected cases in 179 files from 7897 total cases in 576 files; eight nonempty local listing shards. This executes no functional test or migration. Six ownership cases added to reviewed native selection; no new skip. Sixteen untimed files retain fallback weights. Hosted 24-shard setting unchanged; no hosted run dispatched. Original generated listing/configuration/manifest files moved into selection-ownership-census-files. File hashes retained in selection-ownership-summary.json.
