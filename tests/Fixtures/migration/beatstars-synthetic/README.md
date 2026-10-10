# Synthetic BeatStars export fixture

Everything in this directory is invented for automated tests. There is no real
artist, track, account, price, license, rights evidence or customer here, and no
BeatStars export has been inspected to make it. Titles carry the `SYNTHETIC `
marker that `vasey-private-catalog-drafts-v1` requires for `synthetic_fixture`
acquisitions, so this data can never be admitted as a real source.

| File | Purpose |
| --- | --- |
| `export.csv` | Four-row hand-made sheet: quoted cell with a comma and quotes, a quoted two-line description, blank optional cells, a `$3` price, a zero-padded BPM and padded tag separators. One ignored column (`Plays`). |
| `export-findings.csv` | The same sheet with one undeclared column (`Likes`) and one blank rights reference (row 3). The dry run must report exactly those two findings and normalize no row. |
| `mapping.json` | The operator mapping for both sheets: `synthetic_fixture` acquisition, derived slugs, a constant artist, explicit visibility values, two offers (one per-row license name, one constant license) and an invented license vocabulary. |

The prices (`1.00`, `2.50`, `$3`, `4.99`, `10.00`–`40.00` USD) are placeholders to
exercise minor-unit parsing. They are not approved prices and the dry run never
turns them into offers. See `docs/migration/beatstars-export-normalization.md`.
