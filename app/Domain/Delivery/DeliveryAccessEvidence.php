<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;
use Throwable;

/** Historical, database-only reconstruction. New access checks policy/control separately at the commit boundary. */
final class DeliveryAccessEvidence
{
    public function owned(string $publicId, #[SensitiveParameter] string $ownerKey): Order
    {
        if (! OrderRequest::uuid($publicId) || preg_match('/\A[a-f0-9]{64}\z/D', $ownerKey) !== 1) { throw new DeliveryException('not_found'); }
        $order = Order::where('public_id', $publicId)->where('owner_key', $ownerKey)->first();
        if (! $order || ! hash_equals($order->owner_key, $ownerKey)) { throw new DeliveryException('not_found'); }
        return $order;
    }

    public function source(Order $order, string $account): array
    {
        $activation = TestFulfillmentActivation::where('order_id', $order->id)->first();
        if (! $activation) { throw new DeliveryException('blocked'); }
        try {
            $evidence = app(ActivationEvidence::class);
            $source = $evidence->source($order, $account);
            $evidence->verify($activation, $source);
            $source['activation'] = $activation;
            $source['snapshot_hash'] = CanonicalJson::hash($source['snapshot']);
            return $source;
        } catch (QueryException $error) { throw $error; }
        catch (DeliveryException $error) {
            throw new DeliveryException($error->reason === 'unavailable' ? 'unavailable' : 'changed');
        }
    }

    /** A grant UUID plus one role selects exactly one frozen original or entitlement; no client path/filename is accepted. */
    public function target(array $source, string $grantPublicId, string $kind): array
    {
        if (! OrderRequest::uuid($grantPublicId) || ! in_array($kind, ['contract', 'master_wav', 'download_mp3', 'stems_zip'], true)) {
            throw new DeliveryException('not_found');
        }
        $matches = array_values(array_filter($source['snapshot']['members'], fn ($member) => $member['grant_public_id'] === $grantPublicId));
        if (count($matches) !== 1) { throw new DeliveryException('not_found'); }
        $member = $matches[0]; $contract = null; $entitlement = null;
        if ($kind === 'contract') {
            $original = $member['original']; $contract = $original['id'];
            $file = ['kind' => 'contract', 'disk' => $original['disk'], 'storage_path' => $original['storage_path'],
                'sha256' => $original['pdf_hash'], 'size_bytes' => $original['size_bytes'],
                'page_count' => $original['page_count'], 'profile_hash' => $original['profile_hash']];
            $extension = 'pdf'; $mime = 'application/pdf';
        } else {
            $matches = array_values(array_filter($member['entitlements'], fn ($entry) => $entry['role'] === $kind));
            if (count($matches) !== 1) { throw new DeliveryException('not_found'); }
            $entry = $matches[0]; $entitlement = $entry['id'];
            $assets = array_values(array_filter($source['assets'], fn ($asset) => $asset['id'] === $entry['media_asset_id'] && $asset['role'] === $kind));
            if (count($assets) !== 1) { throw new DeliveryException('changed'); }
            $file = $assets[0] + ['kind' => $kind];
            if ($file['sha256'] !== $entry['asset_hash'] || $file['size_bytes'] !== $entry['size_bytes']) { throw new DeliveryException('changed'); }
            [$extension, $mime] = match ($kind) {
                'master_wav' => ['wav', 'audio/wav'], 'download_mp3' => ['mp3', 'audio/mpeg'], 'stems_zip' => ['zip', 'application/zip'],
            };
        }
        return ['kind' => $kind, 'license_grant_id' => $member['grant_id'], 'grant_public_id' => $grantPublicId,
            'grant_contract_id' => $contract, 'pending_entitlement_id' => $entitlement, 'file' => $file,
            'filename' => $grantPublicId.'-'.$kind.'.'.$extension, 'mime_type' => $mime];
    }

    public function control(array $source, ?TestDeliveryControl $control, ?int $version = null): TestDeliveryControl
    {
        if (! $control) { throw new DeliveryException('blocked'); }
        if ($control->order_id !== $source['order']->id || $control->test_fulfillment_activation_id !== $source['activation']->id
            || ! OrderRequest::uuid($control->public_id) || $control->control_version < 0) { throw new DeliveryException('changed'); }
        if ($control->blocked || ($version !== null && $control->control_version !== $version)) { throw new DeliveryException('blocked'); }
        return $control;
    }

    public function requestHash(Order $order, #[SensitiveParameter] string $owner, string $grant, string $kind): string
    {
        return CanonicalJson::hash(['schema_version' => 1, 'order_id' => $order->public_id,
            'owner_key' => $owner, 'grant_id' => $grant, 'kind' => $kind]);
    }

    public function idempotencyHash(Order $order, #[SensitiveParameter] string $owner, string $key): string
    {
        if (! OrderRequest::uuid($key)) { throw new DeliveryException('not_found'); }
        return CanonicalJson::hash(['purpose' => 'test_delivery_request', 'order_id' => $order->public_id, 'owner_key' => $owner, 'key' => $key]);
    }

    public function capture(array $source, array $target, TestDeliveryControl $control, array $columns): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_delivery_authorization', 'policy' => TestAccessPolicy::CONTRACT,
            'authorization_id' => $columns['public_id'], 'owner_key' => $columns['owner_key'], 'token_hash' => $columns['token_hash'],
            'idempotency_key_hash' => $columns['idempotency_key_hash'], 'request_hash' => $columns['request_hash'],
            'order_id' => $source['order']->public_id, 'activation_id' => $source['activation']->public_id,
            'activation_evidence_hash' => $source['activation']->evidence_hash, 'snapshot_hash' => $source['snapshot_hash'],
            'control_id' => $control->public_id, 'control_version' => $columns['control_version'], 'target' => $target,
            'issued_at' => $columns['issued_at']->toIso8601ZuluString(), 'expires_at' => $columns['expires_at']->toIso8601ZuluString()];
    }

    public function verify(TestDeliveryAuthorization $authorization, array $source): array
    {
        try {
            $decoded = $this->decode($authorization);
            $target = $this->target($source, $decoded['target']['grant_public_id'] ?? '', $authorization->kind);
            $control = TestDeliveryControl::find($authorization->test_delivery_control_id);
            if (! $control || $control->order_id !== $source['order']->id
                || $control->test_fulfillment_activation_id !== $source['activation']->id
                || $authorization->order_id !== $source['order']->id
                || $authorization->test_fulfillment_activation_id !== $source['activation']->id
                || $authorization->owner_key !== $source['order']->owner_key
                || $authorization->license_grant_id !== $target['license_grant_id']
                || $authorization->grant_contract_id !== $target['grant_contract_id']
                || $authorization->pending_entitlement_id !== $target['pending_entitlement_id']
                || $authorization->policy_version !== TestAccessPolicy::CONTRACT['version']
                || $authorization->control_version < 1 || $authorization->control_version > $control->control_version
                || ! OrderRequest::uuid($authorization->public_id)
                || ! $authorization->expires_at->equalTo($authorization->issued_at->addSeconds(60))
                || $authorization->issued_at->lessThan($source['activation']->activated_at)
                || $authorization->issued_at->micro !== 0 || $authorization->expires_at->micro !== 0
                || $authorization->request_hash !== $this->requestHash($source['order'], $authorization->owner_key, $target['grant_public_id'], $target['kind'])) {
                throw new DeliveryException('changed');
            }
            $expected = $this->capture($source, $target, $control, $authorization->only(['public_id', 'owner_key', 'token_hash',
                'idempotency_key_hash', 'request_hash', 'control_version', 'issued_at', 'expires_at']));
            if (CanonicalJson::encode($decoded) !== CanonicalJson::encode($expected)) { throw new DeliveryException('changed'); }
            return $target;
        } catch (QueryException $error) { throw $error; }
        catch (Throwable) { throw new DeliveryException('changed'); }
    }

    public function redemption(TestDeliveryAuthorization $authorization, array $source, array $target, string $publicId, CarbonImmutable $at): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_delivery_redemption', 'redemption_id' => $publicId,
            'authorization_id' => $authorization->public_id, 'authorization_evidence_hash' => $authorization->evidence_hash,
            'activation_id' => $source['activation']->public_id, 'activation_evidence_hash' => $source['activation']->evidence_hash,
            'snapshot_hash' => $source['snapshot_hash'], 'control_version' => $authorization->control_version,
            'target_hash' => CanonicalJson::hash($target), 'content_hash' => $target['file']['sha256'],
            'size_bytes' => $target['file']['size_bytes'], 'redeemed_at' => $at->toIso8601ZuluString()];
    }

    public function verifyRedemption(TestDeliveryRedemption $redemption, TestDeliveryAuthorization $authorization, array $source, array $target): void
    {
        $decoded = $this->decode($redemption);
        if ($redemption->test_delivery_authorization_id !== $authorization->id || $redemption->control_version !== $authorization->control_version
            || $redemption->content_hash !== $target['file']['sha256'] || $redemption->size_bytes !== $target['file']['size_bytes']
            || ! OrderRequest::uuid($redemption->public_id) || $redemption->redeemed_at->micro !== 0
            || $redemption->redeemed_at->lessThan($authorization->issued_at) || ! $redemption->redeemed_at->lessThan($authorization->expires_at)
            || CanonicalJson::encode($decoded) !== CanonicalJson::encode($this->redemption($authorization, $source, $target, $redemption->public_id, $redemption->redeemed_at))) {
            throw new DeliveryException('changed');
        }
    }

    public function encrypt(array $payload): array { return app(ActivationEvidence::class)->encrypt($payload); }

    private function decode(TestDeliveryAuthorization|TestDeliveryRedemption $record): array
    {
        try {
            if ($record->canonicalization_version !== CanonicalJson::VERSION
                || strlen($record->evidence_ciphertext) > ActivationEvidence::MAX_CIPHERTEXT_BYTES
                || ! hash_equals($record->evidence_hash, hash('sha256', $record->evidence_ciphertext))) { throw new DeliveryException('changed'); }
            $canonical = Crypt::decryptString($record->evidence_ciphertext);
            if (strlen($canonical) > ActivationEvidence::MAX_BYTES) { throw new DeliveryException('changed'); }
            $decoded = json_decode($canonical, true, 128, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || CanonicalJson::encode($decoded) !== $canonical) { throw new DeliveryException('changed'); }
            return $decoded;
        } catch (Throwable) { throw new DeliveryException('changed'); }
    }
}
