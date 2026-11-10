<?php

namespace App\Domain\Merch\Fulfillment;

use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Merch\Fulfillment\Printful\PrintfulClient;
use App\Domain\Merch\Fulfillment\Printful\PrintfulException;
use App\Domain\Merch\Fulfillment\Printful\PrintfulOrder;
use App\Domain\Merch\Models\MerchFulfillment;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hands one paid order to Printful, once.
 *
 * Guards, in order: the flag; a durable VerifiedPayment bound to this very fulfillment; no active refund/dispute hold; an
 * atomic claim (a single conditional UPDATE, so two workers cannot both submit); and, on any attempt after the first, a lookup by
 * our external id BEFORE any create, because a timed-out create may have succeeded. A definite rejection is recorded as failed
 * and is not retried automatically.
 */
final class SubmitMerchFulfillment
{
    private const LEASE_MINUTES = 5;

    public function __construct(
        private readonly MerchFulfillmentPolicy $policy,
        private readonly PrintfulClient $printful,
        private readonly ActiveOrderHolds $holds,
    ) {}

    public function handle(MerchFulfillment $fulfillment): MerchFulfillment
    {
        $this->policy->requireEnabled();
        $this->requireVerifiedPayment($fulfillment);
        $id = $fulfillment->id;

        $claimed = MerchFulfillment::query()->whereKey($id)->where(function ($query): void {
            $query->where('state', MerchFulfillment::RECORDED)
                ->orWhere(fn ($lapsed) => $lapsed->where('state', MerchFulfillment::SUBMITTING)->where('lease_expires_at', '<=', now()));
        })->update(['state' => MerchFulfillment::SUBMITTING, 'attempts' => DB::raw('attempts + 1'),
            'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES), 'updated_at' => now()]);
        $current = MerchFulfillment::query()->findOrFail($id);
        if ($claimed !== 1) {
            return $this->unclaimed($current);
        }

        try {
            $request = $this->request($current);
            $order = $current->attempts > 1 ? $this->printful->findOrderByExternalId($current->external_id) : null;
            $order ??= $this->printful->createDraftOrder($current->external_id, $request['recipient'], $request['items'], $request['shipping']);
        } catch (PrintfulException $exception) {
            return $this->failed($current, $exception);
        } catch (MerchFulfillmentException $exception) {
            $this->settle($current, MerchFulfillment::FAILED, ['failure_code' => $exception->errorCode]);

            return $current->refresh();
        }
        $this->settle($current, MerchFulfillment::SUBMITTED, ['printful_order_id' => $order->id, 'printful_status' => $order->status,
            'failure_code' => null, 'submitted_at' => now()]);
        $current->refresh();

        return $this->policy->confirmsOrders() ? $this->confirm($current, $order) : $current;
    }

    /**
     * Confirms a submitted draft (charges the Printful account). Only reachable with the separate confirm switch on.
     *
     * The refund/dispute check runs again here, and the confirmation is claimed with one conditional UPDATE (submitted to
     * confirming), so two callers cannot both send it and a hold placed after the draft was created stops the charge. A claim
     * left in `confirming` is never taken over automatically: the outcome of its request is unknown, so an operator decides.
     */
    private function confirm(MerchFulfillment $fulfillment, PrintfulOrder $draft): MerchFulfillment
    {
        if ($this->holds->exist($fulfillment->order_id)) {
            return $fulfillment->refresh();
        }
        $claimed = MerchFulfillment::query()->whereKey($fulfillment->id)->where('state', MerchFulfillment::SUBMITTED)
            ->update(['state' => MerchFulfillment::CONFIRMING, 'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $fulfillment->refresh();
        }
        try {
            $confirmed = $this->printful->confirmOrder($draft->id);
        } catch (PrintfulException $exception) {
            // The draft exists and holds the same external id: leave it submitted so an operator (or the next run) can confirm it.
            MerchFulfillment::query()->whereKey($fulfillment->id)->where('state', MerchFulfillment::CONFIRMING)
                ->update(['state' => MerchFulfillment::SUBMITTED, 'lease_expires_at' => null, 'failure_code' => $exception->errorCode(), 'updated_at' => now()]);

            return $fulfillment->refresh();
        }
        MerchFulfillment::query()->whereKey($fulfillment->id)->where('state', MerchFulfillment::CONFIRMING)
            ->update(['state' => MerchFulfillment::CONFIRMED, 'printful_status' => $confirmed->status, 'failure_code' => null, 'lease_expires_at' => null, 'updated_at' => now()]);

        return $fulfillment->refresh();
    }

    private function requireVerifiedPayment(MerchFulfillment $fulfillment): void
    {
        $verified = VerifiedPayment::query()->whereKey($fulfillment->verified_payment_id)->where('order_id', $fulfillment->order_id)->exists();
        if (! $verified) {
            throw new MerchFulfillmentException('MERCH_PAYMENT_NOT_VERIFIED');
        }
        if ($this->holds->exist($fulfillment->order_id)) {
            throw new MerchFulfillmentException('MERCH_ORDER_HELD');
        }
    }

    private function unclaimed(MerchFulfillment $current): MerchFulfillment
    {
        if ($current->state === MerchFulfillment::SUBMITTING) {
            throw new MerchFulfillmentException('MERCH_SUBMISSION_IN_PROGRESS');
        }
        if ($current->state === MerchFulfillment::SUBMITTED && $this->policy->confirmsOrders() && is_string($current->printful_order_id)) {
            return $this->confirm($current, new PrintfulOrder($current->printful_order_id, $current->external_id, (string) $current->printful_status));
        }

        // Submitted, confirmed, shipped, failed or canceled: the work is done or needs an operator. Never submit again.
        return $current;
    }

    /** @return array{recipient: array<string, mixed>, items: list<array<string, mixed>>, shipping: string} */
    private function request(MerchFulfillment $fulfillment): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($fulfillment->request_ciphertext), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MerchFulfillmentException('MERCH_REQUEST_UNREADABLE');
        }
        if (! is_array($decoded) || ! hash_equals($fulfillment->request_hash, CanonicalJson::hash($decoded))) {
            throw new MerchFulfillmentException('MERCH_REQUEST_UNREADABLE');
        }

        return $decoded;
    }

    private function failed(MerchFulfillment $fulfillment, PrintfulException $exception): MerchFulfillment
    {
        if ($exception->outcomeUnknown || in_array($exception->errorCode(), ['PRINTFUL_NOT_CONFIGURED', 'PRINTFUL_UNAUTHORIZED'], true)) {
            // Back to recorded: the next attempt looks the order up by external id before it creates anything. A missing or
            // refused credential is an operator fix, not a verdict on the order, so it must not strand it as failed.
            $this->settle($fulfillment, MerchFulfillment::RECORDED, ['failure_code' => $exception->errorCode()]);
            throw new MerchFulfillmentException('MERCH_PRINTFUL_UNAVAILABLE', 503);
        }
        $this->settle($fulfillment, MerchFulfillment::FAILED, ['failure_code' => $exception->errorCode()]);

        return $fulfillment->refresh();
    }

    /** @param  array<string, mixed>  $values */
    private function settle(MerchFulfillment $fulfillment, string $state, array $values): void
    {
        MerchFulfillment::query()->whereKey($fulfillment->id)->where('state', MerchFulfillment::SUBMITTING)
            ->update($values + ['state' => $state, 'lease_expires_at' => null, 'updated_at' => now()]);
    }
}
