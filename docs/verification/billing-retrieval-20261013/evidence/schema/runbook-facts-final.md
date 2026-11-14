# Forward263 operational facts (development evidence only)

Use the existing deployment maintenance procedure to drain billing retrieval jobs, webhook-hint writers and membership award/reservation work before running this forward migration. No provider I/O belongs inside the migration. This lane has exercised only disposable local databases; it has not verified a host, applied production changes or enabled billing.

The exact existing259–262 billing graph and its identity/membership parent floor must already be present. Run the one forward migration through the normal authorized migration process:

```sh
php artisan migrate --path=database/migrations/2026_10_13_263000_membership_billing_retrieval_identity.php --force
```

The migration requires no surrounding application/PDO transaction. Its preflight inspects the entire retained billing graph, award fence, reserved names, guard definitions, column/index/constraint shapes, unique retrieval endpoint ownership and extension-row shape before its first DDL. Existing encrypted payloads, seals and old scalar fields remain unchanged; nullable additions to legacy rows remain null. Fresh runtime authority requires the complete263 graph and new issued evidence. Retained legacy rows remain historical evidence; migration never promotes them into new current authority.

The forward sequence is ten individual nullable-column additions, two unique-index creations, and four exact DROP/CREATE guard replacements. SQLite wraps the complete sequence in BEGIN IMMEDIATE. MySQL re-acquires all six billing tables plus the award fence with WRITE locks before every next DDL, re-inspects the exact expected state, and releases them after that statement because native ALTER may end table-lock mode.

After an interrupted deployment, keep the same workers drained and rerun the same263 migration command. Only the exact baseline, one of the nineteen authored intermediate prefixes, or the exact completed graph is recognized. The updater does not adopt arbitrary drift, a removed guard from a fully upgraded graph, a foreign namespace object, non-null extension evidence in an intermediate graph, or duplicate retained endpoint claims. Such refusals need investigation and an explicit reviewed repair; they do not authorize dropping or rewriting retained data. Successful completion is idempotent. Normal runtime ownership accepts no intermediate graph.

`down()` always refuses and requires an explicit retention migration; it never drops billing evidence. The migration does not modify connection timeout settings. The native current-definition regression's secondary DDL connection uses `lock_wait_timeout=5` only to bound the disposable test. No production timeout setting or host procedure has been tested here.

Required current table and named guard definitions are bound by correction71f545bde74b30dab5398d9e4b4848856358b487. Native old-snapshot regressions pass2/34 at that source; the selected retained-upgrade and recovery receipt also passes11/275, both without skips/errors/failures. Unknown-extra-trigger inventory remains a separately carried limitation; this correction does not claim to resolve it.
