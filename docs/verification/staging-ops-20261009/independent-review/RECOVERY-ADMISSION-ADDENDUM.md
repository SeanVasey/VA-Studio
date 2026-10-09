# Independent recovery admission addendum: PR #63

**Decision: APPROVE exact functional source `a44664f4afffb8780093ba247b616b5375ed86e7`, carried to evidence candidate `ea9e0568295d1213c77c4aa59a43f4da12df1ea5` by an empty product-source diff. No unresolved implementation blocker remains in the reviewed recovery deltas.**

The reviewer assessed the actual recovery changes after the prior [build admission decision](BUILD-ADMISSION-ADDENDUM.md), independently reproduced the three new Codex findings on historical source and executed the repaired branches with bounded host substitutes.

| Finding | Actual repaired boundary |
| --- | --- |
| 4226365351: unchanged-SHA retry succeeds after failed resume | Equal environment bytes now require root `healthy <sha>` before a no-op succeeds. Under the existing serialized helper action, the proof requires the sealed selected current, exact open writer gate, no maintenance marker including dangling links, persistent private bind and matching inode, active FPM, every supervised worker/PID on the served release and HTTP 200. Partial state refuses without repairing or resuming it. |
| 4226365357: first-install migration skips snapshot | The deploy invokes snapshot and isolated proof unconditionally before the migration block, including no-current first-install retries. A refused snapshot prevents reaching migration. Existing backup admission still identifies no-current sets explicitly; they do not establish historical environment or encryption-key custody. |
| 4226365364: interrupted bind attachment lacks fstab entry | Both new and already-mounted successful attachment reconcile the exact persistent bind line. Repeated attachment does not add another identical line; unrelated entries remain intact. A different mounted source or failed persistence refuses rather than reporting attachment success. |

## Independent execution and evidence

[recovery-admission-red.txt](recovery-admission-red.txt) records the initial **3 methods / 7 safety assertion failures**, exit **1**, against exact historical source `3b7d1b9c2b763847a5922ca558610638eaaf0308`. The failures cover skipped initial snapshot/refusal, missing persistence after two mounted retries and a write failure, and absent required health proof on unchanged environment. Historical source was read with `git show`; no ref or branch moved. To reproduce those original three cases with the subsequently expanded driver, select its snapshot, mounted-attach and same-SHA methods explicitly.

At exact functional `a44664f4`, [recovery-admission-final.txt](recovery-admission-final.txt) records **4 methods PASS**, exit **0**. The added health method exercises **18 complete/partial states**, including unsealed current, closed or malformed admission, ordinary and dangling maintenance markers, unmounted/wrong private source, missing persistent line, stopped FPM or worker, invalid PID, wrong worker path, failed HTTP, wrong requested SHA and invalid SHA. It checks retained environment bytes/inode and gate/fstab bytes remain unchanged; service and worker fixtures reject attempted mutation.

The reviewer then added a genuine Python wrapper-failure case to clarify the defensive return-propagation evidence. [recovery-sealer-baseline.txt](recovery-sealer-baseline.txt) records **1 method PASS** against the old source. [recovery-admission-final-expanded.txt](recovery-admission-final-expanded.txt) records the completed **5 methods PASS**, exit **0**, at the same exact functional source. The command is:

`python3 docs/verification/staging-ops-20261009/independent-review/recovery-admission-probe.py --source-sha a44664f4afffb8780093ba247b616b5375ed86e7`

The old actual `seal_tool`, `sealed_release` and `served_release` functions, with only protected-host authority modeled and a harmless genuine Python process exiting 23, already refused with exit **1**, empty stdout and `protected release operation refused`. The integrator's extra Bash red instead substitutes `sealed_release() { return 1; }`; it proves a failure-return property under conditional command substitution. The new explicit `|| return 1` is sound defensive propagation. It is not evidence that actual old Python helper errors were silently accepted. The inspected verification README and CHANGELOG now make this distinction accurately.

Separate Bash syntax checks and genuine toolchain Shellcheck `-x` passed for the changed deploy and helper scripts, exit **0**, without diagnostics. The reviewer inspected integrator exact-source `final-ops-a44664f4.txt` (**50 methods PASS**) and `final-runtime-a44664f4.txt` (genuine PHP 8.4.26 / PHPUnit 12.5.34, **13 tests / 63 assertions PASS**). Previous native transport evidence remains historical at `11248aa3`; no native host or expensive matrix was repeated.

## Carry and bounds

The explicit `a44664f4..ea9e0568` diff across `app config database ops scripts tests resources/contracts .github` exited **0**, with no product-source change. Evidence and documentation were inspected separately. Application/configuration/database/contracts/workflows, runner scripts, provisioning, sealer, backup, parser, nginx and runtime validator are unchanged from the prior approved functional source; only the reviewed helper/deploy recovery paths and relevant canonical tests changed.

These are point-in-time health and recovery checks. Genuine file bytes, links, permissions, Git source extraction, Bash control flow and a Python error exit are retained. Root/service/mount/HTTP authority, process-path lookup in the independent health probe, and snapshot provider execution are bounded substitutes; the integrator's canonical health test additionally uses real process working directories. No actual host fstab, mount, service, backup database, provider or secret was touched. First-install sets without a served environment cannot prove restoration of historical encrypted values. The existing controlled-writer, manual/rogue-process and builder-attestation limits remain in force.

Only these new reviewer-owned artifacts were written. Prior source, tests, receipts, refs and commits were not changed. Trailing spaces on three initial unittest progress lines were normalized without altering assertions, failure details or outcomes. Any future source-changing candidate requires review; evidence-only successors require explicit source-equivalence carry. Actual Forge activation, paired-key restore, off-host shipping and Sean's first real Stripe TEST purchase remain acceptance work with his host and private inputs.
