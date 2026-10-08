<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawalReader;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Support\CanonicalJson;
use Illuminate\Support\Str;
use Throwable;

/** Explicit server caller only: no route, queue dispatch, address input, unsuppress or automatic retry.
 * Every durable write happens inside a sealed 253 consent operation and commits before any provider call.
 */
final class ProductionSuppressionIntents
{
    private ProductionSuppressionProvider $provider;

    public function __construct(?ProductionSuppressionProvider $provider = null)
    {
        $this->provider = $provider ?? new UnboundProductionSuppressionProvider;
    }

    /** Record the durable target/intent (and, when bound, the single attempt) for the current withdrawal,
     * commit, then invoke the provider at most once. The suppress outcome never confirms anything.
     */
    public function request(ProductionAccountFeatureIdentity $identity, int $expectedConsentVersion): array
    {
        $this->entry($identity);
        if ($expectedConsentVersion < 0 || $expectedConsentVersion > ConsentPolicy::MAX_REVISION) {
            throw new ConsentException;
        }
        $result = (new ProductionFeatureOperation)->run($identity, function (ProductionFeatureContext $context) use ($expectedConsentVersion): array {
            [$suppression, $rows] = $this->open($context);
            $withdrawal = (new ProductionConsentWithdrawalReader)->read($context, $expectedConsentVersion);
            if ($withdrawal === null) {
                // No withdrawal, or a different/unknown current recipient: nothing to suppress for this address.
                return ['status' => 'not_requested', 'claim' => null];
            }
            $snapshot = $withdrawal->serverSnapshot();
            $binding = $context->binding();
            if ($binding === null || (int) $binding['row']['id'] !== $snapshot['bindingId'] || $binding['binding'] !== $snapshot['originalFeatureBinding']) {
                throw new ProductionFeatureException;
            }
            $records = new ProductionSuppressionRecords($context, $rows, $binding);
            $bound = $this->bound($context, $suppression);
            $targets = $rows->observe('production_suppression_targets', ['binding_id' => (int) $binding['row']['id']], ProductionSuppressionRows::LIMIT);
            $target = null;
            foreach ($targets as $row) {
                if (hash_equals($row['recipient_hmac'], $snapshot['recipientHmac'])) {
                    $target = $row;
                }
            }
            $at = $this->at([$snapshot['capturedAt'], ...array_column($targets, 'created_at')]);
            if ($target === null) {
                if (count($targets) >= ProductionSuppressionRows::LIMIT) {
                    throw new ProductionFeatureException;
                }
                $id = (string) Str::uuid();
                $attributes = ['public_id' => $id, 'binding_id' => (int) $binding['row']['id'], 'purpose' => ConsentPolicy::PURPOSE,
                    'recipient_hmac' => $snapshot['recipientHmac'], 'recipient_ciphertext' => $records->encrypt(['schema' => 1, 'targetId' => $id,
                        'featureBinding' => $binding['binding'], 'purpose' => ConsentPolicy::PURPOSE, 'withdrawalEventId' => $snapshot['withdrawalPublicId'],
                        'email' => $snapshot['recipient']]), 'withdrawal_event_id' => $snapshot['withdrawalEventId'], 'created_at' => $at];
                $rows->insert('production_suppression_targets', $attributes);
                $created = $rows->observe('production_suppression_targets', ['public_id' => $id]);
                $targets = $rows->observe('production_suppression_targets', ['binding_id' => (int) $binding['row']['id']], ProductionSuppressionRows::LIMIT);
                if (count($created) !== 1 || ! in_array($created[0], $targets, true)) {
                    throw new ProductionFeatureException;
                }
                $context->expected($created[0], $attributes);
                $target = $created[0];
            }
            $graph = $records->graph($target);
            $intents = $rows->observe('production_suppression_intents', ['withdrawal_event_id' => $snapshot['withdrawalEventId']]);
            if ($intents === []) {
                $attributes = ['public_id' => (string) Str::uuid(), 'target_id' => (int) $target['id'], 'withdrawal_event_id' => $snapshot['withdrawalEventId'], 'created_at' => $at];
                $rows->insert('production_suppression_intents', $attributes);
                $intents = $rows->observe('production_suppression_intents', ['withdrawal_event_id' => $snapshot['withdrawalEventId']]);
                if (count($intents) !== 1) {
                    throw new ProductionFeatureException;
                }
                $context->expected($intents[0], $attributes);
            }
            $records->intent($intents[0], $target);
            $claim = null;
            // One attempt per target, ever: an existing attempt (unknown or confirmed) is never resent.
            if ($graph['attempt'] === null && $bound) {
                $operation = (string) Str::uuid();
                $attributes = ['public_id' => $operation, 'target_id' => (int) $target['id'], 'intent_id' => (int) $intents[0]['id'],
                    'provider_hash' => $suppression['hash'], 'provider_ciphertext' => $records->encrypt($suppression['provider']),
                    'request_hash' => $records->requestHash($target, $operation, $suppression['hash']), 'created_at' => $at];
                $rows->insert('production_suppression_attempts', $attributes);
                $graph = $records->graph($target);
                if ($graph['attempt'] === null) {
                    throw new ProductionFeatureException;
                }
                $context->expected($graph['attempt'], $attributes);
                $claim = $graph['request'];
            }

            return ['status' => $graph['status'], 'claim' => $claim];
        });
        if ($result['claim'] !== null) {
            // The attempt is durable and proven. Exactly one transport invocation; any outcome stays unknown.
            try {
                $this->provider->suppress($result['claim']);
            } catch (Throwable) {
                // Ambiguous by design: reconcile() may only inspect, never resend.
            }
        }

        return ['status' => $result['status']];
    }

