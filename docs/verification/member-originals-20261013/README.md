# C4b member originals and readiness

Implementation starts from actual C4a PR #115 merge `990020c17fa3f5f7b446b1d41b32b1a1bb48249d`. The shared [API and ownership contract](api-contract.md) is accepted by the lead and the separate [design reviewer](independent-design-review.md), SHA-256 `076c275b1a615d7bda34785c6c716a1f0ec1353a4070aca3ef676f8d95d71eaa`. The [design](design.md) and its review are planning evidence, not an application approval or test pass.

The root branch has integrated the renderer/files, immutable authoring/facts and current-schema implementations at `3b23aa3d4edfc2bbbd0891d9272a9fc3c0ae51e8`. Customer preparation/readiness composition and its integrated checks remain in progress. There is no C4b PR, final implementation approval or whole-suite pass at this checkpoint.

Released component evidence remains source-specific:

| Selection | Source and result | Limit |
| --- | --- | --- |
| Real renderer and private files | `483793065c3f7a66057907a777f938f608bf7036`: 76 tests / 243 assertions, zero failures/errors/skips | Child/file cases, not combined customer preparation or MySQL |
| Facts | Corrected recorded source overlay mapped to `bd3661ec`: 4 / 17, zero failures/errors/skips | A later independent P1, E9, requires additional correction and current/committed regression coverage |
| Authoring | Eight successful leaves / 34 assertions in an invocation that ended at 9 / 39 with one facts teardown error | Not an aggregate passing run; original error retained |
| SQLite schema | 6 / 17, zero failures/errors/skips | Structural rehearsal only |
| Native schema regression on old source | Private MySQL 8.4.11, exact old `15a65e07`: 2 / 10, two intended incorrect-admission assertion failures, zero errors/skips | Corrected four-case run still pending at this checkpoint |

The packet's source maps and archived receipts will retain original failures and distinguish exact commits from captured overlays. Separate review has source-closed the renderer/files findings. E9 concerns identity-policy and actor-state changes after owner admission in the facts issuer; it remains a merge blocker. E4's complete customer-composition proof remains pending. Final focused SQLite/native checks, evidence review, an independent DECISION and cheap exact-head preflight are required before a development merge.

Customer preparation is specified to return a non-authority candidate and write no origins, artifacts, activations or credit events. Explicit staff/MFA authoring appends complete immutable member profiles and definitions. Everything remains default-off and unmounted; C4c will couple consumption and activation in an owned effect transaction. The [runtime and recovery contract](../../ops/member-originals-runtime.md) explains candidate adoption, refusal after durable authoring and orphan limitations. Commercial/legal/account/host inputs and the existing activation conditions remain unanswered/open. No historical frozen contract member is edited or re-pinned, and no production or provider claim follows from synthetic fixtures.
