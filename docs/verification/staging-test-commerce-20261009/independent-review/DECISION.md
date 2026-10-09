# Independent review: PR #62 test-commerce profile and runner

**Decision: APPROVE for the authorized development merge.**

Reviewed sensitive source: `f592ec93890fdf95be8b05b3d4fb5ccb5e706ddf`.

Comparison base: `c8d510004bc5f57e39068f619268eca7475e5478` (the current `origin/main` and merge base). This decision applies to the recorded source and its focused evidence. A successor changing functionality needs another review; an evidence-only successor needs an explicit equivalence check.

The review owns only this directory. The reviewer did not edit runtime, canonical tests, operator documentation, CI policy or configuration, and did not commit, push, deploy, supply credentials or contact a provider.

## Reviewed boundaries

The scope covers `scripts/ops/validate-test-commerce-profile.php`, `scripts/ops/run-test-commerce-pipeline.sh`, the profile and three systemd templates under `ops/staging/test-commerce/`, both new unit suites, `StagingTestCommerceProfileJourneyTest`, and the purchase walkthrough. The review read `AGENTS.md`, the handoff and Monday plan, and the D-13 through D-19 architecture contracts; it inspected the existing console selectors, test Stripe gateway, webhook verifier and delegated domain policies where they determine this profile's safety.

The validator uses the real policy classes instead of duplicating their acceptance rules. Its supplied dotenv copy is private and is the only file source; it overrides the configuration-cache path with a nonexistent scratch path. Exported process variables deliberately retain precedence. Independent child-process probes show that exported debug, production environment, live mode and a synthetic live-key marker are refused, and that neither an exported nor a file-supplied foreign PHP configuration cache is executed. No Stripe request is made during ordinary validation. The optional account GET requires both explicit flags and every preceding check to pass. Invalid profiles tested with those flags print that the probe was not attempted.

The profile selects local, debug-off, HTTPS, secure session cookies and an asynchronous database queue. It installs the exact nonbinding TEST contracts for pricing, inventory, order, checkout, payment finalization, contract issuance, activation and owner access. The pinned renderer check covers the actual PHP runtime, packages and fonts. The test gateway's independent environment, own-account, `sk_test_`, TLS and transaction guards remain in force. Production keys/mode and enabled out-of-profile families are refused. Neither this validator nor the journey turns a signed receipt or return redirect into payment authority.

The runner invokes only the five reviewed receipt/reconcile/finalize/issue/activate commands. It excludes Checkout Session creation recovery and per-order download enablement. The existing domain services retain authority over verified payments, immutable grants, original documents and purchased revisions. Command failures continue later stages but fail the sweep; timeouts are bounded. Saved UUID cursors use the commands' scoped immutable keysets and now cycle past retained failure rows, clear at the end, and revisit the beginning. Atomic state publication, guarded removal and cadence writes make persistence failures visible. A nonblocking lock excludes overlapping sweeps. Fresh state files are mode `0600` inside a `0700` directory. Unrecognized command output and stderr are suppressed; the independent probes assert that diagnostic and synthetic profile values do not reach output.

The walkthrough uses the reviewed interactive rights command and preserves immutable references and unknown write outcomes. It now treats scope as actual rights identity and explicitly forbids bypassing pending occupancy by publishing a successor or inventing another scope. Delivery remains an explicit per-order operator action. The Stripe CLI fallback keeps its key in a root-only environment file, outside arguments and Git, and uses a dedicated state home. Installation and actual forwarding were not performed in this review.

## Findings resolved

1. **Retained early rows starved later work at the page bound.** At `2a1b189e3efadab2c7e253042ed1944b2057f0d6`, two successful sweeps with one item and one page each repeated the same finalize, contract or activation UUID. The real selectors can retain retry/changed/pending-contract/asset-unavailable rows, so a backlog of those rows can indefinitely block healthy later orders. The unchanged independent probe failed all three cases; the implementer also retained three canonical red cases. `ff1479e604116cb4f8ca41d467675135af69bc40` adds per-stage persistence, and the final source preserves the fix. The unchanged independent proof passes all three cases. Canonical coverage also reaches the end, clears the cursor and revisits unresolved earlier work.

2. **State errors reported a successful sweep and nonexistent saved progress.** At `ff1479e604116cb4f8ca41d467675135af69bc40`, a directory occupying `finalize.cursor` made the write fail while the runner logged successful saved continuation and returned zero. Independent red probes reproduced ignored saves and clears for all four cursor stages and an ignored reconcile timestamp write: nine of nine checks failed. The implementer retained seven canonical red cases before the fix. The reviewed final source atomically publishes state with `mktemp` and `mv -T`, guards write/removal results, suppresses private filesystem diagnostics and returns a failed sweep. The original single-case probe and all nine operation cases pass unchanged; the single-case final output has no stderr and makes no saved-progress claim.

