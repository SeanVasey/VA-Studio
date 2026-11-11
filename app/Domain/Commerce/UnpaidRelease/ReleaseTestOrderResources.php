<?php

namespace App\Domain\Commerce\UnpaidRelease;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\TestUnpaidReleaseEvent;
use App\Domain\Commerce\Models\TestUnpaidReleaseWork;
use App\Domain\Commerce\Models\TestUnsentOrderRelease;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Conditional local/test resource release. Every provider operation is a bounded own-account GET.
 *
 * Two proofs, chosen from durable local facts under the Order lock, never from the caller:
 * an order with a CheckoutIntent needs the bound, twice-observed expired and unpaid Checkout Session;
 * an order without one was never sent to the provider (see ReadUnsentRelease) and needs only a closed attempt window.
 */
final class ReleaseTestOrderResources
{
    public const LEASE_SECONDS = 120;

    /** The one proof a caller may pin: the reviewed order had no checkout intent. */
    public const NEVER_SENT = 'never_sent';

    public function query(): Builder
    {
        $query = Order::query()->select(['orders.id', 'orders.public_id', 'orders.created_at']);
        try {
            $account = app(UnpaidReleasePolicy::class)->account();
        } catch (Throwable) {
            return $query->whereRaw('1 = 0');
        }

        // Every `orders` row comes from the test order chain. One with no checkout intent was never sent to the provider.
        $started = fn ($query) => $query->selectRaw('1')->from('checkout_intents')->whereColumn('checkout_intents.order_id', 'orders.id');

        return $query->selectRaw('EXISTS (SELECT 1 FROM checkout_intents started WHERE started.order_id = orders.id) AS checkout_started')
            ->where(fn ($query) => $query->whereExists(fn ($query) => $started($query)->where('mode', 'test')->where('account_id', $account))
                ->orWhereNotExists($started));
    }

    public function review(string $publicId, ?User $actor): array
    {
        UnpaidReleasePolicy::outsideTransactions();

        return $this->safe(fn () => DB::transaction(function () use ($publicId, $actor): array {
            [$order, $current, $intent, $work] = $this->locked($publicId, $actor);
            $release = TestUnpaidRelease::where('order_id', $order->id)->lockForUpdate()->first();
            $paid = VerifiedPayment::where('order_id', $order->id)->lockForUpdate()->exists();
            $unsent = $intent ? null : TestUnsentOrderRelease::where('order_id', $order->id)->lockForUpdate()->first();
            $original = app(ReadOrder::class)->verify($order);
            if ($release) {
                app(ReadUnpaidRelease::class)->verify($release, $order, $original);
            }
            if ($unsent) {
                app(ReadUnsentRelease::class)->verify($unsent, $order);
            }
            $release ??= $unsent;
            AuditEvent::recordAttributed('commerce.unpaid_release.reviewed', $order,
                ['order_id' => $order->public_id, 'sequence' => $work->sequence, 'test_only' => true], $current->id);

            return ['orderId' => $order->public_id, 'sequence' => $work->sequence,
                'status' => $release ? 'released' : ($paid ? 'payment_recorded' : 'unverified'),
                'history' => $this->events($order)->orderByDesc('sequence')->limit(20)->get()->map(fn ($event) => ['sequence' => $event->sequence, 'kind' => $event->kind, 'outcome' => $event->outcome,
                    'createdAt' => $event->created_at->toIso8601ZuluString()])->all(),
                'release' => $release ? ['publicId' => $release->public_id, 'releasedAt' => $release->released_at->toIso8601ZuluString()] : null,
                'checkout' => $intent ? 'started' : self::NEVER_SENT,
                'checkoutWindowClosesAt' => $order->attempt()->sole()->expires_at->toIso8601ZuluString()];
        }, 5));
    }

