# Checkout callback refusal — new replacement work

Executable source is `b6f1dfbb7b92cee5319eac79443b88bf24178ab2`, tree
`a2786396b2d93eef23785f75018ff402267880fa`, based on the frozen checkout
evidence head `182f70569e90815043742d5743f25886415ffaad`. This is new repair
work awaiting independent review. It adds no activation, provider call, grant,
shared registration or dependency upgrade.

## Actual defects and corrected behavior

Two unchanged canaries reproduced on source
`f0a1615be0833804959662fad5d7a7a8e1389ee0`. After real local SMTP enrollment
and checkout, they installed a lazy primary PDO closure which returned the same
original PDO while changing the original buyer's password. An expired paid
source refused its retained proof, but first ran that closure (SQLite 1 test,
3 assertions, 1 failure). A command's TransactionCommitted callback installed
the same lazy closure; the generic command boundary ran it and returned its
prepared result (SQLite 1 test, 2 assertions, 1 failure). This second result does
not establish that a public buyer HTTP response escaped its separate identity
check.

A third unchanged canary reproduced a second callback path on an uncommitted
intermediate repair. Removing the cached connection and installing a driver
extension caused the terminal framework connection lookup to run a callback
which changed the original buyer's password, even though the expired source
ultimately refused (SQLite 1 test, 3 assertions, 1 failure). The exact three
intermediate runtime files are retained under `evidence/intermediate-raw-pdo-only`;
this reproduction is not attributed to a published or frozen commit.

The correction checks only the already resolved connection map and its raw PDO
at authority-sensitive entry, terminal and postcommit boundaries. A missing
connection or unresolved PDO refuses before invoking its connector. The normal
initial command connection setup remains before authority capture. The paid
source's original savepoint continuity, graph checks and line projection are
preserved. The three regression cases verify both refusal and preservation of
the original buyer credential binding and durable financial records.

## Frozen evidence

`evidence/source-manifest.json` records 45 owned runtime/test files: 40 exact
prior files, four changed files, and one new resolved-connection selector. All
38 borrowed identity dependency files remain exact. The entire
ProductionPaidOrderSourceV1 class is byte exact with f0. The eight original
SourceTransaction test cases are unchanged; three actual callback regressions
were added. Existing migration, graph interpretation, provider, controller and
identity source bytes are unchanged. Earlier native schema, financial and race
results retain their original source attribution; they are not described as
new full-suite native acceptance.

Frozen SQLite: 133 recorded tests, 126 executed passes, 625 assertions and seven
explicit native-only skips. The three unchanged canaries separately pass with
10 assertions. Exact argument vectors, raw output, JUnit and exits are retained
in evidence. The broader development pilot selected nine inherited journey
cases twice through canary subclasses; its 33/240 result is retained as pilot
evidence, not added to the final distinct-case count.

Frozen actual MySQL 8.0.46 affected selection: 11 tests, 82 assertions, no
failures/errors/skips. The same three unchanged original canaries independently
pass on that native driver with 10 assertions. The affected selection covers the
three new callback regressions, four original interrupted-frame datasets, repeat
proof preserving consumer writes, actual payable/payment journey, recovery during
provider read, and postcommit shadow refusal. Exact selection, source SHA, raw
output, JUnit and exit are retained. SQLite does not prove MySQL behavior or
concurrency; this native selection is not a full new native suite.

## Limits and remaining dependencies

The native toolchain is actual Oracle MySQL 8.0.46 from authenticated Ubuntu
packages, using a dedicated synthetic checkout schema and private local task
environment. It differs from the final integrated MySQL 8.4 gate. Real local
SMTP data supplies synthetic enrollment proofs; fixture providers establish no
actual merchant, account, tax or payment fact. No live payment request, hosted
CI, push, merge or activation occurred.

The borrowed identity dependency remains exact eaaa source in this isolated
checkout snapshot. Root's later registered main 2cdd identity parent-floor
repair and shared privacy/routes composition are distinct integration work;
these runs do not certify that newer composition. The separately held identity
adapter child 7cc is not borrowed.

A producer-owned committed-read receipt and a separate provider-calculated
automatic-tax SourceV2 child are authorized next preparation. Neither is
implemented or accepted by this repair. Paid-purpose origin issuance, broader
tax/promo/exclusive/refund paths and final integration remain dependencies;
this limited qualified-exemption checkout is not T22 completion or launch
readiness.
