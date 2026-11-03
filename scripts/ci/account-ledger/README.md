# Ledger CI runtime producer — source implementation

This directory implements the first independent provisioning phase. It builds
and qualifies the runtime without executing candidate application code, reading
credentials or creating a MySQL service. **It does not yet implement required CI
routing or native admission.** The existing four-provider Feature test and its
accepted supervisor/worker/pins remain untouched; no skip or discovery exclusion
has been introduced. Do not open the ledger PR as CI-portable on this evidence.

## Source and trust boundary

The controlled build uses Debian base
`debian@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4`,
the observed official signed trixie/trixie-updates/trixie-security sources and
exact PHP `8.4.27-1~deb13u1` / Python `3.13.5-2+deb13u5` packages. APT performs
its normal Release/Packages/package signature-chain, expiry and transport checks.
No unsigned installer or disabled verification is used. These moving mirrors
provide controlled provisioning, **not snapshot reproducibility**. If an exact
package is unavailable, provisioning fails; there is no version fallback.

ROOT observed that historical image ID `394ffa2d…` identifies the Debian base,
not a complete PHP/Python image. The producer therefore never treats that ID or
base RepoDigest as a qualified runtime. It builds a new image, obtains its full
immutable ID through its owned bounded Docker CLI, verifies Linux/amd64, and
checks actual PHP/Python/module hashes against the independently retained pins.
The image retains signed APT indices and source selectors. The runtime-only
descriptor retains the full installed package inventory and downloaded `.deb`
SHA256 inventory, plus their built-image association.

The controller retains the accepted factory's Docker 28.4.0/API1.51/commit249d679,
Linux/amd64, cgroup-v2/cgroupfs profile. The existing accepted `capture.py` is copied
byte-for-byte (SHA256 `39bd4cb691387b9eb838e8b1454d0ac992bc3da367c7788bf7ad822515d31c8f`)
from public factory manifest
`23ba75c8f111e4f821de76c4718acbaa616eebc9d26abf9b5f137c9182df7efc`.
It preserves combined 64 KiB capture, EOF/reaping, no-stderr acceptance and bounded
commands. Provisioning has WORK480 plus one cleanup20 reserve inside 500 seconds.
The ledger supervisor's own deadlines and protocol are unchanged.

The future trusted workflow must first verify exact `expected_sha`, copy and seal
the reviewed producer source into a root-owned tree outside the acceptance UID,
verify its source-manifest hash using trusted bootstrap commands, and invoke the
producer with clean Python environment (`python3 -E -s`). Self-checks in this
program are defense in depth; they cannot authenticate already executing code.
Every source/output ancestor must be root-owned and unwritable by other users.
The producer refuses symlinks, writable ancestors, duplicate JSON keys, unknown
policy fields, ambient image tags and unpinned recipes. Only ROOT has Docker
access. The probe container runs UID/GID1000, read-only, with no network, all
capabilities dropped, no-new-privileges, 512 MiB, no swap, and no socket or writable
source mount. It has no sudo/host namespace/device mount.

After profile checks, actual TERM/wait143/removal and complete CLI transport are
required. A failed/uncertain allocation produces no qualification descriptor;
unknown backend state is not a returned lease. Descriptor publication is exclusive,
root-owned, mode0444, fsynced and cannot follow/replace an existing file. The
fresh root-owned output directory also retains mode0400 progress with intended
name, immutable created ID and closed CLI outcomes; raw process output is excluded.
This preserves ownership for investigation when provisioning fails.
The
descriptor deliberately says `kind=runtime-qualification-only`,
`native_admitted=false`, and `requested_candidate_sha`: it does not establish
candidate application origins, a live service lease, native cleanup or acceptance.

## Remaining implementation and verification

Before required routing can replace ordinary Feature discovery:

1. Wire trusted workflow bootstrap/controller provisioning. Hosted controller
   compatibility with the strict Docker/cgroup profile is unverified; do not
   relax it implicitly. Build this exact recipe, run PHP syntax and both profile
   probes, and inspect the actual package/download closure and image identity.
2. Port the accepted public MySQL factory, ownership/secret transport and observer
   qualifier together. Preserve Oracle8.4.11 identity, exact grants/instrumentation,
   positive visibility witnesses, full unknown-foreground/global-resource audits,
   all six cleanup checks and returned lease. Runtime qualification is insufficient.
3. Port root/vendor bindings and all parent/worker/ordinary-restart origin checks
   together from the immutable reviewed adapter. Supply a separate closed,
   root-owned **native** job descriptor; acceptance cannot create or alter it.
4. Reuse the accepted sole-reaper pidfd/SCM/nonce/FD7 supervisor for exactly the
   four serial original keys, preserve real Laravel ledger/retained-data/restart
   checks, capture each outer exit, then require all four strict current-run receipts
   in the existing manual final gate. Preserve exact40 `expected_sha` and cost policy.
5. Only with that complete required route, relocate the supervised class from
   ordinary Feature discovery and update source pins/census transparently. The
   pure foreground classifier stays Unit. No unconditional skip/quarantine.

HOST verification: `python3 -B scripts/ci/account-ledger/test-runtime-producer.py`
executes finite policy/provenance/isolation/ownership/publication/cleanup-failure
controls. It does not execute Docker, PHP, SQL, native supervision or CI. No native
claim follows from scripted transport responses in these controls.

No runtime build, package installation, PHP lint, Docker/native invocation,
workflow dispatch, push, PR or merge was performed for this source slice.
