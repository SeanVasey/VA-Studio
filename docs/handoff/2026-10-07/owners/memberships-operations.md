# Recovery/inventory owner handoff to Claude Code

Sean clarified the final scope: finish current bounded batches, publish owned branches and hand off remaining work. Do not start a new operative membership, Billing or host implementation as part of this finish. Root owns integration, shared registration/config/routes, PRs and release decisions. No branch here is an instruction to deploy, activate real payments, run routine hosted matrices, change DNS or adopt old customer/entitlement data.

## Current sources and review scope

| Branch | Frozen head / runtime | State |
| --- | --- | --- |
| `codex/production-membership-preparation-20261007` | head `7fa3c62e61fae6e98e61e75eb0451edd588683d0`; latest runtime `3cd105667a76bb15443a9fd0119065effda8bb34` | NEW257 preparation. Exact earlier schema/preparation16e is reviewed; later Rows3cd is tested but needs independent review. |
| `codex/production-member-original-preparation-20261007` | head `fb573a410cda76dd97884e81bc8e15437831a9ae`; runtime `c4cd6d9355257a39d2740b0a4fe55c3e376ef5b3` | NEW258 member-only schema/contracts, tested, independent preparation review pending. |
| `codex/membership-billing-operations-handoff-20261007` | docs-only branch from exact257 head7fa3c62; discover its head with Git | Billing259 and host operations are approved queued plans, with no implementation or operative provider capability claimed. |

All branches are isolated and leave root's integration branch untouched. The final push receipt is reported to root separately; resolve actual remote heads before continuing. Source/tests/original probes and durable verification directories are tracked. Private native environment files, credentials, databases, caches, vendor packages and actual customer/asset data are excluded.

257 began at96fd74060e8b99c043a8ebafca6704594d3fec7c and b635e12992dc91f5131f35e5c46b8dab6ff30eee. Existing synthetic `MembershipPlans`, `CreditLedger`, customer history and migration230 remain unchanged; recorded historical116/448 foundation evidence was not independently replayed from its inaccessible checkpoint. Its24 current synthetic source bindings are reconciled. T23 identity child namespaces have no new incoming FK/trigger dependencies: original buyer/origin payloads require actual typed authenticated proof; canonical account/user/owned-plan FKs establish only structural relationships.

## Defects, repairs and actual evidence

| Actual probe / source | Original result | Frozen repair / final result |
| --- | --- | --- |
| Raw Repository database/policy ArrayObject parents, original b635; byte-exact probe SHA256 `42c9a1fe718de819a5d17fd38cb4d09f04e5519f8d3c731be437b3c91fd722b0` | SQLite2 failures/2 assertions/0 errors; original evidence468142803c27991c9a02e44cc9e13f397bcf87bf | d6933489ae701c5b45192fc3f368a225d41a9185: plain parents/scalar-or-null leaves before getters. SQLite23 recorded/20 executed/55 assertions/3 named native-only skips; native reader1/3. Independent original SQLite2/6 also passed. |
| Actual foreign-database same-named users FK, b635/d693; unchanged72648803519da41d025222e000ecfeceef051ca3e0cdcd0dd01c4dbdbcdaca8a | MySQL8.0.46 one failure/6 assertions/0 errors; missing final guard was recreated. Original2040c221e653210aa51fde4e1f7e64b54441c062 retained. |16e084b924fb9132b76095f26a35f28dc5f1feaf binds referenced/unique schema, primary target and full rules before deferred DDL. Author native1/2; unchanged independent native1/7 and entire before==after graph. Preparation-only approval8fdacaf255caf50f1433eb21f88d1d0aa0d8df3f. Fixture coordinator revoked/dropped its temporary schema/grants. |
| Actual captured PDO custom statement factory; real PDOStatement::fetchAll withdraws policy during metadata reads, original16e | SQLite1 failure/1 assertion/0 errors; original2a0786ed655253d852ae11eac8745ea78357be2d |3cd105667a76bb15443a9fd0119065effda8bb34: finite internal PDO/Pdo\Sqlite/Pdo\Mysql and default PDOStatement before any SQL. Fresh SQLite24 recorded/21 executed/58 assertions/3 named native-only skips; unchanged native1/3. No new independent approval. |
|258 copied redemption admitted a different active customer pair or unrelated invoice identity, original3c8098e7750c746b42c8cde3283c36e631607059 | SQLite2 failures/2 assertions/0 errors; original21b793683fad3702ee77c242da16755416d8e4df | c4 joins retained redemption→paid period and exact account/user/original identity/invoice. Fresh final SQLite22 recorded/21 executed/52 assertions/1 native-only skip; native selected5/17. |

