# Failed or partial attempts (kept separate, not used as passing evidence)

1. **Pin evidence via an inline `bash -c` script (2026-10-09 ~08:56Z).** Claude Code's safety check refused the command
   before it ran, so it produced no output. The command is not repeated here. The same checks were then run through
   `probes/pinned-blobs.sh` → `evidence/01-pins-contracts-diff.txt` and `evidence/02-pinned-blob-ids.txt`.
2. **`evidence/31-approval-default-off-without-bindings.txt`.** This run removed only the renderer `foreach` loop
   (`probes/binding-red.patch`) and left the `use ...ProductionFreeGrantRendererProcess;` import, so the family-256 guard
   still fails on the import alone. It shows only that the import is enough to trip the guard. It is not a red/green
   proof. The proper red for 214256a is `evidence/94-214256a-binding-red-main-provider.txt`, which uses main's provider.
3. **`evidence/73-cache-identity-and-factory.txt`, case 2b.** The intent was to show that an in-place rewrite which keeps
   size and mtime is re-probed because ctime changes. The rewrite landed in the same second as the previous validation,
   PHP's `stat()` ctime has one-second resolution, and the cache accepted the rewritten file. This is recorded as
   finding I-2, not as a pass.
