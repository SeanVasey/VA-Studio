<?php

namespace App\Domain\Commerce\Inventory;

use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReadQuote;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Test inventory exercise on verified provisional quotes. No exclusive offer, price, order or rights grant is created. */
final class ReserveQuoteInventory
{
    public function hold(string $quoteId, string $ownerKey): InventoryReservation
    {
        return $this->handle($quoteId, $ownerKey, null);
    }

    /** Verify an existing hold without creating, expiring or transitioning any reservation. */
    public function read(string $quoteId, string $ownerKey): InventoryReservation
    {
        return $this->handle($quoteId, $ownerKey, null, true);
    }

    /** Future WP-07 must commit an order/intent binding in this transaction before any provider call. */
    public function beginAttempt(string $quoteId, string $ownerKey, string $attemptId): InventoryReservation
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $attemptId)) {
            throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
        }

        return $this->handle($quoteId, $ownerKey, $attemptId);
    }

    private function handle(string $quoteId, string $ownerKey, ?string $attemptId, bool $readOnly = false): InventoryReservation
    {
        InventoryPolicy::requireTestEnvironment();

        return DB::transaction(function () use ($quoteId, $ownerKey, $attemptId, $readOnly) {
            // Quote -> sorted tracks/offers -> sorted scopes -> reservations/claims.
            // Future combined checkout must acquire pricing/campaign locks BEFORE calling this command.
            $quote = app(ReadQuote::class)->handle($quoteId, $ownerKey);
            $policy = app(InventoryPolicy::class)->current();
            $revisionIds = array_column($quote->snapshot['lines'], 'offer_revision_id');
            $links = RightsScopeOffer::whereIn('offer_revision_id', $revisionIds)->orderBy('rights_scope_id')->get();
            $scopeIds = $links->pluck('rights_scope_id')->all();
            if ($links->count() !== count($revisionIds) || count(array_unique($scopeIds)) !== count($scopeIds)) {
                throw new QuoteException('INVENTORY_SCOPE_UNAVAILABLE', 409);
            }
            $scopes = RightsScope::whereIn('id', $scopeIds)->orderBy('id')->lockForUpdate()->get();
            if ($scopes->count() !== count($scopeIds) || $scopes->contains('blocked', true)) {
                throw new QuoteException('INVENTORY_BLOCKED', 409);
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($quote->expires_at->lessThanOrEqualTo($at)) { throw new QuoteException('QUOTE_EXPIRED', 410); }
            $bindings = $links->map(fn ($link) => ['scope_id' => $link->rights_scope_id,
                'scope_public_id' => $scopes->firstWhere('id', $link->rights_scope_id)->public_id,
                'link_id' => $link->id, 'offer_revision_id' => $link->offer_revision_id])->all();
            if (($quote->snapshot['schema_version'] ?? null) === 2) {
                $retained = array_map(fn ($binding) => \Illuminate\Support\Arr::except($binding, ['scope_public_id']), $bindings);
                if (CanonicalJson::hash($retained) !== CanonicalJson::hash($quote->snapshot['scope_bindings']) ||
                    CanonicalJson::hash($policy) !== CanonicalJson::hash($quote->snapshot['inventory_policy'])) {
                    throw new QuoteException('INVENTORY_CHANGED', 409);
                }
            }
            $reservation = InventoryReservation::where('quote_id', $quote->id)->lockForUpdate()->first();
            if ($reservation) {
                $expected = $this->snapshot($quote, $reservation->public_id, $policy, $bindings,
                    $reservation->created_at, $reservation->expires_at);
                $claims = DB::table('inventory_claims')->where('inventory_reservation_id', $reservation->id)
                    ->orderBy('rights_scope_id')->pluck('rights_scope_id')->map(fn ($id) => (int) $id)->all();
                if ($reservation->canonicalization_version !== CanonicalJson::VERSION ||
                    ! hash_equals($reservation->snapshot_hash, CanonicalJson::hash($expected)) ||
                    ! hash_equals($reservation->snapshot_hash, CanonicalJson::hash($reservation->snapshot)) || $claims !== $scopeIds) {
                    throw new QuoteException('INVENTORY_CHANGED', 409);
                }
                if ($reservation->state === 'expired' || ($reservation->state === 'held' && $reservation->expires_at->lessThanOrEqualTo($at))) {
                    throw new QuoteException('INVENTORY_EXPIRED', 410);
                }
                if ($reservation->state === 'pending') {
                    if ($attemptId !== null && $reservation->attempt_id !== $attemptId) {
                        throw new QuoteException('INVENTORY_ATTEMPT_CONFLICT', 409);
                    }

                    return $reservation;
                }
            } elseif ($attemptId !== null || $readOnly) {
                throw new QuoteException('INVENTORY_NOT_FOUND', 404);
            }

            // Current locking reads, not a prior REPEATABLE READ snapshot. Expired holds
            // transition irreversibly before reuse, preventing revival by a slower clock.
            $others = InventoryReservation::query()->select('inventory_reservations.*')
                ->join('inventory_claims', 'inventory_claims.inventory_reservation_id', '=', 'inventory_reservations.id')
                ->whereIn('inventory_claims.rights_scope_id', $scopeIds)
                ->whereIn('inventory_reservations.state', ['held', 'pending'])
                ->when($reservation, fn ($query) => $query->where('inventory_reservations.id', '<>', $reservation->id))
                ->orderBy('inventory_reservations.id')->lockForUpdate()->get()->unique('id');
            foreach ($others as $other) {
                if ($other->state === 'pending' || $other->expires_at->greaterThan($at)) {
                    throw new QuoteException('INVENTORY_UNAVAILABLE', 409);
                }
                if (! $readOnly) {
                    DB::table('inventory_reservations')->where('id', $other->id)->where('state', 'held')
                        ->update(['state' => 'expired', 'expired_at' => $at]);
                    AuditEvent::record('commerce.inventory.expired', $other, ['public_id' => $other->public_id]);
                }
            }
            if (! $reservation) {
                $expires = $at->addSeconds($policy['ttl_seconds'])->min($quote->expires_at);
                $id = (string) Str::uuid();
                $snapshot = $this->snapshot($quote, $id, $policy, $bindings, $at, $expires);
                $reservation = InventoryReservation::create(['public_id' => $id, 'quote_id' => $quote->id,
                    'state' => 'held', 'snapshot' => $snapshot, 'snapshot_hash' => CanonicalJson::hash($snapshot),
                    'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => $at, 'expires_at' => $expires]);
                foreach ($scopeIds as $scopeId) {
                    DB::table('inventory_claims')->insert(['rights_scope_id' => $scopeId, 'inventory_reservation_id' => $reservation->id]);
                }
                AuditEvent::record('commerce.inventory.held', $reservation,
                    ['public_id' => $id, 'quote_public_id' => $quote->public_id, 'snapshot_hash' => $reservation->snapshot_hash]);
            }
            if ($attemptId !== null) {
                if ($reservation->expires_at->lessThanOrEqualTo($at) || $at->lessThan($reservation->created_at)) {
                    throw new QuoteException('INVENTORY_EXPIRED', 410);
                }
                try {
                    DB::table('inventory_reservations')->where('id', $reservation->id)->where('state', 'held')
                        ->update(['state' => 'pending', 'attempt_id' => $attemptId, 'pending_at' => $at]);
                } catch (UniqueConstraintViolationException) { throw new QuoteException('INVENTORY_ATTEMPT_CONFLICT', 409); }
                AuditEvent::record('commerce.inventory.pending', $reservation, ['public_id' => $reservation->public_id, 'attempt_id' => $attemptId]);
                $reservation->refresh();
            }
            if ($reservation->expires_at->lessThanOrEqualTo(now()) || $quote->expires_at->lessThanOrEqualTo(now())) {
                throw new QuoteException('INVENTORY_EXPIRED', 410);
            }

            return $reservation;
        }, 5);
    }

    private function snapshot(Quote $quote, string $id, array $policy, array $bindings, $created, $expires): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_inventory', 'public_id' => $id,
            'quote_public_id' => $quote->public_id, 'quote_snapshot_hash' => $quote->snapshot_hash,
            'policy' => $policy, 'bindings' => $bindings, 'created_at' => $created->toIso8601ZuluString(),
            'expires_at' => $expires->toIso8601ZuluString()];
    }
}
