# D-13 — Prepare private orders and bind retained assent to attempts

Status: implementation candidate, 2026-09-24. Actual CI and independent review belong to the integrating PR. This is partial WP-07 delivery under Sean's continuous-development authorization.

Merged PR #51 supplies exclusive selection/disclosure/pricing and atomic scoped inventory. The next coherent prerequisite is a server-reproduced test order review, explicit assent to its exact hash, immutable encrypted order/line evidence and one atomic promotion/inventory attempt binding.

Use an explicit policy absent by default and accepted only in local/testing. Require fixed test tax and complete frozen disclosures. Capture the synthetic seller/assent policy and supplied unverified buyer identity privately; infer neither a verified customer nor marketing consent. Keep canonical private evidence encrypted, at most 16 MiB before encryption, with model and database immutability and restrictive source FKs. Hash the randomized authenticated ciphertext, never the private plaintext aggregate or buyer/request in a separate unkeyed digest; historical verification reconstructs the canonical plaintext after authenticated decryption. Existing legacy attempts are not converted into orders.

One owner-scoped idempotency key resolves to one request and order. Exact retries and owned recovery verify retained evidence before consulting today's availability, so ordinary expiry or policy/publication changes do not destroy access to the preparation record. Fresh creation retains the existing lock order and commits its order, lines, audit and same-UUID pending resource bindings together. A failed or expired preparation leaves none of those partial effects.

Current reads require the retained inventory/promotion bindings to remain pending. The next terminal-state implementation must extend this historical verifier before adding sold/redeemed/released transitions. Hosted Stripe test sessions, authoritative payment validation, durable processing/reconciliation and terminal business effects remain next, followed by WP-08 contracts/entitlements.

The [implementation contract](../order-preparation.md) documents HTTP, privacy, schema, retries, verification and rollback. `/checkout` remains 503; no provider session, payment, grant or entitlement is created. U-04/U-05/U-07/U-08 and the production/cutover gates remain unresolved for their relevant scope.
