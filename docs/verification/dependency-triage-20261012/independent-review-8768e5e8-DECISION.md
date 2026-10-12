# Independent dependency tooling review — October 12, 2026

**Decision: APPROVE** for the bounded development dependency batch at
`8768e5e8eaa8b68488b2f3e30ed2da1202c678ae`, compared with current main
`eb6e3741587c43cb8efc256865b45fff70f56019`. No material code, scope, metadata or
evidence finding was identified. Green cheap preflight on the actual proposed
merge head remains a merge gate; this decision does not establish that result.

The tested dependency source is
`361607b2ad2e4ca4aa3e944a857d72e1acb498a8`. Its `package.json`,
`package-lock.json` and `.github/dependabot.yml` are byte-identical to the reviewed
candidate. The complete tested-source-to-candidate diff contains only the
changelog, handoff ledgers and archived dependency evidence. Application and test
source therefore also remain identical to the tested source.

## Review performed

Read `AGENTS.md`, the relevant README/architecture context, the complete main-to-
candidate dependency/configuration/ledger diff, the dependency triage README,
`tooling/commands.md`, inspected PR identities, lock graph delta, audit/outdated
JSON, compact command output and JUnit. Per the bounded assignment, no tests,
installs, network requests or database operations were run by this reviewer.
The repository was not edited.

Read-only Git/Python checks established:

- The archived `graph-delta.json` exactly equals a freshly calculated parsed
  lockfile delta against the stated current-main SHA: 24 changed package records,
  two removed records (`siginfo`, `stackback`), no new records, plus the root
  metadata change. Every changed/removed non-root record is a development record.
- All 14 production npm package records are fully equal before/after, as are
  root production constraints and lockfile top-level metadata. Audit metadata
  counts the root as well, reporting 15 production dependencies.
- `composer.json`, `composer.lock`, all 25 entries in `resources/contracts`
  (including all five `profile-assets.json` manifests), application code, tests,
  configuration, scripts and workflow files are unchanged from current main.
- Actual installed manifests agree with the candidate lock: Vite 8.3.3,
  `@vitejs/plugin-react` 6.1.2, Vitest 5.0.3, `@types/node` 24.13.3 and
  `@inertiajs/react` 3.7.1. The exact root Vitest constraint remains `5.0.3`.
- The updated tool engines support the unchanged Node `>=24.15.0 <25` contract.
  The React plugin accepts Vite 8; Vitest's mocker/spy identities remain aligned.
  Rolldown 1.2.13, its platform bindings and OXC types agree. Vitest now requires
  `why-is-node-running` exactly 3.2.1, explaining its major update and the two
  removed transitive packages. The optional compiler peer change introduces no
  new installed package in this graph.
- The Dependabot ignore rule applies only to automated `@types/node` major
  updates in the npm ecosystem. Minor/patch updates remain eligible and the
  documented future runtime-major review remains necessary.
- `git diff --check` against the stated base passed. The worktree was clean.

## Evidence assessment

Independent XML parsing found **65 distinct frontend suites and 1,130 testcase
elements**, with **zero failure, error or skipped elements**. Suite attribute
totals and the root count agree with those counts and the terminal summary.
Repeated parameterized case labels were not discarded when counting cases.

The archived raw outputs agree with their runtime originals after only the stated
worktree-path, ANSI and trailing-whitespace normalization. The recorded lead-run
results are internally consistent: clean npm installation; passing full frontend
selection; TypeScript plus production/preview builds; production scan of six
files and preview scan of four files without hits. The production build's
existing >500 kB chunk warning is retained. Both audit JSON records contain an
empty vulnerabilities object and zero advisories at every severity. Exit statuses
and the successful command-chain completion are the lead's retained command
receipt, not a reviewer rerun.

The post-install outdated report honestly retains newer available versions,
including Vite 8.3.4, Vitest 5.0.4, Inertia 3.9.1 and Node-24 types. The earlier
pre-install report is explicitly excluded as installed-version evidence. The
bounded choice of the three inspected October 9 targets is coherent and makes
no all-current dependency claim.

Evidence anchors (SHA-256):

| Artifact | SHA-256 |
| --- | --- |
| Candidate `package.json` | `b2d763617e9fb49b887a94f27b3952472250498604682f9002e5f5c4087226d1` |
| Candidate `package-lock.json` | `6f0237b5d01ed0237b14314a18025d63e6ff0b8561be72a50cfa11f03f96da1b` |
| Archived `tooling/graph-delta.json` | `f0c570acfe239dad416785f17709e64ad173f246d3ad9dfd75ef809e074da22e` |
| Archived `tooling/frontend.xml` | `95dd00e167c8797014b96ecca62b80daf3079464cdc333a3e483fa1fc1e494ac` |

## Dispositions and limits

The ten-PR disposition table is coherent: tooling #73/#78/#79 is included; Node
types major #76 is rejected for the current runtime; Stripe SDK #71 and frozen
PDF/font #72/#77 remain deferred; Laravel/Inertia #74/#75/#80 belongs to a separate
compatibility batch. Bot closures remain sequenced after the tooling merge.

The MFA ledger accurately identifies the local PR #98 merge object
`eb6e3741587c43cb8efc256865b45fff70f56019` and its second parent
`c2be3d3122f59b03ec5927bf24da6ac49cc36975`. Its recorded focused results and caller
operator-lock limitation agree with the existing MFA packet. The reported
preflight `38318165777` and resolved remote review thread are carried historical
receipts; they were not queried remotely by this npm review.

This approval covers the dependency graph and reviewed evidence at the stated
candidate. It makes no browser, PHP, native MySQL, full Foundation, host, provider
or release-acceptance claim. A later review-receipt-only commit can carry this
approval forward after confirming that exact delta; other changes need review.
