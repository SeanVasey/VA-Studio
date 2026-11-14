# Fixed integer-position join for named lookup — source repair only

Retained actual failures: old long-lifetime2GiB OOM137/setup0assert; controlled
fresh-lifetime case2-02 failed1test/321assertions at initialV2 I2 constraint target.
The approved3read retained-state diagnostic reproduced native target header0/R0,
actual decode0 versus original constraint target1 with zero warnings; statistics
all28 multisets matched. This localizes that lost target upstream of decode in
that condition. It does NOT establish a MySQL optimizer bug, OOM cause, complete
version behavior or permission to admit missing facts. Full133>110 remains FAIL.

## Exact owned source change

ONLY CatalogGroupPlan's PRIVATE named lookup compiler changes. The ORIGINAL
CurrentCatalog::identityPlan is byte-unchanged as independent native differential
oracle. Other app/callers/decoder/factory/tests/guards/migrations/fixtures/limits/
warning checks/bookends/caps and direct/singleton SQL are unchangedcaa.

Old private branch producer repeated `SELECT position,lookup.* FROM lookup WHERE
P(identity_key,?)` once per name with UNION ALL. New producer has one joined SELECT:

```
WITH lookup AS (EXACT ORIGINAL PROJECTION + identity_key/hidden-order
                FROM EXACT ORIGINAL SOURCE WHERE ORIGINAL WHERE AND ORIGINAL IN)
SELECT /*+ NO_MERGE(lookup) */ positions.identity_lookup, lookup.*
FROM lookup
JOIN (SELECT 0 AS identity_lookup UNION ALL SELECT 1 AS identity_lookup ...)
positions
ON CASE positions.identity_lookup
     WHEN 0 THEN EXACT ORIGINAL P(identity_key,?)
     WHEN 1 THEN EXACT ORIGINAL P(identity_key,?) ... ELSE FALSE END
ORDER BY ORIGINAL identity_lookup / hidden ordering when originally present
```

Positions are ONLY fixed integer indices0..N−1. There is NO derived name-string
join/JSON_TABLE/new COLLATE/cast/expression argument. The existing reviewed
NO_MERGE(lookup) hint remains once on this SELECT, with NO new guessed hint.
Fixed lookup base/CTE, projection/alias/key types, WHERE/schema/IN/native comparison
operands, hidden order, parameter vector[names,names], exact dedup and128-name/
1024-group bind caps are unchanged. Family ROW_NUMBER/partition/header/wire/gate
SQL and all validation stages are unchanged.

## Equivalence argument (not a native execution proof)

Let L be the ORIGINAL lookup bag after its unchanged schema/prefilter relation,
and P(r,n_i) the exact old native equality or LOWER equality against the same
prepared parameter (same operand/coercibility context). Original UNION ALL emits
(r,i) once for EVERY occurrence of r in L for which P is TRUE. New integer table
has exactly one row per i; its CASE selects EXACTLY P(r,n_i), and INNER JOIN emits
exactly those same pairs. FALSE and NULL both fail ON just as WHERE; no TRUE row
is lost. The unchanged IN prefilter is the disjunction of these same comparisons.

Base duplicate multiplicity survives: no DISTINCT/group/aggregation is added.
Different request strings equal under native case/accent/PAD/LOWER relations still
produce distinct positions and copies, exactly as original branches. Exact string
array dedup is the old operation, not a new SQL-collation dedup. A NULL key remains
nonmatching under the same comparison; NULL params were already rejected by the
unchanged input floor. CASE combines boolean comparison results, not metadata
strings; no derived name column changes comparison type/charset/coercibility.

Original projected associative cells/types/nulls remain lookup.* in original field
order after integer identity_lookup. Hidden original ordering fields and final
per-position/order-key ordering are retained; no invented tie-breaker/sort folds
strings. Family row-number/window materialization and shared header/body relations
are not removed. Header counts/caps and complete non-MDL namespace/dependent
coverage cannot be funded by an omitted fact. Pure truth-bag enumeration covers
360 T/F/NULL/multiplicity vectors as LOGIC inspection only, not a Python collation
model or native type/collation/SQL legality claim.

All named groups use this one closed private compiler consistently because the
same argument applies to their supported single-parameter native predicates.
Direct groups A0/A1/A2/D0 bypass this compiler and remain byte-identical. No public
signature or external caller family/schema/SQL/decoder API was introduced.

## Static evidence / native limits

All30 frozen plans preserve EXACT family contracts/name arrays and bind vectors;
18 named templates change,12 direct templates stay byte-identical. Each named
family now references lookup once with the same one hint; original oracle still
uses original repeated branches. New I2 SHA3875396f26180c26982e3d28fe757273bdcf11f33d7c89d9ce61faa448e5bee1,
9602bytes/112binds/two lookup references. Smaller text/references do NOT prove
memory fit, faster execution, repeatability, deadline/headroom or a passing110.

Existing40tests/912assertions and lint/Pint are required on final source. No
native SQL, EXPLAIN, test/restart/fixture or new diagnostic has run on this code.
Native legality/whole-row values/types/collation/bags/order/shared materialization/
warning/resource and missing-fact correction remain UNPROVEN until actual review.

Next authorized scope must FIRST repin the reviewed3read I2 diagnostic to this
new app SHA/group template hash; original two query hashes and strict controls
remain unchanged. Review those exact sources/hashes and obtain ROOT lease before
execution. A local corrected target does not accept other groups/version paths
or waive the unchanged six proofs, original cold110/memo15+ratio/terminal/earlyRR,
constructor/TLS/composition/ledger/domain/owner-resource gates. No native queue
continuation or cost run follows from this source repair.
