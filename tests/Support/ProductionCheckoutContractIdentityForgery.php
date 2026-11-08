<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\HostedEvidence;
use App\Domain\Commerce\ProductionCheckout\OrderEvidence;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** D-30: sealed V1 checkout evidence whose v2 declaration the frozen evidence accepts but re-verification refuses. */
trait ProductionCheckoutContractIdentityForgery
{
    /**
     * A sealed review, order, lines and attempt that OrderEvidence accepts (each declaration equals its request's and the
     * order's equals the review's), copied from the one retained order, but whose v2 name the pinned faces cannot print.
     */
    private static function forgedUnprintableOrder(): string
    {
        $tables = CheckoutSchema::TABLES;
        $review = (array) DB::table($tables['review'])->sole();
        $reviewBody = Evidence::open($review, 'production_checkout_review');
        $declarations = [...$reviewBody['buyer_declarations'], 'legal_name' => '王小明'];
        $reviewDigest = Evidence::key('synthetic-forged-review');
        $reviewRequest = [...$reviewBody['request'], 'buyer_declarations' => $declarations, 'key_digest' => $reviewDigest];
        $forgedReview = [...$reviewBody, 'public_id' => (string) Str::uuid(), 'request' => $reviewRequest,
            'request_hash' => CanonicalJson::hash($reviewRequest), 'buyer_declarations' => $declarations];
        $forgedReview['review_hash'] = OrderEvidence::reviewHash($forgedReview);
        $reviewSeal = Evidence::seal($forgedReview);
        unset($review['id']);
        $reviewId = DB::table($tables['review'])->insertGetId([...$review, 'public_id' => $forgedReview['public_id'], ...$reviewSeal,
            'request_key' => $reviewDigest, 'request_hash' => $forgedReview['request_hash']]);

        $order = (array) DB::table($tables['order'])->sole();
        $orderBody = Evidence::open($order, 'production_checkout_order');
        $orderDigest = Evidence::key('synthetic-forged-accept');
        $lines = array_map(fn (array $line): array => [...$line, 'public_id' => (string) Str::uuid()], $orderBody['lines']);
        $orderRequest = [...$orderBody['request'], 'review_id' => $forgedReview['public_id'], 'review_hash' => $forgedReview['review_hash'], 'key_digest' => $orderDigest];
        $forgedOrder = [...$orderBody, 'public_id' => (string) Str::uuid(), 'review_id' => $forgedReview['public_id'], 'review_hash' => $forgedReview['review_hash'],
            'review_payload_hash' => $reviewSeal['payload_hash'], 'request' => $orderRequest, 'request_hash' => CanonicalJson::hash($orderRequest),
            'buyer_declarations' => $declarations, 'assent' => [...$orderBody['assent'], 'review_hash' => $forgedReview['review_hash']],
            'lines' => $lines, 'attempt_id' => (string) Str::uuid()];
        $orderSeal = Evidence::seal($forgedOrder);
        $orderRowId = $order['id'];
        unset($order['id']);
        $orderId = DB::table($tables['order'])->insertGetId([...$order, 'public_id' => $forgedOrder['public_id'], ...$orderSeal, 'review_id' => $reviewId,
            'request_key' => $orderDigest, 'request_hash' => $forgedOrder['request_hash']]);

        foreach (DB::table($tables['line'])->where('order_id', $orderRowId)->orderBy('position')->get() as $i => $row) {
            $row = (array) $row;
            $body = [...Evidence::open($row, 'production_checkout_order_line'), 'public_id' => $lines[$i]['public_id'],
                'order_public_id' => $forgedOrder['public_id'], 'order_payload_hash' => $orderSeal['payload_hash'], 'line' => $lines[$i]];
            unset($row['id']);
            DB::table($tables['line'])->insert([...$row, 'public_id' => $lines[$i]['public_id'], ...Evidence::seal($body), 'order_id' => $orderId,
                'line_hash' => CanonicalJson::hash($lines[$i])]);
        }
        $attempt = (array) DB::table($tables['attempt'])->where('order_id', $orderRowId)->sole();
        $attemptBody = [...Evidence::open($attempt, 'production_checkout_attempt'), 'public_id' => $forgedOrder['attempt_id'],
            'order_public_id' => $forgedOrder['public_id'], 'order_payload_hash' => $orderSeal['payload_hash']];
        unset($attempt['id']);
        DB::table($tables['attempt'])->insert([...$attempt, 'public_id' => $forgedOrder['attempt_id'], ...Evidence::seal($attemptBody), 'order_id' => $orderId]);

        return $forgedOrder['public_id'];
    }

    /**
     * The retained intent `initiate` would have written for an order, written directly (the forged order's initiate refuses),
     * so reconciliation can record a synthetic payment for it. Returns the provider request parameters.
     */
    private static function forgedIntent(string $orderId): array
    {
        $order = CommandTransaction::run(fn (Records $rows): array => OrderEvidence::order($rows, $rows->one('order', $orderId)));
        $context = $order['context'];
        $at = CarbonImmutable::now('UTC');
        $public = (string) Str::uuid();
        $request = HostedEvidence::request($order, $public, $at->format('Y-m-d\TH:i:s\Z'));
        DB::table(CheckoutSchema::TABLES['intent'])->insert(['public_id' => $public, 'created_at' => $at->format('Y-m-d\TH:i:s\Z'), ...Evidence::seal($request),
            'order_id' => $order['row']['id'], 'attempt_id' => $order['attempt']['id'], 'account_id' => $context->accountId, 'funds_mode' => $context->fundsMode,
            'idempotency_key' => $request['idempotency_key'], 'retry_before' => $at->addSeconds($context->retrySeconds)->format('Y-m-d\TH:i:s\Z'),
            'provider_expires_at' => $at->addSeconds($context->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z')]);

        return $request['params'];
    }
}
