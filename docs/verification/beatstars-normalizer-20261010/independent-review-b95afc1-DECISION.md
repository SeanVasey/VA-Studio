DECISION: APPROVE WITH CONDITIONS

# Review: beatstars-normalizer 0e84364..b95afc1

Reviewed range: `0e8436405ffd95ee2e32aad05cea950d3cad2ce5..b95afc1a114b561b2638a2d2cad806daa5e17f3e`. It is one
commit, `b95afc1` (keep numeric BeatStars source labels as JSON object keys). Worktree `/home/user/wt-beatstars`,
branch `harness/beatstars-normalizer`. Run as root in the container, PHP 8.4.26.

The Codex P2 is fixed correctly. The new test is red on 0e84364, and both test files are green.

One README sentence is false. It says the 0e84364 Markdown report "would also have made the Markdown report fail on
integer license names under strict types". I disproved it: there is no TypeError. That is the condition below.

There are also two Info items: the empty visibility map now encodes as `{}`, and an empty `constants` map still
encodes as `[]`. Nothing else blocks a development merge.

## Findings

- **P2, numeric source labels: fixed** (BeatStarsExportNormalizer.php:163-166; BeatStarsDryRunReport.php:70-71).
  - **The bug.** `CanonicalJson` encodes a PHP array as a list when `array_is_list()` is true. Only a map whose keys
    are exactly `"0".."n-1"`, in order, collapses.
  - **Probe on the 0e84364 source:**

    | Input | `mapping.licenses` | `mapping.visibility_values` |
    | --- | --- | --- |
    | licenses `{"0","1"}` | `["synthetic-basic","synthetic-premium"]` | — |
    | visibility `{"0","1"}` | — | `["draft","public"]` |
    | licenses `{"10","11","x"}` (not contiguous from 0) | already an object | — |

  - **The fix.** Both maps become `stdClass` in `result['mapping']` only. `CanonicalJson::normalize` emits a
    `stdClass` as an object with `(string)` keys, sorted `SORT_STRING`, as before.
  - **Probe at HEAD:**
    - licenses `{"0","1"}` → `{"0":"synthetic-basic","1":"synthetic-premium"}`;
    - visibility → `{"0":"draft","1":"public"}`;
    - both together → both objects;
    - the fixture mapping is unchanged.
  - **Lookups.** `isset($mapping['visibility_values'][$value])` and `$mapping['licenses'][$name]` (normalizer
    lines 322-356) still use the local array returned by `decode()`, so they are unaffected.
  - **Snapshot.** `catalog.json` is built from that same local array, not from `result['mapping']`. The snapshot
    bytes are unchanged; the fixture's determinism and admissibility tests stay green.
  - **Markdown report.** `get_object_vars()` on a `stdClass` returns integer keys for numeric property names again:
    - `names()` gets `array_map('strval', …)`;
    - `pairs()` already casts with `(string) $source`;
    - the offers loop reads `$offer['license']`, which stays a string.

    Both reports render `` `0` → `draft` `` and `` `10`, `11` ``.
- **Other consumers of `result['mapping']`.** The only production consumer is `BeatStarsDryRunReport` (grep of
  `app/`, `scripts/`, `tests/`). `BeatStarsDryRunFiles` passes the result through. The importer review reads
  `catalog.json`, not the report.
- **The guide's JSON examples** (`docs/migration/beatstars-export-normalization.md:61-63`) show the input mapping,
  where both maps are objects. Report JSON is not shown, so nothing in the docs is contradicted.
- **The determinism test change is sound.** `CanonicalJson::encode($result)` covers every field, including key
  order and the two objects. `assertSame` on arrays holding two distinct `stdClass` instances would fail.
