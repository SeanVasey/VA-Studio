<?php

namespace Tests\Feature;

use App\Domain\Services\Projects\Attachments\ServiceProjectAttachmentAuthority;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentRows;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures;
use Tests\TestCase;

final class ServiceAttachmentTerminalCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_service_policy_withdrawn_at_the_final_staff_framework_query_cannot_mint_source_proof(): void
    {
        $fixture = ServiceProjectFixtures::setup();
        $pdo = DB::connection()->getPdo();
        $snapshot = [];
        foreach (['users', 'customer_accounts', 'service_projects', 'service_project_events'] as $table) {
            $snapshot[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        }
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, DB::connection()->getQueryGrammar()->wrapTable('users'))
                && ++$reads === 4) {
                config(['services-projects.test_enabled' => false]);
            }
        });
        try {
            DB::transaction(function () use ($fixture): void {
                (new ServiceProjectAttachmentAuthority)->lock($fixture['project']['id'], 0, 'intake',
                    AttachmentActor::operator($fixture['operator']), new AttachmentRows);
            });
            $this->fail('Withdrawn service policy must refuse before a source proof escapes.');
        } catch (AttachmentException $error) {
            $this->assertSame(404, $error->status);
        }
        $this->assertGreaterThanOrEqual(4, $reads);
        $this->assertFalse(config('services-projects.test_enabled'));
        foreach ($snapshot as $table => $rows) {
            $this->assertSame($rows, $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        }
    }
}