Original failures are not erased or represented as successful execution. Rows initially used an uncommitted exact-PDO-only header, which incorrectly rejected legitimate internal PHP8.4 `Pdo\Sqlite`:24 recorded/47 assertions/4 errors/3 skips. The full provisional source/output is retained. The final3cd finite list fixes that ordinary path.258 early unfrozen tests had one undefined validator method error and one fixture restoration assertion failure; they are separate from final frozen checks.

Durable receipts live under:

- `docs/verification/production-membership-preparation-20261007.md`
- `docs/verification/production-membership-preparation-20261007/receipt-map.json`
- `docs/verification/production-membership-raw-parent-20261007/`
- `docs/verification/production-membership-reference-schema-20261007/`
- `docs/verification/production-membership-statement-20261007/`
- `docs/verification/production-member-originals-preparation-20261007.md`
- `docs/verification/production-member-originals-preparation-20261007/receipt-map.json`

All final checks have zero failures/errors; named driver-only skips are counted separately. SQLite does not establish native concurrency. No membership last-credit contention, operative invoice/award/reservation, artifact readiness/activation/fulfillment or production transport was executed.

##257/258 exact owned API and remaining implementation

`MemberGrantIntent` contains three opaque invoice/license/reservation tokens; typed current `ProductionCustomerPrincipal` and real `User` remain separate arguments. Root approved distinct `production-member-origin-v1`/version1 and `production-member-license-grant-v1` legal purpose.258 changes only the owned intent family constant; it does not convert a paid/free/test original, profile, grant or owner.72 old free/contract/technical files and24 synthetic membership bindings stay byte-exact.

`MembershipReservationAuthority::reserve(invoiceProof, licenseProof, requestKey, principal, actor, MembershipRows): MembershipReservationProof` must derive credits from held approved policy/license facts before issuing authority. `lock(redemptionId, principal, actor, rows)` is retained same-origin retry only; `proveCurrent` must close the captured original transaction/source/current owner. Its interface is authored; producer is unimplemented. A readonly value, public constructor, idempotency key or caller hash is not an authority token.

257 schemas retain immutable plan versions, one paid period per source invoice, owner/request-bound redemption intentions and conserved append-only hash-linked award/reserve/consume/release/expire events.256/free and old synthetic membership are separate families. Structural SQL guards do not validate encrypted payloads, invoice ownership, benefits or file readiness. Amounts use integer minor units; no plan price, currency, allowance, rollover, cancellation, late invoice, refund, dunning, grandfathering, honor or retention default is approved by fixture values.

258 schemas retain immutable member profiles/definitions/originals/artifact manifests/one activation receipt. Member-only facts and actual private original artifact authorities are unbound. A manifest value has role/hash/bytes/storage-policy hash and no private path/URL. Technical16-original/512MiB-per-original ceilings are not approved delivery/retention policy. Direct structural activation fixtures contain no files or ready producer authority and cannot be described as usable grants. No renderer or approved member-specific profile/terms is implemented.

Required next sequence after independent preparation reviews and approved dependency composition:

1. Consume the actual reviewed T23 current/committed APIs; base760 misses them. Exact101e3832e8e5637cab784eb2a800d60642a746b4 provides `IdentityCommittedFrame::begin(CurrentRows, originalDeadlineNs)`/`assertActive`/`finish`/`close`, and `ProductionCustomerAccess::proveCommitted` plus committed historical verification. Root owns shared composition. Existing feature-specific access capsules must not be relabelled to member purpose. Historical receipts establish originals, not current owner/payment.
2. Bind actual approved current-MFA-authored version/provenance/policy facts, and the approved sealed policy-decision extension. Derive credit cost/credit expiry/honor deadline from held invoice/license/policy proofs. Hash placeholders cannot define those policies.
3. Implement distinct Billing259 current provider subscription/invoice producer; see the separate plan. Reuse no track checkout order origin, arbitrary invoice id, email match or caller amount as membership authority.
4. Implement operative257 period/one-invoice award/reservation writer, exact retained retries and native last-credit/idempotency contention. Preserve pending/unknown effects; no automatic reversal because a producer is uncertain.
5. Implement current T25 eligible-license adapter, member-only258 actual original renderer/storage/readiness, then commit verified artifact activation and credit consumption in the same owned transaction. Original purpose/profile/terms are distinct even when technical rendering code/font bytes are reused by exact hash.
6. Root mounts private session-bound HTTP/UI/config and reviews the integrated source. Complete fresh owner/credential/flags/deadline/graph fences after callback work and after commit before a prebuilt private response is released. No proof-window renewal. Preserve Laravel commit/events.

