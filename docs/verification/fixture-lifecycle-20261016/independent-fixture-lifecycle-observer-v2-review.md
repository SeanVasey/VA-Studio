# F2 lifecycle collector v2 review

**DECISION: REVISE TWO PRIVATE CAPTURE GAPS BEFORE RETRY.** Parent source carry at `c96da8e29999838ca14477e7dc8f236d6f00ad4a` remains accepted subject to completed execution conditions. No product P0/P1.

V2 repairs observer construction before launch, partial/final FD closure, finite capture bounds, child executable/argv/cwd/birth/ancestry correlation, pinned fresh root/baseline, and copied XML/receipt/warning/dataset validation. Original v1 bytes are unchanged.

Two required corrections remain:

- Both cleanup paths terminate the process group only while the PHP leader is running. A parent that exits or is interrupted while its child survives bypasses group cleanup. Reconcile and terminate the verified owned group/captured child identities after leader exit too, retain the real leader result, and catch vanished-group cleanup races so a terminal receipt is still written.
- Final metadata omits mtime/link count; copied length is not checked against final size and metadata is not rechecked after reading. Add these fields/checks before calling the copy complete original bytes.

The 4-group/19-retained-FD, 8 MiB/file, 64 MiB/total and 32 diagnostic bounds are meaningful. Derived child exit0/2 is accurately distinguished from raw child output/OS status. These corrections concern the private evidence collector; no application deadline or fixture assertion change is required. Exact file/source hashes and correction mapping are in the paired JSON.

Read-only inspection/AST parsing only, with no collector/app/tests/DB/network/CI execution or shared edits. Actual SQLite compatibility, native calibration and all four native datasets remain unaccepted here. The interrupted v1 -15/recorder1 run keeps NULL counts and zero observed groups; no cause or partial pass is inferred. Prior native4/8/E4 and old1/2/E1 failures, full F2/host/provider/activation/release limits remain open.
