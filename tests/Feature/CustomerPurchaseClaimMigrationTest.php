<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerPurchaseClaimMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000048_customer_purchase_claims.php');
    }

    private function schema(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->orderBy('name')->get()->map(fn ($row) => (array) $row)->all()
            : ['tables' => Schema::getTables(), 'guards' => DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->orderBy('TRIGGER_NAME')->get()->map(fn ($row) => (array) $row)->all()];
    }

    public function test_empty_owned_roundtrip_preserves_exact_schema_and_repeat_install_refuses_without_ddl(): void
    {
        $before = $this->schema();
        try {
            $this->migration()->up();
            $this->fail('Existing schema was adopted.');
        } catch (\LogicException) {
        }
        $this->assertSame($before, $this->schema());
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('customer_purchase_challenges'));
        $this->assertFalse(Schema::hasTable('customer_purchase_claims'));
        $this->migration()->down();
        $this->migration()->up();
        // Recreated owned triggers have fresh creation times; all structural bytes and unrelated timestamps remain exact.
        $structure = function (array $snapshot): array {
            if (isset($snapshot['guards'])) {
                foreach ($snapshot['guards'] as &$guard) {
                    if (in_array($guard['EVENT_OBJECT_TABLE'], ['customer_purchase_challenges', 'customer_purchase_claims'], true)) {
                        unset($guard['CREATED']);
                    }
                }
            }

            return $snapshot;
        };
        $this->assertSame($structure($before), $structure($this->schema()));
    }

    public static function alteredObjects(): array
    {
        return [['column'], ['index'], ['incoming-reference'], ['guard'], ['partial']];
    }

    #[DataProvider('alteredObjects')]
    public function test_empty_foreign_or_altered_schema_is_preserved_when_rollback_refuses(string $mode): void
    {
        if ($mode === 'column') {
            Schema::table('customer_purchase_challenges', fn ($table) => $table->string('foreign_note')->nullable());
        } elseif ($mode === 'index') {
            Schema::table('customer_purchase_claims', fn ($table) => $table->index('claimed_at', 'foreign_claim_index'));
        } elseif ($mode === 'incoming-reference') {
            Schema::create('foreign_claim_child', function ($table): void {
                $table->id();
                $table->foreignId('claim_id')->constrained('customer_purchase_claims')->restrictOnDelete();
            });
        } elseif ($mode === 'guard') {
            DB::unprepared('DROP TRIGGER customer_purchase_claims_delete');
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? 'CREATE TRIGGER customer_purchase_claims_delete BEFORE DELETE ON customer_purchase_claims BEGIN SELECT 1; END'
                : 'CREATE TRIGGER customer_purchase_claims_delete BEFORE DELETE ON customer_purchase_claims FOR EACH ROW SET @foreign_claim_guard=1');
        } else {
            foreach (['insert', 'update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER customer_purchase_claims_'.$operation);
            }
            Schema::drop('customer_purchase_claims');
        }
        $before = $this->schema();
        try {
            $this->migration()->down();
            $this->fail('Altered schema was dropped.');
        } catch (\LogicException) {
        }
        $this->assertSame($before, $this->schema());
        $this->assertTrue(Schema::hasTable('customer_purchase_challenges'));
    }

    public function test_foreign_guard_collision_is_refused_before_either_table_is_created(): void
    {
        $this->migration()->down();
        Schema::create('foreign_claim_guard_owner', fn ($table) => $table->integer('id'));
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER customer_purchase_claims_insert BEFORE INSERT ON foreign_claim_guard_owner BEGIN SELECT 1; END'
            : 'CREATE TRIGGER customer_purchase_claims_insert BEFORE INSERT ON foreign_claim_guard_owner FOR EACH ROW SET @foreign_claim_guard=1');
        $before = $this->schema();
        try {
            $this->migration()->up();
            $this->fail('Foreign guard was adopted.');
        } catch (\LogicException) {
        }
        $this->assertSame($before, $this->schema());
        $this->assertFalse(Schema::hasTable('customer_purchase_challenges'));
        $this->assertFalse(Schema::hasTable('customer_purchase_claims'));
        DB::table('foreign_claim_guard_owner')->insert(['id' => 1]);
        $this->assertDatabaseCount('foreign_claim_guard_owner', 1);
    }

    public function test_temporary_table_shadow_is_refused_before_permanent_ddl(): void
    {
        $this->migration()->down();
        DB::statement('CREATE TEMPORARY TABLE customer_purchase_claims (sentinel INTEGER)');
        DB::statement('INSERT INTO customer_purchase_claims VALUES (73)');
        try {
            $before = $this->schema();
            try {
                $this->migration()->up();
                $this->fail('Temporary claim table was adopted.');
            } catch (\LogicException) {
            }
            $this->assertSame($before, $this->schema());
            $this->assertSame(73, (int) DB::table('customer_purchase_claims')->value('sentinel'));
            $this->assertFalse(Schema::hasTable('customer_purchase_challenges'));
        } finally {
            DB::statement('DROP TABLE customer_purchase_claims');
        }
    }
}
