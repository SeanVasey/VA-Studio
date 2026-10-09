# Independent Codex repair addendum: PR #63

**Decision: APPROVE exact functional source `11248aa3ee924cb3d4f3d1f8fe48bda39089cc2a`. Approval explicitly carries to evidence-only candidate `1f84d0b35024c38abdfaf795f91204b61e2cf7c1`. No unresolved blocking finding remains in the reviewed scope.**

The reviewer read the actual changes from the previous approved candidate, assessed the new privileged sealer/configuration interface, operator recovery and backup admission, and executed independent adversarial probes. The intermediate repair `11cf2dcc568975eedec434862807b45d301d014f` was held for the additional findings below. Earlier component/integration decisions and all original reds remain intact. This approval covers the exact development source and documented controlled-runner boundary, not real host activation or a future changed source.

## Codex findings

| Finding | Independent assessment |
| --- | --- |
| 4226007102: missing Stripe signature parameter | Absence of an explicit parameter did not prove default forwarding was broken. Inspected genuine nginx/FPM baseline passes all 28 checks, including exact signature and UTF-8 body. An inherited `fastcgi_pass_request_headers off` produces a real signature red. The explicit `HTTP_STRIPE_SIGNATURE` parameter closes that inherited-configuration boundary; current native receipt passes 28. This is transport hardening, not a falsely claimed default regression. |
| 4226007106: application-writable release files | The root-installed Python helper opens parents without following links, seals parent rename authority before opening children and replaces regular code/vendor/build/environment files on fresh protected inodes. Independent held-descriptor and concurrent-copy probes confirm old open writers cannot change installed bytes and changes during copying refuse. Hard links, special files and external/runtime code symlinks refuse; legitimate internal links and executables retain their documented behavior. Runtime contents are excluded. |
| 4226007111: restore proof without served key | Snapshot distinguishes absent `current` from malformed, dangling or outside references and refuses a served environment without a recoverable literal key before publishing a snapshot pointer. Reproof invalidates old success first, requires an unambiguous release manifest and requires the served environment plus its exact checksum record. Only explicit `release_sha=none` first-install sets omit a historical key. |

The protected environment is root:application-group 0440. The ordinary same-SHA deploy performs quiesce and snapshot before `ctl configure`. Configure requires closed admission, stopped services/writers and the served release in maintenance, captures a bounded private evidence file through anchored no-follow descriptors, then invokes real Laravel admission and unchanged-key validation as the application user. Only then does it replace the environment inode and build the configuration cache. The independent cache-failure case confirms the new protected environment remains installed for verified snapshot recovery and the action reports failure rather than resuming. The runbook now restores the verified environment as root on a fresh protected inode.

## Additional independent findings and closure

At `11cf2dcc`, the reviewer reproduced four issues:

1. A syntactically valid double-quoted multiline dotenv value contained an apparent `APP_KEY=` line. The old literal-key check accepted it; genuine locked Dotenv parsing confirmed no effective `APP_KEY` existed. The repair refuses unsupported multiline assignments before locating the literal key.
2. `env.backup.sha256` could contain a correct checksum for `database.sql`, allowing the environment check to pass without hashing the environment. The repair compares the generated single `env.backup` checksum record byte for byte.
3. An application-owned FIFO candidate blocked capture before its regular-file check, retaining a privileged action on a quiesced host. The repair uses nonblocking source/target opens followed by regular-file metadata checks. The owned FIFO now refuses immediately without touching the protected target.
4. The privileged sealer delegated operations when the application account resolved to UID 0. Provisioning, control, backup and the sealer CLI now explicitly refuse root application identity; independent CLI probes observe no delegated operation for seal, verify or environment capture.

The unchanged selected safety probes extracted the intermediate source read-only with `git show`: [codex-boundaries-red.txt](codex-boundaries-red.txt) records **4 methods / 6 safety assertion failures**, exit **1**. The six failures are one lexical-key case, one hash-target case, one FIFO case and three root-account action subcases. No branch/ref was moved, and no real root operation was attempted.

## Independent execution

At the exact final functional source, these commands exited **0**:

