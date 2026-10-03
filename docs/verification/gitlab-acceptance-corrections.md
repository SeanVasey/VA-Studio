# GitLab acceptance corrections and retained failures

October 2, 2026. Development continues in private [VA-Studio](https://gitlab.com/vaseydev/va-studio), project `87181037`, [draft MR !1](https://gitlab.com/vaseydev/va-studio/-/merge_requests/1). The [agent queue](../development-agent-queue.md) retains one integration owner and the complete remaining product scope. This record separates original native failures, bounded corrections and focused feedback from full acceptance. No final corrected candidate SHA or successful full run is asserted before the lead freezes and verifies that source.

## Native source and failed predecessors

The native candidate abbreviated `6de` matched local commit `bd1fd374b4c284d172e4f10e2c2921f81726f54f`, tree abbreviated `a28`, before the subsequent corrections. [Pipeline 2908136664](https://gitlab.com/vaseydev/va-studio/-/pipelines/2908136664) runs the full thirteen-job acceptance configuration. It has not been accepted at this checkpoint:

- The two disjoint SQLite partitions reported 2,831 cases in total, including 241 exact MySQL-only skips, three failures and one error. Their assertion counts were 17,060 and 12,982. Disjoint partition membership and skip policy must remain verified; reported cases are not all executed passes.
- The mobile browser job reported 43 passes, one failure and one intentional skip. The failed behavior requires fresh native execution after correction.
- MySQL outcomes remain pending in this record. The lead must append actual final native job/collector evidence; configuration and local subsets cannot supply it.

The original [pipeline 2908122261](https://gitlab.com/vaseydev/va-studio/-/pipelines/2908122261) failed under the inherited PHP CLI 128 MiB memory limit. Its original failure remains a predecessor. The correction sets a bounded `512M` CLI budget loaded through PHP configuration, while explicit worker and renderer limits remain unchanged; the native evidence collector requires the observed CLI runtime identity. It does not remove cases or allow unlimited memory. The later failures below are separate observations; the memory correction does not turn them into passed tests.

## CLI and private-root correction

The contract-renderer investigation found a CI environment boundary that needed repair rather than a renderer/profile exemption. CI setup now normalizes only the expected synthetic checkout's private storage root to `0700`, under the predefined native disposable-runner guard. It refuses missing native disposable proof, checkout/storage overrides, symlinks or unsafe path ownership/context before changing permissions. This is not recursive permission rewriting or a production filesystem repair command. Setup verifies the resulting private-root mode; the collector independently verifies runtime identity.

The setup safeguards passed **11 cases** and the collector safeguards passed **22 cases**. These are dedicated helper checks, not acceptance of the entire suite. The application private-file guard, renderer, document profile and existing assertions remain unchanged.

Using the actual validation runtime, the unchanged renderer acceptance case passed **one case / 25 assertions**, with qpdf `11.9.0` and pdftotext `24.02.0`. Runtime/tool identity is retained in `contract-validated-runtime.json` and the original source-bound result in `contract-validated-acceptance.log`, under `/workspace/scratch/2c1d12ebc985/`. This is focused validation of that environment, not a claim that every native contract test passed.

## Mounted mobile navigation correction

The mobile helper waits for the header to mount, opens only a visible closed menu, and checks navigation visibility before following the original exact link. The retained old helper failed the delayed mobile-header regression at its existing 60-second timeout. The final helper and unchanged editorial journey passed all four targeted local WebKit cases in 41.253 seconds, with zero skips or flaky results. Local generated Filament assets were required; their earlier absence caused separate login failures. Native discovery finds 96 cases across both browser projects; the four local passes do not replace full native execution.

## Deterministic image fixture correction

Baseline JPEG fixtures now use the existing FFmpeg encoder with explicit 4:4:4 sampling. The progressive GD branch remains intact; a dedicated flat RGB fixture has genuine equal-128 component samples and a truthful Adobe transform marker. A decoded-byte test verifies those RGB values. Application profiles, the prober, exact format expectations and malformed/CMYK rejection coverage remain unchanged.

At the final frozen hashes, the eight-class SQLite family reported **76 cases: 70 executed passes, six exact MySQL-only skips, 2,871 assertions**. The corresponding real MySQL concurrency run passed **six cases / 947 assertions**. Source hashes and exact reports are retained in `site-image-fixture-validation.json`. Earlier focused four-case / 158-assertion and MySQL / 985-assertion runs remain separate historical feedback. No production upload, scanner readiness or import is implied.

## Combined writer and hosting preparation

The media-writer candidate abbreviated `e77`, tree abbreviated `391bf`, remains preserved on its own branch and is now included in the local integration candidate. Its authority/race/requester-identity MySQL feedback executed **42 cases / 1,003 assertions**. It was not part of the native `6de` candidate above. Composition requires review of the combined source and fresh applicable acceptance. Its synthetic scanner fixtures do not prove production ClamAV signatures, worker isolation or real-media readiness.

Sean authorized private-server migration/hosting preparation. The server has not been inspected or deployed, and prepared environment defaults retain blank secrets and disabled activation flags. Application installation, private storage, scanner, real SMTP, signed webhook, backup restore and operational ownership still need actual host evidence. No live selling or domain transition is claimed.

The [Mac bootstrap](../development-macos.md) is prepared. Its **13 safeguard cases** ran in the available Linux environment; this does not mean dependencies have been installed on Sean's Mac. Mac execution access and the separate Linux media-worker requirements remain distinct.

## Commerce attribution and legacy resource barriers

The composed candidate also includes [explicit commerce audit attribution](commerce-audit-attribution.md). Its final MySQL feedback passed 23 cases / 1,447 assertions; the broader SQLite regression passed 365 executed cases / 3,601 assertions with one existing MySQL-only skip. Customer entry points lock the explicit actor before resources, and system work records nullable attribution without ambient-auth fallback. This does not enable live checkout.

Six failures in the existing exclusive-offer and selection concurrency tests reproduced locally after actor-first fencing. Their original shared actors serialized before the intended resource barriers, making those barriers impossible. The two fixture files now use distinct persisted authorized staff actors for the competing resource operations and scope-block callback. Resource barriers and assertions remain intact; dedicated same-actor fence coverage remains in the writer suites. The three-class diagnostic changed from six failures among 24 cases to **24 passes / 1,219 assertions**, zero errors or skips. All eleven customer-inquiry cases passed unchanged in both local runs; the earlier native inquiry failure remains unresolved until its terminal details are available. No speculative inquiry correction is included.

## Acceptance and next contract

After corrections are frozen, the lead records the exact new native SHA/tree, verifies source equality and independent review, and runs the complete applicable GitLab acceptance on that head. Retain original reports, native project/pipeline/job/artifact identities, exact discovery/partition coverage and fail-closed collected receipts. No earlier green subset accepts corrected executable bytes. Main-push verification after an expected-head merge remains separate; proof reuse is disabled.

Only after writer participation and full acceptance are complete does the queue advance to reviewed publication-manifest compare/apply, followed by track scheduling. Production commerce still requires audited exception resolution, authoritative unpaid release, refunds/disputes, real provider test-account reconciliation, recovery/library and durable private delivery. Historical GitHub records remain original evidence, not native GitLab acceptance or production readiness.
