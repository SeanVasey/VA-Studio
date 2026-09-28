<?php

namespace App\Domain\Delivery;

use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use Illuminate\Database\QueryException;
use SensitiveParameter;
use Throwable;

/** Owner-only public projection. Historical metadata never establishes current private-file health or receipt. */
final class ReadTestOwnerDelivery
{
    private const HISTORY_LIMIT = 20;
    private const KINDS = ['contract', 'master_wav', 'download_mp3', 'stems_zip'];

    public function handle(string $orderPublicId, #[SensitiveParameter] string $ownerKey): array
    {
        $evidence = app(DeliveryAccessEvidence::class);
        $order = $evidence->owned($orderPublicId, $ownerKey);
        $policy = app(TestAccessPolicy::class);
        $account = $policy->environmentAccount();
        $result = ['deliverySchema' => 1, 'orderId' => $order->public_id, 'testOnly' => true,
            'status' => 'unavailable', 'items' => [], 'history' => [],
            'historyLimit' => self::HISTORY_LIMIT, 'historyHasMore' => false];
        if (! TestFulfillmentActivation::where('order_id', $order->id)->exists()) { return $result; }

        try {
            $source = $evidence->source($order, $account);
            $at = now()->toImmutable()->utc();
            if ($source['activation']->activated_at->greaterThan($at)) { throw new DeliveryException('changed'); }
            foreach ($source['snapshot']['members'] as $member) {
                $retainedKinds = ['contract', ...array_column($member['entitlements'], 'role')];
                foreach (self::KINDS as $kind) {
                    if (! in_array($kind, $retainedKinds, true)) { continue; }
                    $target = $evidence->target($source, $member['grant_public_id'], $kind);
                    $result['items'][] = ['grantId' => $target['grant_public_id'], 'kind' => $target['kind'],
                        'filename' => $target['filename'], 'mimeType' => $target['mime_type'], 'sizeBytes' => $target['file']['size_bytes']];
                }
            }

            $authorizations = TestDeliveryAuthorization::where('order_id', $order->id)->with('redemption')
                ->orderByDesc('issued_at')->orderByDesc('id')->limit(self::HISTORY_LIMIT + 1)->get();
            $result['historyHasMore'] = $authorizations->count() > self::HISTORY_LIMIT;
            foreach ($authorizations->take(self::HISTORY_LIMIT) as $authorization) {
                $target = $evidence->verify($authorization, $source);
                if ($authorization->issued_at->greaterThan($at)) { throw new DeliveryException('changed'); }
                $redemption = $authorization->redemption;
                if ($redemption) {
                    $evidence->verifyRedemption($redemption, $authorization, $source, $target);
                    if ($redemption->redeemed_at->greaterThan($at)) { throw new DeliveryException('changed'); }
                }
                // "Unused" means no committed attempt; control/policy changes can still prevent redemption.
                $result['history'][] = ['authorizationId' => $authorization->public_id,
                    'grantId' => $target['grant_public_id'], 'kind' => $target['kind'],
                    'issuedAt' => $authorization->issued_at->utc()->toIso8601ZuluString(),
                    'expiresAt' => $authorization->expires_at->utc()->toIso8601ZuluString(),
                    'status' => $redemption ? 'attempted' : ($authorization->expires_at->lessThanOrEqualTo($at) ? 'expired' : 'unused'),
                    'attemptedAt' => $redemption?->redeemed_at->utc()->toIso8601ZuluString()];
            }

            $control = TestDeliveryControl::where('order_id', $order->id)->first();
            if ($control && ($control->created_at->lessThan($source['activation']->activated_at)
                || $control->created_at->greaterThan($control->updated_at) || $control->updated_at->greaterThan($at)
                || $control->created_at->micro !== 0 || $control->updated_at->micro !== 0
                || $control->control_version > 4294967294 || $control->blocked !== ($control->control_version % 2 === 0))) {
                throw new DeliveryException('changed');
            }
            try {
                $evidence->control($source, $control);
                $policy->current();
                $result['status'] = 'available';
            } catch (DeliveryException $error) {
                if (! in_array($error->reason, ['blocked', 'unavailable'], true)) { throw $error; }
            }
            return $result;
        } catch (QueryException|DeliveryException $error) { throw $error; }
        catch (Throwable) { throw new DeliveryException('changed'); }
    }
}
