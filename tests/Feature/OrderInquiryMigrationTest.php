<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\InquiryOrderContext;
use App\Domain\Inquiries\OrderInquiry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderInquiryFixtures as Fixture;
use Tests\TestCase;

class OrderInquiryMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000049_inquiry_order_contexts.php');
    }

    private function schema(): array
    {
        return DB::getDriverName() === 'sqlite' ? DB::table('sqlite_master')->orderBy('name')->get()->map(fn ($row) => (array) $row)->all()
            : ['tables' => Schema::getTables(), 'guards' => DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->orderBy('TRIGGER_NAME')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe migration or evidence change accepted.');
        } catch (LogicException|QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_empty_owned_roundtrip_and_repeat_install_preserve_unrelated_schema(): void
    {
        $before = $this->schema();
        $this->refused(fn () => $this->migration()->up());
        $this->assertSame($before, $this->schema());
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('inquiry_order_contexts'));
        $this->migration()->down();
        $this->migration()->up();
        $structure = static function (array $schema): array {
            if (isset($schema['guards'])) {
                foreach ($schema['guards'] as &$guard) {
                    if ($guard['EVENT_OBJECT_TABLE'] === 'inquiry_order_contexts') {
                        unset($guard['CREATED']);
                    }
                }
            }

            return $schema;
        };
        $this->assertSame($structure($before), $structure($this->schema()));
    }

    #[DataProvider('alterations')]
    public function test_empty_altered_or_foreign_objects_are_refused_before_any_ddl(string $kind): void
    {
        match ($kind) {
            'column' => Schema::table('inquiry_order_contexts', fn ($table) => $table->string('foreign_note')->nullable()),
            'index' => Schema::table('inquiry_order_contexts', fn ($table) => $table->index('created_at', 'foreign_context_index')),
            'incoming' => Schema::create('foreign_context_child', function ($table): void {
                $table->id();
                $table->foreignId('context_id')->constrained('inquiry_order_contexts')->restrictOnDelete();
            }),
            'guard' => DB::unprepared('DROP TRIGGER inquiry_order_contexts_delete'),
        };
        $before = $this->schema();
        $this->refused(fn () => $this->migration()->down());
        $this->assertSame($before, $this->schema());
    }

    public static function alterations(): array
    {
        return [['column'], ['index'], ['incoming'], ['guard']];
    }

    public function test_temporary_same_named_table_is_not_adopted_or_dropped(): void
    {
        DB::statement('CREATE TEMPORARY TABLE inquiry_order_contexts (foreign_marker INTEGER)');
        DB::table('inquiry_order_contexts')->insert(['foreign_marker' => 73]);
        try {
            $this->refused(fn () => $this->migration()->up());
            $this->refused(fn () => $this->migration()->down());
            $this->assertSame(73, DB::table('inquiry_order_contexts')->sole()->foreign_marker);
        } finally {
            DB::statement('DROP TABLE '.(DB::getDriverName() === 'sqlite' ? 'temp.' : '').'inquiry_order_contexts');
        }
        $this->assertSame(0, DB::table('inquiry_order_contexts')->count());
    }

    public function test_retained_association_is_immutable_by_model_bulk_sql_and_replace_and_prevents_rollback(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Fixture::configure();
        $order = Fixture::guest();
        app(OrderInquiry::class)->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, Fixture::body());
        $context = InquiryOrderContext::sole();
        $row = $context->getAttributes();
        $before = $this->schema();
        $this->refused(fn () => $context->update(['order_hash' => str_repeat('0', 64)]));
        $this->refused(fn () => $context->delete());
        $this->refused(fn () => DB::table('inquiry_order_contexts')->where('id', $context->id)->update(['order_hash' => str_repeat('0', 64)]));
        $this->refused(fn () => DB::table('inquiry_order_contexts')->where('id', $context->id)->delete());
        $quoted = implode(',', array_map(fn ($column) => DB::connection()->getQueryGrammar()->wrap($column), array_keys($row)));
        $this->refused(fn () => DB::statement('REPLACE INTO inquiry_order_contexts ('.$quoted.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row)));
        $this->refused(fn () => $this->migration()->down());
        $this->assertSame($row, $context->fresh()->getAttributes());
        $this->assertSame($before, $this->schema());
    }

    public function test_insert_guards_require_exact_existing_parent_hashes_shape_and_new_inquiry(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $fixture = Fixture::configure();
        $order = Fixture::guest();
        $inquiry = $fixture['inquiry'];
        $row = ['inquiry_id' => $inquiry->id, 'order_id' => $order->id, 'schema_version' => 1, 'order_hash' => $order->payload_hash,
            'inquiry_hash' => $inquiry->payload_hash, 'context_hash' => OrderInquiry::bindingHash($inquiry, $order), 'created_at' => $inquiry->created_at->format('Y-m-d H:i:s')];
        foreach (['inquiry_id' => 9999999, 'order_id' => 9999999, 'schema_version' => 2, 'order_hash' => str_repeat('f', 64),
            'inquiry_hash' => str_repeat('e', 64), 'context_hash' => str_repeat('A', 64), 'created_at' => now()->addSecond()->format('Y-m-d H:i:s')] as $field => $value) {
            $this->refused(fn () => DB::table('inquiry_order_contexts')->insert(array_replace($row, [$field => $value])));
        }
        DB::table('inquiry_order_contexts')->insert($row);
        $this->assertSame($order->public_id, app(OrderInquiry::class)->ownerContext($inquiry->public_id, InquiryConversationFixtures::OWNER)['order']['id']);
        $this->assertDatabaseCount('inquiry_order_contexts', 1);
    }
}
