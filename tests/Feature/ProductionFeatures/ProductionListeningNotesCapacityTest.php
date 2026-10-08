<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFeatureFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ProductionListeningNotesCapacityTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
        $this->fakePrivateMediaStorage();
    }

    public function test_test_rollout_cannot_promote_production_notes_and_existing_v2_is_never_downgraded(): void
    {
        $owner = $this->featureIdentity();
        $track = QuoteFixtures::selection();
        $id = (string) $track['track']->id;
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $library->change($owner, ['action' => 'save-track', 'version' => 0, 'trackId' => $id]);
        $before = (array) DB::table('production_listening_libraries')->sole();
        config(['customer-listening' => ['v2_promotion_enabled' => true, 'v2_rollout_review_reference' => 'SYNTHETIC test rollout only']]);
        try {
            $library->change($owner, ['action' => 'set-track-note', 'version' => 1, 'trackId' => $id, 'body' => 'SYNTHETIC private lyric note']);
            $this->fail('Test rollout cannot enable production promotion.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole());
        config(['production-customer-listening' => ['v2_promotion_enabled' => true,
            'v2_rollout_review_reference' => 'SYNTHETIC stopped upgrade, backup and consumer review']]);
        $note = $library->change($owner, ['action' => 'set-track-note', 'version' => 1, 'trackId' => $id, 'body' => 'SYNTHETIC private lyric note']);
        $this->assertSame(2, $note['library']['listeningSchema']);
        config(['production-customer-listening' => ['v2_promotion_enabled' => false, 'v2_rollout_review_reference' => null]]);
        $edited = $library->change($owner, ['action' => 'set-track-note', 'version' => 2, 'trackId' => $id, 'body' => 'SYNTHETIC revised lyric note']);
        $this->assertSame(2, $edited['library']['listeningSchema']);
        $this->assertSame([['trackId' => $id, 'body' => 'SYNTHETIC revised lyric note']], $library->export($owner, 3)['notes']);
        $clear = $library->change($owner, ['action' => 'clear-library', 'version' => 3]);
        $this->assertSame(2, $clear['library']['listeningSchema']);
        $this->assertSame([], $clear['library']['notes']);
        $raw = (array) DB::table('production_listening_libraries')->sole();
        $this->assertSame(2, json_decode(Crypt::decryptString($raw['payload']), true)['schema']);
    }

    public function test_actual_over_text_capacity_envelope_is_refused_before_update_with_row_and_revision_unchanged(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $ids = array_map('strval', range(7001, 7025));
        $notes = array_map(fn ($id) => ['trackId' => $id, 'body' => str_repeat('é', 2000)], array_slice($ids, 0, 8));
        $base = ['schema' => 2, 'accountId' => $owner->principal()->accountId, 'version' => 1, 'favorites' => $ids, 'playlists' => [], 'notes' => $notes];
        $cipher = $this->encrypt($base);
        for ($characters = 1; $characters <= 2000; $characters += 10) {
            $candidate = $base;
            $candidate['notes'][] = ['trackId' => $ids[8], 'body' => str_repeat('é', $characters)];
            $encrypted = $this->encrypt($candidate);
            if (strlen($encrypted) <= 60000) {
                $retained = $candidate;
                $cipher = $encrypted;
            }
        }
        $this->assertNotNull($retained ?? null, 'Bounded valid retained fixture must fit the real encrypted budget.');
        $this->assertLessThanOrEqual(60000, strlen($cipher));
        DB::table('production_listening_libraries')->update(['version' => 1, 'payload' => $cipher]);
        $before = (array) DB::table('production_listening_libraries')->sole();
        $intended = $retained;
        $intended['version'] = 2;
        $intended['notes'][] = ['trackId' => $ids[9], 'body' => str_repeat('é', 2000)];
        $this->assertGreaterThan(65535, strlen($this->encrypt($intended)), 'Attempt must exceed native TEXT, not merely the 60000 application budget.');
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/\A(?:INSERT|UPDATE|DELETE)\b/i', $query->sql) && str_contains($query->sql, 'production_listening_libraries')) {
                $writes[] = $query->sql;
            }
        });
        try {
            $library->change($owner, ['action' => 'set-track-note', 'version' => 1, 'trackId' => $ids[9], 'body' => str_repeat('é', 2000)]);
            $this->fail('Envelope must refuse before physical storage.');
        } catch (ListeningException $error) {
            $this->assertSame(422, $error->status);
        }
        $this->assertSame([], $writes);
        $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole());
        $this->assertSame(1, $library->export($owner, 1)['version']);
        $this->assertSame($retained['notes'], $library->export($owner, 1)['notes']);
    }

    private function encrypt(array $state): string
    {
        return Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
