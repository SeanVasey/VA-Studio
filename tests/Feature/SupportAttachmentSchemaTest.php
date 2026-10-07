<?php

namespace Tests\Feature;

use App\Domain\SupportAttachments\AttachmentSchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class SupportAttachmentSchemaTest extends TestCase
{
    private string $prefix = 'support_probe_';

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->setTablePrefix($this->prefix);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $pdo = DB::connection()->getPdo();
        $name = $this->prefix.'support_attachments';
        foreach (['update', 'delete'] as $operation) {
            $pdo->exec('DROP TRIGGER IF EXISTS '.$name.'_'.$operation);
        }
        $pdo->exec('DROP TABLE IF EXISTS '.$name);
    }

    public function test_complete_unlogged_schema_retry_is_exact_and_prefix_safe(): void
    {
        $schema = new AttachmentSchema;
        $schema->up();
        $before = $this->definition();
        $schema->up();
        $this->assertSame($before, $this->definition());
        $this->assertSame([], DB::table('support_attachments')->get()->all());
    }

    public function test_empty_atomic_table_and_each_owned_guard_prefix_can_resume(): void
    {
        $schema = new AttachmentSchema;
        $schema->up();
        $pdo = DB::connection()->getPdo();
        $name = $this->prefix.'support_attachments';
        $pdo->exec('DROP TRIGGER '.$name.'_update');
        $pdo->exec('DROP TRIGGER '.$name.'_delete');
        $schema->up();
        $this->assertSame(2, $this->guardCount());
        $pdo->exec('DROP TRIGGER '.$name.'_delete');
        $schema->up();
        $this->assertSame(2, $this->guardCount());
    }

    public function test_schema_drift_and_unknown_guards_refuse_before_mutation(): void
    {
        $schema = new AttachmentSchema;
        $schema->up();
        $pdo = DB::connection()->getPdo();
        $name = $this->prefix.'support_attachments';
        $pdo->exec('ALTER TABLE '.$name.' ADD COLUMN unexpected INTEGER');
        $before = $this->definition();
        try {
            $schema->up();
            $this->fail('Schema drift was adopted.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->definition());
    }

    public function test_retained_missing_guard_refuses_and_down_never_discards_originals(): void
    {
        $schema = new AttachmentSchema;
        $schema->up();
        $pdo = DB::connection()->getPdo();
        $name = $this->prefix.'support_attachments';
        $row = ['public_id' => '00000000-0000-4000-8000-000000000001', 'source_kind' => 'inquiry', 'source_id' => '00000000-0000-4000-8000-000000000002', 'source_family' => 'original_inquiry_session_v1',
            'source_hash' => str_repeat('a', 64), 'origin_hash' => str_repeat('a', 64), 'actor_hash' => str_repeat('a', 64), 'request_key' => '00000000-0000-4000-8000-000000000003',
            'policy_hash' => str_repeat('a', 64), 'manifest_hash' => str_repeat('a', 64), 'source_binding' => 'synthetic opaque evidence', 'policy_binding' => 'synthetic opaque evidence', 'manifest' => 'synthetic opaque evidence',
            'source_version' => 0, 'bytes' => 12, 'expires_at' => 200, 'attempt' => 0, 'created_at' => 100, 'updated_at' => 100, 'state' => 'receiving'];
        DB::table('support_attachments')->insert($row);
        $pdo->exec('DROP TRIGGER '.$name.'_delete');
        $before = $this->definition();
        foreach ([fn () => $schema->up(), fn () => $schema->down()] as $operation) {
            try {
                $operation();
                $this->fail('Retained evidence was silently repaired or discarded.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($before, $this->definition());
            $this->assertDatabaseCount('support_attachments', 1);
        }
    }

    private function definition(): array
    {
        $pdo = DB::connection()->getPdo();
        $name = $this->prefix.'support_attachments';
        if (DB::getDriverName() === 'sqlite') {
            return $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name LIKE '".$name."%' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        }

        return [$pdo->query('SHOW CREATE TABLE '.$name)->fetch(\PDO::FETCH_NUM), $pdo->query("SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = '".$name."' ORDER BY TRIGGER_NAME")->fetchAll(\PDO::FETCH_ASSOC)];
    }

    private function guardCount(): int
    {
        $name = $this->prefix.'support_attachments';
        $pdo = DB::connection()->getPdo();

        return (int) $pdo->query(DB::getDriverName() === 'sqlite' ? "SELECT count(*) FROM sqlite_master WHERE type = 'trigger' AND tbl_name = '".$name."'" : "SELECT count(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = '".$name."'")->fetchColumn();
    }
}