    /** Inspect-only reconciliation of the binding's oldest unconfirmed attempt, using its captured target.
     * Confirmation is stored only from an exact positive scoped inspect receipt.
     */
    public function reconcile(ProductionAccountFeatureIdentity $identity): array
    {
        $this->entry($identity);
        $result = (new ProductionFeatureOperation)->run($identity, function (ProductionFeatureContext $context): array {
            [$suppression, $rows, $records, $graphs] = $this->binding($context);
            $bound = $this->bound($context, $suppression);
            foreach ($graphs as $graph) {
                if ($graph['status'] === 'unknown') {
                    $claim = $bound && hash_equals($suppression['hash'], $graph['attempt']['provider_hash']) ? $graph['request'] : null;

                    return ['status' => $this->summary($graphs), 'claim' => $claim];
                }
            }

            return ['status' => $this->summary($graphs), 'claim' => null];
        });
        if ($result['claim'] === null) {
            return ['status' => $result['status']];
        }
        $claim = $result['claim'];
        try {
            $receipt = $this->provider->inspect($claim);
        } catch (Throwable) {
            $receipt = null;
        }
        if (! $receipt instanceof ProductionSuppressionReceipt || ! $receipt->confirms($claim)) {
            return ['status' => $result['status']];
        }

        return (new ProductionFeatureOperation)->run($identity, function (ProductionFeatureContext $context) use ($claim, $receipt): array {
            [$suppression, $rows, $records, $graphs] = $this->binding($context);
            if (! $this->bound($context, $suppression) || ! hash_equals($suppression['hash'], $claim->providerHash())) {
                throw new ProductionFeatureException;
            }
            $selected = null;
            foreach ($graphs as $index => $graph) {
                if ($graph['target']['public_id'] === $claim->targetId()) {
                    $selected = $index;
                }
            }
            $graph = $selected === null ? null : $graphs[$selected];
            if ($graph === null || $graph['attempt'] === null || $graph['attempt']['public_id'] !== $claim->operationId()
                || ! hash_equals($graph['request']->requestHash(), $claim->requestHash()) || $graph['request']->recipient() !== $claim->recipient()) {
                throw new ProductionFeatureException;
            }
            if ($graph['confirmation'] === null) {
                $plain = $receipt->capture();
                $attributes = ['attempt_id' => (int) $graph['attempt']['id'], 'request_hash' => $claim->requestHash(), 'receipt_hash' => CanonicalJson::hash($plain),
                    'receipt_ciphertext' => $records->encrypt($plain), 'created_at' => $this->at([$graph['attempt']['created_at']])];
                $rows->insert('production_suppression_confirmations', $attributes);
                $graphs[$selected] = $records->graph($graph['target']);
                if ($graphs[$selected]['confirmation'] === null) {
                    throw new ProductionFeatureException;
                }
                $context->expected($graphs[$selected]['confirmation'], $attributes);
            }

            return ['status' => $this->summary($graphs)];
        });
    }