## Reproduce focused checks safely

In the recorded `/workspace` environment, activate the existing locked toolchain; do not install a new environment merely to repeat these checks. PHP8.4.26 and Node24.19.0 were observed. Native daemon is MySQL8.0.46, not8.4. Native schema/account are limited disposable synthetic fixtures; loopback executions require explicit network permission in the app sandbox. Root/native coordinator owns the temporary cross-schema lifecycle. Private env contents must never be printed or committed.

```bash
cd /workspace/VA-Studio-production-membership
source /workspace/.va-studio-toolchain/activate.sh
APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M= vendor/bin/phpunit tests/Feature/ProductionMembership --log-junit /tmp/membership-current.xml
vendor/bin/pint --test app/Domain/Memberships/Production tests/Feature/ProductionMembership
git diff --check

# Only after coordinating exclusive use of this owned synthetic schema:
source /workspace/.va-studio-toolchain/mysql/membership-task.env
APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M= vendor/bin/phpunit tests/Feature/ProductionMembership/MembershipNativeStatementClosureTest.php --log-junit /tmp/membership-statement-native.xml
```

258 final SQLite selection was `tests/Feature/ProductionMemberOriginals tests/Feature/ProductionMembership/MembershipPreparationTest.php`. Its native five-method regex selected owned retry, bounded artifact admission, global CHECK preflight and both original wrong-owner/invoice cases. Exact command selections and XML counters are retained in the referenced evidence. Vendor uses the original locked package directories with independent generated composer/autoload/bin metadata; reflection verifies owned source resolution. Never copy full vendor trees or remove original vendor/cache/source/evidence/keys.

## Other significant owner branches and evidence

The recovery inventory is a read-only source census: inaccessible prior unpublished/private worker/uncommitted work was not claimed recovered. Exact recovered PWA/private return source is separate from the NEW discovery worker. Discovery worker replacement97d5 and strict schema07bf were new implementation; root's public registration/fallback/throttle successor scope is separate. Final actual URL/DNS/legacy inventory and production catalog gates remain human/content work.

Owned refs are preserved/published in the push receipt:

- `codex/cloud-recovery-inventory-20261007` at147affc207c14baa7d4a96f9ef605e19868b34bd.
- `codex/recovered-install-checkout-ui-20261007` atddd7c58d9914aeaeb1da782cfee8dc3dfffb8dfd.
- `codex/cloud-listening-independent-composed-20261007` at32e33ef957074d2da68c8b774603e338d9cbbcc1.
- `codex/private-support-attachments-20261007` at419627bb4e72a18ce7fd724860667cd0e063f574; `codex/support-committed-read-closure-20261007` atdb62b00a20b3a8a547194d211ebdd53484a32a70.
- `codex/support-session-independent-20261007` at15c9e34630966243ce92783dbacf167f908262ee.
- `codex/support-test-lifecycle-independent-20261007` at662d5baec69a7f98b5fee5722df0d2819712b3e4.
- `codex/support-pr35-composition-independent-20261007` atde314e2fb6fec289ae2a349867f7a7218d2f0827; source-only merge review, source-bound root45/608 evidence carried, no broad duplicate run.
- `codex/new-discovery-sitemap-worker-20261007` at1b7625b07200f2c2e8c4856409aae56cebd3b2dd; `codex/discovery-schema-namespace-repair-20261007` at8cf82db12086d2579390882ccbc6a24eaf63a9c6 (original326/07 counter attribution preserved).
- `codex/production-free-identity-independent-20261007` atfbd3d56bba11f187c528018c5b0bf51e89a4eb4f approves exact ff0f7a85be397f017a227967058f745ceea3da28 successor only: unchanged nested-parent112bc original+admission/default-off SQLite10/55; unchanged original+actual SMTP owner native2/15. Historical233 approvala82 remains historical. Actual default-off identity preparation, not operative free commerce/terms/storage acceptance.

These historical owned refs are evidence/source retention, not instructions to merge whole old branches or reuse superseded approvals. Root's final integration/handoff supersedes broad status inferred from older branch names. Sensitive source must retain independent review at its exact final composition.
