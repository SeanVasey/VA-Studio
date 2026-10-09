Attempt 1 of the affected-selection shards (started ~09:29Z) was aborted by the reviewer at ~09:35Z, unfinished.
Reason: the reviewer's route-cache probe (probe-route-cache-mfa.txt) briefly created bootstrap/cache/routes-v7.php
in the same worktree (~09:34:55Z-09:35:07Z) while the shards were running; tests booting in that window could load
cached routes compiled under APP_ENV=local. The partial outputs are retained here and are not counted as evidence.
The selection was re-run from scratch after `route:clear` (bootstrap/cache holds only packages.php and services.php).
