# D-11 — Freeze exclusive scope evidence before activation

Status: implementation candidate, 2026-09-23; actual CI and independent-review state belongs in the integrating PR.

PR #44 merged shared inventory at `bd52f75256a2f89c86863147798a7190c73a54bf`. The next ordered dependency spans exclusive offer publication, quote/disclosure versioning, pricing, promotions and reservations. Current public readers deliberately support non-exclusive v1 commercial snapshots. Removing their guards would advertise unsupported checkout behavior and change the interpretation of historical evidence.

Prepare an immutable, inactive v2 exclusive revision with an explicit shared scope and link hash first. Reuse current reviewed license/media evidence and restrictive SQL history guards; commit the revision and exact link atomically. Restrict this internal command to local/testing and keep the public storefront and quote/pricing contracts unchanged. Separate preparations may name the same underlying scope without asserting a reservation or sale.

This approach avoids an independent parallel catalog schema and an unsafe activation flag. It also avoids backfilling scope identity from filenames, track IDs or existing non-exclusive contracts. A scope/reference change needs a new explicit successor. No production exclusivity term, TTL, cutoff, late-payment/refund policy or rights transfer is inferred.

The [implementation contract](../exclusive-offer-preparation.md) defines current command, evidence, transaction order, verification and recovery. The next bounded dependency is exclusive activation plus versioned quote/disclosure/pricing and atomic inventory integration, preserving existing readers. Then continue WP-07 payment/order finalization and WP-08 delivery. The [ordered development status](../development-order.md) retains all broader work and launch gates.
