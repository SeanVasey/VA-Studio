# Required supervised account ledger CI — source candidate

Both manual Foundation mirrors now require `backend-ledger`, the same common
bootstrap/controller/suite, and strict current-run receipts for all four serial
providers: `up-overlap`, `down-overlap`, `up-end`, `down-end`. The existing exact40
`expected_sha`/moved-ref checks precede allocation in both jobs. Bootstrap also
requires HEAD and the manual event SHA to equal the requested commit before
snapshotting it. No automatic full matrix, dispatch, prior-run reuse or optional
ledger gate was added.

This is **source implementation and HOST validation, not a runtime or CI pass**.
The local native proof at90334297 remains historical and source-scoped. Do not
promote it to proof of these builds, namespaces, loaders or workflow jobs.

## Owned source, runtime and controller

`source-snapshot.py` verifies the actual Git commit, reconstructed tree and every
archive blob, and seals root-owned source under `/run/va-ledger-<nonce>`. It copies
no working-tree files, `.git`, ambient `.env`, submodules or tracked symlinks.
The acceptance UID cannot alter the source, descriptors or root audit directories.
`RUNNER_TEMP` and writable ancestors cannot satisfy the producer path checks.

`Runtime.Dockerfile` uses the pinned Debian base
`debian@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4`,
official signed trixie/trixie-updates/trixie-security selectors, exact PHP
`8.4.27-1~deb13u1`, and exact full Python/libpython/stdlib `3.13.5-2+deb13u5`.
Composer is a signed distro build tool. Full resolved package/download inventories
and signed APT indices remain associated with the resulting immutable image ID.
Moving signed mirrors provide controlled provisioning, not snapshot reproducibility.
Unavailable exact packages or mismatched retained binary/module hashes stop admission.
No ambient binary or the historical Debian-base image ID is self-admitted as PHP.

The private privileged controller copies Docker28.4 and buildx from the official
linux/amd64 `docker@sha256:b0ea6e8d57b28d04a6999591fdd0f10de8717e956f4f4b6128d2a8cf57b27eb9`.
Public config12af9dba and published buildxv0.28.0 binary SHA256
`696bc104bac3bb708eff1af3f8bbc09fda0fd88f5757c1f9b404a35117889224` establish source
provenance/path association only; actual files/builds remain unqualified.
The source checks actual engine28.4/API1.51/commit249d679, cgroup-v2/cgroupfs and
published buildx bytes. Inner dockerd and producer run in the same PID namespace;
outer-host PIDs are never substituted for its `inspect.State.Pid` observations.
The daemon exposes only its private Unix socket, owns its data directory, and uses
explicit nested networks. Workers have no socket, privileged mode or host PID/network.

Inherited Docker client configuration/registry credential selectors are preserved,
not replaced or opened by the source helper. Only selected proxy variables reach
the daemon. Builds use transient required combined-CA secrets for networked RUNs;
Composer gets a read-only CA mount and verified TLS settings. Session CA material is
not copied into image layers, and there is no direct-egress/TLS-verification bypass.

## Qualification and real provider execution

The MySQL factory retains the accepted private bootstrap/replacement, ownership,
resource and bounded Admin/archive transports. HOST reverse-normalization controls
prove the finite root/path/nonce/source substitutions; accepted `capture.py`, Admin
and archive code remain byte-identical. Service-only source checks do not claim a
Composer dependency audit. The observer port retains the accepted native SQL body:
real1142/1227 denials, exact six-to-ten scoped grants, unchanged instrumentation and
positive own-thread/current-event/global visibility witnesses from the same account.

Dependencies install only from the reviewed lock with scripts/plugins disabled,
then audit, stop/remove the installer, verify locked package identities and retained
critical vendor pins, and seal the complete output inventory. No lockfile changes.
The ROOT job descriptor binds those qualified artifacts, source, service and case;
its credential and public admission files are root-owned read-only mounts.
Acceptance UID1000 cannot produce or change admission. Reusable test acceptance
reads the independently qualified binary profile rather than a local executable pin.

The accepted supervisor, SCM credentials/nonce/FD7, two-phase pidfd custody, sole
reapers and WORK480/cleanup20 logic are reused. Native outer600/kill10, PHP512M,
64KiB capture, genuine Laravel ledger/retained rows and ordinary artisan restarts
remain required. Parent Prepared origins, killed-worker lineage and every normal
restart origin are checked. No native mock, direct multi-provider PHPUnit run or
retry is accepted. Each actual outer exit must be zero, captured and reaped.

All six postflight checks run before teardown, on failure too: owned state, server
profile, strict whole-server foreground/orphan/transaction/lock/wait resources,
PHP absence, memory and exact source/dependencies. Failure remains unverified;
killing a container cannot redeem a receipt. Setup-only PID1 sleep containers use
owned SIGKILL/actual137/removal rather than relying on ignored default SIGTERM.
The service shuts down through its own handler with actual0 before owned removal.

The exact four-provider class is explicitly inventoried in `selection.json` under
`tests/Supervised`; namespace and qualified-profile binding normalize to the original
accepted test bytes. It is neither a helper, residual nor skip. Both required gates
collect all four real receipts; ordinary shard source censuses remain ordinary
censuses. The pure foreground classifier stays Unit. The fixed native worker is
transparently listed in the existing driver-branching helper policy.

## Verification and remaining qualification

HOST controls execute no PHP, SQL, Docker, installation, Git snapshot, native
supervision or CI. They cover source-object identity, provenance/ownership,
immutable descriptor/publication, runtime/observer contracts, original supervisor
controls, strict receipt refusals, workflow parity and moved-ref gating. Run:

```
for ledger_control in scripts/ci/account-ledger/test-*.py; do python3 -B "$ledger_control"; done
```

The cheap preflight and both manual quality jobs run those HOST controls. Existing
scope/cadence/shard/database/GitLab receipt controls are also required.
Recorded source verification: 68 ledger HOST controls, 27 scope, 14 cadence,
52 shard, 61 database-receipt and 33 GitLab-receipt controls pass (255 total).
All 27 Python sources parse; source/parent-entry pins and Git whitespace checks pass.
These include synthetic receipt fixtures, not native execution evidence.

No controlled image build, PHP lint, native probe/provider, workflow dispatch,
package installation, push, PR or merge ran for this source candidate. GitHub's
outer Docker/buildx/sudo capability and GitLab's privileged nested-controller
capability are **UNQUALIFIED**; missing capability fails the required job and blocks
release. No runner configuration or host permissions were changed.

This local execution user is UID1000 and has no sudo executable. The ROOT-only
producer/path guards were preserved. A separately reviewed owned root transport
container could later perform local bootstrap; that execution design is unrun.

ROOT next reviews the frozen source, then in its free runtime slot validates PHP
syntax and the controlled build/controller/observer/dependency/loader boundaries,
and executes the four original providers serially with authoritative outer exits
and all six audits. Preserve failures and qualify each actual source; reserve full
Foundation dispatch for the final integrated exact reviewed SHA under the cost policy.