- **C1 (Low, docs): the README's TypeError claim is false.** The sentence under "Codex round on `0e84364`" reads:
  "Before the fix, the same input would also have made the Markdown report fail on integer license names under
  strict types."
  - **Probe on the 0e84364 report and normalizer.** licenses `{"0","1"}` rendered `Known license names: `0`, `1`.`;
    licenses `{"10","11","x"}` rendered `` `10`, `11`, `x` ``. There was no exception.
  - **Why** (`strict.php`, a `declare(strict_types=1)` file): `array_map(fn (string $v) => $v, [0, 10])` returns
    `["0","10"]`. A user callback invoked by an internal function receives its arguments in coercive mode. The same
    closure called directly from the file throws a TypeError.
  - The old `names()` passed `array_keys(...)` only through `array_map` into that closure, so it coerced the
    integers silently.
  - The only real defect was the JSON list collapse, and the evidence file's red (`does not match expected type
    "object"`) correctly shows only that.
- **I-1 (Info): the empty visibility map is now `{}`.**
  - A mapping with no visibility column (a constant visibility, so `visibility_values` must be absent from the
    mapping) used to report `"visibility_values": []`. It now reports `{}` (probe `no-visibility`).
  - `{}` matches the mapping's own object form and the docs. The Markdown is unchanged (`Visibility values:
    constant.`).
  - Consequence: a retained `beatstars-dry-run.json` written before this commit, for such a mapping, now differs
    byte-for-byte. A rerun into the same output directory reports `output_differs`, which is the designed
    behavior. No real report has been retained yet.
- **I-2 (Info): an empty `constants` map still encodes as `[]`.**
  - Probe: a mapping with no constants (artist mapped as a column) gives `"constants": []`, next to
    `"visibility_values": {}`.
  - `constants` and `columns` are keyed by field names (`BeatStarsExportMapping::FIELDS`), so they can never
    collapse into a non-empty list. Only the empty case is inconsistent.
  - Optional: cast `constants` (and, for uniformity, `columns`) with `(object)` too.
- **The rest of the README and record is accurate.**
  - The 0e84364 summary paragraph matches that review:
    - the cell counts held under both renderers;
    - I-1 and I-2 listed as informational and unchanged;
    - the "second commit after 20f5dc9" correction.
  - The Codex-round entry is accurate apart from C1: "Lookups were unaffected", the maps carried as objects, and the
    red and green counts.
  - `independent-review-0e84364-DECISION.md` is byte-identical to the reviewer's `review-0e84364/DECISION.md`.
  - The evidence file's paths name `/home/user/wt-beatstars-b`, a different worktree, which is harmless. Its counts
    match this run.

## Commands and results

- **Given command** at HEAD b95afc1: `OK (119 tests, 939 assertions)`. Separately: unit `OK (91 tests, 731 assertions)`
  and feature `OK (28 tests, 208 assertions)`.
- **Red on the 0e84364 source.** `BeatStarsExportNormalizer.php` and `BeatStarsDryRunReport.php` were replaced by
  `git show 0e84364:…`.
  - Filtered `test_numeric_source_labels_stay_object_keys`: `[...] does not match expected type "object".`,
    `Tests: 1, Assertions: 1, Failures: 1`. This matches the evidence.
  - Full unit: `Tests: 91, Assertions: 728, Failures: 1` (only the new test).
  - Feature: `OK (28 tests, 208 assertions)`.
  - The files were restored, then `sha256sum -c orig.sha256` was OK.
- **Probes:**
  - `typeerror.php` (`numeric-licenses`, `numeric-visibility`, `both`, `no-visibility`) and `zero.php` (licenses
    `{"0","1"}`), each on the 0e84364 and the HEAD source;
  - `strict.php` (callback coercion under `strict_types`);
  - `consts.php` (empty `constants`).
- `vendor/bin/pint --test` on the 3 changed PHP files: passed. `git diff --check 0e84364..b95afc1`: clean.
- At the end, `git status --short` was empty and HEAD was `b95afc1a114b561b2638a2d2cad806daa5e17f3e`.

## Conditions

1. **C1, before final acceptance.** Remove or correct the README sentence about a strict-types failure. For example:
   "The Markdown report was unaffected: `array_map` passes the integer keys to its string-typed callback in coercive
   mode."
2. Optional (I-2): encode an empty `constants` map (and `columns`) as an object too, so empty maps are consistently
   `{}`.
3. Optional (I-1): note in the README that reports for mappings without a visibility column now carry
   `"visibility_values": {}`, so a report retained from before this commit reports `output_differs` on rerun.
