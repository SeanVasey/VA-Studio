DECISION: REVISE DESIGN — REMOVE THE SPLIT-EMISSION CALLBACK GAP

Reviewed exact clean `36e1f63ab3c448d70b8bd2cc799eaab21f999879`. The private kernel epoch correctly addresses premature send from middleware/RequestHandled: completion occurs only after the parent root handle returns, binds the original request/response and cannot be minted by public phase queries. Replacement/incomplete-root refusal, empty string conversion, already-sent-header refusal and original one-use closure are appropriately explicit.

One sentence still conflicts with the callback-free release contract: “Separate header/content calls may finalize only once after root completion...” can let `sendHeaders` consume the sole closure and return to arbitrary caller code before `sendContent` releases the private body. A late authority change or expiry in that gap is no longer covered by the consumed proof.

Refuse standalone split emission (allowing those methods internally only during one synchronous `send`), or make the first permitted emission entry complete headers and body atomically. No authorized prepared body may remain behind a returned-to-caller gap. Add a header-call -> late scope/deadline withdrawal -> content-call control, with no delayed private payload; retain ordinary synchronous post-kernel-send positives and one-use/no-repeat behavior.

This is one narrow design clarification, not a broader source audit. No established P0/P1 is reported. Metadata approval remains unchanged; actual HTTP/context/cleanup implementation and real send-path evidence/final review remain pending. Default-off, original deadline and non-HTTP behavior remain required.

Only three documentation paths differ from d4bb; the original d4 REVISE MD/JSON imports match their prior hashes. The accompanying JSON binds exact paths/hashes. No app/DB/network/CI execution, shared edit or preparation error occurred.
