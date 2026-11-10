<?php

namespace App\Domain\Merch\Fulfillment;

use App\Domain\Merch\Models\MerchFulfillment;
use App\Domain\Merch\Models\MerchFulfillmentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Receives a Printful v2 webhook event.
 *
 * Verification as Printful documents it (https://developers.printful.com/docs/v2-beta/, "Event signing", read 2026-11-10): the
 * header `x-pf-webhook-signature` carries the hex HMAC-SHA256 of the raw request body, keyed with the configuration's secret
 * key after hex-decoding it; `x-pf-webhook-public-key` names the configuration. An event with a missing or invalid signature is
 * refused before anything is parsed or stored. Whether the HMAC covers exactly the raw bytes was not testable here (no Printful
 * account), so the check is isolated in verify() and pinned by tests against the documented construction.
 *
 * Printful sends no event id, so the replay key is a hash of type, order id, shipment id and occurred_at (not `retries`, which
 * changes on every redelivery). The unique key on the event row rolls a replay back whole.
 */
final class ReceivePrintfulWebhook
{
    public const MAX_BODY_BYTES = 65536;

    private const SHIPPED_FROM = [MerchFulfillment::SUBMITTED, MerchFulfillment::CONFIRMED, MerchFulfillment::SHIPPED];

    public function __construct(private readonly MerchFulfillmentPolicy $policy) {}

    /** @return string applied|ignored|unmatched|duplicate */
    public function handle(#[SensitiveParameter] string $body, ?string $signature, ?string $publicKey): string
    {
        $this->policy->requireEnabled();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new MerchFulfillmentException('PRINTFUL_WEBHOOK_TOO_LARGE', 413);
        }
        $this->verify($body, $signature, $publicKey);
        $event = json_decode($body, true, 16);
        $type = is_array($event) ? ($event['type'] ?? null) : null;
        if (! is_string($type) || preg_match('/\A[a-z_]{1,48}\z/D', $type) !== 1) {
            throw new MerchFulfillmentException('PRINTFUL_WEBHOOK_INVALID', 400);
        }
        $occurred = is_string($event['occurred_at'] ?? null) ? $event['occurred_at'] : '';
        $order = is_array($event['data']['order'] ?? null) ? $event['data']['order'] : [];
        $shipment = is_array($event['data']['shipment'] ?? null) ? $event['data']['shipment'] : [];
        $orderId = $this->identifier($order['id'] ?? null, '/\A[0-9]{1,20}\z/D');
        $externalId = $this->identifier($order['external_id'] ?? null, '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D');
        $key = hash('sha256', implode('|', [$type, $orderId, (string) ($shipment['id'] ?? ''), $occurred]));
        $at = strtotime($occurred);

        try {
            return DB::transaction(function () use ($type, $order, $shipment, $orderId, $externalId, $key, $body, $at): string {
                $fulfillment = $externalId === null ? null : MerchFulfillment::query()->where('external_id', $externalId)->lockForUpdate()->first();
                $outcome = $fulfillment === null ? 'unmatched' : $this->apply($fulfillment, $type, $order, $shipment);
                MerchFulfillmentEvent::query()->create([
                    'event_key' => $key, 'merch_fulfillment_id' => $fulfillment?->id, 'event_type' => $type, 'external_id' => $externalId,
                    'printful_order_id' => $orderId, 'outcome' => $outcome, 'body_sha256' => hash('sha256', $body),
                    'occurred_at' => $at === false ? null : date('Y-m-d H:i:s', $at), 'received_at' => now(),
                ]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            // A replay (or a concurrent twin): the first delivery's effects stand, this one changed nothing.
            return 'duplicate';
        }
    }

    private function verify(#[SensitiveParameter] string $body, ?string $signature, ?string $publicKey): void
    {
        $secret = config('merch.printful.webhook_secret_key');
        if (! is_string($secret) || $secret === '' || preg_match('/\A(?:[0-9a-f]{2})+\z/iD', $secret) !== 1) {
            throw new MerchFulfillmentException('PRINTFUL_WEBHOOK_NOT_CONFIGURED', 503);
        }
        $expected = hash_hmac('sha256', $body, (string) hex2bin($secret));
        if (! is_string($signature) || ! hash_equals($expected, strtolower($signature))) {
            throw new MerchFulfillmentException('PRINTFUL_WEBHOOK_SIGNATURE_INVALID', 401);
        }
        $configured = config('merch.printful.webhook_public_key');
        if (is_string($configured) && $configured !== '' && (! is_string($publicKey) || ! hash_equals($configured, $publicKey))) {
            throw new MerchFulfillmentException('PRINTFUL_WEBHOOK_SIGNATURE_INVALID', 401);
        }
    }

    /**
     * Forward-only: a late or reordered event never moves a shipped, failed or canceled fulfillment backwards.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $shipment
     */
    private function apply(MerchFulfillment $fulfillment, string $type, array $order, array $shipment): string
    {
        $status = $this->identifier($order['status'] ?? null, '/\A[a-z_]{1,32}\z/D');
        $updates = match (true) {
            $type === 'shipment_sent' && in_array($fulfillment->state, self::SHIPPED_FROM, true) => [
                'state' => MerchFulfillment::SHIPPED, 'printful_status' => $status ?? $fulfillment->printful_status, 'shipped_at' => now(),
                'tracking_number' => $this->text($shipment['tracking_number'] ?? null, 128),
                'tracking_url' => $this->text($shipment['tracking_url'] ?? null, 500), 'failure_code' => null],
            $type === 'order_failed' && ! in_array($fulfillment->state, [MerchFulfillment::SHIPPED, MerchFulfillment::CANCELED], true) => [
                'state' => MerchFulfillment::FAILED, 'failure_code' => 'PRINTFUL_ORDER_FAILED', 'printful_status' => $status ?? $fulfillment->printful_status],
            $type === 'order_canceled' && $fulfillment->state !== MerchFulfillment::SHIPPED => [
                'state' => MerchFulfillment::CANCELED, 'printful_status' => $status ?? $fulfillment->printful_status],
            in_array($type, ['order_created', 'order_updated'], true) && $status !== null && ! in_array($fulfillment->state, [MerchFulfillment::SHIPPED, MerchFulfillment::CANCELED], true) => [
                'printful_status' => $status],
            default => null,
        };
        if ($updates === null) {
            return 'ignored';
        }
        $fulfillment->forceFill($updates + ['updated_at' => now()])->save();

        return 'applied';
    }

    private function identifier(mixed $value, string $pattern): ?string
    {
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }

    private function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $limit && ! preg_match('/[\x00-\x1F\x7F]/u', $value) ? $value : null;
    }
}
