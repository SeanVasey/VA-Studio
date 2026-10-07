<?php

namespace Tests\Feature;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\ListeningNotesFixtures;
use Tests\TestCase;

class CustomerListeningNotesCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ListeningNotesFixtures::enablePromotion();
    }

    public function test_actual_envelope_above_native_text_capacity_is_refused_without_writing_or_losing_the_retained_revision(): void
    {
        $customer = CustomerFixtures::account();
        $ids = array_map('strval', range(1000, 1024));
        $state = ['schema' => 2, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => $ids, 'playlists' => [], 'notes' => []];
        $body = str_repeat('🎵', 1000);
        $retained = $state;
        foreach ($ids as $id) {
            $state['notes'][] = ['trackId' => $id, 'body' => $body];
            $candidate = new SavedListeningLibrary(['customer_account_id' => $customer['account']->id, 'version' => 1, 'payload' => $state]);
            $encryptedBytes = strlen($candidate->getAttributes()['payload']);
            if ($encryptedBytes > 65535) {
                break;
            }
            $retained = $state;
        }
        $this->assertGreaterThan(65535, $encryptedBytes, 'This intent must exceed actual TEXT capacity, not only the conservative application budget.');
        $saved = SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1, 'payload' => $retained]);
        $this->assertLessThanOrEqual(65535, strlen($saved->getAttributes()['payload']));
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'mysql') {
            $capacity = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_saved_tracks' AND COLUMN_NAME='payload'")->fetchColumn();
            $this->assertSame(65535, (int) $capacity, 'This native canary must exercise the original TEXT column.');
        }
        $before = $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC);
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/\b(?:insert\s+into|update)\s+["`]?customer_saved_tracks\b/i', $query->sql)) {
                $writes++;
            }
        });
        $library = app(ListeningLibrary::class);
        try {
            $library->change($customer['principal'], $customer['user'], ['action' => 'set-track-note', 'version' => 1, 'trackId' => $id, 'body' => $body]);
            $this->fail('An actual oversized TEXT intent must be refused before any database write.');
        } catch (ListeningException $error) {
            $this->assertSame(422, $error->status);
        }
        $this->assertSame(0, $writes);
        $this->assertSame($before, $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC));
        $export = $library->export($customer['principal'], $customer['user'], 1);
        $this->assertSame($retained['notes'], $export['notes']);
        $this->assertSame(1, $export['version']);
        $removed = $library->change($customer['principal'], $customer['user'], ['action' => 'delete-track-note', 'version' => 1, 'trackId' => $ids[0]]);
        $this->assertSame(2, $removed['version']);
        $this->assertCount(count($retained['notes']) - 1, $removed['notes']);
        $this->assertLessThanOrEqual(60000, strlen((string) $pdo->query('SELECT payload FROM customer_saved_tracks')->fetchColumn()));
    }

    public static function bodies(): array
    {
        return ['maximum ASCII characters' => [str_repeat('x', 2000)], 'maximum multibyte bytes with escaped JSON' => [str_repeat('🎵', 1000)]];
    }

    #[DataProvider('bodies')]
    public function test_actual_encrypted_aggregate_capacity_refuses_before_sql_and_preserves_the_entire_row_and_revision(string $body): void
    {
        $customer = CustomerFixtures::account();
        $ids = array_map('strval', range(1000, 1024));
        SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => $ids, 'playlists' => []]]);
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'mysql') {
            $capacity = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_saved_tracks' AND COLUMN_NAME='payload'")->fetchColumn();
            $this->assertSame(65535, (int) $capacity, 'This native canary must exercise the original TEXT column.');
        }
        $library = app(ListeningLibrary::class);
        $version = 1;
        $refused = false;
        $accepted = 0;
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/\b(?:insert\s+into|update)\s+["`]?customer_saved_tracks\b/i', $query->sql)) {
                $writes++;
            }
        });
        foreach ($ids as $id) {
            $before = $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC);
            $writes = 0;
            try {
                $result = $library->change($customer['principal'], $customer['user'], ['action' => 'set-track-note', 'version' => $version, 'trackId' => $id, 'body' => $body]);
                $this->assertSame(++$version, $result['version']);
                $this->assertSame(++$accepted, count($result['notes']));
                $this->assertLessThanOrEqual(60000, strlen((string) $pdo->query('SELECT payload FROM customer_saved_tracks')->fetchColumn()));
            } catch (ListeningException $error) {
                $this->assertSame(422, $error->status, 'Capacity must be a definite bounds refusal, not a database overflow.');
                $this->assertSame(0, $writes, 'No library SQL write may occur for the over-capacity intent.');
                $this->assertSame($before, $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC));
                $refused = true;
                break;
            }
        }
        $this->assertTrue($refused, 'Per-note/count limits alone must not allow an oversized TEXT envelope.');
        $this->assertGreaterThan(0, $accepted);
        $this->assertLessThan(25, $accepted, '25 maximum notes are an upper bound, not a promised storage capacity.');
        $this->assertSame($version, $library->export($customer['principal'], $customer['user'], $version)['version']);
        $removed = $library->change($customer['principal'], $customer['user'], ['action' => 'delete-track-note', 'version' => $version, 'trackId' => $ids[0]]);
        $this->assertSame(++$version, $removed['version']);
        $resumed = $library->change($customer['principal'], $customer['user'], ['action' => 'set-track-note', 'version' => $version, 'trackId' => $id, 'body' => $body]);
        $this->assertSame(++$version, $resumed['version']);
        $this->assertCount($accepted, $resumed['notes']);
        $this->assertLessThanOrEqual(60000, strlen((string) $pdo->query('SELECT payload FROM customer_saved_tracks')->fetchColumn()));
    }
}
