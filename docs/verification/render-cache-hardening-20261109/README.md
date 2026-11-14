# Render cache hardening evidence (2026-11-09, PR #254)

`glyph-equivalence-r2.txt` records the glyph equivalence comparison repeated for ProductionFree renderer revision r2
(open_basedir separators only): `ProductionFreeGrantRenderable::glyph` against the Tcpdf font API the renderer uses
(`isCharDefined` and a non-zero `getGidForOrd`, DejaVu Sans styles `B` and regular), over U+0020..U+10FFFF excluding
surrogates.

**The comparison command was not recorded.** It was a scratch script, as for the r1 run
(`docs/verification/free-256-20261007/hardening/codex-7/glyph-equivalence.txt`), and neither run committed it, so it cannot
be reconstructed exactly from the repository (review of #254, P3). The nearest committed script is the review probe
`docs/verification/free-256-20261007/independent-review/review-evidence/addendum4/probes/glyph-sample.php`. It applies the
same rule but checks a different set of code points: every BMP scalar value from U+0000 plus a seeded random sample of
50,000 supplementary ones, not the full range. A repeat for a later revision should commit its script beside its result.
