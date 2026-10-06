# Measured database shard refresh

This bounded timing component changes only the committed MySQL/SQLite timing estimates and this record. It does not change a test, exclusion, shard count, partition algorithm, receipt requirement, workflow, timeout or database durability setting. Timing inputs remain ordinary reviewed source and therefore require the existing full CI mode.

## Initial measurement

The ten database artifacts from GitHub run `37410220669`, attempt 1, were independently verified against PR head `12a5c70bc4b9dbb94353c290e031975803d24fa7` and tree `86584c422341d8f03ac885d59916aba8faf34521`. All database jobs passed, although the complete run failed separate quality and browser gates. The measurements cover 3,566 expanded cases in 223 files for each engine, with zero MySQL skips and exactly 371 reviewed SQLite skips.

The existing `scripts/ci/phpunit-timings.py` generated each timing file directly from that engine's eight or two exact JUnit logs. Its enclosing-class attribution includes inherited methods under the class that actually runs them; summing only the testcase's declaring file would incorrectly omit that work. No duration was manually reduced or substituted. Each JSON records the actual source run, head, tree and the failed overall-run boundary.

On the 3,570-case successor inventory, the existing assignment evaluated with these measured file costs projects MySQL loads from 22.41 to 35.00 minutes. The refreshed assignment projects 28.48 minutes per shard. SQLite's corresponding projected range changes from 17.50–19.76 minutes to 18.63 minutes per shard. These are estimates using one verified run, not observed performance of a new assignment; runner load and future changes may differ. The new order-reference class is the only untimed file and retains the existing mean-per-case fallback.

Fresh complete PHPUnit discovery/partition proofs retain all 3,570 cases in 224 files exactly once across eight nonempty MySQL and two nonempty SQLite shards. All 36 existing partition safeguards pass. The source candidate and its currently running acceptance remain frozen and are not changed by this separate worktree.

## Next evidence

The reviewed refresh can join the next already-required browser correction, using the complete verified database measurements above and the supported fallback for the one new lookup class. Waiting for newer timings is not required to publish that otherwise ready correction. A fresh full run must execute the resulting assignment and every acceptance gate; neither prior receipts nor this projection certify the successor. Refresh future inputs from newly verified complete database artifacts as coverage changes. No measured speedup is claimed before executing the new assignment.
