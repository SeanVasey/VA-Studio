<?php

namespace App\Domain\Customers\ProductionFeatures\Preferences;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentEvent;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentPolicySnapshot;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentState;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureShape;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Explicit current-owner capture. Neither an import promotion nor a provider delivery claim. */
final class ProductionConsentPreferences
{
    public function read(ProductionAccountFeatureIdentity $identity): array
    {
        return $this->run($identity);
    }

    public function initialize(ProductionAccountFeatureIdentity $identity): array
    {
        return $this->run($identity, initialize: true);
    }

    public function change(ProductionAccountFeatureIdentity $identity, array $command): array
    {
        $this->command($command);

        return $this->run($identity, $command);
    }

    private function run(ProductionAccountFeatureIdentity $identity, ?array $command = null, bool $initialize = false): array
    {
        if ($identity->feature !== 'consent_preferences') {
            throw new IdentityException;
        }

        return (new ProductionFeatureOperation)->run($identity, function (ProductionFeatureContext $context) use ($command, $initialize): array {
            $source = $context->configuration();
            $purpose = ProductionConsentPurposePolicy::capture($source);
            $context->guard(fn () => ProductionConsentPurposePolicy::requireCurrent($purpose, $source));
            $binding = $context->binding();
            if ($binding === null && ! $initialize) {
                if ($command !== null) {
                    throw new ProductionFeatureException(409);
                }

                return ['initialized' => false];
            }
            if ($binding === null) {
                $binding = $context->createBinding(now()->utc()->format('Y-m-d H:i:s'));
            }
            $bindingId = (int) $binding['row']['id'];
            $where = ['binding_id' => $bindingId, 'purpose' => ConsentPolicy::PURPOSE];
            $states = $context->observe('production_consent_states', $where);
            $events = $context->observe('production_consent_events', $where, 2, true);
            $state = $states[0] ?? null;
            if (count($states) > 1) {
                throw new ProductionFeatureException;
            }
            $policies = [];
            foreach ($events as $event) {
                $this->event($event, $binding, $context);
                if ($event['policy_id'] !== null) {
                    $this->loadPolicy($context, (int) $event['policy_id'], $policies);
                }
            }
            $revision = $state === null ? 0 : (int) $state['revision'];
            if (($state === null && $events !== []) || ($state !== null && ($events === [] || $revision < 1 || $revision > ConsentPolicy::MAX_REVISION
                || (int) $state['event_id'] !== (int) $events[0]['id'] || (int) $events[0]['revision'] !== $revision
                || ! ProductionFeatureShape::timestamp($state['created_at']) || ! ProductionFeatureShape::timestamp($state['updated_at'])
                || $state['created_at'] > $state['updated_at'] || $state['updated_at'] !== $events[0]['created_at']))
                || ($revision === 1 && count($events) !== 1) || ($revision > 1 && (count($events) !== 2
                    || (int) $events[1]['revision'] !== $revision - 1 || $events[0]['created_at'] < $events[1]['created_at']))) {
                throw new ProductionFeatureException;
            }
            if ($initialize && $revision !== 0) {
                throw new ProductionFeatureException(409);
            }
            $withdrawal = null;
            if ($state !== null && $state['withdrawal_event_id'] !== null) {
                $rows = $context->observe('production_consent_events', ['id' => (int) $state['withdrawal_event_id']]);
                if (count($rows) !== 1) {
                    throw new ProductionFeatureException;
                }
                $withdrawal = $rows[0];
                $this->event($withdrawal, $binding, $context);
                if ($withdrawal['status'] !== 'withdrawn' || (int) $withdrawal['revision'] > $revision || $withdrawal['created_at'] > $state['updated_at']) {
                    throw new ProductionFeatureException;
                }
            }
            if ($events !== [] && $events[0]['status'] === 'withdrawn'
                && ($withdrawal === null || (int) $withdrawal['id'] !== (int) $events[0]['id'])) {
                throw new ProductionFeatureException;
            }
            $configured = $purpose['configured'];
            $configuredRow = null;
            if ($configured !== null) {
                $rows = $context->observe('production_consent_policies', ['purpose' => ConsentPolicy::PURPOSE, 'version' => $configured['version']]);
                if (count($rows) > 1) {
                    throw new ProductionFeatureException;
                }
                $configuredRow = $rows[0] ?? null;
                if ($configuredRow !== null) {
                    $this->policy($configuredRow);
                    $policies[(int) $configuredRow['id']] = $configuredRow;
                }
            }
            $collision = $configuredRow !== null && ! $this->matches($configuredRow, $configured);
            $recipient = IdentityPolicy::email($context->authority()['identity']['user']['email']);
            $recipientHash = ProductionConsentRecords::recipientHash($binding, $recipient, $context->configuration());
            if ($command !== null) {
                if ($command['version'] !== $revision || $revision >= ConsentPolicy::MAX_REVISION) {
                    throw new ConsentException(409);
                }
                $grant = $command['action'] === 'grant-consent';
                if ($grant && (! $purpose['grantsEnabled'] || $configured === null
                    || $command['noticeVersion'] !== $configured['version'] || ! hash_equals($configured['notice_hash'], $command['noticeHash']))) {
                    throw new ConsentException;
                }
                if ($grant && $collision) {
                    throw new ProductionFeatureException;
                }
                $at = now()->utc()->format('Y-m-d H:i:s');
                if ($at < $binding['row']['created_at'] || ($events !== [] && $at < $events[0]['created_at'])) {
                    throw new ProductionFeatureException;
                }
                if ($grant && $configuredRow === null) {
                    $attributes = $configured + ['created_at' => $at];
                    $model = ProductionConsentPolicySnapshot::create($attributes);
                    $context->expected($model->getRawOriginal(), $attributes);
                    $rows = $context->observe('production_consent_policies', ['purpose' => ConsentPolicy::PURPOSE, 'version' => $configured['version']]);
                    if (count($rows) !== 1 || (int) $rows[0]['id'] !== (int) $model->id || ! $this->matches($rows[0], $configured)) {
                        throw new ProductionFeatureException;
                    }
                    $configuredRow = $rows[0];
                    $policies[(int) $configuredRow['id']] = $configuredRow;
                }
                $id = (string) Str::uuid();
                $capture = ['schema' => 1, 'featureBinding' => $binding['binding'], 'purpose' => ConsentPolicy::PURPOSE,
                    'revision' => $revision + 1, 'eventId' => $id, 'email' => $recipient];
                $attributes = ['public_id' => $id, 'binding_id' => $bindingId, 'purpose' => ConsentPolicy::PURPOSE,
                    'revision' => ++$revision, 'status' => $grant ? 'granted' : 'withdrawn', 'policy_id' => $grant ? (int) $configuredRow['id'] : ($events[0]['policy_id'] ?? null),
                    'source' => 'first_party_customer', 'affirmative' => $grant ? 1 : 0, 'recipient_hmac' => $recipientHash,
                    'recipient_ciphertext' => Crypt::encryptString(CanonicalJson::encode($capture)), 'created_at' => $at];
                if (strlen($attributes['recipient_ciphertext']) > 8192) {
                    throw new ProductionFeatureException;
                }
                $eventModel = ProductionConsentEvent::create($attributes);
                $context->expected($eventModel->getRawOriginal(), $attributes);
                $event = $eventModel->getRawOriginal();
                $this->event($event, $binding, $context);
                $stateAttributes = ['binding_id' => $bindingId, 'purpose' => ConsentPolicy::PURPOSE, 'revision' => $revision,
                    'event_id' => (int) $eventModel->id, 'withdrawal_event_id' => $grant ? ($state['withdrawal_event_id'] ?? null) : (int) $eventModel->id,
                    'created_at' => $state['created_at'] ?? $at, 'updated_at' => $at];
                $stateModel = $state === null ? new ProductionConsentState : ProductionConsentState::findOrFail($state['id']);
                $stateModel->fill($stateAttributes)->save();
                $context->expected($stateModel->getRawOriginal(), $stateAttributes);
                $states = $context->observe('production_consent_states', $where);
                if (count($states) !== 1) {
                    throw new ProductionFeatureException;
                }
                $context->expected($states[0], $stateAttributes);
                $state = $states[0];
                $events = $context->observe('production_consent_events', $where, 2, true);
                if ($events === [] || (int) $events[0]['id'] !== (int) $eventModel->id) {
                    throw new ProductionFeatureException;
                }
                $context->expected($events[0], $attributes);
                if (! $grant) {
                    $withdrawal = $events[0];
                    $context->observe('production_consent_events', ['id' => (int) $withdrawal['id']]);
                }
            }
            $status = $events[0]['status'] ?? 'unknown';
            if ($events !== [] && ! ProductionConsentRecords::recipientMatches($binding, $recipient, $events[0]['recipient_hmac'], $context->configuration())) {
                $status = 'unknown';
            }
            if ($status === 'granted' && ($configured === null || $collision || ! $purpose['grantsEnabled']
                || ! $this->matches($policies[(int) $events[0]['policy_id']] ?? [], $configured))) {
                $status = 'unknown';
            }
            $projection = ['schema' => 1, 'purposes' => [['purpose' => ConsentPolicy::PURPOSE, 'version' => $revision, 'status' => $status,
                'notice' => $configured ? ['version' => $configured['version'], 'hash' => $configured['notice_hash'], 'text' => $configured['notice']] : null,
                'canGrant' => $purpose['grantsEnabled'] && $configured !== null && ! $collision,
                'suppression' => ['status' => $withdrawal !== null && ProductionConsentRecords::recipientMatches($binding, $recipient, $withdrawal['recipient_hmac'], $context->configuration()) ? 'pending' : 'not_requested']]]];

            return ['initialized' => true, 'preferences' => $projection];
        });
    }