3. **Operator guidance bypassed shared rights occupancy.** The original revision-per-scope tinker recipe and later troubleshooting instruction to publish/link a new revision did not preserve actual rights identity. The replacement interactive recipe and corrected troubleshooting row preserve the pending reservation and require the separately reviewed resolution path. The final journey comment now describes its isolated unrelated fixture rather than teaching the old rule.

4. **The live-key check overstated its file coverage.** The parser checks configuration values; it does not scan discarded comments. A synthetic marker in a comment passes. The final message correctly limits the claim to parsed configuration values. This observation never established a route to live provider I/O. The unchanged probe's historical “file-wide clean” label names the original wording, not the final validator's assurance.

No unresolved blocking finding remains in this scope.

## Executed independent evidence

Run from `/workspace/VA-Studio-commerce`. The Python drivers create disposable private fixtures, call the actual scripts, check subprocess outcomes, and clean the fixtures. The validator driver uses genuine `/workspace/.va-studio-toolchain/standalone/bin/php8.4` (PHP 8.4.26, default ini `/tmp/va-php84cli/php.ini`) with only `PATH` and the deliberate case overrides in the child environment. No test isolation is weakened.

| Driver | Final output at the reviewed source | Result |
| --- | --- | --- |
| `python3 docs/verification/staging-test-commerce-20261009/independent-review/runner-cursor-probe.py` | [runner-cursor-final.txt](runner-cursor-final.txt) | Exit 0; all 3 retained-row cases advance on the second sweep |
| `python3 docs/verification/staging-test-commerce-20261009/independent-review/runner-state-failure-probe.py` | [runner-state-failure-green.txt](runner-state-failure-green.txt) | Exit 0; failed persistence produces sweep exit 1, no false saved-progress claim or stderr |
| `python3 docs/verification/staging-test-commerce-20261009/independent-review/runner-state-operations-probe.py` | [runner-state-operations-green.txt](runner-state-operations-green.txt) | Exit 0; all 9 failure operations produce sweep exit 1 |
| `python3 docs/verification/staging-test-commerce-20261009/independent-review/runner-failure-probe.py` | [runner-failure-final.txt](runner-failure-final.txt) | Exit 0; 15 reset, short-page, malformed-cursor, timeout, lock and mode probes pass |
| `python3 docs/verification/staging-test-commerce-20261009/independent-review/validator-isolation-probe.py` | [validator-isolation-final.txt](validator-isolation-final.txt) | Exit 0; 9 isolation/privacy cases pass; complete synthetic profile passes all 35 policy checks |

The retained independent red files are [runner-cursor-red.txt](runner-cursor-red.txt), [runner-state-failure-red.txt](runner-state-failure-red.txt) and [runner-state-operations-red.txt](runner-state-operations-red.txt). The first starvation repair also has a retained intermediate green log. Final driver output was preserved separately rather than replacing these records.

The review inspected the implementer's canonical red outputs (`../runner-starvation-red.txt`: 3 tests / 9 assertions / 3 failures; `../runner-state-red.txt`: 7 tests / 7 assertions / 7 failures) and final SQLite selection (`../state-repair-sqlite.txt`: **63 tests / 664 assertions**, no failures). Those are implementer runs, not additional independent runs. `git diff --check` exited zero during the final review. Native MySQL journey output was still running when this decision was written; this decision does not claim its completion.

## Limits and remaining acceptance

The runner probes use a scripted artisan stand-in, justified against the actual retained-row selectors; they do not prove provider interoperability, real deployment or MySQL concurrency. The journey uses synthetic Stripe transport and a synthetic renderer. Its receipt, domain command, operator control and exact-download assertions are meaningful composition evidence, not a real Stripe purchase or a real contract-render acceptance result. Existing real renderer evidence belongs to its separately recorded selection.

No real credentials, accounts, live payment, DNS, upload, Forge installation or production action was used. The systemd templates were inspected but not installed. The CLI forwarding fallback and external test account GET remain unexecuted. A host must validate its final private profile, rebuild any active cached configuration, restart workers and use the verified PHP 8.4 CLI; the isolated validator intentionally does not certify an old active cache. Private access/TLS and host setup remain the staging kit's responsibility.

The finalization deadline uses when this application observed and retained confirmation before the original attempt expiry. Prompt payment at Stripe cannot rescue a confirmation first observed after that deadline. Retained unpaid reservations and repeated expired-session reconcile observations remain documented limitations. Sean's account, secret-channel, seller/assent, actual catalog and staging inputs remain required; none was inferred here. Full integrated Foundation acceptance and the first real Stripe TEST purchase remain outstanding. Approval is limited to the authorized focused development merge with the repository's preflight and exact-head controls.
