<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CatalogRead;
use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CatalogBatchPlanTest extends TestCase
{
    public function test_identity_batch_keeps_original_native_predicate_and_binds_names_as_values(): void
    {
        $plan = (new ReflectionMethod(CurrentCatalog::class, 'identityPlan'))->invoke(null, CatalogRead::QA8762690E619, ['users', 'customer_accounts']);
        self::assertSame("WITH lookup AS (SELECT TABLE_NAME name,'table' type,TABLE_NAME tbl_name, TABLE_NAME AS identity_key FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?)) SELECT /*+ NO_MERGE(lookup) */ 0 AS identity_lookup, lookup.* FROM lookup WHERE identity_key=? UNION ALL SELECT /*+ NO_MERGE(lookup) */ 1 AS identity_lookup, lookup.* FROM lookup WHERE identity_key=?", $plan['sql']);
        self::assertSame(['users', 'customer_accounts', 'users', 'customer_accounts'], $plan['bindings']);
        $untrusted = "x'); SELECT * FROM users; --";
        $plan = (new ReflectionMethod(CurrentCatalog::class, 'identityPlan'))->invoke(null, CatalogRead::QA8762690E619, [$untrusted]);
        self::assertStringNotContainsString($untrusted, $plan['sql']);
        self::assertSame([$untrusted, $untrusted], $plan['bindings']);
    }

    public function test_membership_batch_retains_binary_qualification_ordering_and_parameter_relations(): void
    {
        $plan = (new ReflectionMethod(CurrentCatalog::class, 'membershipPlan'))->invoke(null, CatalogRead::Q3AA887855776, ['one' => ['table_one'], 'two' => ['table_two']], 'one');
        self::assertSame('WITH membership_lookup AS (SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, TABLE_NAME AS membership_key_0, ORDINAL_POSITION AS membership_order_0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_SCHEMA = BINARY DATABASE() AND 1 = 1 AND ((TABLE_NAME = ?) OR (TABLE_NAME = ?))) SELECT /*+ NO_MERGE(membership_lookup) */ 0 AS membership_batch, membership_lookup.* FROM membership_lookup WHERE membership_key_0 = ? UNION ALL SELECT /*+ NO_MERGE(membership_lookup) */ 1 AS membership_batch, membership_lookup.* FROM membership_lookup WHERE membership_key_0 = ? ORDER BY membership_batch, membership_order_0', $plan['sql']);
        self::assertSame(['table_one', 'table_two', 'table_one', 'table_two'], $plan['bindings']);
        self::assertSame(['one', 'two'], $plan['names']);
    }

    public function test_nonbatch_catalog_and_wrong_parameter_shape_cannot_gain_compiled_sql_authority(): void
    {
        $this->expectException(LogicException::class);
        (new ReflectionMethod(CurrentCatalog::class, 'membershipPlan'))->invoke(null, CatalogRead::GlobalViews, ['one' => []], 'one');
    }

    public function test_both_membership_catalog_consumers_use_closed_enums_for_every_batch_call(): void
    {
        $root = dirname(__DIR__, 3);
        $consumers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source = file_get_contents($file->getPathname());
                if (str_contains($source, 'new MembershipSchemaCatalog(')) {
                    $consumers[] = $file->getPathname();
                    preg_match_all('/\$catalog->(?:named|rows)\(([^,]+),/', $source, $calls);
                    self::assertCount(9, $calls[1], $file->getPathname());
                    foreach ($calls[1] as $argument) {
                        self::assertMatchesRegularExpression('/\ACatalogRead::[A-Z0-9]+\z/', $argument);
                        self::assertInstanceOf(CatalogRead::class, constant(CatalogRead::class.'::'.substr($argument, strlen('CatalogRead::'))));
                    }
                }
            }
        }
        sort($consumers);
        self::assertSame([$root.'/app/Domain/Memberships/Billing/BillingSchema.php', $root.'/app/Domain/Memberships/Production/MembershipSchema.php'], $consumers);
    }
}