    private function command(array $command): void
    {
        $grant = ($command['action'] ?? null) === 'grant-consent';
        $keys = $grant ? ['action', 'version', 'purpose', 'noticeVersion', 'noticeHash', 'affirmative'] : ['action', 'version', 'purpose'];
        if (! ConsentPolicy::keys($command, $keys) || ! in_array($command['action'], ['grant-consent', 'withdraw-consent'], true)
            || $command['purpose'] !== ConsentPolicy::PURPOSE || ! is_int($command['version']) || $command['version'] < 0 || $command['version'] > ConsentPolicy::MAX_REVISION
            || ($grant && ($command['affirmative'] !== true || ! ConsentPolicy::version($command['noticeVersion'])
                || ! is_string($command['noticeHash']) || preg_match('/\A[a-f0-9]{64}\z/D', $command['noticeHash']) !== 1))) {
            throw new ConsentException;
        }
    }

    private function loadPolicy(ProductionFeatureContext $context, int $id, array &$policies): void
    {
        if (isset($policies[$id])) {
            return;
        }
        $rows = $context->observe('production_consent_policies', ['id' => $id]);
        if (count($rows) !== 1) {
            throw new ProductionFeatureException;
        }
        $this->policy($rows[0]);
        $policies[$id] = $rows[0];
    }

    private function policy(array $row): void
    {
        ProductionConsentRecords::policy($row);
    }

    private function matches(array $row, ?array $configured): bool
    {
        if ($configured === null) {
            return false;
        }
        foreach ($configured as $key => $value) {
            if (($row[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function event(array $event, array $binding, ProductionFeatureContext $context): void
    {
        ProductionConsentRecords::event($event, $binding, $context->configuration());
    }
}