| Command / receipt | Actual result |
| --- | --- |
| `python3 docs/verification/staging-ops-20261009/independent-review/codex-boundaries-probe.py --source-sha 11248aa3ee924cb3d4f3d1f8fe48bda39089cc2a` → [codex-boundaries-final.txt](codex-boundaries-final.txt) | **9 methods pass**, including the four new findings, fresh source/environment inode custody, mutation during source/candidate copying, untouched retained runtime metadata/bytes and configure cache failure with genuine Laravel admission |
| `PHP_BIN=/workspace/.va-studio-toolchain/standalone/bin/php8.4 python3 -m unittest discover -s tests/ops -p 'test_staging*.py' -v` → [codex-canonical-final.txt](codex-canonical-final.txt) | **43 methods pass**, no skips; normalization, refresh, writer admission, sealing, key custody and protected configuration |
| `bash -n` separately for provisioning, deploy, backup, helper and runner; Shellcheck `-x` on those five scripts | Exit **0**, no diagnostic |

The reviewer inspected the integrator's exact-source `final-runtime-11248aa3.txt`: genuine PHP 8.4.26 / PHPUnit 12.5.34, **13 tests / 63 assertions**, exit **0**. The `final-signature-11248aa3.txt` receipt reports genuine nginx 1.26.3 / PHP 8.4.26 FPM, **28 transport checks**, exit **0**, with inherited request headers disabled. Those are inspected integrator executions, not additional independent native or PHPUnit reruns. The app/config/database, contracts, #62 runner/validator/profile and relevant canonical commerce/runtime tests have empty diffs from the earlier reviewed source. The 89/876 commerce selection and native MySQL receipts remain historical; they are not relabeled as executions of this helper repair. No long matrix or extra hosted CI was launched.

The original owner sealing/key-custody reds and corrected intermediate canonical reds were inspected and retained. The native default-header baseline is positive evidence; only the inherited-off signature failure is its regression red.

## Fixture and release bounds

The independent tests retain genuine inode replacement, file descriptors, modes, links, bytes, process lifetime, PHP/Dotenv parsing and Laravel configuration admission. Root ownership is modeled only in owned temporary trees. Privileged filesystem/service authority and the configuration cache command's side effect are bounded fixtures. Runtime private-file bytes, inode, owner/group and mode remain unchanged during sealing. These checks do not prove real host root permissions, installed sudoers/helper paths, mounts, service activation or deployment.

[The first combined harness attempt](codex-boundaries-attempt1.txt) contains the six product failures plus two fixture errors: its single-quoted multiline example was invalid Dotenv syntax, and its unquoted configuration value contained a space, so genuine admission correctly refused before the intended cache-failure case. The corrected product red uses the valid double-quoted example; the cache test now supplies a valid single-line profile. These errors were preserved and are not counted as product findings. Trailing spaces on unittest progress lines in these new receipts were normalized for publication without changing assertions, traces or outcomes.

Writable `bootstrap/cache`, compiled views, maintenance files, logs and private storage remain explicit application trust exceptions. Fresh readonly source files do not certify an uncompromised builder or immutable generated PHP execution. The post-seal Git comparison detects ordinary tracked-source changes during the app-owned build window; it is not a complete build attestation. Backup key admission deliberately supports one complete assignment per line and refuses multiline profiles. First-install sets cannot claim recovery of historical encrypted columns.

Only new reviewer-owned probes, receipts and this addendum were written. No product source, canonical test, previous receipt, git ref, commit, provider, account or actual secret was changed by the reviewer. Actual Forge/DigitalOcean, root/service/mount/AppArmor, DNS/TLS, paired-key restore, encrypted backup shipping and the first real Stripe TEST purchase remain private-host acceptance work with Sean's inputs.

## Exact evidence carry

The final evidence README accurately distinguishes default-header behavior, current 43/13-63/28 receipts, historical commerce/native checks, unsuccessful attempts and the explicit runtime/builder bounds. The following command exited **0**, with no product-source difference:

```sh
git diff --exit-code 11248aa3ee924cb3d4f3d1f8fe48bda39089cc2a..1f84d0b35024c38abdfaf795f91204b61e2cf7c1 -- app config database ops scripts tests resources/contracts .github
```

`git diff --check` exited **0**. Approval carries to that exact evidence candidate. Any later evidence/base candidate still needs its own exact comparison; functional changes need re-review. Cheap preflight, current Codex findings and expected-head merge remain integrator gates.
