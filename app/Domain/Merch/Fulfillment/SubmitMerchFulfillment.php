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

    /** Printful statuses that prove a confirmation took effect. */
    private const CONFIRMED_STATUSES = ['pending', 'inreview', 'inprocess', 'partial', 'fulfilled'];

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
        if (in_array($order->status, self::CONFIRMED_STATUSES, true)) {
            // A recovered order that was already confirmed (for example in the Printful dashboard) is settled, never re-sent.
            $this->settle($current, MerchFulfillment::CONFIRMED, ['printful_order_id' => $order->id, 'printful_status' => $order->status,
                'failure_code' => null, 'submitted_at' => now()]);

            return $current->refresh();
        }
        if ($order->status !== 'draft') {
            // Neither a draft nor confirmed (canceled, failed, on hold, or a status this code does not know): record the
            // order and stop loudly for an operator instead of leaving a row that looks healthy.
            $this->settle($current, MerchFulfillment::SUBMITTED, ['printful_order_id' => $order->id, 'printful_status' => $order->status,
                'failure_code' => 'PRINTFUL_STATUS_UNEXPECTED', 'submitted_at' => now()]);
            throw new MerchFulfillmentException('MERCH_PRINTFUL_STATUS_UNEXPECTED');
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
     * confirming), so two callers cannot both send it and a hold placed after the draft was created stops the charge.
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

        return $this->sendConfirmation($fulfillment, $draft->id);
    }

    /**
     * Sends the confirmation for a row this caller has just claimed into `confirming`.
     *
     * A definite rejection (the request did not take effect) goes back to `submitted`. Anything whose outcome is unknown
     * (timeout, 5xx, 429, or a success whose body cannot be read) stays `confirming` with the lease cleared: the charge may
     * have landed, so the next run reconciles by looking the order up and never re-sends blindly.
     */
    private function sendConfirmation(MerchFulfillment $fulfillment, string $printfulOrderId): MerchFulfillment
    {
        try {
            $confirmed = $this->printful->confirmOrder($printfulOrderId);
        } catch (PrintfulException $exception) {
            $update = ['failure_code' => $exception->errorCode(), 'lease_expires_at' => null, 'updated_at' => now()];
            if (! $exception->outcomeUnknown) {
                $update['state'] = MerchFulfillment::SUBMITTED;
            }
            MerchFulfillment::query()->whereKey($fulfillment->id)->where('state', MerchFulfillment::CONFIRMING)->update($update);

            return $fulfillment->refresh();
        }
        MerchFulfillment::query()->whereKey($fulfillment->id)->where('state', MerchFulfillment::CONFIRMING)
            ->update(['state' => MerchFulfillment::CONFIRMED, 'printful_status' => $confirmed->status, 'failure_code' => null, 'lease_expires_at' => null, 'updated_at' => now()]);

        return $fulfillment->refresh();
    }

    /**
     * A `confirming` row whose lease has lapsed or was cleared by an unknown outcome. The caller first takes the
     * reconciliation lease with one conditional UPDATE and holds it through the provider lookup and the decision, so two
     * workers can never act on the same (possibly stale) lookup. Then it looks the order up by external id: confirmed or
     * in production settles it without a second charge; still a draft means the first request never took effect, so it is
     * re-confirmed once, and only while the confirm switch is on; anything else needs an operator.
     */
    private function reconcileConfirmation(MerchFulfillment $current): MerchFulfillment
    {
        $claimed = MerchFulfillment::query()->whereKey($current->id)->where('state', MerchFulfillment::CONFIRMING)
            ->where(fn ($unowned) => $unowned->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->update(['lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES), 'updated_at' => now()]);
        if ($claimed !== 1) {
            throw new MerchFulfillmentException('MERCH_CONFIRMATION_IN_PROGRESS');
        }
        try {
            return $this->reconcileClaimed($current);
        } catch (MerchFulfillmentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Anything unexpected after the claim (a ledger read, a client error) must not leave the lease held, or the next
            // run would see a live lease and return as if the work were settled.
            $this->releaseReconciliation($current);
            throw $exception;
        }
    }

    /** The body of reconciliation, run while this caller holds the reconciliation lease. */
    private function reconcileClaimed(MerchFulfillment $current): MerchFulfillment
    {
        try {
            $order = $this->printful->findOrderByExternalId($current->external_id);
        } catch (PrintfulException) {
            $this->releaseReconciliation($current);
            throw new MerchFulfillmentException('MERCH_CONFIRMATION_UNRESOLVED');
        }
        if ($order === null || $order->id !== (string) $current->printful_order_id) {
            $this->releaseReconciliation($current);
            throw new MerchFulfillmentException('MERCH_CONFIRMATION_UNRESOLVED');
        }
        if (in_array($order->status, self::CONFIRMED_STATUSES, true)) {
            MerchFulfillment::query()->whereKey($current->id)->where('state', MerchFulfillment::CONFIRMING)
                ->update(['state' => MerchFulfillment::CONFIRMED, 'printful_status' => $order->status, 'failure_code' => null, 'lease_expires_at' => null, 'updated_at' => now()]);

            return $current->refresh();
        }
        if ($order->status !== 'draft' || ! $this->policy->confirmsOrders()) {
            // A draft is only re-sent while the confirm switch is on; with it off the row waits for an operator.
            $this->releaseReconciliation($current);
            throw new MerchFulfillmentException('MERCH_CONFIRMATION_UNRESOLVED');
        }
        if ($this->holds->exist($current->order_id)) {
            $this->releaseReconciliation($current);

            return $current->refresh();
        }
        $result = $this->sendConfirmation($current, $order->id);
        if ($result->state === MerchFulfillment::CONFIRMING) {
            throw new MerchFulfillmentException('MERCH_CONFIRMATION_UNRESOLVED');
        }

        return $result;
    }

    /** Gives the reconciliation lease back without changing state, so the next run (or an operator) can reconcile again. */
    private function releaseReconciliation(MerchFulfillment $current): void
    {
        MerchFulfillment::query()->whereKey($current->id)->where('state', MerchFulfillment::CONFIRMING)
            ->update(['lease_expires_at' => null, 'updated_at' => now()]);
    }

    private function requireVerifiedPayment(MerchFulfillment $fulfillment): void
    {
        $verified = VerifiedPayment::query()->whereKey($fulfillment->verified_payment_id)->where('order_id', $fulfillment->order_id)->exists();
        if (! $verified) {
            throw new MerchFulfillmentException('MERCH_PAYMENT_NOT_VERIFIED');
        }
        // A row already in `confirming` may have been charged at Printful. It still reaches reconciliation under a hold,
        // because that only reads Printful and settles local state; the re-send of a draft checks holds itself.
        $state = MerchFulfillment::query()->whereKey($fulfillment->id)->value('state');
        if ($state !== MerchFulfillment::CONFIRMING && $this->holds->exist($fulfillment->order_id)) {
            throw new MerchFulfillmentException('MERCH_ORDER_HELD');
        }
    }

    private function unclaimed(MerchFulfillment $current): MerchFulfillment
    {
        if ($current->state === MerchFulfillment::SUBMITTING) {
            throw new MerchFulfillmentException('MERCH_SUBMISSION_IN_PROGRESS');
        }
        if ($current->state === MerchFulfillment::CONFIRMING) {
            if ($current->lease_expires_at === null || $current->lease_expires_at->isPast()) {
                // The earlier confirmation's outcome is unknown. Never silently return it as if it were settled.
                return $this->reconcileConfirmation($current);
            }
            // Another caller holds the confirmation or its reconciliation right now.
            throw new MerchFulfillmentException('MERCH_CONFIRMATION_IN_PROGRESS');
        }
        if ($current->state === MerchFulfillment::SUBMITTED && $this->policy->confirmsOrders() && is_string($current->printful_order_id)
            && $current->printful_status === 'draft') {
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
