# MySQL stored-byte guard diagnosis

The T04 migration enforces the bytes stored in the existing columns. MySQL converts a value to its declared column type before a `BEFORE` trigger reads `NEW`. A rejected raw predicate and an accepted column write can therefore be consistent when the column has already discarded an excess whitespace suffix. The migration does not rewrite historical rows or change existing format policies.

## Exact diagnostic evidence

[Diagnostic run 36806946452](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36806946452) used application commit `e08e90d69b8716b2641b6de603375358f6404176`, tree `38ef073721fb766e1875ba020f94f9d32c16e85b`, migration blob `36001db055319f031b954dc4e21ca639f6c5aa4b`, and unchanged original test blob `af7d564abdf26c73615c7d0c850dff5f4d5b27f5`. The separate diagnostic commit was `b79b11f19b0b30e5bbe4a9bc2eeb68c3fb71ea32`.

Artifact `11138361098` contains 310 JSONL records: 296 scratch writes, 10 actual `site_images` writes, three metadata records and one completed-cleanup record. MySQL was 8.4.11, PHP's client was mysqlnd 8.4.26, native prepares were enabled, and both session and global SQL modes included `STRICT_TRANS_TABLES`. Trigger definitions were captured. Sixteen disposable scratch tables were removed; actual application rows were rolled back.

| Input after a valid 64-byte ASCII hash | Actual `site_images` result | Stored value |
| --- | --- | --- |
| NUL + `suffix`, or NUL alone | Rejected, error 1406 | No row |
| Newline, CRLF, or space | Accepted after column conversion | Exactly the original 64 ASCII bytes; suffix absent |
| Another ordinary ASCII character | Rejected, error 1406 | No row |
| 64 multibyte characters, or 63 ASCII characters | Rejected by existing/supplemental guards | No row |

Both bound parameters and hexadecimal literals produced identical acceptance, `NEW` values and retained bytes. Trigger-free `CHAR(64)` and `VARCHAR(64)` controls, UTF-8 and ASCII columns, and observed/guarded columns all reproduced whitespace conversion. The observer saw the exact 64-byte prefix before the guard executed. A wider guarded `VARCHAR(128)` preserved the complete suffix until the trigger and rejected it. Thus a fixed-width column trigger cannot examine a suffix already discarded by the column conversion.

The original seven methods still ran unchanged and reported three failures. The first NUL payload was rejected; the next newline payload caused the assertion failures. These failures did not demonstrate malformed retained hashes.

The first diagnostic's `SHOW WARNINGS` capture is unreliable: attempting to prepare that command replaced the prior diagnostics with error 1295. No warning code or warning absence is used to establish this conclusion. Parameter HEX, observed `NEW`, stored HEX, lengths, error responses and cleanup records remain usable. The disposable probe was corrected to use direct PDO `query()` for any future warning capture.

## Regression contract

The raw predicate matrix still rejects all excess-byte values on both engines. Actual writes still reject NUL tails, ordinary overflow, short/multibyte values and SQLite BLOB storage; hexadecimal fields additionally reject embedded NUL, uppercase and nonhex values. Cases name their table, column and payload in failures.

Newline, CRLF and space writes remain covered for source hashes, variant hashes, both ready-image hashes, authorization hashes and redemption evidence. SQLite rejects them. MySQL must read back exactly the valid prefix, its exact hexadecimal bytes, and a byte count of 64. Each probe is rolled back and the complete table snapshot must be unchanged. Populated commerce probes also compare all retained historical evidence. No production column, migration, immutable record or format policy is altered to accommodate input conversion.

Length-only fields retain their existing policy. A 64-byte value containing an embedded NUL can satisfy that policy on MySQL; the hexadecimal policy rejects it. This is not permission to broaden legacy format validation or repair retained evidence. Application writers derive token/request/evidence hashes on the server and validate owner hashes; authorization reads verify the exact owner, token and evidence again. The diagnostic did not establish an HTTP authorization bypass.

## UUID follow-up boundary

The same diagnostic reproduced newline/space conversion into an exact 36-byte UUID in `CHAR(36)` and ASCII `VARCHAR(36)`. NUL tails and ordinary overflow were rejected. A wider canonical-UUID guard saw and rejected the complete suffix and embedded NUL. The next UUID batch needs analogous stored-value/HEX/36-byte assertions with rollback, while retaining raw predicate, malformed-byte, nullable-state, format, preflight and immutable-history tests. This diagnostic does not constitute acceptance of migration 000030.

MySQL documents [column checks before trigger activation and trigger SQL modes](https://dev.mysql.com/doc/refman/8.4/en/create-trigger.html) and [fixed-width conversion, strict overflow handling and excess trailing-space handling](https://dev.mysql.com/doc/refman/8.4/en/char.html). The specific newline/CRLF results above are observations from the pinned diagnostic, not a claim that every server version must behave identically.

Full candidate MySQL, SQLite, browser and other required CI gates remain mandatory. This isolated diagnostic is not merge or production migration acceptance.
