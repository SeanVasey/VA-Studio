<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutEvidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutRecords;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** D-30: sealed tax checkout evidence whose v2 declaration the order evidence accepts unverified but re-verification refuses. */
trait ProductionTaxCheckoutContractIdentityForgery
{
    /**
     * A sealed copy of the first retained order whose request and body agree, so only the re-verification can refuse it: the
     * name cannot be printed by the faces checkout is bound to. Returns the forged order's public id.
     */
    private static function forgedUnprintableTaxOrder(string $key = 'synthetic-forged-order', ?\Closure $tamperBody = null, ?\Closure $tamperRow = null): string
    {
        $row = (array) DB::table(TaxCheckoutSchema::TABLES['order'])->orderBy('id')->first();
        $body = Evidence::open($row, 'production_tax_checkout_order');
        $unprintable = [...$body['buyer_declarations'], 'legal_name' => '王小明'];
        $request = [...$body['request'], 'buyer_declarations' => $unprintable, 'key_digest' => Evidence::key($key)];
        $forged = [...$body, 'public_id' => (string) Str::uuid(), 'request' => $request, 'request_hash' => CanonicalJson::hash($request), 'buyer_declarations' => $unprintable];
        // Optional tampering of the sealed body (resealed) or of the stored row (not resealed), for refusal tests.
        $sealed = $tamperBody === null ? $forged : $tamperBody($forged);
        unset($row['id']);
        $insert = [...$row, 'public_id' => $forged['public_id'], ...Evidence::seal($sealed), 'request_key' => $request['key_digest'], 'request_hash' => $forged['request_hash']];
        DB::table(TaxCheckoutSchema::TABLES['order'])->insert($tamperRow === null ? $insert : $tamperRow($insert));

        return $forged['public_id'];
    }

    /**
     * The retained provider request and session binding `initiate` would have written for an order, written directly (the
     * forged order's initiate refuses), so reconciliation can record a synthetic payment for it. Returns the request params
     * the synthetic transport must answer for.
     */
    private static function forgedTaxSession(string $orderId, string $sessionId): array
    {
        $order = CommandTransaction::run(fn (Records $rows): array => TaxCheckoutEvidence::order(TaxCheckoutRecords::writer($rows),
            TaxCheckoutRecords::writer($rows)->one('order', $orderId), false));
        $context = $order['context'];
        $at = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $public = (string) Str::uuid();
        $body = TaxCheckoutEvidence::request($order, $public, $at);
        $requestId = DB::table(TaxCheckoutSchema::TABLES['request'])->insertGetId(['public_id' => $public, 'created_at' => $at, ...Evidence::seal($body),
            'order_id' => $order['row']['id'], 'account_id' => $context->accountId, 'funds_mode' => $context->fundsMode,
            'idempotency_key' => $body['idempotency_key'], 'currency' => 'USD', 'subtotal_minor' => $order['row']['subtotal_minor'],
            'tax_behavior' => $context->taxBehavior, 'maximum_rate_bps' => $context->maximumRateBps, 'provider_expires_at' => $body['provider_expires_at']]);
        $request = ['row' => (array) DB::table(TaxCheckoutSchema::TABLES['request'])->where('id', $requestId)->sole(), 'body' => $body];
        $bindingId = (string) Str::uuid();
        $binding = TaxCheckoutEvidence::binding($request, $bindingId, $at, $sessionId);
        DB::table(TaxCheckoutSchema::TABLES['binding'])->insert(['public_id' => $bindingId, 'created_at' => $at, ...Evidence::seal($binding),
            'request_id' => $requestId, 'account_id' => $context->accountId, 'funds_mode' => $context->fundsMode, 'provider_session_id' => $sessionId]);

        return $body['params'];
    }
}