    public function release(string $publicId, ?User $actor, string $requestId, int $expectedSequence, ?string $expectedProof = null): array
    {
        UnpaidReleasePolicy::outsideTransactions();
        if (! OrderRequest::uuid($requestId) || $expectedSequence < 0 || $expectedSequence >= 4294967293
            || ($expectedProof !== null && $expectedProof !== self::NEVER_SENT)) {
            throw new RuntimeException('Invalid test unpaid release request.');
        }
        $claim = $this->safe(fn () => DB::transaction(function () use ($publicId, $actor, $requestId, $expectedSequence, $expectedProof): array {
            [$order, $current, $intent, $work] = $this->locked($publicId, $actor);
            if ($intent === null) {
                return ['result' => $this->releaseUnsent($order, $current, $work, $requestId, $expectedSequence)];
            }
            if ($expectedProof === self::NEVER_SENT) {
                // Checkout started after the review: the never-sent proof no longer applies.
                return ['result' => $this->result('stale', $work->sequence)];
            }
            $events = $this->events($order)->where('request_id', $requestId)->get();
            $requested = $events->firstWhere('kind', 'requested');
            if ($events->isNotEmpty()) {
                if (! $requested || $requested->actor_id !== $current->id || $requested->review_sequence !== $expectedSequence) {
                    throw new RuntimeException('Test unpaid release request changed.');
                }
                if ($observed = $events->firstWhere('kind', 'observed')) {
                    if ($observed->outcome === 'released') {
                        $retained = TestUnpaidRelease::where('order_id', $order->id)->lockForUpdate()->sole();
                        if ($retained->event_id !== $observed->id || $retained->request_id !== $requestId || $retained->actor_id !== $current->id) {
                            throw new RuntimeException('Test unpaid release replay changed.');
                        }
                        app(ReadUnpaidRelease::class)->verify($retained, $order, app(ReadOrder::class)->verify($order));
                    }

                    return ['result' => $this->result($observed->outcome, $observed->sequence)];
                }
                if ($work->sequence !== $requested->sequence || $work->request_id !== $requestId) {
                    throw new RuntimeException('Test unpaid release request superseded.');
                }
            } elseif ($work->sequence !== $expectedSequence) {
                return ['result' => $this->result('stale', $work->sequence)];
            }
            if ($work->lease_expires_at?->greaterThan(now())) {
                return ['result' => $this->result('busy', $work->sequence)];
            }
            $policy = app(UnpaidReleasePolicy::class)->current();
            $release = TestUnpaidRelease::where('order_id', $order->id)->lockForUpdate()->first();
            $payment = VerifiedPayment::where('order_id', $order->id)->lockForUpdate()->first();
            $finalization = OrderFinalization::where('order_id', $order->id)->lockForUpdate()->first();
            $original = app(ReadOrder::class)->verify($order);
            if ($release) {
                app(ReadUnpaidRelease::class)->verify($release, $order, $original);

                return ['result' => $this->result('released', $work->sequence)];
            }
            if ($payment || $finalization) {
                return ['result' => $this->result('payment_recorded', $work->sequence)];
            }
            $session = CheckoutSession::where('checkout_intent_id', $intent->id)->lockForUpdate()->first();
            if (! $session) {
                return ['result' => $this->result('unavailable', $work->sequence)];
            }
            $request = app(CheckoutEvidence::class)->verifyIntent($intent, $order, $original);
            $this->boundSession($session, $intent);
            if (! $requested) {
                $this->append($order, $current, $work, $requestId, $expectedSequence, 'requested', 'pending');
            }
            $work->fill(['request_id' => $requestId, 'claim_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS)])->save();

            return ['token' => $work->claim_token, 'request' => $request, 'policy' => $policy,
                'session_id' => $session->id, 'provider_session_id' => $session->provider_session_id];
        }, 5));
        if (isset($claim['result'])) {
            return $claim['result'];
        }
        $observation = null;
        try {
            $observation = app(UnpaidReleaseEvidence::class)->inspect($claim['provider_session_id'], $claim['request']);
            $outcome = 'released';
        } catch (UnpaidOutcomeException) {
            $outcome = 'not_unpaid';
        } catch (PaymentVerificationException $error) {
            $outcome = in_array($error->reason, ['retry', 'unavailable'], true) ? 'unavailable' : 'attention';
        } catch (Throwable) {
            $outcome = 'unavailable';
        }
        $observedAt = now()->toImmutable()->utc()->startOfSecond();

        return $this->safe(fn () => DB::transaction(function () use ($publicId, $actor, $requestId, $expectedSequence, $claim, $observation, $outcome, $observedAt): array {
            [$order, $current, $intent, $work] = $this->locked($publicId, $actor);
            $policy = app(UnpaidReleasePolicy::class)->current();
            if ($intent === null || $work->request_id !== $requestId || $work->claim_token !== $claim['token']
                || $work->lease_expires_at === null || $work->lease_expires_at->lessThanOrEqualTo(now())) {
                return $this->result('stale', $work->sequence);
            }
            $payment = VerifiedPayment::where('order_id', $order->id)->lockForUpdate()->first();
            $finalization = OrderFinalization::where('order_id', $order->id)->lockForUpdate()->first();
            $existing = TestUnpaidRelease::where('order_id', $order->id)->lockForUpdate()->first();
            $original = app(ReadOrder::class)->verify($order);
            $request = app(CheckoutEvidence::class)->verifyIntent($intent, $order, $original);
            $session = CheckoutSession::whereKey($claim['session_id'])->lockForUpdate()->firstOrFail();
            $this->boundSession($session, $intent);
            if (CanonicalJson::encode($request) !== CanonicalJson::encode($claim['request'])
                || CanonicalJson::encode($policy) !== CanonicalJson::encode($claim['policy'])
                || $session->provider_session_id !== $claim['provider_session_id'] || $existing) {
                throw new RuntimeException('Test unpaid release evidence changed.');
            }
            if ($payment || $finalization) {
                $outcome = 'payment_recorded';
            }
            if ($outcome === 'released') {
                app(UnpaidReleaseEvidence::class)->validate($observation, $request);
                $attempt = $order->attempt()->sole();
                $use = null;
                if ($attempt->promotion_use_id !== null) {
                    $campaignId = PromotionUse::whereKey($attempt->promotion_use_id)->value('promotion_campaign_id');
                    PromotionCampaign::whereKey($campaignId)->lockForUpdate()->firstOrFail();
                    $use = PromotionUse::whereKey($attempt->promotion_use_id)->lockForUpdate()->firstOrFail();
                }
                $scopeIds = array_column($original['attempt']['inventory']['snapshot']['bindings'], 'scope_id');
                $scopes = RightsScope::whereIn('id', $scopeIds)->orderBy('id')->lockForUpdate()->get();
                $reservation = InventoryReservation::whereKey($attempt->inventory_reservation_id)->lockForUpdate()->firstOrFail();
                $claims = DB::table('inventory_claims')->where('inventory_reservation_id', $reservation->id)
                    ->orderBy('rights_scope_id')->lockForUpdate()->pluck('rights_scope_id')->map(fn ($id) => (int) $id)->all();
                if ($scopes->count() !== count($scopeIds) || $claims !== $scopeIds || $reservation->state !== 'pending'
                    || $reservation->attempt_id !== $attempt->public_id || $reservation->consumed_at !== null
                    || ($use && ($use->state !== 'pending' || $use->attempt_id !== $attempt->public_id || $use->consumed_at !== null))) {
                    throw new RuntimeException('Test unpaid release resources changed.');
                }
            }
            $event = $this->append($order, $current, $work, $requestId, $expectedSequence, 'observed', $outcome, $observedAt);
            if ($outcome === 'released') {
                $id = (string) Str::uuid();
                $payload = app(ReadUnpaidRelease::class)->capture($order, $intent, $session, $id, $requestId, $current->id,
                    $event->id, $policy, $observation, $observedAt, $event->created_at);
                [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($payload);
                $release = TestUnpaidRelease::create(['public_id' => $id, 'order_id' => $order->id, 'order_attempt_id' => $attempt->id,
                    'checkout_intent_id' => $intent->id, 'checkout_session_id' => $session->id, 'event_id' => $event->id,
                    'request_id' => $requestId, 'actor_id' => $current->id, 'account_id' => $intent->account_id, 'mode' => 'test',
                    'policy_version' => $policy['version'], 'inventory_reservation_id' => $reservation->id,
                    'promotion_use_id' => $use?->id, 'released_at' => $event->created_at,
                    'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION]);
                if (DB::table('inventory_reservations')->where('id', $reservation->id)->where('state', 'pending')->update(['state' => 'released']) !== 1
                    || ($use && DB::table('promotion_uses')->where('id', $use->id)->where('state', 'pending')->update(['state' => 'released']) !== 1)) {
                    throw new RuntimeException('Test unpaid release resources changed.');
                }
                app(ReadUnpaidRelease::class)->verify($release, $order, $original);
            }
            $work->fill(['request_id' => null, 'claim_token' => null, 'lease_expires_at' => null])->save();

            return $this->result($outcome, $work->sequence);
        }, 5));
    }

    /**
     * Never-sent release, entirely inside the caller's transaction and locks: User -> Order -> (absent) CheckoutIntent
     * -> release work -> promotion campaign/use -> sorted rights scopes -> reservation/claims. No provider call is made.
     */
    private function releaseUnsent(Order $order, User $current, TestUnpaidReleaseWork $work, string $requestId, int $expectedSequence): array
    {
        $events = $this->events($order)->where('request_id', $requestId)->get();
        if ($events->isNotEmpty()) {
            $requested = $events->firstWhere('kind', 'requested');
            $observed = $events->firstWhere('kind', 'observed');
            // Both events commit together, so a lone request record is never a valid replay.
            if (! $requested || ! $observed || $requested->actor_id !== $current->id || $requested->review_sequence !== $expectedSequence) {
                throw new RuntimeException('Test unpaid release request changed.');
            }
            if ($observed->outcome === 'released') {
                $retained = TestUnsentOrderRelease::where('order_id', $order->id)->lockForUpdate()->sole();
                if ($retained->event_id !== $observed->id || $retained->request_id !== $requestId || $retained->actor_id !== $current->id) {
                    throw new RuntimeException('Test unpaid release replay changed.');
                }
                app(ReadUnsentRelease::class)->verify($retained, $order);
            }

            return $this->result($observed->outcome, $observed->sequence);
        }
        if ($work->sequence !== $expectedSequence) {
            return $this->result('stale', $work->sequence);
        }
        if ($work->lease_expires_at?->greaterThan(now())) {
            return $this->result('busy', $work->sequence);
        }
        $policy = app(UnpaidReleasePolicy::class)->current();
        $existing = TestUnsentOrderRelease::where('order_id', $order->id)->lockForUpdate()->first();
        $payment = VerifiedPayment::where('order_id', $order->id)->lockForUpdate()->first();
        $finalization = OrderFinalization::where('order_id', $order->id)->lockForUpdate()->first();
        $original = app(ReadOrder::class)->verify($order);
        if ($existing) {
            app(ReadUnsentRelease::class)->verify($existing, $order);

            return $this->result('released', $work->sequence);
        }
        if ($payment || $finalization || TestUnpaidRelease::where('order_id', $order->id)->lockForUpdate()->exists()) {
            return $this->result('payment_recorded', $work->sequence);
        }
        $attempt = $order->attempt()->sole();
        // While the attempt window is open the buyer may still start checkout; never cut off a live order.
        if ($attempt->expires_at->greaterThan(now()->toImmutable()->utc()->startOfSecond())) {
            return $this->result('checkout_open', $work->sequence);
        }
        [$reservation, $use] = $this->lockPendingResources($attempt, $original);
        $this->append($order, $current, $work, $requestId, $expectedSequence, 'requested', 'pending');
        $at = now()->toImmutable()->utc()->startOfSecond();
        $event = $this->append($order, $current, $work, $requestId, $expectedSequence, 'observed', 'released', $at);
        $id = (string) Str::uuid();
        $payload = app(ReadUnsentRelease::class)->capture($order, $attempt, $id, $requestId, $current->id, $event->id, $policy, $event->created_at);
        [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($payload);
        $release = TestUnsentOrderRelease::create(['public_id' => $id, 'order_id' => $order->id, 'order_attempt_id' => $attempt->id,
            'event_id' => $event->id, 'request_id' => $requestId, 'actor_id' => $current->id, 'mode' => 'test',
            'policy_version' => ReadUnsentRelease::POLICY_VERSION, 'proof' => ReadUnsentRelease::PROOF,
            'inventory_reservation_id' => $reservation->id, 'promotion_use_id' => $use?->id,
            'checkout_window_closed_at' => $attempt->expires_at, 'released_at' => $event->created_at,
            'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION]);
        $this->releaseResources($reservation, $use);
        app(ReadUnsentRelease::class)->verify($release, $order);
        AuditEvent::recordAttributed('commerce.unpaid_release.unsent_released', $order, ['order_id' => $order->public_id,
            'release_id' => $id, 'proof' => ReadUnsentRelease::PROOF, 'request_id' => $requestId,
            'sequence' => $event->sequence, 'test_only' => true], $current->id);
        $work->fill(['request_id' => null, 'claim_token' => null, 'lease_expires_at' => null])->save();

        return $this->result('released', $work->sequence);
    }

    /** Lock promotion campaign/use, sorted rights scopes, then reservation/claims, and require the original pending pair. */
    private function lockPendingResources($attempt, array $original): array
    {
        $use = null;
        if ($attempt->promotion_use_id !== null) {
            $campaignId = PromotionUse::whereKey($attempt->promotion_use_id)->value('promotion_campaign_id');
            PromotionCampaign::whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $use = PromotionUse::whereKey($attempt->promotion_use_id)->lockForUpdate()->firstOrFail();
        }
        $scopeIds = array_column($original['attempt']['inventory']['snapshot']['bindings'], 'scope_id');
        $scopes = RightsScope::whereIn('id', $scopeIds)->orderBy('id')->lockForUpdate()->get();
        $reservation = InventoryReservation::whereKey($attempt->inventory_reservation_id)->lockForUpdate()->firstOrFail();
        $claims = DB::table('inventory_claims')->where('inventory_reservation_id', $reservation->id)
            ->orderBy('rights_scope_id')->lockForUpdate()->pluck('rights_scope_id')->map(fn ($id) => (int) $id)->all();
        if ($scopes->count() !== count($scopeIds) || $claims !== $scopeIds || $reservation->state !== 'pending'
            || $reservation->attempt_id !== $attempt->public_id || $reservation->consumed_at !== null
            || ($use && ($use->state !== 'pending' || $use->attempt_id !== $attempt->public_id || $use->consumed_at !== null))) {
            throw new RuntimeException('Test unpaid release resources changed.');
        }

        return [$reservation, $use];
    }

    private function releaseResources(InventoryReservation $reservation, ?PromotionUse $use): void
    {
        if (DB::table('inventory_reservations')->where('id', $reservation->id)->where('state', 'pending')->update(['state' => 'released']) !== 1
            || ($use && DB::table('promotion_uses')->where('id', $use->id)->where('state', 'pending')->update(['state' => 'released']) !== 1)) {
            throw new RuntimeException('Test unpaid release resources changed.');
        }
    }

    private function locked(string $publicId, ?User $actor): array
    {
        $current = $actor?->exists ? User::whereKey($actor->id)->lockForUpdate()->first() : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        $account = app(UnpaidReleasePolicy::class)->account();
        if (! OrderRequest::uuid($publicId)) {
            throw (new ModelNotFoundException)->setModel(Order::class);
        }
        $order = Order::where('public_id', $publicId)->lockForUpdate()->firstOrFail();
        // A locking read of the absent intent also fences a concurrent first checkout start behind this transaction.
        $intent = CheckoutIntent::where('order_id', $order->id)->lockForUpdate()->first();
        if (($intent && ($intent->mode !== 'test' || ! hash_equals($account, $intent->account_id)))
            || ! hash_equals($publicId, $order->public_id)) {
            throw (new ModelNotFoundException)->setModel(Order::class);
        }
        $work = TestUnpaidReleaseWork::where('order_id', $order->id)->lockForUpdate()->first();
        $work ??= TestUnpaidReleaseWork::create(['order_id' => $order->id, 'sequence' => 0]);

        return [$order, $current, $intent, $work];
    }

    private function boundSession(CheckoutSession $session, CheckoutIntent $intent): void
    {
        $initial = app(CheckoutEvidence::class)->decrypt($session->evidence_ciphertext, $session->evidence_hash, $session->canonicalization_version);
        if ($session->checkout_intent_id !== $intent->id || $session->mode !== 'test' || $session->account_id !== $intent->account_id
            || ($initial['session_id'] ?? null) !== $session->provider_session_id || ($initial['intent_id'] ?? null) !== $intent->public_id
            || ($initial['account_id'] ?? null) !== $intent->account_id || ($initial['mode'] ?? null) !== 'test') {
            throw new RuntimeException('Test unpaid release binding changed.');
        }
    }

    private function events(Order $order): Builder
    {
        return TestUnpaidReleaseEvent::where('order_id', $order->id)->lockForUpdate();
    }

    private function append(Order $order, User $actor, TestUnpaidReleaseWork $work, string $requestId, int $expected, string $kind, string $outcome, mixed $observedAt = null): TestUnpaidReleaseEvent
    {
        $event = TestUnpaidReleaseEvent::create(['order_id' => $order->id, 'sequence' => $work->sequence + 1,
            'review_sequence' => $expected, 'request_id' => $requestId, 'actor_id' => $actor->id,
            'kind' => $kind, 'outcome' => $outcome, 'observed_at' => $observedAt, 'created_at' => now()->utc()->startOfSecond()]);
        $work->sequence = $event->sequence;
        $work->save();
        AuditEvent::recordAttributed('commerce.unpaid_release.'.$kind, $order,
            ['order_id' => $order->public_id, 'sequence' => $event->sequence, 'outcome' => $outcome, 'test_only' => true], $actor->id);

        return $event;
    }

    private function result(string $status, int $sequence): array
    {
        return ['testOnly' => true, 'status' => $status, 'sequence' => $sequence];
    }

    private function safe(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('Test unpaid release unavailable.');
        }
    }
}
