<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CatalogLease;
use App\Domain\Customers\ProductionIdentity\CatalogRead;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use ValueError;

final class CatalogReadTest extends TestCase
{
    public function test_closed_operations_cannot_select_business_rows_or_accept_caller_sql(): void
    {
        foreach (CatalogRead::cases() as $read) {
            self::assertStringStartsWith('SELECT ', $read->value);
            self::assertStringContainsString('FROM information_schema.', $read->value);
            self::assertDoesNotMatchRegularExpression('/;|\\b(?:CALL|INSERT|UPDATE\\s+[a-z]|DELETE|SLEEP|LOAD_FILE|INTO OUTFILE)\\b/i', $read->value);
        }
        $this->expectException(ValueError::class);
        CatalogRead::from('SELECT * FROM users');
    }

    public function test_namespace_union_preserves_six_exact_catalogs_and_original_binding_slots(): void
    {
        $sql = CatalogRead::AccountUnpinned->value;
        self::assertSame(29, substr_count($sql, '?'));
        self::assertSame(5, substr_count($sql, ' UNION ALL '));
        foreach (['TABLES', 'TRIGGERS', 'ROUTINES', 'EVENTS', 'STATISTICS', 'TABLE_CONSTRAINTS'] as $catalog) {
            self::assertStringContainsString('FROM information_schema.'.$catalog.' WHERE ', $sql);
        }
        self::assertStringContainsString("AND BINARY EVENT_OBJECT_TABLE<>BINARY 'customer_accounts'", $sql);
        self::assertStringNotContainsString('LOWER(', $sql);
        $this->expectException(LogicException::class);
        CatalogRead::namespace('users');
    }

    public function test_foreign_pdo_getter_callback_is_refused_before_it_can_execute(): void
    {
        $calls = 0;
        $foreign = new class($calls) extends PDO
        {
            private $calls;

            public function __construct(&$calls)
            {
                $this->calls = &$calls;
            }

            public function getAttribute(int $attribute): mixed
            {
                $this->calls++;

                return 'mysql';
            }
        };
        try {
            CatalogLease::open($foreign);
            self::fail('A foreign PDO subclass was admitted.');
        } catch (LogicException) {
            self::assertSame(0, $calls);
        }
    }
}
