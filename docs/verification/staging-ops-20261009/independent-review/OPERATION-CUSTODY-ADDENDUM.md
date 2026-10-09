# Independent root-operation and credential-custody review

**Decision: APPROVE the bounded development change at exact functional source
`9cc584c471a90bacac8979c185496d0cc412a292`.** No unresolved material source finding
remains in the assessed delta. This decision does not establish Forge host acceptance.

The assessment covers Codex findings 4226458399 (locks released before migration)
and 4226458404 (an existing database identity without its persisted credential), plus
the independent standalone-configuration interruption defect and the final public
service-operation corrections. I read the actual controller, fixed release helper,
Forge orchestrator, provisioning changes, canonical tests and recovery instructions.

`prepare`, `activate` and `refresh` now hold the root control lock and writer barrier
through their critical application child and sealing or healthy resume. The child
receives neither privileged descriptor. A root-only marker is created before an
application child can run and persists on failure or root-parent death, so a surviving
child cannot be passed by a new public resume. Recovery quiesce preserves that marker.
The fixed helper is admitted only as a protected root-owned, regular, single-link
file; application evidence paths are written by the application child. No arbitrary
command is executed as root through these operation arguments.

Standalone configuration is now internal to refresh. Public quiesce/resume have the
same interruption custody. Read-only resume admission occurs under the root control
lock before marker creation, so a queued resume after successful activation cannot
poison recovery state. Cold re-quiesce bypasses the HTTP503 requirement only after
the actual stopped-web predicate succeeds, avoids redundant worker stop only after
the actual stopped-worker predicate succeeds, and still proves both final states.

Existing app credentials must have protected custody and the exact finite literal
two-line profile before grants. Missing, malformed or unsafe files refuse without
rotation. A missing database identity cannot overwrite an orphan credential file;
generation is checked before account mutation and first persistence is no-clobber.
These checks establish file custody, not that a stored password authenticates to the
actual host database.

## Independent commands and receipts

The final command was:

```sh
python3 docs/verification/staging-ops-20261009/independent-review/operation-custody-probe.py --source-sha 9cc584c471a90bacac8979c185496d0cc412a292
```

[Final receipt](operation-custody-final.txt): **10 methods PASS**, exit 0. The probes
exercise real kernel locks, descriptor closure, process lifetime and SIGKILL of an
owned root-controller stand-in while its application child survives. They include
all public mutators after interruption, configuration/build lease ordering, unsafe
fixed-helper files, credential bytes and no-overwrite behavior, seven warm/cold/partial
quiesce states, and five read-only resume refusals. The actual public quiesce/resume
bodies are exercised with genuine owned process cwd for the worker-path proof.

Preserved earlier receipts:

| Receipt | Exact source | Outcome |
| --- | --- | --- |
| [Credential red](operation-credential-red.txt) | `565870388ff698bb94067913ede576d5cce31e74` | 1 method, 10 malformed/missing credential failures |
| [Standalone configure red](operation-configure-red.txt) | `0e0df68d7eca40c892eb8cfc959264f240ffd04c` | 1 genuine interruption failure: resume passes a surviving configuration child |
| [Lifecycle red](operation-lifecycle-red.txt) | `0e0df68d7eca40c892eb8cfc959264f240ffd04c` | 2 methods, 8 failing states: cold HTTP503 demand and missing public-operation custody |
| [Resume admission red](operation-resume-admission-red.txt) | `a7cf5d55e6f89da367cf59181c05ef9efa7a8d10` | 1 method, 5 false interrupted-state failures |
| [Initial green](operation-custody-initial-green.txt) and [expanded green](operation-custody-a7cf-green.txt) | `a7cf5d55e6f89da367cf59181c05ef9efa7a8d10` | 7 then 9 methods PASS; superseded by the final exact-source selection |

[First custody attempt](operation-custody-attempt1.txt) preserves one fixture expectation
error: allocation was added to the trace; checking ordering instead of two adjacent
entries corrected it. [First lifecycle attempt](operation-lifecycle-harness-attempt1.txt)
preserves historical-fixture incompatibilities: the old fixture lacked an FPM service
variable and a down-command maintenance side effect. The corrected driver supplies
both bounded substitutes. Those attempts are not counted as product regressions.
Only progress-line trailing whitespace and the space after ten empty assertion
messages in the credential red are normalized for diff checks; assertions and
outcomes are unchanged.

The four changed shell scripts parsed separately and Shellcheck `-x` passed; the
final five-line controller admission delta also passed those checks. Product source
outside this operation/provision/orchestration delta has an empty diff from previously
approved `56587038`, including application/configuration/migrations, commerce runner,
HTTP templates, backup, sealer and dump normalizer. Prior approvals carry on those
unchanged paths; historical native transport/migration receipts are not rerun or
relabeled as current execution.

I also inspected the owner's exact-source `final-ops-9cc584c4.txt` (**60 PASS**) and
genuine PHP8.4.26 `final-runtime-9cc584c4.txt` (**13 tests / 63 assertions PASS**).
These are owner receipts, separate from the independent probes above.

## Remaining acceptance bounds

The probe imports canonical fixture infrastructure at the assessed source, retaining
actual controller definitions/dispatch, file bytes, modes, links, locks and process
behavior. Root ownership and host service/mount/database authority are modeled.
Laravel admission in the interruption probes and Composer/npm build effects are
substitutes; they do not prove actual Laravel migrations, host configuration refresh
or credential recovery. The reviewed runbook requires root to stop/reap surviving
helpers and descendants, inspect or restore state, and only then remove the marker.
A later marker-cleanup or evidence failure after healthy resume needs inspection;
it does not prove a closed gate.

Actual sudoers, service supervision, bind mounts/fstab, host backup/restore and shipping,
DNS/TLS, real credentials, Stripe TEST delivery and historical encryption-key custody
remain pending. Manual/rogue processes outside the controlled census and compromised
builders remain explicit trust limits. A no-current first-install snapshot establishes
no historical key. Any later product-source change requires a new review or an explicit
exact-source carry check.