    /** Server read of the binding's suppression status. Never an HTTP projection of private rows. */
    public function status(ProductionAccountFeatureIdentity $identity): array
    {
        $this->entry($identity);

        return (new ProductionFeatureOperation)->run($identity, fn (ProductionFeatureContext $context): array => ['status' => $this->summary($this->binding($context)[3])]);
    }

    private function entry(ProductionAccountFeatureIdentity $identity): void
    {
        if ($identity->feature !== 'consent_preferences') {
            throw new IdentityException;
        }
        // Default-off: the shipped parent refuses before the sealed operation performs any lookup.
        // The operation re-admits and re-captures the raw parent inside its held frame.
        if (ProductionSuppressionConfiguration::capture(new ProductionFeatureConfiguration)['enabled'] !== true) {
            throw new ProductionFeatureException;
        }
    }

    /** Raw configuration admission before any lookup; default-off refuses before reading anything. */
    private function open(ProductionFeatureContext $context): array
    {
        ProductionFeatureOperation::assertLive($context);
        $context->admitConfiguration();
        $features = $context->configuration();
        $suppression = ProductionSuppressionConfiguration::capture($features);
        if ($suppression['enabled'] !== true) {
            throw new ProductionFeatureException;
        }
        $context->guard(fn () => ProductionSuppressionConfiguration::requireCurrent($suppression, $features));

        return [$suppression, new ProductionSuppressionRows($context)];
    }

    private function binding(ProductionFeatureContext $context): array
    {
        [$suppression, $rows] = $this->open($context);
        $binding = $context->binding();
        if ($binding === null) {
            throw new ProductionFeatureException(409);
        }
        $records = new ProductionSuppressionRecords($context, $rows, $binding);
        $graphs = [];
        foreach ($rows->observe('production_suppression_targets', ['binding_id' => (int) $binding['row']['id']], ProductionSuppressionRows::LIMIT) as $target) {
            $graphs[] = $records->graph($target);
        }

        return [$suppression, $rows, $records, $graphs];
    }

    /** Adapter binding is a callbackful source: captured once, then fenced before the final closure. */
    private function bound(ProductionFeatureContext $context, array $suppression): bool
    {
        try {
            $bound = $this->provider->boundTo();
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
        $context->fence(function () use ($bound): void {
            try {
                $current = $this->provider->boundTo();
            } catch (Throwable) {
                throw new ProductionFeatureException;
            }
            if ($current !== $bound) {
                throw new ProductionFeatureException;
            }
        });

        return $suppression['hash'] !== null && is_string($bound) && hash_equals($suppression['hash'], $bound);
    }

    private function summary(array $graphs): string
    {
        $statuses = array_column($graphs, 'status');
        foreach (['unknown', 'pending', 'confirmed'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return 'not_requested';
    }

    private function at(array $previous): string
    {
        $at = now()->utc()->format('Y-m-d H:i:s');
        foreach ($previous as $time) {
            if ($at < $time) {
                throw new ProductionFeatureException;
            }
        }

        return $at;
    }
}
