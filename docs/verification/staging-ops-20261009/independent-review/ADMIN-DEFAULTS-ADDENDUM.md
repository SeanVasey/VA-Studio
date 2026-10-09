# Independent MySQL administrator defaults custody review

**Decision: APPROVE** exact functional source
`fd9dd035dc9069db3727bd12960bb5952eb28399` for the narrowly scoped repair of
Codex finding `4227010176`. No unresolved finding remains in this delta. Actual
Forge host custody and administrator authentication remain unverified.

The optional `--mysql-admin-defaults` file now requires a canonical absolute
path, a regular nonsymlink leaf owned by root with mode 0600 and one link, and
canonical root-owned ancestry without group/world write permissions. The actual
CLI admission calls this guard before package installation, filesystem/service
changes and the first MySQL query. It refuses unsafe custody without repairing
ownership or permissions. Omitting the option retains the local root socket
path. Protected ancestry removes application authority to replace a validated
path; simultaneous changes by a trusted root operator are outside this boundary.

## Independent evidence

```sh
python3 docs/verification/staging-ops-20261009/independent-review/admin-defaults-probe.py --source-sha fd9dd035dc9069db3727bd12960bb5952eb28399
```

[Final receipt](admin-defaults-final.txt): exit 0, **4 methods PASS**, covering
**25 states**. The probe executes the complete, unmodified CLI admission prelude
(including its real argument parser), followed by an admission sentinel and the
actual first-query construction. All 22 unsafe states refuse before either
sentinel or query: missing files, leaf/dangling/ancestor symlinks, hardlinks,
directories, FIFOs, five incorrect leaf modes, application-owned leaf/parent/
ancestor, writable parent/ancestor, and five noncanonical path forms. Canonical
protected profiles, a canonical filename containing spaces, and the omitted
option reach exactly one bounded query with the intended arguments. Existing
fixture inode, mode and link count remain unchanged.

[Old-source receipt](admin-defaults-red.txt), exact
`d7e60167037c04e3e5c26549153027a25c7d0fb2`: exit 1, **4 methods / 22 failing
states**. The same complete CLI prelude admits every unsafe case and reaches the
bounded first query. Three intended success states already pass. No fixture
failure was discarded. Three unittest progress-line trailing spaces in this red
receipt were removed for repository whitespace checks; outcomes are unchanged.

The fixtures use real Bash control flow, owned files, mode/link/type checks and
canonicalization. Root/application UID facts and `/tmp`'s protected ancestor
mode are modeled. MySQL is a harmless tracing substitute. The omitted host
stages never execute; this is an early-admission proof, not evidence that host
provisioning succeeds. No credential, account or global host file is changed.

Independent `bash -n ops/staging/provision.sh` and `shellcheck -x` exit 0. An
initial Shellcheck invocation without `-x` exited 1 with SC1091 for the existing
`/etc/os-release` source; the correct `-x` invocation passes without a source
change. Owner receipts inspected at this exact source show **71 operations tests
PASS** and genuine **PHP 8.4.26: 13 tests / 63 assertions PASS**. The owner's
focused old-source receipt retains 14 failing states before the repair.

## Carry and limits

The explicit source diff from the previously approved publication `d7e60167`
contains only the administrator custody function/call/header in `provision.sh`,
its new canonical regression test, and explanatory README/CHANGELOG entries.
An exclusion check for exactly those paths and `docs/**` has an empty diff,
exit 0. Previous app/backup native MySQL authentication, release custody,
operation serialization, runtime and nginx approvals carry for unchanged code.
Their historical receipts have not been rerun or relabeled as current tests.

Approval does not certify an actual root-owned admin profile, host MySQL
authority, Forge/systemd/mounts, DNS/TLS, backup shipping/key recovery, or a real
Stripe TEST purchase. Exact publication source equivalence, cheap preflight,
final Codex review and expected-head merge gates remain the owner's work.
