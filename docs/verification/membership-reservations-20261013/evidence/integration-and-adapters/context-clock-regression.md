# Terminal Context clock regression

The first development Context placed its last Carbon clock read after its final
current-owner proof. A clock callback could deactivate the buyer while the write
still committed. This was found during independent pre-review and fixed before
publication.

The retained `Context-clock-order-red.php` deliberately restores that earlier
ordering on integrated source565f19d1. The runner loads this explicit single-class
overlay before PHPUnit; it does not modify the worktree. It is not a claim that
the already merged first-award source had this reservation behavior.

Exact command: source /workspace/.va-studio-toolchain/activate.sh, then:

```
python /workspace/runtime/membership-reservation-evidence/run-focused.py --name context-clock-order-red --driver sqlite --overlay /workspace/runtime/membership-reservation-evidence/Context-clock-order-red.php --filter test_terminal_clock_callback tests/Feature/ProductionMembershipBilling/MembershipReservationClockClosureTest.php
```

Observed: exit1, one test,15assertions,one intended failure,zero errors/skips.
The test observed one retained redemption where rollback required zero. Overlay
hash, all source hashes, actual argv/environment, JUnit and output are retained.
Fixed integrated run is pending; no passing result is inferred from the red.
