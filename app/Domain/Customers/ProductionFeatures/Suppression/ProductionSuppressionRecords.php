<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentRecords;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureShape;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** One retained meaning for the 254 graph. Lineage is only the 253 binding and its production withdrawal events. */
final class ProductionSuppressionRecords
{
    public function __construct(private readonly ProductionFeatureContext $context, private readonly ProductionSuppressionRows $rows,
        private readonly array $binding) {}

    /** Validated target graph: target, its capture, its newest attempt and confirmation, and the derived status. */
    public function graph(array $target): array
    {
        $capture = $this->target($target);
        $attempts = $this->rows->observe('production_suppression_attempts', ['target_id' => (int) $target['id']]);
        if ($attempts === []) {
            return ['target' => $target, 'capture' => $capture, 'attempt' => null, 'confirmation' => null, 'status' => 'pending'];
        }
        $attempt = $attempts[0];
        $request = $this->request($target, $capture, $attempt);
        $confirmations = $this->rows->observe('production_suppression_confirmations', ['attempt_id' => (int) $attempt['id']]);
        if ($confirmations !== []) {
            $this->confirmation($confirmations[0], $attempt, $request);
        }

        return ['target' => $target, 'capture' => $capture, 'attempt' => $attempt, 'request' => $request,
            'confirmation' => $confirmations[0] ?? null, 'status' => $confirmations === [] ? 'unknown' : 'confirmed'];
    }

