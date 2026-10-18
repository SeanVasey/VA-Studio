DECISION: APPROVE WITH CONDITIONS at a28a87c56b98be9521b27a897af5b3cd57bc725a

I found no P0 or P1. One new P2 needs fixing, or deferring in the ledger with a guide caveat, before r4305404770 is closed.

**Prior conditions**
- **P2-1: closed.** I reproduced the reviewer's case on a chunker remote with `hash_type = none`. The ship is refused, the old ciphertext is byte-identical and no digest is sent (backup.sh:448–459).
  - Any `lsf` exit other than 0 or 3 is refused with "could not list" and nothing is sent or kept. I checked exits 1, 2, 4, 5 and 7 (410–419).
  - Set names are restricted by SET_NAME_RE, so filter escaping cannot occur. The listing is top-level only.
  - The digest read-back with `cmp` is exact. It is bounded in practice: a 64 MiB remote digest was refused in 0.18 s, and a digest one byte short was refused (451, 467).
  - A first ship to a path that does not exist yet succeeds.
- **P2-2: closed for accumulation.** The cap keeps the newest other copy plus the set being shipped (513–522). `check-offhost` and the log report the copies accurately. Symlinked retry folders and records are skipped. There is still a kept-copy gap: see P2-A.
- **P3-1: closed.** `predeploy` sets the wait to 600 s (667, 498). A manual `ship` keeps the default 0 and refuses at once, and the environment cannot override it. Behaviour past the bound is documented (guide:58–63).
- **P3-2: closed** (guide:63–65 and a code comment).
- **P3-3: closed, with P3-B below.**
- **P3-4: closed.** All six earlier mutations are killed.
- **P3-5: closed.** The guard compares `head -n 1` exactly. The derived production provision.sh:264 matches, and the profile's first line is exact (no CR).

**New findings**

**P2-A: when a transfer fails because the remote went away, retries no longer converge.**
- On failure, the copy is dropped whenever the follow-up `lsf` does not report `<set>.tar.age`, and that includes when the listing itself fails (backup.sh:468–473).
- An outage that interrupts the digest upload after the ciphertext has landed is the most likely real case of the original r4305404770. In that case the listing fails too, so the copy is deleted. Every retry is then refused with "remote already holds … remove that object first".
- Reproduced with a wrapper: the digest upload fails and `lsf` fails while the outage lasts. The retry after the outage ended exited 1 with that refusal.
- The guide (line 118) and the log (line 472) both say that "a retry encrypts the set again", which is wrong here.
- **Remedy:**
  - Drop the copy only when the listing succeeded (exit 0 or 3) and showed no ciphertext. Otherwise keep it; the cap already bounds disk use.
  - Correct line 118 and line 472.
  - Add the outage test.

**P3-B: "already shipped" never looks at the ciphertext, on any backend.**
- With the remote `.tar.age` replaced by 34 bytes of garbage, a repeat ship exits 0 "already shipped" (449–455). It is not size-checked either.
- Deleting the ciphertext is refused, but my mutation R-a (accept when only the digest name is present) survives the tests.
- The guide (lines 112–115) does not state this limitation.
- **Remedy:**
  - Record the ciphertext size and compare it with `lsf --format sp`, or say plainly that "already shipped" does not re-check the ciphertext.
  - Add tests for replaced and deleted ciphertext.

**P3-C: the promise "never drops the set being shipped" is untested.**
- My mutation R-b, which removes the `"$kept" != "$name"` exclusion at line 516, survives. The cap test uses fake copies, and the current set's copy gets recreated anyway.
- **Remedy:** a test with a real reusable copy for the set being shipped plus two newer copies, asserting "re-sending".

**P3-D: the deploy's own time limit is not covered (guide:58–63).**
- The guide should say that any deploy-runner time limit (Forge) must cover 600 s plus the snapshot and ship. Otherwise the runner can strand the operation marker anyway.
- I did not verify whether Forge has such a limit.

**Nit:** my mutation R-c survives. With the `! -L` check on the shipped record removed (450), no test fails. Impact is low.

**Commands and counts** (I extracted HEAD with `git archive` into a scratch copy; vendor was set up as briefed; `App\` resolves to the scratch copy)
- **Ops suite:** `python3 -m unittest discover -s tests/ops -p 'test_*.py'` gave **309 OK**.
- **PHPUnit:** the brief's three files gave **149 tests, 2,693 assertions, OK**.
- **Kit:** `build-kit --check` ok, 13 files. `--delta` is byte-identical to `semantic-delta.diff` (213 lines).
- **Lint:** `bash -n` ok on all three scripts. shellcheck at default severity is clean on backup.sh and host-baseline.sh. `-S warning` is clean on staging provision.sh, forge-deploy.sh and every derived production `.sh`.
- **Mutations:** P1–P5 and P6 (`enabled-runtime`) are all killed. Of my six new ones, three are killed (cap off by one, any `lsf` failure treated as empty, record not written) and three survive (R-a, R-b, R-c).
  - Each file was restored and its sha256 checked; the scratch backup.sh matches HEAD.
- **Probes:** I used real rclone 1.60.1 against temp local and chunker remotes only.
- **Worktree:** `git status` is clean and HEAD is unchanged.

**Not verified**
- An `lsf` permission error on a real backend. I ran as root, so I reasoned from rclone's exit-3 mapping instead.
- Any real remote, or the host's rclone version.
- Forge's deploy timeout, and the 600 s wait inside a real ctl or Forge deploy.
- systemd and cron.
- Large-bucket listing cost (reasoned only).
- A process killed mid-rename.
- PHPUnit beyond the three files.
- The evidence file line by line (spot-checked against my reruns).
