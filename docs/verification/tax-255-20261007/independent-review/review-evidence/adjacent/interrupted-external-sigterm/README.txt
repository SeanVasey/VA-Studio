Environment interruption, retained, not evidence about the code.
At 2026-10-08T03:31:00Z the reviewer's native Guard run (4 of 6 cases passed, "....") exited 143 (SIGTERM),
and at the same second the reviewer's SQLite adjacent run in /home/user/VA-Studio-review-tax255-base exited 144.
The reviewer sent no signal at that time (its only kills were pid 28941 at 03:20:23Z, recorded in run-ledger.txt).
Other lanes' agents share this host; the signal source was not identified. Both runs were repeated afterwards.
Update 2026-10-08T04:26:40Z: the coordinator reported that another lane ran 'pkill -f vendor/phpunit/phpunit/phpunit' at about 03:20-03:30Z. That matches these terminations (and mutation R1's first Journey run, exit -15, mutations/R1-first-run-interrupted/). Every affected run was repeated; only the repeats are cited.
