# Frozen synthetic v1 snapshots

These contain synthetic fixture metadata only. Generated using the unchanged
`HistoryNormalizer.php` from main
`2ab0c41e946e30cc402afc8ec107a3670e399abd`, evaluated as a distinct class, against
the existing `history-synthetic/baseline` source package. No live export or buyer
information was used.

- `baseline-v1.json`: exact original v1 normalized snapshot.
- `missing-extra-v1.json`: same source package plus the synthetic contracts CSV row
  `synthetic-extra,synthetic-ord-1001,1,synthetic-extra.txt,` with no supplied file.
  The original normalizer omits that declaration from contract records. This fixture
  exercises the historical information loss that a later v2 export cannot repair.

The v2 normalizer retains the canonical serialized customer/order/line/original
cohorts exactly as the frozen baseline; tests compare those cohorts and their hashes.
Neither fixture claims declaration completeness. V1 rows remain archived/readable;
any retained v1 batch blocks verified-grant eligibility under the approved design.
