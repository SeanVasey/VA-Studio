# Independent PDF successor seam review — correction required

Reviewed source `4f1c5b0fb4a3765a63f85ae58682987deb7f077c` (all 18 source/asset paths)
on candidate `46f57568960268ae6a2c90b72db38793ecb45dc7`. The separate experimental
lock `ebadd5d0f36e2a9d068c32dd7d4e7d43c230c959` changes exactly the PDF/font package
records. Both independent worktrees regenerate their own autoload and verify
all 158 installed versions/references against their actual locks.

Changes required: the new direct V2 renderer accepts a valid retained V1 profile
on the original runtime. It returns V1 profile hash `ff46257d...` but altered
PDF hash `cf7703861c79d7c583a62633c9181519b8d8c4f29c5fe1137c49a060ab7732f4`,
751,516 bytes with V2 creator, differing from exact original V1 `37c92cb5...`.
The operational fixed child dispatch is correct; the defect is the direct new
implementation accepting a profile that pins a different implementation.
The recorded reviewer regression fails on this path. Correction must enforce
implementation/version identity and regenerate the V2 implementation manifest
and actual output proof, preserving immutable V1 source/assets/profile/output.

Independent original-graph selection: 14 cases / 19 assertions passed. New-graph
selection: 14 / 27 passed. Four additional reviewer canaries: original 3 passed +
1 finding failure / 21 assertions; successor 4 / 34 passed. Both real isolated children
preserve complete normalized text from all frozen sections, 150 paragraphs and
markup-looking literal content using independent Poppler pdftotext extraction.
They produce deterministic multipage bytes/fixed page timestamps and no PDF
URI/JavaScript/embedded-file entries. The new graph additionally proves a
modified generated font is refused after first verifying a complete exact copy.
Successor configuration cannot activate current issuance on either graph.

All 9 font/source/license bytes are unchanged from V1 and match the successor
manifest; source Git blob identities and implementation pins match. Actual
new-graph font conversion reran with an empty asset/manifest diff. Current
profile/policy stay V1, and no experimental dependency lock is authorized for
publication. Correction is underway; this receipt provides no approval for
source4f1. No full/native/browser/provider/archival/launch acceptance is claimed.
