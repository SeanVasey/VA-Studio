# Independent build and provisioning admission addendum: PR #63

**Decision: APPROVE exact functional source `7bced5ecb91593e28854297a2ca14247931c3448`. Runtime source approval carries to evidence successor `e6843b1fbf2fabe9150416966581e021f2ced6bb`. No unresolved implementation blocker remains in these two deltas.**

This addendum assesses Codex findings 4226283468 and 4226283473 after the prior [Codex repair decision](CODEX-REPAIR-ADDENDUM.md). It does not reinterpret prior host or provider bounds as completed acceptance.

For 4226283468, real Git comparison against tracked source does not attest ignored `vendor/` or generated build files. At the prior candidate, the independent live-process probe modified actual ignored vendor bytes while the extracted deploy body still completed its real Git checks. The repaired deploy validates the frozen environment using the protected current release, when present, and then invokes quiesce before creating any application-writable release candidate. The built candidate repeats admission before attachment and sealing. The unchanged root quiesce implementation closes the durable pipeline gate, fences admitted sweeps, stops controlled workers/web and refuses unsafe writer state. Downtime now includes dependency installation and asset compilation.

For 4226283473, provisioning now checks lexical canonical components and walks every existing ancestor, including an existing root, before package/account/database/filesystem changes. Existing directories must be canonical, root-owned and lack group/world write permission. Missing descendants are accepted only below this protected prefix. An additional absent-root edge found during review is closed: double slashes, trailing slash and dot components cannot pass provisioning only to fail the helper's canonical equality later.

## Independent evidence

The same selected independent probes read historical source with `git show`, without moving refs:

`python3 docs/verification/staging-ops-20261009/independent-review/build-admission-probe.py --source-sha c9ff14349707ca860aeb3ccf07c91b1a99207be8 BuildAdmissionProbe.test_controlled_live_actor_cannot_modify_ignored_vendor BuildAdmissionProbe.test_provision_refuses_ownership_modes_links_and_noncanonical_roots`

[build-admission-red.txt](build-admission-red.txt) records **2 methods / 11 safety failures**, exit **1**: one ignored-vendor injection and ten authority/mode/link/lexical subcases. These are assertion failures against the actual old deploy/root admission, not missing-command or invalid-fixture errors.

At the exact final functional source:

`python3 docs/verification/staging-ops-20261009/independent-review/build-admission-probe.py --source-sha 7bced5ecb91593e28854297a2ca14247931c3448`

[build-admission-final.txt](build-admission-final.txt) records **6 methods PASS**, exit **0**, without skips. The cases cover live ignored-vendor injection, protected-current admission refusal before downtime/allocation, build failure leaving admission closed, first-install quiesce before allocation, expanded root authority/link/mode/lexical rejection and placement of root admission before host mutations. The driver retains actual Git, files, links, modes and a separate live process; build tools, binding, service authority and the private gate are bounded fixtures. Root identity is modeled only for owned temporary paths. Real Laravel admission and the root helper's stop/barrier behavior remain covered by prior unchanged-source reviews and the integrator's current receipts, rather than being falsely claimed as real host behavior in this driver.

Separate `bash -n` checks passed for both changed scripts. The genuine shared-toolchain Shellcheck command `/workspace/.va-studio-toolchain/root/usr/bin/shellcheck -x ops/staging/forge-deploy.sh ops/staging/provision.sh` exited **0**, without diagnostics. Two earlier invocations found no `shellcheck` on the default or activated PATH; those exits **127** were tool discovery failures and are not reported as passes.

The reviewer inspected exact committed integrator receipts `final-ops-7bced5ec.txt` (**45 methods PASS**) and `final-runtime-7bced5ec.txt` (genuine PHP 8.4.26 / PHPUnit 12.5.34, **13 tests / 63 assertions PASS**). The prior 28 native HTTP checks remain evidence at `11248aa3`, with the HTTP template unchanged. No expensive native or hosted matrix was repeated.

## Carry and limits

Explicit source comparison from `11248aa3` shows no change to application/configuration/database/contracts/workflows, runner scripts, root control/quiesce, sealer, backup, parser, nginx or runtime validator. The changes are the two inspected scripts, their new canonical test and documentation. The `7bced5ec..e6843b1f` comparison across `app config database ops scripts tests resources/contracts .github`, excluding the separately read `ops/staging/README.md`, exited **0**. The README and runbook changes in that successor correct prose; the verification README distinguishes current 45/13-63 receipts, historical native checks and unsuccessful fixture attempts.

Activation failures **before a healthy resume** leave writer admission closed for recovery. After successful resume, later evidence-writing failure can occur with a healthy site and admission open. The reviewer requested that the owner narrow the documentation's universal “every failure after quiesce” wording to this actual boundary before publication.

These fixes fence controlled web, supervised workers and the admitted pipeline. Uncontrolled manual/rogue application-account processes must separately be stopped. They do not attest an uncompromised builder or immutable generated PHP execution. Actual Forge host permissions, installed privileged paths, services, mounts, DNS/TLS, secrets, restore/shipping and Stripe TEST purchase remain private-host acceptance work with Sean's inputs.

Only these new reviewer artifacts were written. No product source, canonical test, previous receipt, git ref, commit, secret, provider or host service was changed. Any further source-changing candidate requires independent assessment; evidence-only successors require an explicit equivalence check. One unittest progress-line trailing space in the new red receipt was normalized without changing assertions, failure detail or outcomes.
