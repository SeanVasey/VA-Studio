# Review: beatstars-normalizer 7acdd5d..82993ff (0a6566f, 82993ff)

**Decision: REQUEST CHANGES.** One narrow fix: whitespace before an opening quote bypasses the P1 check. Everything else is correct.

## Findings

- **M1: whitespace before a quote bypasses `quotes()`** (BeatStarsExportSheet.php:68-72). `fgetcsv` skips leading `" \t\v\f\r"` and then parses a quoted field. `quotes()` sees an unquoted field, so both Codex P1 shapes are still admitted:
  - `"a\n \"legacy-123"` (unterminated) is admitted and `fgetcsv` returns `legacy-123`.
  - `"a,b\n \"x\"-1,z\n"` (text after the closing quote) is admitted and returns `x-1`.
  - The same holds for tab, VT, FF and CR. NBSP is not skipped.

  A 300k-input differential fuzz against a strict RFC 4180 reference and `fgetcsv` found 0 valid inputs refused; every admitted misparse was in this class.
- **L1: no test pins the CRLF check.** The mutant that accepts a bare CR after a closing quote survives (`"x"\ry` is admitted and parses as `x\ry`). Advancing by 1 instead of 2 after CRLF is an equivalent mutant.
- **L2: the `.git` loop has no `.git`-file case.** Replacing `lstat` with `is_dir` survives. The code is correct as written (BeatStarsDryRunFiles.php:161-167):
  - `path()` guarantees an absolute path with no `//`, `.`, `..`, trailing `/` or symlinks, so `dirname` always reaches `/`.
  - `clearstatcache` and `lstat` catch a `.git` directory, file or symlink.
  - Side effect: a `~/.git` dotfiles repository makes every output directory under home refuse.
- **Declined positional id: sound.** A row with the wrong cell count cannot be aligned. The finding carries the row number, the row is withheld anyway, and null is fail-closed.
- **Rollback test: kills the loop mutant and is portable.** It needs only bash, a lowered RLIMIT_FSIZE (allowed for non-root users) and an ignored SIGXFSZ. The rename-phase mutant (`$created[$index] = $path`) still survives; the README already records it.
- **Cost: bounded and linear.** At 8 MiB, `quotes()` took 1.7 ms on realistic rows. The worst shapes took 194-329 ms (all commas, `"",` repeated, one doubled-quote field, all LF); the `fgetcsv` pass that follows is always slower.

## Conditions

1. In the unquoted branch, refuse with `export_csv_quotes` when `strspn($bytes, " \t\v\f\r", $offset) > 0` and the next byte is `"`. A fuzz of this variant found 0 admitted misparses and 0 refusals outside that class.
2. Add unit cases for: space then an unterminated quote, tab then quote then text after the close, and `"x"\ry`.
3. Optionally, add a feature case with a `.git` **file** for the output directory.

## Commands and results

- Unit tests: `OK (81 tests, 708 assertions)`. Feature tests: `OK (22 tests, 171 assertions)`. Both run as root in the worktree, whose HEAD is now 8d5b887 (README-only on top of 82993ff; code and tests identical).
- Mutants (`git archive` copy) killed: quotes() removed, any byte after close, EOF in quotes, doubled-as-close, newline ignored in unquoted fields, `.git` loop removed, check of the output directory alone, rollback loop removed. Survived: bare CR (L1), CRLF +1 (equivalent), `is_dir` (L2), rename-phase `$created` (already recorded).
- Scripts: `probe.php`, `fuzz.php`, `variants.php`, `timing.php`, `mutate.py`.
