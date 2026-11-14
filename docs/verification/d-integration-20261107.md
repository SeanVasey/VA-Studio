# D integration — CAT, DEF-ID, UXA and PF1 onto C2 (2026-11-07)

Publication record for the D batch, stacked on C2 (`claude/c2-deferred-batch-20261107`), merged in this order:

| Lane | Branch head | Packet | Review | Evidence recorded in the packet |
| --- | --- | --- | --- | --- |
| CAT bounded staff media inventory | `2fc74539` | [`cat-media-inventory-20261103/`](cat-media-inventory-20261103/README.md) | APPROVE; F1/F3/F5 fixed | SQLite 11/59; native 11/59 |
| DEF-ID checkout metadata admission | `9278d79c` | [`def-id-metadata-20261103/`](def-id-metadata-20261103/README.md) | APPROVE WITH CONDITIONS, met | SQLite 53/169 (40/53 fail on unmodified main); affected 98/665; native 76 |
| UXA public accessibility spec | `614b9d5d` | [`accessibility-public-20261102.md`](accessibility-public-20261102.md) | no application code | npm audit 0, typecheck, vitest 1177, build; the axe browser spec needs a host with ClamAV signatures |
| PF1-A sound kit technical readiness | `f4f03a84` | [`pf1-a-technical-revision-20261103/`](pf1-a-technical-revision-20261103/README.md) | Codex: existing conditions fulfilled | unit 13/14 plus original A5 UI 12/88 (Codex) |

## Integration changes (this PR only)

- `ProductionCheckoutMetadataAdmissionTest` (DEF-ID) branches on the driver and was routed nowhere; it joins the native include list.
- Native selection recount on the integrated source: 2826 of 10341 cases in 278 files; both count comments updated.

## Verification at the integrated head (Claude harness, PHP 8.4.27)

| Check | Result |
| --- | --- |
| The five CI safeguard suites | all OK |
| SQLite: the 5 changed test files plus DEF-ID's affected checkout suites | OK, 182 tests, 945 assertions, 2 skipped (native-only) |

Not run here: the UXA browser spec (blocked in this container), the integrated native selection, Foundation CI. Real kit licence
and deliverable terms stay unset and refuse (PF1); no license term or price is introduced.
