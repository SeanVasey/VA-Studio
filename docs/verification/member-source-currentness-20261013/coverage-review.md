# C4a focused coverage reconciliation

This ledger selects only passed testcase leaves from explicitly named zero-exit invocations, deduplicated by driver plus full testcase/dataset identity. Source-specific component checks remain separate from the completed integrated native canary at frozen `0095e46a`. Earlier configuration, baseline and parser passes overlap and are not added to this ledger.

| Scope | Distinct passed driver/case identities | Assertions in selected leaves | Sources |
| --- | ---: | ---: | --- |
| SQLite policy/frame/compatibility and whole eligibility | 65 | 445 | R4 `15a65e07`; catalog `982f4ea6` |
| Pure catalog parser, no application/database | 63 | 73 | Five captured inputs match `a58d53ef` |
| Native MySQL policy/frame and catalog selection | 9 | 60 | R4 `15a65e07`; catalog `982f4ea6` |
| Native integrated reservation canary | 1 | 21 | Frozen root `0095e46a` |

These are separate scopes, not a summed cross-driver unique-test census or one combined-source suite. The selected R4 SQLite invocation has one native-only skip, excluded from passing coverage. Its identity has a separate native pass. All intended reds, parser/setup/teardown errors and superseded pass invocations remain in the source map without contributing passing coverage here.

The corrected old-class catalog comparison reaches an actual independent committed counter 2→3 change, then fails original-snapshot reproof with `changed_catalog_source`. It is one intended uncaught JUnit error (1 / 12), not an assertion failure. The subsequent fixed five-case selection passes 5 / 46 on identical captured candidate inputs with only the explicit old-class preload removed. The earlier native 1205 setup error remains distinct.

Local selection/quality is excluded from the PHPUnit coverage ledger. Corrected policy 154 passes at `ca0fe30b` after the original shard-policy failure; Pint eight files passes at `cc612650` with exact carry to `0095e46a`. Discovery enumerates 2,103/202 of 8,245/602 across 24 shards without bodies. These observations do not establish hosted CI or application-suite acceptance.

All planned local invocations have completed. The final source comparison retains component differences; separate independent decision and exact-head preflight remain root/reviewer gates. Eight R4 frame methods and other eligibility revision/rights/callback variants were not run natively in this batch. Parser engine distinctions do not prove an actual native engine transition. There is no operative grant, provider/payment/customer, host, production activation or C4 credit-consumption claim.
