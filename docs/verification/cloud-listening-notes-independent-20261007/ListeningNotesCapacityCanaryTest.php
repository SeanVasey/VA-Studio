<?php

namespace Tests\IndependentListeningNotes;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

final class ListeningNotesCapacityCanaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_oversized_encrypted_text_intent_is_refused_before_sql_and_retains_exportable_state(): void
    {
        $customer = CustomerFixtures::account();
        $ids = array_map('strval', range(1200, 1224));
        $body = str_repeat('🎵', 1000);
        $next = ['schema' => 2, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => $ids, 'playlists' => [], 'notes' => []];
        $retained = $next;
        foreach ($ids as $target) {
            $next['notes'][] = ['trackId' => $target, 'body' => $body];
            $candidate = new SavedListeningLibrary(['customer_account_id' => $customer['account']->id, 'version' => 1, 'payload' => $next]);
            $nextBytes = strlen($candidate->getAttributes()['payload']);
            if ($nextBytes > 65535) {
                break;
            }
            $retained = $next;
        }
        $this->assertGreaterThan(65535, $nextBytes);
        $saved = SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1, 'payload' => $retained]);
        $retainedBytes = strlen($saved->getAttributes()['payload']);
        $this->assertLessThanOrEqual(65535, $retainedBytes);
        $pdo = DB::connection()->getPdo();
        $capacity = DB::getDriverName() === 'mysql' ? (int) $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_saved_tracks' AND COLUMN_NAME='payload'")->fetchColumn() : null;
        if ($capacity !== null) {
            $this->assertSame(65535, $capacity);
        }
        $before = $this->snapshot($pdo);
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$attempts): void {
            if (preg_match('/\b(?:insert\s+into|update)\s+["`]?customer_saved_tracks\b/i', $sql)) {
                $attempts++;
            }
        });
        $status = null;
        try {
            app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'set-track-note', 'version' => 1, 'trackId' => $target, 'body' => $body]);
        } catch (ListeningException $error) {
            $status = $error->status;
        } catch (QueryException $error) {
            $this->record(['capacity' => $capacity, 'retained_bytes' => $retainedBytes, 'intent_bytes' => $nextBytes, 'sql_attempts' => $attempts,
                'driver_error' => $error->errorInfo, 'prior_rows_exact' => $before === $this->snapshot($pdo)]);
            throw $error;
        }
        $this->record(['capacity' => $capacity, 'retained_bytes' => $retainedBytes, 'intent_bytes' => $nextBytes, 'sql_attempts' => $attempts,
            'status' => $status, 'prior_rows_exact' => $before === $this->snapshot($pdo)]);
        $this->assertSame(422, $status);
        $this->assertSame(0, $attempts);
        $this->assertSame($before, $this->snapshot($pdo));
        $export = app(ListeningLibrary::class)->export($customer['principal'], $customer['user'], 1);
        $this->assertSame($retained['notes'], $export['notes']);
        $this->assertSame(1, $export['version']);
        $this->assertSame($before, $this->snapshot($pdo));
        $removed = app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'delete-track-note', 'version' => 1, 'trackId' => $ids[0]]);
        $this->assertSame(2, $removed['version']);
        $this->assertCount(count($retained['notes']) - 1, $removed['notes']);
        $this->assertLessThanOrEqual(60000, strlen((string) $pdo->query('SELECT payload FROM customer_saved_tracks')->fetchColumn()));
    }

    public function test_v1_reads_and_noops_retain_ciphertext_and_repeated_clear_fences_stale_export(): void
    {
        $customer = CustomerFixtures::account();
        SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => ['999'], 'playlists' => []]]);
        $pdo = DB::connection()->getPdo();
        $before = $this->snapshot($pdo);
        $library = app(ListeningLibrary::class);
        $this->assertSame(1, $library->read($customer['principal'], $customer['user'])['version']);
        $this->assertSame(1, $library->change($customer['principal'], $customer['user'], ['action' => 'delete-track-note', 'version' => 1, 'trackId' => '999'])['version']);
        $this->assertSame([], $library->export($customer['principal'], $customer['user'], 1)['notes']);
        $this->assertSame($before, $this->snapshot($pdo));
        foreach ([1, 2] as $version) {
            $result = $library->change($customer['principal'], $customer['user'], ['action' => 'clear-library', 'version' => $version]);
            $this->assertSame($version + 1, $result['version']);
            $this->assertSame([], $result['favorites']);
            $this->assertSame([], $result['notes']);
            try {
                $library->export($customer['principal'], $customer['user'], $version);
                $this->fail('A preceding aggregate version must not export after clear.');
            } catch (ListeningException $error) {
                $this->assertSame(409, $error->status);
            }
        }
        $this->assertSame(['exportSchema' => 1, 'feature' => 'customer-listening-library', 'version' => 3, 'favorites' => [], 'playlists' => [], 'notes' => []], $library->export($customer['principal'], $customer['user'], 3));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM customer_saved_tracks')->fetchColumn());
    }

    public function test_actual_last_export_account_query_cannot_release_notes_after_credential_withdrawal(): void
    {
        $customer = CustomerFixtures::account();
        SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 2, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => ['999'], 'playlists' => [],
                'notes' => [['trackId' => '999', 'body' => 'SYNTHETIC private lyric']]]]);
        $pdo = DB::connection()->getPdo();
        $before = $this->snapshot($pdo);
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired, $pdo, $customer): void {
            if (! $fired && preg_match('/\bfrom ["`]customer_accounts["`]/i', $query->sql) && ++$seen === 2) {
                $fired = true;
                $pdo->exec("UPDATE users SET password='SYNTHETIC withdrawn credential' WHERE id=".(int) $customer['user']->id);
            }
        });
        try {
            app(ListeningLibrary::class)->export($customer['principal'], $customer['user'], 1);
            $this->fail('Export must refuse credentials withdrawn at its last real framework callback.');
        } catch (CustomerAccessException) {
            $this->assertTrue($fired);
            $this->assertSame($before, $this->snapshot($pdo));
        }
    }

    private function snapshot(PDO $pdo): array
    {
        return array_combine(['users', 'customer_accounts', 'customer_saved_tracks'], array_map(fn ($table) => $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), ['users', 'customer_accounts', 'customer_saved_tracks']));
    }

    private function record(array $proof): void
    {
        $proof['actual_source'] = trim((string) getenv('NOTES_SOURCE_SHA'));
        $proof['actual_driver'] = DB::getDriverName();
        $proof['native_version'] = DB::getDriverName() === 'mysql' ? DB::connection()->getPdo()->query('SELECT VERSION()')->fetchColumn() : null;
        file_put_contents((string) getenv('NOTES_PROBE_PATH'), json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }
}
