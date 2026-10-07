<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class CustomerListeningFreshnessTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function latePublicChanges(): array
    {
        $cases = [];
        foreach (['read', 'change'] as $operation) {
            foreach (['track', 'metadata', 'offer', 'file', 'configuration', 'rights', 'disk'] as $change) {
                $cases[$operation.' '.$change] = [$operation, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('latePublicChanges')]
    public function test_terminal_account_callback_cannot_release_superseded_public_summary_or_commit(string $operation, string $change): void
    {
        $this->fakePrivateMediaStorage();
        $customer = CustomerFixtures::account();
        $selection = QuoteFixtures::selection();
        $library = app(ListeningLibrary::class);
        $library->change($customer['principal'], $customer['user'], ['action' => 'save-track', 'version' => 0, 'trackId' => (string) $selection['track']->id]);
        $pdo = DB::connection()->getPdo();
        $before = $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC);
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired, $selection, $change): void {
            if ($fired || ! preg_match('/\bfrom ["`]customer_accounts["`]/i', $query->sql) || ++$seen !== 2) {
                return;
            }
            $fired = true;
            match ($change) {
                'track' => app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']),
                'metadata' => app(SaveTrackMetadata::class)->handle($selection['track'], ['title' => 'Fresh replacement public title', 'metadata_version' => $selection['track']->metadata_version], $selection['actor']),
                'offer' => app(DeactivateOffer::class)->handle($selection['offer'], $selection['actor']),
                'file' => unlink(Storage::disk('local')->path($selection['media']['preview_tagged']->storage_path)),
                'configuration' => config(['filesystems.disks.local.visibility' => 'public']),
                'rights' => RightsDeclaration::create(['track_id' => $selection['track']->id, 'provenance_reference' => 'SYNTHETIC new uncleared rights', 'sample_disclosure' => 'Test only', 'status' => 'pending']),
                'disk' => Storage::fake('local'),
            };
        });
        $refused = false;
        try {
            if ($operation === 'read') {
                $library->read($customer['principal'], $customer['user']);
            } else {
                $library->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 1, 'name' => 'PRIVATE new intent']);
            }
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
            $refused = true;
        }
        $this->assertTrue($fired, 'The final real account query callback must fire.');
        $this->assertTrue($refused, 'No superseded title, link or mutation may escape.');
        $this->assertSame($before, $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_callback_during_catalog_query_cannot_release_a_stale_retained_library_revision(): void
    {
        $this->fakePrivateMediaStorage();
        $customer = CustomerFixtures::account();
        $selection = QuoteFixtures::selection();
        $library = app(ListeningLibrary::class);
        $library->change($customer['principal'], $customer['user'], ['action' => 'save-track', 'version' => 0, 'trackId' => (string) $selection['track']->id]);
        $row = SavedListeningLibrary::sole();
        $before = $row->getRawOriginal();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $row): void {
            if (! $fired && preg_match('/\bfrom ["`]tracks["`]/i', $query->sql)) {
                $fired = true;
                $state = $row->payload;
                $state['version'] = 2;
                $state['favorites'] = [];
                $row->fill(['version' => 2, 'payload' => $state])->save();
            }
        });
        try {
            $library->read($customer['principal'], $customer['user']);
            $this->fail('A superseded retained library was released.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($fired);
            $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        }
    }

    public function test_saved_model_callback_cannot_rebind_expected_physical_evidence_to_another_valid_revision(): void
    {
        $customer = CustomerFixtures::account();
        $fired = false;
        SavedListeningLibrary::saved(function (SavedListeningLibrary $row) use (&$fired): void {
            if (! $fired) {
                $fired = true;
                $row->refresh();
                $state = $row->payload;
                $state['version'] = 2;
                $state['playlists'][0]['name'] = 'PRIVATE replacement callback name';
                $row->fill(['version' => 2, 'payload' => $state])->save();
            }
        });
        try {
            app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'PRIVATE intended name']);
            $this->fail('A rebound saved model allowed another revision to commit.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($fired);
            $this->assertDatabaseCount('customer_saved_tracks', 0);
        }
    }
}
