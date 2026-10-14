# C4c focused evidence protocol

2026-10-13. Runtime planning only; no runner implementation or execution. C4b stays frozen. C4c local development follows the approved contract; publication still waits for actual C4b preflight/merge. The goal is complete provenance with fewer duplicate artifacts, not broader test claims.

## 1. Freeze one shared bundle per source and input scope

Before a focused selection, record the exact 40-character commit, worktree, declared input scope and selected files/filter. Include relevant application/configuration/bootstrap code, migrations/schema definitions, contract resources, child programs, selected tests and support fixtures, dependency manifests/locks and PHPUnit configuration. Include the exact synthetic fixture bytes through tracked Git identities or a separately retained public fixture. Missing dependencies are a stated limit; an affected-file list alone is not an executed dependency inventory.

Create one immutable path/SHA-256 manifest for that commit and scope, verified against actual Git blobs using batched reads. Capture exact runner bytes/hash, PHP/PHPUnit and applicable database/child-tool versions, dependency-lock hash, and the worktree's actual autoload/proxy metadata hashes. Public configuration identity belongs here; credentials do not. A source, runner, fixture, configuration or capture-scope change requires a new bundle. Never patch a prior receipt. Identical blobs may be referenced by digest instead of copied again.

Before and after every invocation, observe the declared path set and recompute its content hashes, including relevant additions/deletions and overlay files. Record the observed commit/status, canonical manifest digest/count and equality result. Reuse the shared manifest only when those observations match it. On drift, retain the actual delta or new manifest and mark the execution changed-source; do not issue exact-frozen-source acceptance. Avoid rescanning unrelated documentation and prior evidence packets. A cached Git manifest does not replace observation of the actual working inputs.

## 2. Keep each invocation small and complete

Use a unique run ID and refuse overwrite. Retain:

- Exact argv as an array, cwd, explicit selection, shared bundle identity and public environment projection.
- Observed runner/autoload/runtime identities, UTC start/end, monotonic wall duration, before/after input digests, source changes and workload overlap.
- The actual process exit, complete public log, JUnit bytes, and independently reconciled testcase/suite counts for tests, assertions, errors, failures and skips.

Keep command setup, PHPUnit exit and verifier exit distinct. Empty/malformed/missing JUnit or a fatal exit before tests is an abort, never a passing zero-test run. Preserve every failure before correction; link the next run to its reason. Expected red requires the intended existing behavior failure, not a missing API or broken fixture. A fail-fast run leaves later selected cases unrun, not skipped or passed. Discovery is list-only evidence and does not establish executed cases. Do not rerun a successful unchanged selection merely to repair its receipt index.

## 3. Reuse runner behavior, not hard-coded paths

Practical starting points are C4b's `native-preparation-correction/run-composed.py` in `/workspace/runtime/member-original-operation-evidence/` and `run-native-focused.py` in `/workspace/runtime/member-originals-root-evidence/`. They already retain literal argv, owned autoload, exits, JUnit and source observations. Copy only the needed runner into the new runtime namespace and bind its new bytes; leave C4b originals untouched.

When implementation is authorized, parameterize worktree/output/schema, adopt shared manifests, retain timeout/empty-XML handling, and serialize only an explicit public environment allowlist. The composed runner's malformed-XML handling is useful; the root native runner needs that same protection. Use unique output names and check owned fixture/TMPDIR prerequisites before a costly run. Store each runner/version record once. This protocol does not implement those changes.

## 4. Coordinate native and concurrent-worker evidence

Obtain the root's bounded native workload slot and record start/end and any overlap. Pause unrelated heavy tests, discovery, archive rebuilds and broad Git audits during original-budget native checks. Keep actual original clocks/caps; diagnostic instrumentation and synthetic cap-consumption fixtures are separate labelled runs, never substituted for the real positive path. Total invocation duration includes fixture work and is not an individual frame measurement or performance guarantee.

Record the private synthetic server version, logical owned schema and public connection/server identity once per verified setup. Actual two-writer claims need independently coordinated workers, connection identities, barrier/event ordering and observed outcomes—not merely two sequential calls. Keep only necessary public worker receipts and synthetic outcome counts/hashes; omit database dumps and artifact payloads. Driver labels distinguish SQLite, actual MySQL and pure bodies.

## 5. Publish only explicitly released synthetic evidence

Each lane releases exact relative paths with byte lengths, SHA-256 and provenance: synthetic fixture origin, deterministic seed where used, and production/host conditions not exercised. Secret values, private environment files, credentials, keys, actual generated PDFs/audio and database bytes stay outside the public allowlist. Do not hash secret values into a public manifest. Public configuration identity and appropriate nonsecret source/artifact hashes can identify observations without publishing private content.

Do not crawl a runtime directory or copy an earlier packet wholesale. Inspect logs for private material before release. If projection/redaction is necessary, retain the original privately and label the public transformation; never call transformed bytes the original. Retain exact public diagnostic source overlays and their base/capsule hashes, with the scope explicitly limited to the classes changed. Hashing a generated PDF can identify the observed synthetic artifact without copying its bytes; it does not itself prove retention or accessibility.

## 6. Assemble one minimal reproducible packet

Use one command/source/limits narrative, shared bundles and runner capsules, per-run command/result/log/JUnit receipts, one explicit release manifest, and one derived index containing counts and case/source relationships. Deduplicate identical bytes by digest. Preserve each differing failure and source variant; compress large unique manifests losslessly only when useful. Do not copy the same full manifest before/after or duplicate entire case lists across several generated summaries.

The offline verifier checks release hashes, source/Git bindings, before/after observations, exits/JUnit totals, runtime identities and the declared coverage selection without executing application/database bodies. Any author commit needed by a public reviewer must remain reachable or have an explicit equivalent-path mapping to reachable integration history. Integration compares named captured inputs with the frozen target and lists all differences; it never relabels an older run as a new execution. Mixed-source deduplicated coverage is a catalog, not a final-head suite pass. Independent review and exact-head preflight remain separate root-owned records.
