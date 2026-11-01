<?php

namespace Tests\Unit;

use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AccountAdmissionGuardDefinitionTest extends TestCase
{
    public function test_mysql_v2_changes_only_the_named_insert_collision_expression(): void
    {
        $v1 = AccountAdmissionGuards::definitions('mysql', AccountAdmissionGuards::V1);
        $v2 = AccountAdmissionGuards::definitions('mysql', AccountAdmissionGuards::V2);
        $this->assertSame($v1['customer_accounts_update'], $v2['customer_accounts_update']);
        $this->assertSame($v1['customer_accounts_delete'], $v2['customer_accounts_delete']);
        $this->assertArrayNotHasKey(AccountAdmissionGuards::OLD_INSERT, $v2);
        $body = $v2[AccountAdmissionGuards::NEW_INSERT]['body'];
        $original = 'EXISTS (SELECT 1 FROM customer_accounts WHERE id=NEW.id OR public_id=NEW.public_id OR user_id=NEW.user_id OR owner_key=NEW.owner_key)';
        $replacement = '(EXISTS (SELECT 1 FROM customer_accounts FORCE INDEX (`PRIMARY`) WHERE id=NEW.id) OR EXISTS (SELECT 1 FROM customer_accounts FORCE INDEX (`customer_accounts_public_id_unique`) WHERE public_id=NEW.public_id) OR EXISTS (SELECT 1 FROM customer_accounts FORCE INDEX (`customer_accounts_user_id_unique`) WHERE user_id=NEW.user_id) OR EXISTS (SELECT 1 FROM customer_accounts FORCE INDEX (`customer_accounts_owner_key_unique`) WHERE owner_key=NEW.owner_key))';
        $this->assertSame($v1[AccountAdmissionGuards::OLD_INSERT]['body'], str_replace($replacement, $original, $body, $count));
        $this->assertSame(1, $count);
        $this->assertSame($v1 + [AccountAdmissionGuards::NEW_INSERT => $v2[AccountAdmissionGuards::NEW_INSERT]], AccountAdmissionGuards::definitions('mysql', AccountAdmissionGuards::OVERLAP));
    }

    public function test_sqlite_has_only_the_retained_v1_contract(): void
    {
        $this->assertCount(3, AccountAdmissionGuards::definitions('sqlite', AccountAdmissionGuards::V1));
        $this->expectException(LogicException::class);
        AccountAdmissionGuards::definitions('sqlite', AccountAdmissionGuards::V2);
    }

    public function test_unreadable_stored_dependency_definition_refuses_closed(): void
    {
        $this->expectException(LogicException::class);
        (new ReflectionMethod(IdentityMigrationOwnership::class, 'references'))->invoke(new IdentityMigrationOwnership, null, ['customer_accounts']);
    }
}
