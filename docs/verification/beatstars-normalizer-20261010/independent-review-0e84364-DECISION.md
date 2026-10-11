DECISION: APPROVE

# Review: beatstars-normalizer 20f5dc9..0e84364

Reviewed range: `20f5dc9941dff75e274ed2d26436871d748bbadc..0e8436405ffd95ee2e32aad05cea950d3cad2ce5`. It has two
commits: `936ac18` (escape backslashes before pipes in Markdown cells) and `0e84364` (docs items and review record).
Worktree `/home/user/wt-beatstars`, branch `harness/beatstars-normalizer`. Run as root in the container, PHP 8.4.26.
Renderers: `league/commonmark` `GithubFlavoredMarkdownConverter` from `vendor/`, and pandoc 3.1.3 `-f gfm`.

The Codex P2 is fixed correctly. Every table row now keeps its column count for values that contain backslashes and
pipes, under both renderers. The new test is red on 20f5dc9 and kills the obvious mutants. Both L items from the
20f5dc9 review are met, and the README paragraph summarizes that review accurately.

What remains is pre-existing display distortion and inline Markdown or HTML interpretation of operator-supplied
values. It does not affect table structure, the JSON report or the snapshot. Nothing blocks a development merge.

## Findings

- **P2, backslash before a pipe: fixed** (BeatStarsDryRunReport.php:131-138).
  - **How a GFM row is split.** The row scanner treats `\` plus the next character as one unit. A cell's `\|` then
    becomes `|` before inline parsing.
  - **New escaping.** Backslashes are doubled first, then pipes are escaped, so `a\|b` → `a\\\|b`. The scanner sees
    two escape pairs and no delimiter. After the pipe unescape, inline parsing shows `a\|b`.
  - **Before the fix,** pandoc gfm (the cmark-gfm rules GitHub uses) split `a\|b` into a title cell `a\` and a
    shifted next cell `b`. That reproduces the Codex report. `league/commonmark` did not split it, but it displayed
    `a|b`, which is still wrong.
  - **After the fix,** both renderers give 9 cells in the Rows table and 2 in the mapping table for every probe
    value: `a\|b`, `a\\|b`, `x\`, `\`, `|`, `a|b`, `c:\dir\file`, `c:\dir\|x`, a backtick, `<b>x</b>`, `# h` and
    `` \` ``.
  - The order matters. The mutant that escapes pipes before doubling backslashes is killed by the new test (unit
    `Failures: 1`).
- **Each context that `cell()` feeds** (`render.php`, which overrides the result array, so every context is
  exercised regardless of validation):

  | Context | What is displayed |
  | --- | --- |
  | Plain table cells: title, rights reference, source id, finding column and detail, slug | Exactly the value, for backslash and pipe values. |
  | Code spans inside tables: mapping column or constant, offer license, price column, export name | Pipes display correctly. Backslashes display doubled (`a\|b` shows as `a\\|b`), because code spans do not process escapes. This is new in this delta and the README states it. |
  | The `Operator reference:` paragraph (plain text, outside a table) | Exactly the value: `\\` renders as `\`, and `\|` as `\|`. |
  | Code spans outside tables: source system, snapshot id, `names()` lists, `pairs()` sources | Backslashes display doubled (new). A pipe displays as `\|`; that predates this delta, since pipes were already escaped and there is no table pipe-unescape outside a table. |

  - Values that do not go through `cell()` are validated vocabularies or numbers: scope, code, disposition, finding
    codes, `license_label` (`[a-z0-9-]`), currency, acquisition method, visibility targets and timestamps. None can
    carry a pipe.
- **I-1 (Info, pre-existing): inline Markdown and HTML are interpreted in plain contexts.**
  - In plain cells and the `Operator reference:` paragraph, `*q*` renders as emphasis, and `<b>x</b>` renders as
    raw HTML (probe: displayed `x`). Links work the same way.
  - The values come from the operator's own private export and mapping, and the report is a private local file.
    GitHub sanitizes HTML; a local previewer may not.
  - Structure is safe. CR and LF are flattened to spaces, so no value can start a line, and a leading `#`, a list
    marker or an HTML block cannot form. Pipes are escaped.
  - Optional hardening: in plain contexts, backslash-escape ASCII punctuation (`` \ ` * _ [ ] < > ! # ``). This is
    now safe, because backslashes are doubled.
- **I-2 (Info, pre-existing): a backtick in a value ends its code span early.**
  - Probe: the column `` a`b `` shows `ab`` ` ``. A source system of `` \` `` swallows the following text.
  - Rendering is garbled but the row structure holds, because the pipes in the rest of the line are still escaped.
  - Optional fix: use a backtick fence longer than the longest backtick run in the value, padded with spaces.
- **I-3 (Info, docs wording).** The README says the L items "are met in the next commit". They land in `0e84364`,
  which is the second commit after 20f5dc9 (`936ac18` comes first).
- **L items from the 20f5dc9 review: met.**
  - **Guide.** The reason codes now list `export_unreadable` ("the sheet could not be fully buffered for parsing").
    That is accurate.
  - **Class docblock** (BeatStarsDryRunFiles.php:11-22). It now says the mapping is private and that only a
    synthetic mapping may be read with its export from `tests/Fixtures/`. The output-directory sentence (outside
    every Git working tree, ancestors no other user can rename, caller-owned 0600 retained outputs) matches
    `directory()`. It does not mention the single-link rule, which is fine for a summary.
- **The README summary of the 20f5dc9 review is accurate.**
  - Decision APPROVE.
  - A 1.6M-input fuzz, with the admitted set equal to strict RFC 4180 under LF or CRLF line endings.
  - Both L items met.
  - Unchanged and informational: the untested fixture-mapping clause and `input_changed` comparison (correct by
    probe), and bare CR or old Mac line endings reporting as `export_csv_quotes`.
  - It omits I-b (a synthetic mapping with a private export, by design) and the non-root CI check. Neither omission
    is misleading.
- **The rest of the record is accurate.**
  - `independent-review-20f5dc9-DECISION.md` is byte-identical to the reviewer's `review-20f5dc9/DECISION.md`.
  - The Codex-round README entry is accurate, including "Inside code spans a doubled backslash displays as two; the
    JSON report stays the authoritative copy".
  - The evidence file `codex-markdown-backslash-red-green.txt` is accurate (see the red check below).

## Commands and results

- **Given command** at HEAD 0e84364: `OK (118 tests, 935 assertions)`. Separately: unit `OK (90 tests, 727 assertions)`
  and feature `OK (28 tests, 208 assertions)`.
- **Red on the 20f5dc9 report source.** `BeatStarsDryRunReport.php` was replaced by `git show 20f5dc9:…`.
  - Filtered `test_markdown_table_cells_keep_backslashes_and_pipes_inside_their_cell`:
    `Tests: 1, Assertions: 2, Failures: 1`. The failure message is the evidence file's verbatim.
  - Full unit: `Tests: 90, Assertions: 726, Failures: 1` (only the new test).
  - Feature: `OK (28 tests, 208 assertions)`.
  - The file was restored with `git checkout`, then `sha256sum -c` was OK.
- **Test fidelity.** The test's delimiter counter skips the character after a backslash, which is the cmark-gfm
  rule, and expects 10 pipes for the 9-column Rows table.

  | Mutant | Result |
  | --- | --- |
  | no backslash doubling (the 20f5dc9 source) | killed |
  | pipe escaped before backslash doubling | killed |

- **Renderer probes:** `render.php`, using league GFM with DOM extraction of each context, and pandoc gfm via
  `new.md` and `old.md`, run on the new and the old source.
- `vendor/bin/pint --test` on the 3 changed PHP files: passed. `git diff --check 20f5dc9..0e84364`: clean.
- At the end, `sha256sum -c orig.sha256` was OK, `git status --short` was empty, and HEAD was
  `0e8436405ffd95ee2e32aad05cea950d3cad2ce5`.

## Optional follow-ups (non-blocking)

1. I-1: backslash-escape Markdown punctuation and `<` in plain-text contexts.
2. I-2: use backtick fences longer than any backtick run in the value.
3. I-3: change "in the next commit" to "in `0e84364`".
