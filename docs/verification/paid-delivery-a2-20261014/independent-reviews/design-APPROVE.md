# Revised A2 timing design approval

**APPROVE the bounded timing design at `05f9d5036c1f6e9e7b7d8f93faaddf516f1c4b77` as a basis for implementation. This is design approval, not application/test/merge or production acceptance.**

The separate non-author reviewer `/root/c4c_final_packet_review` independently read the revised `docs/verification/paid-delivery-a2-20261014/request-start-design.md` and its exact delta from base `98bb9a917e77cbb12a40b4d1b4ad01b152092cdb`. The only added tracked path is that design document; no operative source changed. HEAD is exact05f9d503 and the worktree is clean.

The revision records the required original-age subtraction for initial and overall clocks, original captured snapshot-phase cap, unchanged observation cap, post-identity/later callback/locate/preinsert checks, preservation of current/committed/producer/raw/ProjectionRead admission, and immediate prepared/transfer cleanup with truthful consumed-attempt status. It preserves existing valid-at-arrival semantics and the seven cases, trusted legacy domain behavior, and open host/pre-mount limits.

The complete independent design review and regression requirements remain in `/workspace/runtime/paid-delivery-a2-request-start-design-review-98bb9a91-20261014.md`, SHA-256 `bf3d1f5ebc8ab3ea72d022bc83e8fdd4a1c18d3af91333d154b2a8f775f54cd5`. In particular, the author has explicitly agreed that the factory captures monotonic time before instant/policy resolution and that the supplied budget owns its exact admitted-instant value; implementation must enforce that pairing and must not accept a renewed/raw caller deadline or clock.

Application source and adversarial executable evidence still need independent review of the actual tested candidate. Only read-only design/source inspection and runtime notes were performed in this review. No application/DB/host/CI test was executed.
