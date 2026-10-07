<?php

namespace Tests\Feature;

use App\Domain\Commerce\Policy\PrepareProductionTrackPolicy;
use App\Domain\Commerce\Policy\SaveProductionTrackPolicy;
use App\Domain\Commerce\ProductionPolicy\CloseProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReadProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\PrepareProductionTrackPreparationPacket;
use App\Domain\Commerce\ProductionPreparation\ReadProductionTrackPreparationPacket;
use App\Domain\Commerce\ProductionPreparation\SaveProductionTrackPreparationPacket;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ProductionTrackPreparationPacketTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
        $this->fakePrivateMediaStorage();
    }

    public function test_review_then_encrypted_packet_retains_exact_primary_catalog_subtotal_without_commerce_effects(): void
    {
        $f = Fixture::prepared();
        $this->assertDatabaseCount(PacketEvidence::PACKETS, 0);
        $packet = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
        $body = app(ReadProductionTrackPreparationPacket::class)->read($packet->public_id, $f['actor']);
        $this->assertSame(CanonicalJson::encode($body), Crypt::decryptString($packet->payload_ciphertext));
        $this->assertSame(4999, $body['selection']['advertised_subtotal_minor']);
        $this->assertNull($body['selection']['tax_minor']);
        $this->assertNull($body['selection']['total_minor']);
        foreach (['payable', 'execution_allowed', 'external_facts_verified', 'private_bytes_verified'] as $field) {
            $this->assertFalse($body['selection'][$field]);
        }
        $this->assertSame('not_collected', $body['selection']['assent_state']);
        $this->assertSame('not_bound', $body['selection']['buyer_state']);
        $this->assertSame($f['revision']->snapshot_hash, $body['selection']['lines'][0]['offer_snapshot_hash']);
        $this->assertSame(CanonicalJson::hash($f['revision']->snapshot['license']), CanonicalJson::hash($body['selection']['lines'][0]['offer_snapshot']['license']));
        $this->assertArrayNotHasKey('payload_ciphertext', $packet->toArray());
        $this->assertStringNotContainsString('NONBINDING', json_encode(DB::table(PacketEvidence::PACKETS)->get(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('synthetic:', json_encode(DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->get(), JSON_THROW_ON_ERROR));
        foreach (['quotes', 'orders', 'inventory_reservations', 'inventory_claims', 'license_grants', 'checkout_intents', 'pending_entitlements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_exact_response_replay_and_recovery_remain_historical_after_closure_source_and_catalog_movement(): void
    {
        $f = Fixture::prepared(true);
        $body = app(ReadProductionTrackPreparationPacket::class)->read($f['packet']->public_id, $f['actor']);
        app(CloseProductionTrackCapabilities::class)->applyReviewed(app(CloseProductionTrackCapabilities::class)->review($f['candidate'], $f['actor']),
            ['reason_code' => 'owner_withdrawal', ...Fixture::reference('closure_requested')], $f['actor']);
        $authored = $f['authored'];
        $authored['version'] = 'synthetic-source-successor';
        app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($f['source'], $authored, $f['actor']), $f['actor']);
        DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false]);
        DB::table('tracks')->where('id', $f['track']->id)->update(['status' => 'draft']);
        $before = Fixture::rows();
        $same = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
        $this->assertSame($f['packet']->id, $same->id);
        $this->assertSame($body, app(ReadProductionTrackPreparationPacket::class)->recover($f['key'], $f['actor']));
        $this->assertSame($body, app(ReadProductionTrackPreparationPacket::class)->read($same->public_id, $f['reviewer']));
        $this->assertSame($before, Fixture::rows());
        $this->expectException(ValidationException::class);
        app(PrepareProductionTrackPreparationPacket::class)->review($f['candidate'], $f['context'], $f['items'], 'new-request', $f['actor']);
    }

    public function test_recovery_keys_are_actor_bound_and_current_staff_mfa_are_required(): void
    {
        $f = Fixture::prepared(true);
        $this->assertNull(app(ReadProductionTrackPreparationPacket::class)->recover($f['key'], $f['reviewer']));
        $this->assertNull(app(ReadProductionTrackPreparationPacket::class)->recover('missing-key', $f['actor']));
        DB::table('users')->where('id', $f['actor']->id)->update(['is_admin' => false]);
        $before = Fixture::rows();
        try {
            app(ReadProductionTrackPreparationPacket::class)->recover($f['key'], $f['actor']);
            $this->fail('Revoked staff recovered evidence.');
        } catch (AuthorizationException) {
            $this->assertSame($before, Fixture::rows());
        }
    }

    public function test_existing_four_argument_adapter_behavior_and_optional_captured_reader_void_proof(): void
    {
        $f = Fixture::prepared();
        $before = Fixture::rows();
        $service = app(ReadProductionTrackCapabilities::class);
        $this->assertSame('original', $service->withLockedForAdapter($f['candidate'], $f['context'], $f['actor'], fn (array $p): string => 'original'));
        $this->assertSame(1, $service->withLockedForAdapter($f['candidate'], $f['context'], $f['actor'], function (array $p): int {
            return func_num_args();
        }));
        $prepareReader = null;
        $finalReader = null;
        $result = $service->withLockedForAdapter($f['candidate'], $f['context'], $f['actor'], function (array $p, CurrentRows $reader) use (&$prepareReader): string {
            $prepareReader = $reader;

            return 'captured';
        }, function (CurrentRows $reader) use (&$finalReader): void {
            $finalReader = $reader;
        });
        $this->assertSame('captured', $result);
        $this->assertSame($prepareReader, $finalReader);
        $this->assertSame($before, Fixture::rows());
        $this->expectException(ValidationException::class);
        $service->withLockedForAdapter($f['candidate'], $f['context'], $f['actor'], fn (array $p): string => 'never', fn (CurrentRows $reader): bool => false);
    }

    public static function changedReview(): array
    {
        return [['signature'], ['public_id'], ['authority_hash'], ['catalog_graph_hash'], ['actor'], ['items'], ['context'], ['extra']];
    }

    #[DataProvider('changedReview')]
    public function test_untrusted_or_changed_capture_cannot_write(string $field): void
    {
        $f = Fixture::prepared();
        $capture = $f['capture'];
        match ($field) {
            'signature', 'authority_hash', 'catalog_graph_hash' => $capture[$field] = str_repeat('0', 64),
            'public_id' => $capture[$field] = '00000000-0000-4000-8000-000000000000',
            'actor' => $capture['request']['actor_id'] = $f['reviewer']->id,
            'items' => $capture['request']['items'][0]['trackId']++,
            'context' => $capture['request']['context']['currency'] = 'EUR',
            'extra' => $capture['execution_allowed'] = true,
        };
        $before = Fixture::rows();
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($capture, $f['actor']);
            $this->fail('Changed review saved.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertSame($before, Fixture::rows());
        }
    }

    public function test_signed_stale_selection_and_changed_key_request_refuse_atomically(): void
    {
        $f = Fixture::prepared(true);
        $capture = $f['capture'];
        $capture['request']['items'][0]['offerId']++;
        unset($capture['signature']);
        $capture['signature'] = PacketEvidence::signature('production-packet-review-v1', $capture);
        $before = Fixture::rows();
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($capture, $f['actor']);
            $this->fail('Key request changed.');
        } catch (ValidationException) {
            $this->assertSame($before, Fixture::rows());
        }
    }

    public function test_packet_created_after_historical_miss_replays_exact_retained_rows_without_new_encryption(): void
    {
        $f = Fixture::prepared();
        $winner = null;
        $before = null;
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use ($f, &$armed, &$winner, &$before): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount(PacketEvidence::PACKETS, 0);
            $winner = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $before = Fixture::rows();
            $encrypter = Mockery::mock(Crypt::getFacadeRoot())->makePartial();
            $encrypter->shouldNotReceive('encryptString');
            Crypt::swap($encrypter);
        });
        $same = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
        $this->assertNotNull($winner);
        $this->assertSame(CanonicalJson::encode($winner->getAttributes()), CanonicalJson::encode($same->getAttributes()));
        $this->assertSame($before, Fixture::rows());
        $this->assertDatabaseCount(PacketEvidence::PACKETS, 1);
        $this->assertDatabaseCount(PacketEvidence::LINES, 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->count());
        Http::assertNothingSent();
    }

    public function test_changed_request_after_historical_miss_cannot_recover_the_winner(): void
    {
        $f = Fixture::prepared();
        $capture = $f['capture'];
        $capture['request']['items'][0]['offerId']++;
        unset($capture['signature']);
        $capture['signature'] = PacketEvidence::signature('production-packet-review-v1', $capture);
        $before = null;
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use ($f, &$armed, &$before): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            $this->assertDatabaseCount(PacketEvidence::PACKETS, 0);
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $before = Fixture::rows();
        });
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($capture, $f['actor']);
            $this->fail('Changed request recovered the concurrent winner.');
        } catch (ValidationException) {
            $this->assertNotNull($before);
            $this->assertSame($before, Fixture::rows());
        }
    }

    public function test_peer_actor_cannot_replay_the_signed_creator_capture(): void
    {
        $f = Fixture::prepared(true);
        $before = Fixture::rows();
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['reviewer']);
            $this->fail('Peer actor recovered a creator capture.');
        } catch (AuthorizationException) {
            $this->assertSame($before, Fixture::rows());
            $this->assertNull(app(ReadProductionTrackPreparationPacket::class)->recover($f['key'], $f['reviewer']));
        }
    }

    public function test_distinct_confirmations_of_the_same_request_retain_the_winners_public_identity(): void
    {
        $f = Fixture::prepared();
        $other = app(PrepareProductionTrackPreparationPacket::class)->review($f['candidate'], $f['context'], $f['items'], $f['key'], $f['actor']);
        $this->assertSame($f['capture']['request'], $other['request']);
        $this->assertNotSame($f['capture']['public_id'], $other['public_id']);
        $winner = null;
        $before = null;
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use ($f, &$armed, &$winner, &$before): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            $winner = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $before = Fixture::rows();
        });
        $same = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($other, $f['actor']);
        $this->assertSame(CanonicalJson::encode($winner->getAttributes()), CanonicalJson::encode($same->getAttributes()));
        $this->assertSame($before, Fixture::rows());
    }

    public static function damagedConcurrentWinner(): array
    {
        return [['ciphertext'], ['line'], ['audit'], ['staff'], ['mfa']];
    }

    #[DataProvider('damagedConcurrentWinner')]
    public function test_concurrent_winner_still_requires_authenticated_retained_evidence_and_current_authority(string $scenario): void
    {
        $f = Fixture::prepared();
        $before = null;
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use ($f, $scenario, &$armed, &$before): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            // Damaged restore fixtures remove only their own write guard;
            // operational migration guards remain unchanged.
            if ($scenario === 'ciphertext') {
                DB::unprepared('DROP TRIGGER ptp_packet_update');
                DB::table(PacketEvidence::PACKETS)->update(['payload_ciphertext' => 'damaged synthetic cipher', 'payload_hash' => hash('sha256', 'damaged synthetic cipher')]);
            } elseif ($scenario === 'line') {
                DB::unprepared('DROP TRIGGER ptp_line_update');
                DB::table(PacketEvidence::LINES)->update(['price_minor' => 1]);
            } elseif ($scenario === 'audit') {
                DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->update(['action' => 'synthetic.damaged']);
            } elseif ($scenario === 'staff') {
                DB::table('users')->where('id', $f['actor']->id)->update(['is_admin' => false]);
            } else {
                Filament::getPanel('admin')->multiFactorAuthentication(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders(), isRequired: true);
                $f['actor']->saveAppAuthenticationSecret(null);
            }
            $before = Fixture::rows();
        });
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $this->fail('Concurrent winner bypassed evidence or authority.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertNotNull($before);
            $this->assertSame($before, Fixture::rows());
        }
    }

    public static function lateReplayDrift(): array
    {
        return [['packet'], ['line'], ['audit'], ['source audit'], ['actor']];
    }

    #[DataProvider('lateReplayDrift')]
    public function test_concurrent_replay_final_authority_query_drift_refuses_without_changing_committed_winner(string $scenario): void
    {
        $f = Fixture::prepared();
        $before = null;
        $gap = true;
        $armed = false;
        $mutated = false;
        Event::listen(TransactionCommitted::class, function () use ($f, $scenario, &$gap, &$armed, &$before): void {
            if (! $gap) {
                return;
            }
            $gap = false;
            $winner = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            foreach (match ($scenario) {
                'packet' => ['ptp_packet_update'], 'line' => ['ptp_line_update'], default => []
            } as $trigger) {
                DB::unprepared('DROP TRIGGER '.$trigger);
            }
            $before = Fixture::rows();
            $original = Crypt::getFacadeRoot();
            $encrypter = Mockery::mock($original)->makePartial();
            $encrypter->shouldNotReceive('encryptString');
            $encrypter->shouldReceive('decryptString')->andReturnUsing(function (string $value) use ($original, $winner, &$armed): string {
                $result = $original->decryptString($value);
                if ($value === $winner->payload_ciphertext) {
                    $armed = true;
                }

                return $result;
            });
            Crypt::swap($encrypter);
        });
        DB::listen(function ($query) use ($f, $scenario, &$armed, &$mutated): void {
            if (! $armed || ! str_contains($query->sql, 'from "users"') && ! str_contains($query->sql, 'from `users`')) {
                return;
            }
            $armed = false;
            $mutated = true;
            match ($scenario) {
                'packet' => DB::table(PacketEvidence::PACKETS)->update(['request_hash' => str_repeat('0', 64)]),
                'line' => DB::table(PacketEvidence::LINES)->update(['price_minor' => 1]),
                'audit' => DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->update(['action' => 'synthetic.late_replay']),
                'source audit' => DB::table('audit_events')->where('subject_type', ProductionTrackCapabilities::class)->update(['action' => 'synthetic.late_source']),
                'actor' => DB::table('users')->where('id', $f['actor']->id)->update(['is_admin' => false]),
            };
        });
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $this->fail('Late replay drift was admitted.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertTrue($mutated, 'Drift must follow retained authentication during final authority refresh.');
            $this->assertSame($before, Fixture::rows());
        } finally {
            $armed = false;
        }
    }

    public function test_multiple_tracks_are_retained_in_canonical_order_with_exact_integer_advertised_subtotal(): void
    {
        $f = Fixture::prepared();
        $other = QuoteFixtures::selection(6001);
        $items = [$other['items'][0], $f['items'][0]];
        $capture = app(PrepareProductionTrackPreparationPacket::class)->review($f['candidate'], $f['context'], $items, 'two-tracks', $f['actor']);
        $packet = app(SaveProductionTrackPreparationPacket::class)->applyReviewed($capture, $f['actor']);
        $body = app(ReadProductionTrackPreparationPacket::class)->read($packet->public_id, $f['actor']);
        $this->assertSame(11000, $body['selection']['advertised_subtotal_minor']);
        $this->assertSame([$f['track']->id, $other['track']->id], array_column($body['selection']['lines'], 'track_id'));
        $this->assertDatabaseCount(PacketEvidence::LINES, 2);
    }

    public function test_reviewed_non_usd_machine_is_not_silently_converted_to_supported_catalog_currency(): void
    {
        $f = Fixture::prepared();
        $machine = $f['machine'];
        $machine['version'] = 'synthetic-eur-successor';
        $machine['choices']['currency'] = ['code' => 'EUR', 'minor_unit_exponent' => 2];
        $next = app(SaveProductionTrackCapabilities::class)->applyReviewed(app(PrepareProductionTrackCapabilities::class)->review($f['candidate'], $f['source'], $machine, $f['actor']), $f['actor']);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($next, $f['reviewer']), Fixture::reference('software_choices_reviewed'), $f['reviewer']);
        $before = Fixture::rows();
        try {
            app(PrepareProductionTrackPreparationPacket::class)->review($next, PreparationContextV1::forMachine($machine), $f['items'], 'eur-request', $f['actor']);
            $this->fail('Unsupported machine currency defaulted to USD.');
        } catch (ValidationException) {
            $this->assertSame($before, Fixture::rows());
        }
    }

    public function test_capture_becomes_stale_when_current_catalog_moves_before_apply(): void
    {
        $f = Fixture::prepared();
        DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false]);
        $before = Fixture::rows();
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $this->fail('Stale review wrote.');
        } catch (ValidationException) {
            $this->assertSame($before, Fixture::rows());
        }
    }
}
