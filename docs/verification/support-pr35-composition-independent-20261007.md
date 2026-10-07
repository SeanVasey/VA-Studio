# Support composition with merged Discovery: independent review

Exact `408f2e4d93b7081e8c6725ed686d961c08710023` (docs-only atop executable `a9eb1ac23718874db3d38ae0dbeb1deab7539dba`) is approved for this shared merge. The comparison uses approved support `5f9c1af243dd6474a68ba727ec7a361e66ea3b2e` and main PR35 `cd83b0cf4bddaeac9b113fabe7a918e37bfd2901`.

The15 incoming Discovery source/config/route/schema paths are byte-exact to approved main. PublicDiscoveryController is byte-exact to main except the retained `/private-support` robots prefix. Bootstrap is byte-exact to prior support except the incoming Discovery route registration; its private middleware, generic error response and private reporter hooks remain intact. The current editorial fallback and direct public response bodies therefore carry unchanged. Both exact parent diffs are preserved.

The662 review map independently reconciles32 unchanged approved runtime/test bindings, seven normalized tests,41 original archival artifacts and15 durable normalization artifact entries. The five shared source/test hashes and three new root artifact hashes also reconcile. Root's actual five-file45-case/608-assertion selection has zero failures/errors/skips; this is source-bound root execution, not a fresh peer test replay. Whitespace passes. No new native run was necessary for this merge-only comparison.

This approval preserves the prior narrow966+9ce runtime and test-lifecycle review scope. The separate independently reviewed full-family sitemap throttle correction must integrate before publication. Default-off preparation, actual production actor/scanner/storage/transport/policy facts and final integrated acceptance remain separate gates.
