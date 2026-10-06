<?php

namespace Tests\Feature;

use App\Domain\Rights\BulkReplaceLicenseDraftSource;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\ReviewedLicenseDraft;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\BulkReplaceLicenseDraftSourceFixtures as Fixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class BulkReplaceLicenseDraftSourceConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Bulk source fences require independent MySQL sessions and exact record waits; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    public static function mutationRaces(): array
    {
        $cases = [];
        foreach (['bulk', 'single', 'legacy', 'submit', 'template', 'role', 'email', 'mfa'] as $operation) {
            foreach ([0, 1] as $first) {
                $cases[$operation.' / worker '.$first.' first'] = [$operation, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('mutationRaces')]
    public function test_all_selected_draft_template_lifecycle_and_current_authority_fences_serialize_both_commit_orders(string $operation, int $first): void
    {
        ['actor' => $editor, 'versions' => $versions] = $this->selection();
        $ids = array_map(fn ($row) => $row->id, $versions);
        $other = in_array($operation, ['role', 'email', 'mfa'], true) ? $editor : LicenseFixtures::admin();
        $this->requireMfa($operation);
        $target = $versions[2];
        $template = $target->template;
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review(array_reverse($versions), Fixtures::replacement(), $editor);
        $singleReview = app(ReviewedLicenseDraft::class)->review($target, $other);
        $data = Arr::only($singleReview['display'], ReviewedLicenseDraft::FIELDS);
        $history = LicenseFixtures::published();
        $retained = $this->history($history);
        $before = array_map(fn ($row) => $row->fresh()->getAttributes(), $versions);
        $audits = AuditEvent::count();
        $otherSource = 'NONBINDING OTHER SYNTHETIC SOURCE';
        $inputs = [$this->input('bulk', $editor, $versions, ['review' => $review]),
            $this->input($operation, $other, $versions, ['version_id' => $target->id, 'template_id' => $template->id])];
        $inputs[1] += match ($operation) {
            'bulk' => ['review' => $command->review(array_reverse($versions), $otherSource, $other)],
            'single' => ['review' => $singleReview, 'data' => array_replace($data, ['authored_source' => $otherSource])],
            'legacy' => ['data' => array_replace($data, ['authored_source' => $otherSource])],
            'template' => ['review' => app(SaveLicenseTemplate::class)->review($template, $other),
                'data' => array_replace($template->only(SaveLicenseTemplate::FIELDS), ['name' => 'NEW NONBINDING SYNTHETIC TEMPLATE'])],
            default => [],
        };
        $otherBulkReview = $operation === 'bulk' ? $inputs[1]['review'] : null;
        [$table, $id] = match ($operation) {
            'role', 'email', 'mfa' => ['users', $editor->id],
            'legacy' => ['license_versions', $target->id],
            'bulk' => ['license_templates', $versions[0]->license_template_id],
            default => ['license_templates', $template->id],
        };
        foreach ($inputs as &$input) {
            $input['require_mfa'] = $operation === 'mfa';
        }
        unset($input);
        $inputs[$first] += ['pause_table' => $table, 'pause_id' => $id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $operation, $table, $id, $versions, $ids, $editor, $other, $template, $before, $audits, $review, $otherBulkReview, $otherSource): void {
            $this->releaseAfterExactWait($directory, $processes, $connections, $first, $table, $id, 'apply/'.$operation);
            $results = $this->results($processes, $connections);
            $this->assertTrue($results[$first]['paused']);
            $this->assertSame(['kind' => 'lock', 'table' => 'users', 'ids' => [$editor->id]], $results[0]['trace'][0]);
            if ($results[0]['result'] === 'saved') {
                $this->assertFencesBeforeAudit($results[0], $versions, $editor);
                $this->assertSame(['changed_ids' => $ids, 'unchanged_ids' => []], $results[0]['applied']);
            } elseif (! in_array($operation, ['role', 'email', 'mfa'], true)) {
                $this->assertFencesBeforeAudit($results[0], $versions, $editor, $operation !== 'submit');
            }
            if ($operation === 'bulk' && $first === 1) {
                $this->assertFencesBeforeAudit($results[1], $versions, $other);
                $this->assertSame(['changed_ids' => $ids, 'unchanged_ids' => []], $results[1]['applied']);
            }
            $bulkSaved = $first === 0;
            if (in_array($operation, ['bulk', 'single'], true)) {
                $this->assertSame('saved', $results[$first]['result']);
                $this->assertSame('blocked', $results[1 - $first]['result']);
                $this->assertArrayHasKey($first === 0 && $operation === 'single' ? 'license' : 'licenses', $results[1 - $first]['errors']);
                $this->assertSame($audits + ($operation === 'bulk' || $first === 0 ? 3 : 1), AuditEvent::count());
            } elseif (in_array($operation, ['role', 'email', 'mfa'], true)) {
                $this->assertSame('withdrawn', $results[1]['result']);
                $this->assertSame($first === 0 ? 'saved' : 'denied', $results[0]['result']);
                $this->assertSame($audits + ($first === 0 ? 3 : 0), AuditEvent::count());
                $this->assertSame($operation === 'role' ? false : null, match ($operation) {
                    'role' => $editor->fresh()->is_admin, 'email' => $editor->fresh()->email_verified_at, 'mfa' => $editor->fresh()->app_authentication_secret,
                });
            } else {
                $this->assertSame('saved', $results[1]['result']);
                $this->assertSame($first === 0 ? 'saved' : 'blocked', $results[0]['result']);
                if ($first === 1) {
                    $this->assertArrayHasKey('licenses', $results[0]['errors']);
                }
                $this->assertSame($audits + ($first === 0 ? 4 : 1), AuditEvent::count());
            }
            foreach ($versions as $index => $version) {
                $current = $version->fresh();
                $expected = $bulkSaved ? Fixtures::replacement() : $before[$index]['authored_source'];
                if (($operation === 'bulk' && $first === 1) || ($index === 2 && in_array($operation, ['single', 'legacy'], true) && ($operation === 'legacy' || $first === 1))) {
                    $expected = $otherSource;
                }
                $this->assertSame($expected, $current->authored_source);
                $this->assertSame($version->license_template_id, $current->license_template_id);
                $this->assertSame($version->structured_terms, $current->structured_terms);
                $this->assertSame($version->effective_from, $current->effective_from);
                $this->assertSame($version->effective_until, $current->effective_until);
                $changed = $expected !== $before[$index]['authored_source'];
                if ($changed) {
                    $expectedAuthor = (($operation === 'bulk' && $first === 1) || ($index === 2 && in_array($operation, ['single', 'legacy'], true) && ($operation === 'legacy' || $first === 1))) ? $other : $editor;
                    $this->assertSame($expectedAuthor->id, $current->author_id);
                    $this->assertContains($editor->id, $current->content_author_ids);
                    $this->assertContains($expectedAuthor->id, $current->content_author_ids);
                } else {
                    $this->assertSame($before[$index]['author_id'], $current->author_id);
                    $this->assertSame($version->content_author_ids, $current->content_author_ids);
                }
                $this->assertSame($index === 2 && $operation === 'submit' ? 'legal_review' : 'draft', $current->status);
                if ($index === 2 && $operation === 'submit') {
                    $this->assertSame($expected, $current->submission_payload['authored_source']);
                } else {
                    $left = $before[$index];
                    $right = $current->getAttributes();
                    foreach (['authored_source', 'author_id', 'content_author_ids', 'updated_at'] as $field) {
                        unset($left[$field], $right[$field]);
                    }
                    $this->assertSame($left, $right);
                }
            }
            if ($operation === 'template') {
                $this->assertSame('NEW NONBINDING SYNTHETIC TEMPLATE', $template->fresh()->name);
            }
            $events = AuditEvent::where('action', 'rights.license.draft_source_bulk_updated')->orderBy('id')->get();
            $expectedBulk = $operation === 'bulk' || $bulkSaved ? 3 : 0;
            $this->assertCount($expectedBulk, $events);
            foreach ($events as $event) {
                $this->assertSame($operation === 'bulk' && $first === 1 ? $other->id : $editor->id, $event->actor_id);
                $this->assertSame(LicenseVersion::class, $event->subject_type);
                $this->assertContains($event->subject_id, $ids);
                $this->assertSame(['authored_source'], $event->context['changed_fields']);
                $this->assertSame(CanonicalJson::hash($operation === 'bulk' && $first === 1 ? $otherBulkReview : $review), $event->context['batch_review_hash']);
            }
        });
        $this->assertSame($retained, $this->history($history));
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('offer_revisions', 0);
    }

    public static function captureRaces(): array
    {
        return ['legacy / capture first' => ['legacy', 0], 'legacy / writer first' => ['legacy', 1],
            'submit / capture first' => ['submit', 0], 'submit / writer first' => ['submit', 1]];
    }

    #[DataProvider('captureRaces')]
    public function test_explicit_capture_reads_only_the_after_fence_committed_state_and_later_apply_retains_that_exact_review(string $operation, int $first): void
    {
        ['actor' => $editor, 'versions' => $versions] = $this->selection();
        $other = LicenseFixtures::admin();
        $target = $versions[2];
        $before = array_map(fn ($row) => $row->fresh()->getAttributes(), $versions);
        $source = Fixtures::replacement();
        $data = Arr::only(app(ReviewedLicenseDraft::class)->review($target, $other)['display'], ReviewedLicenseDraft::FIELDS);
        $inputs = [$this->input('capture', $editor, $versions, ['source' => $source]),
            $this->input($operation, $other, $versions, ['version_id' => $target->id, 'template_id' => $target->license_template_id,
                'data' => array_replace($data, ['authored_source' => 'NONBINDING LEGACY CAPTURE RACE'])])];
        [$table, $id] = $operation === 'legacy' ? ['license_versions', $target->id] : ['license_templates', $target->license_template_id];
        $inputs[$first] += ['pause_table' => $table, 'pause_id' => $id];
        $audits = AuditEvent::count();
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $operation, $table, $id, $versions, $before, $audits, $editor): void {
            $this->releaseAfterExactWait($directory, $processes, $connections, $first, $table, $id, 'capture/'.$operation);
            $results = $this->results($processes, $connections);
            $this->assertTrue($results[$first]['paused']);
            $this->assertSame('saved', $results[1]['result']);
            $this->assertSame($audits + 1, AuditEvent::count());
            if ($operation === 'submit' && $first === 1) {
                $this->assertSame('blocked', $results[0]['result']);
                $this->assertArrayHasKey('licenses', $results[0]['errors']);
                $this->assertFencesBeforeAudit($results[0], $versions, $editor, false);

                return;
            }
            $this->assertSame('captured', $results[0]['result']);
            $this->assertFencesBeforeAudit($results[0], $versions, $editor);
            $captured = $results[0]['review'];
            $this->assertSame(array_map(fn ($row) => $row->id, $versions), array_column($captured['drafts'], 'version_id'));
            foreach ($captured['drafts'] as $index => $row) {
                $expected = $index === 2 && $operation === 'legacy' && $first === 1 ? 'NONBINDING LEGACY CAPTURE RACE' : $before[$index]['authored_source'];
                $this->assertSame($expected, $row['before']['authored_source']);
                if ($first === 1) {
                    $this->assertSame(CanonicalJson::hash($versions[$index]->fresh()->getAttributes()), $row['version_hash']);
                    $this->assertSame((int) AuditEvent::where('subject_type', LicenseVersion::class)->where('subject_id', $row['version_id'])->max('id'), $row['version_audit_id']);
                }
            }
            if ($first === 0) {
                $retained = array_map(fn ($row) => $row->fresh()->getAttributes(), $versions);
                try {
                    app(BulkReplaceLicenseDraftSource::class)->applyReviewed($captured, $editor);
                    $this->fail('A previously captured review silently adopted the later writer.');
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('licenses', $error->errors());
                }
                $this->assertSame($retained, array_map(fn ($row) => $row->fresh()->getAttributes(), $versions));
                $this->assertSame($audits + 1, AuditEvent::count());
            } else {
                $this->assertSame(['changed_ids' => array_map(fn ($row) => $row->id, $versions), 'unchanged_ids' => []], app(BulkReplaceLicenseDraftSource::class)->applyReviewed($captured, $editor));
                $this->assertSame($audits + 4, AuditEvent::count());
            }
        });
    }

    private function selection(): array
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, sameTemplate: true);
        $versions[] = Fixtures::drafts(1, actor: $actor)['versions'][0];

        return compact('actor', 'versions');
    }

    private function requireMfa(string $operation): void
    {
        if ($operation === 'mfa') {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        }
    }

    private function history(LicenseVersion $version): array
    {
        $current = $version->fresh();

        return [$current->getAttributes(), $current->template->getAttributes(), $current->reviewEvidence()->sole()->getAttributes()];
    }

    private function input(string $operation, User $actor, array $versions, array $data): array
    {
        return $data + ['operation' => $operation, 'actor_id' => $actor->id, 'version_ids' => array_reverse(array_map(fn ($row) => $row->id, $versions)),
            'media_root' => Storage::disk('local')->path(''), 'require_mfa' => false];
    }

    private function assertFencesBeforeAudit(array $result, array $versions, User $actor, bool $requiresAudit = true): void
    {
        // Command, verified catalog Gate, and explicit MFA recheck each fence current authority.
        $expected = array_fill(0, 3, ['kind' => 'lock', 'table' => 'users', 'ids' => [$actor->id]]);
        $parents = array_values(array_unique(array_map(fn ($row) => $row->license_template_id, $versions)));
        sort($parents, SORT_NUMERIC);
        foreach ($parents as $id) {
            $expected[] = ['kind' => 'lock', 'table' => 'license_templates', 'ids' => [$id]];
        }
        foreach ($versions as $version) {
            $expected[] = ['kind' => 'lock', 'table' => 'license_versions', 'ids' => [$version->id]];
        }
        $this->assertSame($expected, array_slice($result['trace'], 0, count($expected)));
        if ($requiresAudit) {
            $this->assertSame('audit', $result['trace'][count($expected)]['kind']);
        } else {
            $this->assertSame($expected, $result['trace']);
        }
    }

    private function releaseAfterExactWait(string $directory, array $processes, array $connections, int $first, string $table, int $id, string $case): void
    {
        touch($directory.'/start-'.$first);
        $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
        touch($directory.'/start-'.(1 - $first));
        $observed = null;
        $this->await(function () use ($connections, $first, $table, $id, &$observed): bool {
            $observed = $this->waiting($connections[1 - $first], $connections[$first], $table, $id);

            return $observed !== null;
        }, $processes);
        $this->assertNotNull($observed);
        echo 'NATIVE_BULK_LICENSE_WAIT '.json_encode(['case' => $case, 'first' => $first] + (array) $observed, JSON_THROW_ON_ERROR)."\n";
        touch($directory.'/release-'.$first);
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/bulk-license-source-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/bulk-replace-license-draft-source-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_BULK_LICENSE_DIRECTORY' => $directory, 'VASEY_BULK_LICENSE_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $this->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            foreach ($ready as $worker => $row) {
                $this->assertTrue($row['retained_admin']);
                $this->assertTrue($row['retained_verified_email']);
                $this->assertSame($inputs[$worker]['version_ids'], $row['retained_version_ids']);
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
            $this->assertSame(0, $process->getExitCode(), 'Bulk source worker process failed: '.$process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($connections[$index], $result['connection_id']);
            $this->assertSame(0, $result['transaction_level']);
            $results[] = $result;
        }

        return $results;
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): ?object
    {
        $sql = <<<'SQL'
SELECT requesting_thread.PROCESSLIST_ID AS requester, blocking_thread.PROCESSLIST_ID AS blocker,
       requested.OBJECT_SCHEMA AS database_name, requested.OBJECT_NAME AS table_name,
       requested.INDEX_NAME AS index_name, requested.LOCK_TYPE AS lock_type,
       requested.LOCK_STATUS AS lock_status, requested.LOCK_DATA AS lock_data
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), $table, (string) $id]);
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
                $this->assertTrue($process->isRunning(), 'A bulk source worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Bulk source workers did not reach the required exact row wait/barrier.');
    }
}
