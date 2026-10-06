<?php

namespace Tests\Feature;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Domain\Rights\VerifiedLicense;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class LicenseTemplateAuthoringConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exact template and current-authority row waits require independent MySQL sessions; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    public static function submissionOrdering(): array
    {
        return ['edit first' => [0], 'submission first' => [1]];
    }

    #[DataProvider('submissionOrdering')]
    public function test_template_edit_and_review_submission_serialize_on_the_exact_template_row_in_both_orders(int $first): void
    {
        $editor = LicenseFixtures::admin();
        $submitter = LicenseFixtures::admin();
        $version = LicenseFixtures::draft($submitter);
        $template = $version->template;
        $original = $template->getAttributes();
        $content = array_intersect_key($version->fresh()->getAttributes(), array_flip(['license_template_id', 'version', 'author_id', 'content_author_ids', 'predecessor_id', 'authored_source', 'structured_terms', 'effective_from', 'effective_until', 'created_at']));
        $historical = $this->historicalSnapshot();
        $evidence = $this->rows(['license_review_evidence']);
        $audits = AuditEvent::count();
        $change = $this->data('review-race');
        $inputs = [
            $this->input('edit', $editor, ['template_id' => $template->id, 'review' => app(SaveLicenseTemplate::class)->review($template, $editor), 'data' => $change]),
            $this->input('submit', $submitter, ['version_id' => $version->id]),
        ];
        $inputs[$first] += ['pause_table' => 'license_templates', 'pause_id' => $template->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $editor, $submitter, $template, $version, $original, $change): void {
            $this->releaseAfterExactWait($directory, $processes, $connections, $first, 'license_templates', $template->id);
            $results = $this->results($processes, $connections);
            $this->assertSame('submitted', $results[1]['result'], json_encode($results[1], JSON_THROW_ON_ERROR));
            $this->assertSame($results[1]['row'], $version->fresh()->getAttributes());
            $this->assertTemplateFence($results[0], $editor, $template);
            $this->assertTemplateFence($results[1], $submitter, $template);
            $this->assertSame('legal_review', $version->fresh()->status);
            $this->assertSame($submitter->id, $version->fresh()->submitted_by);
            if ($first === 0) {
                $this->assertSame('saved', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
                $this->assertSame($change, $template->fresh()->only(['name', 'slug', 'type']));
                $this->assertSame($results[0]['row'], $template->fresh()->getAttributes());
                $this->assertOrderedVersionFence($results[0]);
            } else {
                $this->assertSame('blocked', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
                $this->assertNotEmpty($results[0]['errors']);
                $this->assertArrayNotHasKey('row', $results[0]);
                $this->assertSame($original, $template->fresh()->getAttributes());
            }
            $submitted = $version->fresh();
            $this->assertSame($template->fresh()->only(['id', 'name', 'slug', 'type']), $submitted->submission_payload['template']);
            $this->assertSame(CanonicalJson::hash($submitted->submission_payload), $submitted->submission_hash);
            app(VerifiedLicense::class)->assertSubmitted($submitted);
        });
        $this->assertSame($content, array_intersect_key($version->fresh()->getAttributes(), $content));
        $this->assertSame($evidence, $this->rows(['license_review_evidence']));
        $this->assertHistoricalSnapshot($historical);
        $this->assertSame($audits + ($first === 0 ? 2 : 1), AuditEvent::count());
        $this->assertSame($first === 0 ? 1 : 0, AuditEvent::where('action', 'rights.license_template.updated')->where('subject_id', $template->id)->where('actor_id', $editor->id)->count());
        $this->assertSame(1, AuditEvent::where('action', 'rights.license.review_requested')->where('subject_id', $version->id)->where('actor_id', $submitter->id)->count());
    }

    public static function competingOrdering(): array
    {
        return ['first editor wins' => [0], 'second editor wins' => [1]];
    }

    #[DataProvider('competingOrdering')]
    public function test_competing_reviewed_template_edits_reject_the_stale_loser_after_an_exact_template_row_wait(int $first): void
    {
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $version = LicenseFixtures::draft($actors[0]);
        $template = $version->template;
        // Two draft rows make the writer's ordered version scan substantive.
        app(CreateLicenseDraft::class)->handle($template, $version->only(['authored_source', 'structured_terms']), $actors[1], $version);
        $historical = $this->historicalSnapshot();
        $versions = $this->rows(['license_versions', 'license_review_evidence']);
        $audits = AuditEvent::count();
        $changes = [$this->data('first-editor'), $this->data('second-editor')];
        $inputs = array_map(fn ($index) => $this->input('edit', $actors[$index], [
            'template_id' => $template->id, 'review' => app(SaveLicenseTemplate::class)->review($template, $actors[$index]), 'data' => $changes[$index],
        ]), [0, 1]);
        $inputs[$first] += ['pause_table' => 'license_templates', 'pause_id' => $template->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $actors, $template, $changes): void {
            $this->releaseAfterExactWait($directory, $processes, $connections, $first, 'license_templates', $template->id);
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[$first]['result'], json_encode($results[$first], JSON_THROW_ON_ERROR));
            $this->assertSame('blocked', $results[1 - $first]['result'], json_encode($results[1 - $first], JSON_THROW_ON_ERROR));
            $this->assertNotEmpty($results[1 - $first]['errors']);
            $this->assertArrayNotHasKey('row', $results[1 - $first]);
            $this->assertSame($changes[$first], $template->fresh()->only(['name', 'slug', 'type']));
            $this->assertSame($results[$first]['row'], $template->fresh()->getAttributes());
            foreach ($results as $index => $result) {
                $this->assertTemplateFence($result, $actors[$index], $template);
            }
            $this->assertOrderedVersionFence($results[$first]);
        });
        $this->assertSame($versions, $this->rows(['license_versions', 'license_review_evidence']));
        $this->assertHistoricalSnapshot($historical);
        $this->assertSame($audits + 1, AuditEvent::count());
        $audit = AuditEvent::where('action', 'rights.license_template.updated')->where('subject_id', $template->id)->sole();
        $this->assertSame($actors[$first]->id, $audit->actor_id);
        $this->assertSame(LicenseTemplate::class, $audit->subject_type);
    }

    public static function withdrawalOrdering(): array
    {
        return ['create / writer first' => ['create', 0], 'create / withdrawal first' => ['create', 1],
            'edit / writer first' => ['edit', 0], 'edit / withdrawal first' => ['edit', 1]];
    }

    #[DataProvider('withdrawalOrdering')]
    public function test_template_creation_and_reviewed_edit_serialize_role_withdrawal_on_the_exact_user_row(string $operation, int $first): void
    {
        $actor = LicenseFixtures::admin();
        $version = LicenseFixtures::draft($actor);
        $template = $version->template;
        $historical = $this->historicalSnapshot();
        $before = $this->rows(['license_templates', 'license_versions', 'license_review_evidence', 'audit_events']);
        $versions = $this->rows(['license_versions', 'license_review_evidence']);
        $audits = AuditEvent::count();
        $templates = LicenseTemplate::count();
        $change = $this->data($operation.'-withdrawal');
        $input = $this->input($operation, $actor, ['data' => $change]);
        if ($operation === 'edit') {
            $input += ['template_id' => $template->id, 'review' => app(SaveLicenseTemplate::class)->review($template, $actor)];
        }
        $inputs = [$input, $this->input('withdraw', $actor)];
        $inputs[$first] += ['pause_table' => 'users', 'pause_id' => $actor->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $operation, $actor, $template, $change, $before, $audits, $templates): void {
            $this->releaseAfterExactWait($directory, $processes, $connections, $first, 'users', $actor->id);
            $results = $this->results($processes, $connections);
            $this->assertSame('withdrawn', $results[1]['result']);
            $this->assertFalse($actor->fresh()->is_admin);
            $this->assertSame('users', $results[0]['locks'][0]['table']);
            $this->assertContains($actor->id, $results[0]['locks'][0]['ids']);
            if ($first === 0) {
                $this->assertSame('saved', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
                $saved = LicenseTemplate::findOrFail($results[0]['row']['id']);
                $this->assertSame($results[0]['row'], $saved->getAttributes());
                $this->assertSame($change, $saved->only(['name', 'slug', 'type']));
                $this->assertSame($audits + 1, AuditEvent::count());
                $this->assertSame($templates + ($operation === 'create' ? 1 : 0), LicenseTemplate::count());
                $audit = AuditEvent::where('action', 'rights.license_template.'.($operation === 'create' ? 'created' : 'updated'))->where('subject_id', $saved->id)->sole();
                $this->assertSame($actor->id, $audit->actor_id);
                $this->assertSame(LicenseTemplate::class, $audit->subject_type);
                if ($operation === 'edit') {
                    $this->assertSame($template->id, $saved->id);
                    $this->assertTemplateFence($results[0], $actor, $template);
                    $this->assertOrderedVersionFence($results[0]);
                }
            } else {
                $this->assertSame('denied', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
                $this->assertArrayNotHasKey('row', $results[0]);
                foreach ($results[0]['locks'] as $lock) {
                    $this->assertSame('users', $lock['table']);
                    $this->assertContains($actor->id, $lock['ids']);
                }
                $this->assertSame($before, $this->rows(['license_templates', 'license_versions', 'license_review_evidence', 'audit_events']));
            }
        });
        $this->assertSame($versions, $this->rows(['license_versions', 'license_review_evidence']));
        $this->assertHistoricalSnapshot($historical);
    }

    private function data(string $label): array
    {
        return ['name' => 'NONBINDING '.$label, 'slug' => $label.'-'.Str::uuid(), 'type' => 'non-exclusive'];
    }

    private function input(string $operation, User $actor, array $input = []): array
    {
        return $input + ['operation' => $operation, 'actor_id' => $actor->id, 'media_root' => Storage::disk('local')->path('')];
    }

    private function rows(array $tables): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    }

    private function historicalSnapshot(): array
    {
        $published = LicenseFixtures::published();

        return ['version' => $published, 'row' => $published->getAttributes(), 'template' => $published->template->getAttributes(), 'evidence' => $published->reviewEvidence()->sole()->getAttributes()];
    }

    private function assertHistoricalSnapshot(array $historical): void
    {
        $version = $historical['version']->fresh();
        $this->assertSame($historical['row'], $version->getAttributes());
        $this->assertSame($historical['template'], $version->template->getAttributes());
        $this->assertSame($historical['evidence'], $version->reviewEvidence()->sole()->getAttributes());
        app(VerifiedLicense::class)->assertReviewed($version);
    }

    private function assertTemplateFence(array $result, User $actor, LicenseTemplate $template): void
    {
        $this->assertSame('users', $result['locks'][0]['table']);
        $this->assertContains($actor->id, $result['locks'][0]['ids']);
        $templateIndex = array_search('license_templates', array_column($result['locks'], 'table'), true);
        $this->assertIsInt($templateIndex);
        $this->assertGreaterThan(0, $templateIndex);
        foreach (array_slice($result['locks'], 0, $templateIndex) as $lock) {
            $this->assertSame('users', $lock['table']);
            $this->assertContains($actor->id, $lock['ids']);
        }
        $this->assertContains($template->id, $result['locks'][$templateIndex]['ids']);
        $this->assertSame('license_versions', $result['locks'][$templateIndex + 1]['table']);
    }

    private function assertOrderedVersionFence(array $result): void
    {
        $locks = array_values(array_filter($result['locks'], fn ($lock) => $lock['table'] === 'license_versions'));
        $this->assertNotEmpty($locks);
        $this->assertStringContainsString('order by `id` asc', $locks[0]['sql']);
    }

    private function releaseAfterExactWait(string $directory, array $processes, array $connections, int $first, string $table, int $id): void
    {
        touch($directory.'/start-'.$first);
        $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
        touch($directory.'/start-'.(1 - $first));
        $this->await(fn () => $this->waiting($connections[1 - $first], $connections[$first], $table, $id), $processes);
        touch($directory.'/release-'.$first);
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/license-template-authoring-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/license-template-authoring-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_TEMPLATE_WRITER_DIRECTORY' => $directory, 'VASEY_TEMPLATE_WRITER_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $this->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            foreach ($ready as $row) {
                $this->assertTrue($row['retained_admin']);
                $this->assertTrue($row['retained_verified_email']);
            }
            $coordinate($directory, $processes, $connections);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function results(array $processes, array $connections): array
    {
        $results = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Template writer process failed: '.$process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($connections[$index], $result['connection_id']);
            $this->assertSame(0, $result['transaction_level']);
            $results[] = $result;
        }

        return $results;
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): bool
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), $table, (string) $id])?->lock_status === 'WAITING';
    }

    private function await(callable $ready, array $processes): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'A template writer exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Template writers did not reach the required exact row wait/barrier.');
    }
}
