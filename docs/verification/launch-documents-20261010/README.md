# Launch documents lane: evidence (2026-10-10)

Branch `harness/launch-documents` from `f57e7256891d0d3c117e151a36f7fb967c724ab7`. Documents only. See `PLAN.md` for the files owned, assumptions and acceptance criteria. These are focused documents checks, not a test-suite run or any form of acceptance.

| File | What it records |
| --- | --- |
| `PLAN.md` | Files owned, assumptions, acceptance criteria, checks, what cannot be proven here (committed before any edit). |
| `red-contradictions.txt` | The contradicting lines in the five reconciliation targets as they stood at the base SHA, and the absence of `docs/legal/`, before any edit. |
| `red-check-citations.txt` | First run of `check-citations.py`: failed on three header lines (the check compared whole lines while the header carries Markdown emphasis) and on one glob cited as a path in the index. Both fixed; neither was a factual error in the drafts. |
| `check-citations.py` | The check: extracts every backticked repository path from the three legal documents, fails on a missing path or a missing owner-review header. Run from the repository root with `python3 docs/verification/launch-documents-20261010/check-citations.py`. |
| `green-check-citations.txt` | Second run: 209 citations across 3 documents, `RESULT PASS`, exit 0. |
| `green-contradictions.txt` | The same greps after the edits, showing each corrected line with its citation, plus the three new `docs/legal/` files. |

## Commands and results

```
$ python3 -I docs/verification/launch-documents-20261010/check-citations.py
docs/legal/README.md: 95 distinct cited paths
docs/legal/PRIVACY.md: 62 distinct cited paths
docs/legal/TERMS.md: 52 distinct cited paths
checked 209 citations across 3 documents
RESULT PASS
$ git diff --check
(no output)
```

No PHP or TypeScript file changed, so Pint, PHPUnit, the frontend tests and the build were not run; running them would not exercise this change.

## Claims corrected, with citations

| File | Was | Now | Citation |
| --- | --- | --- | --- |
| `docs/ops/production-activation-packet.md`, section 1 gate row | "the test checkout/processing policies require `local`/`testing`" blocks hosted test-mode payments on a staging host | `CheckoutPolicy` and `PaymentProcessingPolicy` admit `staging` since PR #68 at `d8aba146`; only `ExecutionContextV1` test funds still require `local`/`testing` | `app/Support/Environment/TestEnvironment.php` (`TEST_COMMERCE`); `app/Domain/Commerce/Checkout/CheckoutPolicy.php` and `app/Domain/Commerce/Payments/PaymentProcessingPolicy.php` (`TestEnvironment::admitsTestCommerce`); `app/Domain/Commerce/ProductionCheckout/ExecutionContextV1.php` (`environment(['local', 'testing'])`); `docs/private-server-readiness.md`, "First sale and cutover remain separate gates" |
| same file, section 6 finding and S2b bullet | "the current source refuses S2 on a hosted staging environment"; S2b "needs a reviewed code change" | Finding dated to base `3716324a` and marked superseded; S2b records the merged admission and the remaining production-checkout gates | as above |
| same file, section 8 unknowns | whether S2 runs through "a reviewed staging admission change" is unknown | the admission is no longer unknown; the S2a/S2b choice remains Sean's | as above |
| `docs/live-payment-and-production-preparation-queue.md`, line 20 | "hosted staging test mode is refused" | past tense at base `3716324a`, superseded by PR #68 at `d8aba146` | as above |
| `docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 2 interim-environment row | "Every test-commerce policy admits only `local`/`testing` today" | "admitted only … when this was decided; M-06 (PR #68 at `d8aba146`) has since admitted `staging`" | same file, row M-06; `docs/private-server-readiness.md` |
| `docs/architecture/decision-register.md`, U-01 | open: namespace, creation and access unresolved | resolved: `SeanVasey/VA-Studio`; D-02's `VASEYDEV/VASEYAUDIO` kept as history | `ops/staging/README.md`, provisioning step 4; `docs/handoff/2026-10-08/MONDAY-PLAN.md`, status log |
| same file, new dated entry | no record of the staging-host choice | Forge + VPS (DigitalOcean) recorded as the staging-host decision, explicitly not U-02 | `docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 2; `docs/ops/production-activation-packet.md`, sections 2 and 8; `docs/site-content-releases.md` ("not chosen yet (U-02)") |
| same entry and `ops/staging/README.md`, step 1 | plan says "at least 4 GB RAM", kit says 4 vCPU / 8 GB / 160 GB, neither names the other | both figures stated; the ops README named as the sizing specification and 4 GB as its floor | `ops/staging/README.md`, step 1; `docs/handoff/2026-10-08/MONDAY-PLAN.md`, S-2 |

## Contradictions reported, not edited

- `CLAUDE.md`, Project Notes, "§5 auth": "customer accounts don't exist yet (U-07)". Test customer accounts exist and are documented (`docs/verification/customer-account-test-journey.md`; `docs/customer-test-self-service.md`; `routes/customer.php`); production identity remains default-off (`config/production-customer-identity.php`). U-07 (production enrollment, recovery and claim policy) is still open, so the accurate statement is that production customer accounts do not exist yet.
- `resources/contracts/test-v2/PROVENANCE.md`: "current issuance profile and policy still select v1". The registry selects v2 (`app/Domain/Contracts/ContractRenderProfileRegistry.php`, `CURRENT_VERSION = 'test-buyer-pdf-v2'`; `app/Domain/Contracts/ContractIssuancePolicy.php`, `profile => 'test-buyer-pdf-v2'`; `docs/test-contract-issuance.md`, "The current development runtime selects v2"). The file is frozen and was not changed.
- `docs/architecture/decision-register.md`, D-02: "Target GitHub repository name is VASEYAUDIO". Left as history; the 2026-10-10 entry records the current repository beside it.