    public function target(array $target): array
    {
        try {
            $capture = $this->plain($target['recipient_ciphertext'] ?? null);
            if (! ConsentPolicy::keys($capture, ['schema', 'targetId', 'featureBinding', 'purpose', 'withdrawalEventId', 'email'])
                || $capture['schema'] !== 1 || $capture['targetId'] !== $target['public_id'] || $capture['featureBinding'] !== $this->binding['binding']
                || $capture['purpose'] !== ConsentPolicy::PURPOSE || ! is_string($capture['email']) || IdentityPolicy::email($capture['email']) !== $capture['email']
                || (int) $target['binding_id'] !== (int) $this->binding['row']['id'] || $target['purpose'] !== ConsentPolicy::PURPOSE
                || ! Str::isUuid($target['public_id']) || ! ProductionFeatureShape::timestamp($target['created_at'])
                || ! hash_equals(ProductionConsentRecords::recipientHash($this->binding, $capture['email'], $this->context->configuration()), $target['recipient_hmac'])) {
                throw new ProductionFeatureException;
            }
            $event = $this->withdrawalEvent((int) $target['withdrawal_event_id'], $target['recipient_hmac']);
            if ($event['public_id'] !== $capture['withdrawalEventId'] || $target['created_at'] < $event['created_at']) {
                throw new ProductionFeatureException;
            }

            return $capture;
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }

    public function intent(array $intent, array $target): void
    {
        if ((int) $intent['target_id'] !== (int) $target['id'] || ! Str::isUuid($intent['public_id'])
            || ! ProductionFeatureShape::timestamp($intent['created_at']) || $intent['created_at'] < $target['created_at']) {
            throw new ProductionFeatureException;
        }
        $event = $this->withdrawalEvent((int) $intent['withdrawal_event_id'], $target['recipient_hmac']);
        if ($intent['created_at'] < $event['created_at']) {
            throw new ProductionFeatureException;
        }
    }

    public function request(array $target, array $capture, array $attempt): ProductionSuppressionRequest
    {
        try {
            $provider = $this->plain($attempt['provider_ciphertext'] ?? null);
            $intents = $this->rows->observe('production_suppression_intents', ['id' => (int) $attempt['intent_id']]);
            if ($intents === [] || ! ProductionSuppressionConfiguration::provider($provider)
                || ! hash_equals(CanonicalJson::hash($provider), $attempt['provider_hash']) || (int) $attempt['target_id'] !== (int) $target['id']
                || ! Str::isUuid($attempt['public_id']) || ! ProductionFeatureShape::timestamp($attempt['created_at']) || $attempt['created_at'] < $target['created_at']) {
                throw new ProductionFeatureException;
            }
            $this->intent($intents[0], $target);
            $hash = $this->requestHash($target, $attempt['public_id'], $attempt['provider_hash']);
            if (! hash_equals($hash, $attempt['request_hash'])) {
                throw new ProductionFeatureException;
            }

            // The captured target address, not the account's current one.
            return ProductionSuppressionRequest::fromRecords($attempt['public_id'], $target['public_id'], $capture['email'], $target['recipient_hmac'], $attempt['provider_hash'], $hash);
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }

    public function confirmation(array $confirmation, array $attempt, ProductionSuppressionRequest $request): void
    {
        try {
            $plain = $this->plain($confirmation['receipt_ciphertext'] ?? null);
            if (! ConsentPolicy::keys($plain, ['schema', 'operationId', 'requestHash', 'recipientHmac', 'providerHash', 'providerReceiptId', 'status'])
                || $plain['schema'] !== 1 || ! is_string($plain['operationId']) || ! is_string($plain['requestHash']) || ! is_string($plain['recipientHmac'])
                || ! is_string($plain['providerHash']) || ! is_string($plain['providerReceiptId']) || ! is_string($plain['status'])
                || ! (new ProductionSuppressionReceipt($plain['operationId'], $plain['requestHash'], $plain['recipientHmac'], $plain['providerHash'], $plain['providerReceiptId'], $plain['status']))->confirms($request)
                || ! hash_equals(CanonicalJson::hash($plain), $confirmation['receipt_hash']) || ! hash_equals($request->requestHash(), $confirmation['request_hash'])
                || (int) $confirmation['attempt_id'] !== (int) $attempt['id'] || ! ProductionFeatureShape::timestamp($confirmation['created_at'])
                || $confirmation['created_at'] < $attempt['created_at']) {
                throw new ProductionFeatureException;
            }
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }

    public function requestHash(array $target, string $operationId, string $providerHash): string
    {
        return CanonicalJson::hash(['schema' => 1, 'operationId' => $operationId, 'targetId' => $target['public_id'],
            'bindingPublicId' => $this->binding['row']['public_id'], 'bindingHash' => $this->binding['row']['binding_hash'],
            'purpose' => ConsentPolicy::PURPOSE, 'recipientHmac' => $target['recipient_hmac'], 'providerHash' => $providerHash]);
    }

    public function encrypt(array $plain): string
    {
        $cipher = Crypt::encryptString(CanonicalJson::encode($plain));
        $this->context->admitConfiguration();
        if (strlen($cipher) > 8192) {
            throw new ProductionFeatureException;
        }

        return $cipher;
    }

    /** Production withdrawal lineage only: the 253 event under this binding, explicitly withdrawn for this recipient. */
    private function withdrawalEvent(int $id, string $recipientHmac): array
    {
        $events = $this->context->observe('production_consent_events', ['id' => $id]);
        if (count($events) !== 1) {
            throw new ProductionFeatureException;
        }
        $event = $events[0];
        ProductionConsentRecords::event($event, $this->binding, $this->context->configuration());
        if ($event['status'] !== 'withdrawn' || (int) $event['affirmative'] !== 0 || ! hash_equals($event['recipient_hmac'], $recipientHmac)) {
            throw new ProductionFeatureException;
        }

        return $event;
    }

    private function plain(mixed $cipher): array
    {
        if (! is_string($cipher) || strlen($cipher) < 1 || strlen($cipher) > 8192) {
            throw new ProductionFeatureException;
        }
        $this->context->admitConfiguration();
        $plain = Crypt::decryptString($cipher);
        $this->context->admitConfiguration();
        $data = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || CanonicalJson::encode($data) !== $plain) {
            throw new ProductionFeatureException;
        }

        return $data;
    }
}
