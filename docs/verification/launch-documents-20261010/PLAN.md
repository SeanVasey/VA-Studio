# Launch documents lane: plan (2026-10-10)

Branch `harness/launch-documents` from `f57e7256891d0d3c117e151a36f7fb967c724ab7`. Documents only: no routes, PHP, TypeScript, schema or publication change. Nothing in this lane is published to customers; the legal drafts are for owner review.

## Files owned

- `docs/legal/README.md`, `docs/legal/PRIVACY.md`, `docs/legal/TERMS.md` (new)
- `docs/ops/production-activation-packet.md` (the hosted-staging refusal claims only)
- `docs/live-payment-and-production-preparation-queue.md` (line 20, same claim)
- `docs/handoff/2026-10-08/MONDAY-PLAN.md` (section 2 interim-environment row only)
- `docs/architecture/decision-register.md` (U-01 resolution; staging host decision recorded as staging, not U-02; server sizing)
- `ops/staging/README.md` (the sizing note only)
- `docs/verification/launch-documents-20261010/**` (this plan, evidence, the citation check)

Not touched, by instruction: `CHANGELOG.md`, `README.md`, `CLAUDE.md`, `AGENTS.md`, `resources/contracts/**`, `.env*`, `vendor/`, lockfiles, CI files, `tests/`. Contradictions found in those files are reported to the integrator, not fixed here.

## Assumptions

1. "What the application does today" means the source at the base SHA above. Every statement in the drafts about collection, purpose, retention, processors or what a buyer receives cites the file that proves it; where several files prove one statement the most specific one is cited.
2. Everything the code does not decide (retention schedule and analytics/email processors, U-13; seller legal name and assent text, S-8; governing law; refund and dispute policy, T21; exclusive late-payment policy, U-08) is listed under "Sean to decide" and is not written as policy.
3. The drafts describe the application in the shape a launch would use (customer accounts, consent, inquiries) while stating which of those features are default-off or local/testing-only today, so the drafts are not read as a claim that any of it is live.
4. The hosted-staging admission by PR #68 at `d8aba146` (`App\Support\Environment\TestEnvironment`; `docs/private-server-readiness.md` "First sale and cutover remain separate gates") is the fact the three refusal claims are corrected against.
5. `SeanVasey/VA-Studio` is the repository named by `ops/staging/README.md` step 4 and `docs/handoff/2026-10-08/MONDAY-PLAN.md` status log, so U-01 is resolved by observed evidence in the repo, not by assertion.

## Acceptance criteria

- `docs/legal/PRIVACY.md` and `docs/legal/TERMS.md` exist, open with the exact header `DRAFT FOR OWNER REVIEW. Not published. Not legal advice.`, cite a repository path for every factual claim, and keep undecided matters in a "Sean to decide" section rather than in policy text.
- `docs/legal/README.md` indexes every document or template a launch needs with location, where a buyer or operator sees it today, and status (exists / draft / missing and who supplies it), without touching `resources/contracts/`.
- Each reconciliation edit corrects only the named claim and carries a one-line citation.
- Every path cited by the three legal documents exists in this worktree (checked by `check-citations.py`).
- The CLAUDE.md "customer accounts don't exist" and `resources/contracts/test-v2/PROVENANCE.md` "current profile is v1" contradictions are reported, not edited.

## Checks this lane adds

This lane owns no path under `tests/`, so the check lives in this evidence directory:

- `check-citations.py`: reads the three legal documents, extracts every backticked repository path (`app/…`, `config/…`, `database/…`, `docs/…`, `ops/…`, `resources/…`, `routes/…`, `scripts/…`), and fails if any does not exist in the worktree or if either draft lacks the required header. Its output is saved as `check-citations.txt`.
- "Red first" for the reconciliation edits: `red-contradictions.txt` records the contradicting lines as they stood at the base SHA before the edits; `green-contradictions.txt` records the same greps after.

## What cannot be proven in this container

- Whether any draft is legally adequate: no reviewer is involved and the drafts say so.
- Hosted staging behavior: the admission is cited from the merged source and its evidence record, not re-run here.
- Full acceptance: the citation check is a focused documents check, not a test suite run.
