# F2 lifecycle collector v3 carry

**DECISION: REVISE THE REMAINING TERMINAL-RECEIPT RACE BEFORE RETRY.** Parent source `c96da8e29999838ca14477e7dc8f236d6f00ad4a` is unchanged; no product P0/P1.

V3 satisfies final-byte metadata/completeness: mtime/link count, copied length equal to final size, and identical post-read descriptor metadata. Its guarded post-leader reconciliation validates owned group/birth/session/UID, captured child birth ticks, finite TERM/KILL polling and no live writers.

One prior V2 requirement remains: the earlier except/finally leader-stop blocks still execute killpg/wait outside that guard. A group exiting between poll and signal can raise ProcessLookupError and skip terminal receipt writing. Catch these cleanup races/errors or route every stop through one guarded helper, then retain the real leader result plus cleanup outcome.

V1 originals and reconstructed V2 reviewed bytes match their recorded hashes; the reconstruction is honestly documented. V3 hashes and narrow correction mapping are in the paired JSON. Read-only inspection/AST parsing, no execution/shared edits. SQLite12/native calibration1 are not accepted or eligible for retry until this last correction closes. All prior failed/interrupted runs and full F2/host/provider/activation limits remain unchanged.
