<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuoteLine;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateQuote
{
    /** Provisional selection-review lifetime only; no payment or reservation policy is established. */
    public const LIFETIME_MINUTES = 15;

    public function handle(string $ownerKey, string $idempotencyKey, array $items): Quote
    {
        QuoteRequest::owner($ownerKey);
        QuoteRequest::key($idempotencyKey);
        $items = QuoteRequest::items($items);
        $requestHash = CanonicalJson::hash($items);
        $keyHash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($ownerKey, $items, $requestHash, $keyHash) {
            // INSERT IGNORE waits for a competing first insert. The subsequent row lock serializes absent-key creation too.
            DB::table('quote_owners')->insertOrIgnore(['owner_key' => $ownerKey]);
            DB::table('quote_owners')->where('owner_key', $ownerKey)->lockForUpdate()->firstOrFail();
            $existing = Quote::query()->where('owner_key', $ownerKey)->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new QuoteException('IDEMPOTENCY_CONFLICT', 409);
                }

                return app(ReadQuote::class)->verified($existing);
            }
            $lines = app(QuoteSelection::class)->resolve($items, true);
            // File hashing may take time; recheck effective licenses after all selected bytes have been read.
            app(QuoteSelection::class)->resolve($items, false);
            app(Inventory\SelectionInventory::class)->assertAvailable($lines);
            $issuedAt = now()->toImmutable()->utc()->startOfSecond();
            $expiresAt = $issuedAt->addMinutes(self::LIFETIME_MINUTES);
            $publicId = (string) Str::uuid();
            $subtotal = 0;
            foreach ($lines as $line) {
                $subtotal += $line['offer_snapshot']['commercial']['price_minor'];
            }
            $snapshot = app(QuoteSnapshot::class)->capture($publicId, $issuedAt, $expiresAt, $lines);
            $quote = Quote::create(['public_id' => $publicId, 'owner_key' => $ownerKey, 'idempotency_key_hash' => $keyHash, 'request' => $items, 'request_hash' => $requestHash, 'snapshot' => $snapshot, 'snapshot_hash' => CanonicalJson::hash($snapshot), 'canonicalization_version' => CanonicalJson::VERSION, 'subtotal_minor' => $subtotal, 'currency' => 'USD', 'created_at' => $issuedAt, 'expires_at' => $expiresAt]);
            foreach ($lines as $position => $line) {
                QuoteLine::create(['quote_id' => $quote->id, 'offer_revision_id' => $line['offer_revision_id'], 'position' => $position, 'line_hash' => CanonicalJson::hash($line)]);
            }
            AuditEvent::record('commerce.quote.created', $quote, ['public_id' => $quote->public_id, 'snapshot_hash' => $quote->snapshot_hash, 'line_count' => count($lines)]);

            return $quote;
        }, 5);
    }
}
