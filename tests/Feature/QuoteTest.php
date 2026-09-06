<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuoteLine;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReadQuote;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private function quote(array $fixture, string $key = 'review-1'): Quote
    {
        return app(CreateQuote::class)->handle(self::OWNER, $key, $fixture['items']);
    }

    private function rejects(string $code, int $status, callable $command): void
    {
        try {
            $command();
            $this->fail('The invalid quote operation succeeded.');
        } catch (QuoteException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
        }
    }

    public function test_quote_freezes_exact_offer_snapshot_without_claiming_a_payable_total(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        $this->assertSame(4999, $quote->subtotal_minor);
        $this->assertSame('USD', $quote->currency);
        $this->assertSame('selection_review', $quote->snapshot['purpose']);
        $this->assertFalse($quote->snapshot['payable']);
        $this->assertSame('unresolved', $quote->snapshot['tax_status']);
        $this->assertNull($quote->snapshot['tax_minor']);
        $this->assertNull($quote->snapshot['total_minor']);
        $this->assertSame(CanonicalJson::encode($fixture['revision']->snapshot), CanonicalJson::encode($quote->snapshot['lines'][0]['offer_snapshot']));
        $this->assertSame($fixture['revision']->snapshot_hash, $quote->snapshot['lines'][0]['offer_snapshot_hash']);
        $this->assertSame(CanonicalJson::hash($quote->snapshot), $quote->snapshot_hash);
        $this->assertSame(900.0, $quote->created_at->diffInSeconds($quote->expires_at));
        $this->assertSame($quote->id, app(ReadQuote::class)->handle($quote->public_id, self::OWNER)->id);
        $this->assertDatabaseHas('quote_lines', ['quote_id' => $quote->id, 'offer_revision_id' => $fixture['revision']->id]);
    }

    public function test_normalized_reordered_replay_returns_one_quote_and_one_audit(): void
    {
        $first = QuoteFixtures::selection(2147483647);
        $second = QuoteFixtures::selection(2147483647);
        $items = [...$second['items'], ...$first['items']];
        $quote = app(CreateQuote::class)->handle(self::OWNER, 'ReplayKey', $items);
        $this->assertSame(4294967294, $quote->subtotal_minor);
        $this->assertSame($first['track']->id, $quote->snapshot['lines'][0]['track_id']);
        $numericStrings = array_map(fn ($item) => array_map('strval', $item), array_reverse($items));
        $replay = app(CreateQuote::class)->handle(self::OWNER, 'ReplayKey', $numericStrings);
        $this->assertSame($quote->public_id, $replay->public_id);
        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('quote_lines', 2);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.quote.created')->count());
    }

    public function test_same_key_changed_body_conflicts_before_making_another_quote(): void
    {
        $fixture = QuoteFixtures::selection();
        $this->quote($fixture);
        $fixture['items'][0]['offerRevisionId']++;
        $this->rejects('IDEMPOTENCY_CONFLICT', 409, fn () => $this->quote($fixture));
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_expiry_is_exact_and_never_extends_on_read_or_replay(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        $hash = $quote->snapshot_hash;
        $this->travelTo($quote->expires_at->subSecond());
        $this->assertSame($quote->public_id, $this->quote($fixture)->public_id);
        $this->travelTo($quote->expires_at);
        $this->rejects('QUOTE_EXPIRED', 410, fn () => $this->quote($fixture));
        $this->rejects('QUOTE_EXPIRED', 410, fn () => app(ReadQuote::class)->handle($quote->public_id, self::OWNER));
        $this->assertSame($hash, $quote->refresh()->snapshot_hash);
        $replacement = $this->quote($fixture, 'new-review');
        $this->assertNotSame($quote->public_id, $replacement->public_id);
    }

    public function test_quote_expiring_during_a_lock_wait_cannot_be_returned_as_current(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        $waited = false;
        DB::listen(function ($query) use (&$waited, $quote) {
            if (! $waited && str_contains($query->sql, 'tracks') && str_starts_with($query->sql, 'select')) {
                $waited = true;
                // Model time consumed acquiring the track lock after ReadQuote's initial expiry check.
                $this->travelTo($quote->expires_at);
            }
        });
        $this->rejects('QUOTE_EXPIRED', 410, fn () => app(ReadQuote::class)->handle($quote->public_id, self::OWNER));
        $this->assertTrue($waited);
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_real_offer_and_license_ids_cannot_be_mixed_between_products(): void
    {
        $first = QuoteFixtures::selection();
        $second = QuoteFixtures::selection();
        $mixed = $first;
        foreach (['offerId', 'offerRevisionId', 'licenseVersionId'] as $field) {
            $mixed['items'][0][$field] = $second['items'][0][$field];
        }
        $this->rejects('SELECTION_CHANGED', 409, fn () => $this->quote($mixed));
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_owner_scope_and_case_sensitive_keys_do_not_collide(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture, 'CaseKey');
        $caseVariant = $this->quote($fixture, 'casekey');
        $other = app(CreateQuote::class)->handle(str_repeat('b', 64), 'CaseKey', $fixture['items']);
        $this->assertNotSame($quote->public_id, $caseVariant->public_id);
        $this->assertNotSame($quote->public_id, $other->public_id);
        $this->rejects('QUOTE_NOT_FOUND', 404, fn () => app(ReadQuote::class)->handle($quote->public_id, str_repeat('b', 64)));
        $this->travel(20)->minutes();
        $this->rejects('QUOTE_NOT_FOUND', 404, fn () => app(ReadQuote::class)->handle($quote->public_id, str_repeat('b', 64)));
        $this->rejects('QUOTE_NOT_FOUND', 404, fn () => app(ReadQuote::class)->handle((string) $quote->id, self::OWNER));
    }

    public function test_draft_changes_preserve_quote_but_published_successor_invalidates_it_without_rewriting_history(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        $original = $quote->snapshot;
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['price_minor' => 8999, 'deliverable_asset_ids' => []], $fixture['actor']);
        $this->assertSame($quote->public_id, $this->quote($fixture)->public_id);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['deliverable_asset_ids' => [$fixture['media']['master_wav']->id]], $fixture['actor']);
        $next = app(PublishOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $this->rejects('SELECTION_CHANGED', 409, fn () => $this->quote($fixture));
        $this->rejects('SELECTION_CHANGED', 409, fn () => app(ReadQuote::class)->handle($quote->public_id, self::OWNER));
        $this->assertSame(CanonicalJson::encode($original), CanonicalJson::encode($quote->refresh()->snapshot));
        $fixture['items'][0]['offerRevisionId'] = $next->id;
        $new = $this->quote($fixture, 'successor');
        $this->assertSame(8999, $new->subtotal_minor);
        $this->assertDatabaseCount('quotes', 2);
    }

    public function test_inactive_unpublished_missing_and_cross_product_selections_fail_closed(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        $wrong = $fixture;
        foreach (['trackId', 'offerId', 'offerRevisionId', 'licenseVersionId'] as $field) {
            $wrong['items'] = $fixture['items'];
            $wrong['items'][0][$field] = 999999;
            $this->rejects('SELECTION_CHANGED', 409, fn () => $this->quote($wrong, 'missing-'.$field));
        }
        app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $this->rejects('SELECTION_CHANGED', 409, fn () => app(ReadQuote::class)->handle($quote->public_id, self::OWNER));
        app(PublishOffer::class)->handle($fixture['offer'], $fixture['actor']);
        app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $this->rejects('SELECTION_CHANGED', 409, fn () => $this->quote($fixture, 'unpublished'));
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_new_rights_evidence_and_missing_artwork_invalidate_review(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        Storage::disk('local')->delete($fixture['media']['artwork']->storage_path);
        $this->rejects('SELECTION_CHANGED', 409, fn () => app(ReadQuote::class)->handle($quote->public_id, self::OWNER));
        $other = QuoteFixtures::selection();
        $otherQuote = $this->quote($other, 'other');
        RightsDeclaration::create(['track_id' => $other['track']->id, 'provenance_reference' => 'NEW-UNREVIEWED', 'sample_disclosure' => 'Changed source', 'status' => 'draft']);
        $this->rejects('SELECTION_CHANGED', 409, fn () => app(ReadQuote::class)->handle($otherQuote->public_id, self::OWNER));
    }

    public function test_quote_creation_hashes_exact_bytes_despite_recent_public_read_cache(): void
    {
        $fixture = QuoteFixtures::selection();
        $asset = $fixture['media']['master_wav'];
        $path = Storage::disk('local')->path($asset->storage_path);
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        chmod($path, 0600);
        file_put_contents($path, $bytes);
        $this->rejects('SELECTION_CHANGED', 409, fn () => $this->quote($fixture));
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_missing_exact_deliverable_invalidates_existing_quote_without_deleting_it(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        Storage::disk('local')->delete($fixture['media']['master_wav']->storage_path);
        $this->rejects('SELECTION_CHANGED', 409, fn () => $this->quote($fixture));
        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'snapshot_hash' => $quote->snapshot_hash]);
    }

    public function test_client_amounts_unknown_fields_duplicates_and_lossy_ids_are_rejected_before_storage(): void
    {
        $valid = ['trackId' => 1, 'offerId' => 1, 'licenseVersionId' => 1, 'offerRevisionId' => 1];
        foreach ([[], [$valid, $valid], ['line' => $valid], array_fill(0, 11, $valid), [$valid + ['priceMinor' => 1]], [$valid + ['currency' => 'USD']], [$valid + ['taxMinor' => 0]], [array_diff_key($valid, ['offerRevisionId' => true])], ['invalid']] as $items) {
            $this->rejects('INVALID_QUOTE_REQUEST', 422, fn () => app(CreateQuote::class)->handle(self::OWNER, 'key', $items));
        }
        foreach ([true, false, 1.0, 1.2, 0, -1, '01', '1e2', '+1', ' 1', '1 ', '9007199254740992', [], null] as $id) {
            $invalid = $valid;
            $invalid['trackId'] = $id;
            $this->rejects('INVALID_QUOTE_REQUEST', 422, fn () => app(CreateQuote::class)->handle(self::OWNER, 'key', [$invalid]));
        }
        foreach (['', ' spaced', 'newline\n', str_repeat('x', 129), 'é'] as $key) {
            $this->rejects('INVALID_QUOTE_REQUEST', 422, fn () => app(CreateQuote::class)->handle(self::OWNER, $key, [$valid]));
        }
        $this->rejects('INVALID_QUOTE_REQUEST', 422, fn () => app(CreateQuote::class)->handle('raw-session', 'key', [$valid]));
        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('quote_owners', 0);
    }

    public function test_quote_lines_owner_bindings_and_snapshots_are_immutable_through_orm_and_bulk_sql(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        $line = $quote->lines()->firstOrFail();
        foreach ([fn () => $quote->update(['subtotal_minor' => 1]), fn () => $quote->delete(), fn () => $line->update(['line_hash' => str_repeat('0', 64)]), fn () => $line->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Mutable quote evidence accepted.');
            } catch (LogicException) {
            }
        }
        foreach (['quotes' => ['owner_key' => str_repeat('b', 64)], 'quote_lines' => ['line_hash' => str_repeat('0', 64)], 'quote_owners' => ['owner_key' => str_repeat('b', 64)]] as $table => $data) {
            foreach ([fn () => DB::table($table)->update($data), fn () => DB::table($table)->delete()] as $mutation) {
                try {
                    $mutation();
                    $this->fail('Bulk quote evidence mutation accepted.');
                } catch (QueryException) {
                }
            }
        }
        $this->assertSame($quote->public_id, app(ReadQuote::class)->handle($quote->public_id, self::OWNER)->public_id);
    }

    public function test_additional_unexpected_reference_is_detected_even_if_inserted_outside_services(): void
    {
        $fixture = QuoteFixtures::selection();
        $other = QuoteFixtures::selection();
        $quote = $this->quote($fixture);
        QuoteLine::create(['quote_id' => $quote->id, 'offer_revision_id' => $other['revision']->id, 'position' => 1, 'line_hash' => str_repeat('0', 64)]);
        $this->rejects('SELECTION_CHANGED', 409, fn () => app(ReadQuote::class)->handle($quote->public_id, self::OWNER));
    }
}
