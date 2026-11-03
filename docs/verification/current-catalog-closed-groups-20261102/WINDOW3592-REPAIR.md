# Minimal direct-window syntax repair

Actual approved read-only7cae A1 diagnostic STOPPED at native PREPARE,HY000/3592.
Frozen template hash36940bdd2038ed5d3f0cd3482a41d5660677d9d25d2910a0f24255d852a9fa66.
Factory/config/target passed; execute/EOF/meta/decode/postprepare warnings UNREACHED.
All-zero DB/PHP/CLI/source cleanup verified. Raw private receipt remains
/workspace/account-guard-evidence-20261101/a1diag-20261102T033035Z-d63a9b84b697.
No SHOW WARNINGS, retries, test bodies or app writes occurred in that diagnosis.

Exact Oracle8.4.11 share/messages_to_clients.txt Git blob
fb157bb8b0ead3c78c9755803e548a49ac1dea48 line8213: ER_WINDOW_ILLEGAL_ORDER_BY;
3592 determined by sequential definitions from start-error-number3500. Local
source Git-blob SHA independently matches that exact blob. Its public message
identifies unsupported legacy positional ORDER/PARTITION syntax.

The compiler now omits PARTITION BY for direct single-slot families: OVER() for
unsorted originals, OVER(ORDER BY exact original hidden keys) for ordered. Named
families retain PARTITION BY s.identity_lookup. One partition/window/slot0/header/
row/order/materialization and every original predicate/bind/type/guard remain.
No constant cast guess, limits, warnings or primary validation stage change.

Existing pure per-family window count still checks the SAME total, using
ROW_NUMBER() OVER ( instead of conflating windows with PARTITION syntax. Root
approved this one existing assertion normalization; no native oracle/test changed
and no new mirror-only test was added. Existing40tests/912assertions PASS at final
bytes (1.284seconds,PHP8.4.27,exit0), targeted lint/PintPASS. Commands and30 current
source hashes are in window3592-repair-{static,pure}.json and refreshed manifest.

Static complete compiled-plan differential: all30 plans preserve EXACT families/
bind arrays;12 templates change by ONLY45 bare singleton PARTITION removals over
three versions. Named window text is byte unchanged. The app delta is compiler
ONLY; runtime/factory/decoder/callers/old migrations/fixtures and ALLsix native
proof methods are unchanged7cae. None of the corrected SQL has run natively.

Original setup0assertions/six proof bodiesUNRUN/full133FAIL remain binding. This
source repair does not establish legal execution, lossless types/materialization/
warning/resource behavior, conditional107 or budget/main/member acceptance. New
exact-source review and root native lease must precede any query/test rerun.
