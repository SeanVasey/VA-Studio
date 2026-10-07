<?php

namespace App\Domain\Customers\ProductionFeatures\Preferences;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureShape;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use JsonSerializable;
use LogicException;

/** Sealed server capture, usable only inside the caller's still-held operation and final fences. */
final readonly class ProductionConsentWithdrawal implements JsonSerializable
{
    private function __construct(private array $snapshot) {}

    public static function capture(ProductionFeatureContext $context, int $expectedVersion): ?self
    {
        if ($context->identity->feature !== 'consent_preferences') {
            throw new IdentityException;
        }
        if ($expectedVersion < 0 || $expectedVersion > ConsentPolicy::MAX_REVISION) {
            throw new ConsentException;
        }
        $context->heldStorage();
        $binding = $context->binding();
        if ($binding === null) {
            throw new ProductionFeatureException(409);
        }
        $where = ['binding_id' => (int) $binding['row']['id'], 'purpose' => ConsentPolicy::PURPOSE];
        $states = $context->observe('production_consent_states', $where);
        $events = $context->observe('production_consent_events', $where, 2, true);
        $state = $states[0] ?? null;
        if (count($states) > 1 || ($state === null && $events !== [])) {
            throw new ProductionFeatureException;
        }
        $revision = $state === null ? 0 : (int) $state['revision'];
        if ($expectedVersion !== $revision) {
            throw new ConsentException(409);
        }
        if ($state === null) {
            return null;
        }
        if ($events === [] || $revision < 1 || $revision > ConsentPolicy::MAX_REVISION
            || (int) $state['event_id'] !== (int) $events[0]['id'] || (int) $events[0]['revision'] !== $revision
            || ! ProductionFeatureShape::timestamp($state['created_at']) || ! ProductionFeatureShape::timestamp($state['updated_at'])
            || $state['created_at'] > $state['updated_at'] || $state['updated_at'] !== $events[0]['created_at']
            || ($revision === 1 && count($events) !== 1) || ($revision > 1 && (count($events) !== 2
                || (int) $events[1]['revision'] !== $revision - 1 || $events[0]['created_at'] < $events[1]['created_at']))) {
            throw new ProductionFeatureException;
        }
        $configuration = $context->configuration();
        $policies = [];
        foreach ($events as $event) {
            ProductionConsentRecords::event($event, $binding, $configuration);
            self::policy($context, $event, $policies);
        }
        if ($state['withdrawal_event_id'] === null) {
            if ($events[0]['status'] === 'withdrawn') {
                throw new ProductionFeatureException;
            }

            return null;
        }
        $rows = $context->observe('production_consent_events', ['id' => (int) $state['withdrawal_event_id']]);
        if (count($rows) !== 1) {
            throw new ProductionFeatureException;
        }
        $withdrawal = $rows[0];
        $capture = ProductionConsentRecords::event($withdrawal, $binding, $configuration);
        self::policy($context, $withdrawal, $policies);
        if ($withdrawal['status'] !== 'withdrawn' || (int) $withdrawal['revision'] > $revision
            || $withdrawal['created_at'] > $state['updated_at']
            || ($events[0]['status'] === 'withdrawn' && (int) $withdrawal['id'] !== (int) $events[0]['id'])) {
            throw new ProductionFeatureException;
        }
        $context->admitConfiguration();
        $recipient = IdentityPolicy::email($context->authority()['identity']['user']['email']);
        if ($capture['email'] !== $recipient || ! hash_equals(ProductionConsentRecords::recipientHash($binding, $recipient, $configuration), $withdrawal['recipient_hmac'])) {
            return null;
        }

        return new self(['bindingId' => (int) $binding['row']['id'], 'bindingPublicId' => $binding['row']['public_id'],
            'originalFeatureBinding' => $binding['binding'], 'originalBindingHash' => $binding['row']['binding_hash'],
            'withdrawalEventId' => (int) $withdrawal['id'], 'withdrawalPublicId' => $withdrawal['public_id'],
            'withdrawalRevision' => (int) $withdrawal['revision'], 'consentVersion' => $revision,
            'recipient' => $recipient, 'recipientHmac' => $withdrawal['recipient_hmac'], 'capturedAt' => $withdrawal['created_at']]);
    }

    /** Private recipient and signed ownership evidence: never an HTTP projection. */
    public function serverSnapshot(): array
    {
        return $this->snapshot;
    }

    public function __debugInfo(): array
    {
        return ['purpose' => ConsentPolicy::PURPOSE, 'version' => $this->snapshot['consentVersion']];
    }

    public function __serialize(): never
    {
        throw new LogicException('Withdrawal evidence must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Withdrawal evidence is not an HTTP projection.');
    }

    private static function policy(ProductionFeatureContext $context, array $event, array &$seen): void
    {
        if ($event['policy_id'] === null || isset($seen[(int) $event['policy_id']])) {
            return;
        }
        $rows = $context->observe('production_consent_policies', ['id' => (int) $event['policy_id']]);
        if (count($rows) !== 1) {
            throw new ProductionFeatureException;
        }
        ProductionConsentRecords::policy($rows[0]);
        $seen[(int) $event['policy_id']] = true;
    }
}
