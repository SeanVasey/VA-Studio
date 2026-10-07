<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ListeningNotesFixtures;
use Tests\TestCase;

class CustomerListeningNotesBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function callbacks(): array
    {
        return ['note account withdrawal' => ['set-track-note', 'account'], 'note retained revision' => ['set-track-note', 'library'],
            'note deletion withdrawal' => ['delete-track-note', 'account'], 'clear account withdrawal' => ['clear-library', 'account'],
            'clear retained revision' => ['clear-library', 'library'], 'export account withdrawal' => ['export', 'account'],
            'export credential withdrawal' => ['export', 'password'], 'export policy withdrawal' => ['export', 'policy'],
            'export retained revision' => ['export', 'library']];
    }

    #[DataProvider('callbacks')]
    public function test_new_private_operations_refuse_real_terminal_callback_changes_and_retain_original_contents(string $operation, string $change): void
    {
        $customer = CustomerFixtures::account();
        SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 2, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => ['999'], 'playlists' => [],
                'notes' => [['trackId' => '999', 'body' => 'PRIVATE original lyric']]]]);
        $pdo = DB::connection()->getPdo();
        $before = $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC);
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired, $change, $customer, $pdo): void {
            if ($fired || ! preg_match('/\bfrom ["`]customer_accounts["`]/i', $query->sql) || ++$seen !== 2) {
                return;
            }
            $fired = true;
            if ($change === 'policy') {
                config(['customer.test_accounts_enabled' => false]);
            } elseif ($change === 'account') {
                $pdo->exec('UPDATE customer_accounts SET active=0,access_version=access_version+1 WHERE id='.(int) $customer['account']->id);
            } elseif ($change === 'password') {
                $pdo->exec("UPDATE users SET password='SYNTHETIC revoked credential' WHERE id=".(int) $customer['user']->id);
            } else {
                $pdo->exec('UPDATE customer_saved_tracks SET version=version+1 WHERE customer_account_id='.(int) $customer['account']->id);
            }
        });
        $refused = false;
        try {
            if ($operation === 'export') {
                app(ListeningLibrary::class)->export($customer['principal'], $customer['user'], 1);
            } else {
                $fields = $operation === 'clear-library' ? [] : ['trackId' => '999'];
                if ($operation === 'set-track-note') {
                    $fields['body'] = 'PRIVATE changed lyric';
                }
                app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => $operation, 'version' => 1, ...$fields]);
            }
        } catch (CustomerAccessException|ListeningException) {
            $refused = true;
        }
        $this->assertTrue($fired, 'The last genuine account query callback must be exercised.');
        $this->assertTrue($refused, 'Private notes/export/deletion must never escape terminal withdrawal.');
        $this->assertSame($before, $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_saved_note_model_callback_cannot_bind_projection_to_a_different_valid_revision(): void
    {
        ListeningNotesFixtures::enablePromotion();
        $customer = CustomerFixtures::account();
        SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => ['999'], 'playlists' => []]]);
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $fired = false;
        SavedListeningLibrary::saved(function (SavedListeningLibrary $row) use (&$fired): void {
            if (! $fired) {
                $fired = true;
                $row->refresh();
                $state = $row->payload;
                $state['version'] = 3;
                $state['notes'][0]['body'] = 'PRIVATE rebound callback lyric';
                $row->fill(['version' => 3, 'payload' => $state])->save();
            }
        });
        try {
            app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'set-track-note', 'version' => 1, 'trackId' => '999', 'body' => 'PRIVATE intended lyric']);
            $this->fail('A saved callback silently rebound the intended note.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($fired);
            $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        }
    }

    public function test_cross_account_actor_cannot_clear_another_owned_feature_aggregate(): void
    {
        $owner = CustomerFixtures::account();
        $other = CustomerFixtures::account();
        app(ListeningLibrary::class)->change($owner['principal'], $owner['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'PRIVATE owned list']);
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        try {
            app(ListeningLibrary::class)->change($owner['principal'], $other['user'], ['action' => 'clear-library', 'version' => 1]);
            $this->fail('Cross-account actor cleared the library.');
        } catch (CustomerAccessException) {
            $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        }
        $otherCleared = app(ListeningLibrary::class)->change($other['principal'], $other['user'], ['action' => 'clear-library', 'version' => 0]);
        $this->assertSame(1, $otherCleared['version']);
        $this->assertSame($before, SavedListeningLibrary::where('customer_account_id', $owner['account']->id)->sole()->getRawOriginal());
    }
}
