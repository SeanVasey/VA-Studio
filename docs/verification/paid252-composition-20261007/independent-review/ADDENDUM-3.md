# Independent review: Paid252 composition, Addendum 3 (A2-I2 fix, one slot per buyer)

**Range:** `a2126d28966aedcf38ffb99a47825a1b3fdc3e10..3f7d295c165bb7efef28dbcb8416de6d6dfa8b2f`. The head was fetched from
`origin/harness/paid252-composition`, and the worktree is detached there.

| Commit | Content |
| --- | --- |
| `ea7a63b9` | `admit()` now reads `pending()` before `freeBytes()` (fixes A2-I2) |
| `9c19dabb` | One held slot per buyer (Codex P2, which is my A1-L2/C8) |
| `17c05c1d`, `3f7d295c` | docs |

`17c05c1d` committed Addendum 2's files. Those are byte-identical to my copies, except for `PaidGrantSpoolReviewAddendum2Test.php`: its documenting case now asserts refusal, which correctly reflects the fixed order.

Nothing was committed or pushed, and no product code was modified.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- **A2-I2 is resolved.** Written sizes are now read before free space (`PaidGrantPrepareStream.php:224-225`), so a write between the two probes is counted conservatively.
- **A1-L2 / C8 is resolved in design.** One buyer can no longer hold every slot. The slot count and the 2 h hold time remain an operator capacity decision for mount.
- C2 and C4–C7 from the original review stay open.

## (a) Can the holder check be bypassed, or wrongly refuse a different buyer?

**It cannot be bypassed.**

- Same-buyer requests serialize on the admission lock. Under that lock, the first one writes its `slot-N.holder` before releasing it (`:229-234`). The second one then reads every *held* slot's holder before anything is reserved (`:219`, `holders()` at `:349-388`).
- `redeem()` is the only caller of `handle()`, and it always passes `sha256("paid-spool-holder-v1:" . account_id)` (`PaidGrantDownloads.php:143-144`).
- A `null` holder skips the check by design. Only tests pass `null`.

**It does not wrongly refuse a different buyer.**

- Digests differ per account.
- Holder sidecars are never cleared, but `holders()` reads only slots whose lock is currently held. A stale digest in an unheld slot is ignored, and that slot's next holder overwrites it. This also covers crash residue: `flock` is released when the process dies.
- **Write order: holder written, then the reservation fails.** The slot's lease is closed in the `catch`, so the written holder sits in an unheld slot and is ignored.
  - My case forces exactly this by replacing the reservation with a symlink after the free-space probe.
  - The buyer is refused once, then admitted on the next slot. That slot is retired only by the pre-existing no-repair rule (symlinked `.reserve`), not by the holder.
- **Tampering fails closed for other buyers.** A held slot whose holder sidecar is tampered (64 non-hex bytes, same inode) makes the next request with a holder refuse, rather than count it as nobody. When that slot is released, its holder is not read again and the next holder overwrites it.

**A3-I1 (Info, transitional).** During a rolling deploy, a pre-round-9 process holds a slot without writing a holder. Another buyer's stale digest in that slot can then refuse that buyer until the old process releases the slot. It fails closed and records nothing.

**A3-I2 (Info, UX).** A buyer can now run only one paid download at a time. For example, the contract cannot be downloaded while a master is streaming. The second download gets the generic sealed 503 ("The download was refused"). Its authorization is untouched but may expire (60 s) before the first stream ends, in which case the buyer authorizes again.

## (b) Does any failure path permanently retire a slot beyond the no-repair rule?

**No.**

- The holder sidecar is created with a temporary file and an atomic rename (`reservation()`, `:395-428`), then overwritten in place. A partial in-place write keeps the 64-byte size, and its contents are hex digits or `0` either way.
- So no normal failure leaves a holder sidecar that `slot()` (`:260-290`) would treat as malformed.
- A holder sidecar with the wrong mode, size or link count retires its slot: never repaired, the next slot is used. My case confirms this. It is the same no-repair class as `.reserve`, and only reachable by tampering with the 0700 spool as the same user.

## (c) Is a second concurrent redeem by the same buyer refused before any attempt is recorded?

**Yes, and I tested it through `redeem()`.**

`tests/Feature/PaidGrantReviewAddendum3Test.php` runs the real finalize, document and authorize steps, then:

1. Authorizes `master_wav` and `contract` for one buyer.
2. Redeems the master. It holds slot 0, and `slot-0.holder` equals the account digest.
3. Redeems the contract while the master transfer is held. It throws `DeliveryException('target_unavailable')`, which the controller maps to a sealed 503.
4. Checks what the refusal left behind:
   - `paid_redemptions` is still 1;
   - no snapshot exists;
   - slot 1 has neither a `.reserve` nor a `.holder` file, so the refusal came before any sidecar was created;
   - status reports `attemptCount` 1, `contract` as `unused` and `master_wav` as `attempted`.
5. Releases the first transfer, which frees slot 0, then redeems the contract again. It streams a PDF, and `paid_redemptions` goes to 2. `license_grants` stays 0.

In the test, the first transfer is released by dropping it rather than by streaming it. The second redeem committed frames in the same process, which by design invalidates the held transfer's pre-byte proof. In production, the second request runs in another process.

## Runs (`review-evidence/addendum3/3f7d295c/`, head `3f7d295c`, PHP 8.4.26, SQLite, `public/build` absent)

| Command (args to the worktree PHPUnit runner) | Result | rc | File |
| --- | --- | --- | --- |
| `--filter PaidGrantSpool tests/Feature` | 12/12, 48 assertions (Reservation 5, my Addendum 1 spool test 3, Addendum 2 spool test 2, the untracked Addendum 3 spool test 2) | 0 | `sqlite-spool.{txt,xml}` |
| `tests/Feature/PaidGrantReviewAddendum3Test.php` (redeem path) | 1/1, 26 assertions | 0 | `sqlite-redeem-one-slot.{txt,xml}` |
| `pint --test` on both new test files | passed | 0 | `pint.txt` |

**Not tested:**

- the full paid family at this head (the coordinator reports 93/93 with 1 skipped);
- a multi-process spool run;
- native MySQL;
- a browser.

**Untracked files added:**

- this file;
- `review-evidence/addendum3/`;
- `tests/Feature/PaidGrantSpoolReviewAddendum3Test.php`;
- `tests/Feature/PaidGrantReviewAddendum3Test.php`.
