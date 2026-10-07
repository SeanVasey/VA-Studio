<?php

namespace App\Domain\Customers\Preferences\Suppression;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\Preferences\ConsentEvidence;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionAttempt;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionConfirmation;
use App\Models\User;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Explicit server caller only. No queue dispatch, address input, unsuppress, or automatic retry. */
final class SuppressionDelivery
{
    private SuppressionAdapter $adapter;

    private CustomerConsentPreferences $preferences;

    public function __construct(?SuppressionAdapter $adapter = null, ?CustomerConsentPreferences $preferences = null)
    {
        $this->adapter = $adapter ?? new UnboundSuppressionAdapter;
        $this->preferences = $preferences ?? new CustomerConsentPreferences;
    }

    public function process(CustomerPrincipal $principal, User $actor, int $expectedVersion): array
    {
        $this->topLevel($expectedVersion);
        // This durable commit must precede the only possible transport invocation.
        $claim = $this->transaction(function () use ($principal, $actor, $expectedVersion): array {
            $context = $this->context($principal, $actor, $expectedVersion);
            $graph = $context['graph'];
            $claim = null;
            if ($graph['status'] === 'pending' && $context['bound']) {
                $id = (string) Str::uuid();
                $attributes = ['public_id' => $id, 'target_id' => $graph['target']['id'], 'account_access_version' => $principal->accessVersion,
                    'binding_hash' => $context['policy']['hash'], 'binding_ciphertext' => Crypt::encryptString(CanonicalJson::encode($context['policy']['binding'])),
                    'request_hash' => SuppressionOutbox::requestHash($graph['target'], $id, $principal->accessVersion, $context['policy']['hash']), 'created_at' => $this->at($graph['target']['created_at'])];
                SuppressionOutbox::cipher($attributes['binding_ciphertext']);
                $attempt = SuppressionAttempt::create($attributes);
                SuppressionOutbox::expected($attempt, $attributes);
                // Re-capture after the intended append, then fence the exact intended model bytes.
                $context['outboxProof'] = new SuppressionEvidence;
                $context['graph'] = (new SuppressionOutbox)->graph($principal, $context['recipient'], $context['outboxProof'], $context['policy']);
                if ($context['graph']['attempt']['public_id'] !== $id
                    || ConsentEvidence::normalized([array_intersect_key($context['graph']['attempt'], $attributes)]) !== ConsentEvidence::normalized([$attributes])) {
                    throw new ConsentException(503);
                }
                $claim = ['target' => $graph['target']['public_id'], 'operation' => $id, 'policy' => $context['policy']];
            }
            $this->terminal($principal, $context);

            return ['status' => $context['graph']['status'], 'claim' => $claim];
        });
        if ($claim['claim'] === null) {
            return ['status' => $claim['status']];
        }

        return $this->receipt($principal, $actor, $expectedVersion, $claim['claim'], false);
    }

    public function reconcile(CustomerPrincipal $principal, User $actor, int $expectedVersion): array
    {
        $this->topLevel($expectedVersion);

        return $this->receipt($principal, $actor, $expectedVersion, null, true);
    }

    private function receipt(CustomerPrincipal $principal, User $actor, int $version, ?array $claim, bool $inspect): array
    {
        return $this->transaction(function () use ($principal, $actor, $version, $claim, $inspect): array {
            $context = $this->context($principal, $actor, $version);
            $graph = $context['graph'];
            if ($claim !== null && ($graph['target'] === null || $graph['attempt'] === null
                || $graph['target']['public_id'] !== $claim['target'] || $graph['attempt']['public_id'] !== $claim['operation']
                || $context['policy'] !== $claim['policy'])) {
                throw new ConsentException(503);
            }
            if ($graph['status'] !== 'unknown' || ! $context['bound'] || ! hash_equals($context['policy']['hash'], $graph['attempt']['binding_hash'])) {
                $this->terminal($principal, $context);

                return ['status' => $graph['status']];
            }
            $request = (new SuppressionOutbox)->request($graph['target'], $graph['attempt']);
            // Current authority/configuration proof immediately before the transport. The locks stay held.
            $this->terminal($principal, $context);
            try {
                $receipt = $inspect ? $this->adapter->inspect($request) : $this->adapter->suppress($request);
            } catch (Throwable) {
                $receipt = null;
            }
            if ($receipt !== null && $receipt->confirms($request)) {
                $capture = ['schema' => 1, 'operationId' => $receipt->operationId, 'purpose' => ConsentPolicy::PURPOSE,
                    'recipientHmac' => $receipt->recipientHmac, 'bindingHash' => $receipt->bindingHash,
                    'requestHash' => $receipt->requestHash, 'providerReceiptId' => $receipt->providerReceiptId, 'status' => 'suppressed'];
                $attributes = ['attempt_id' => $graph['attempt']['id'], 'request_hash' => $request->requestHash,
                    'receipt_hash' => CanonicalJson::hash($capture), 'receipt_ciphertext' => Crypt::encryptString(CanonicalJson::encode($capture)), 'created_at' => $this->at($graph['attempt']['created_at'])];
                SuppressionOutbox::cipher($attributes['receipt_ciphertext']);
                $confirmation = SuppressionConfirmation::create($attributes);
                SuppressionOutbox::expected($confirmation, $attributes);
                $context['outboxProof'] = new SuppressionEvidence;
                $context['graph'] = (new SuppressionOutbox)->graph($principal, $context['recipient'], $context['outboxProof'], $context['policy']);
                if (ConsentEvidence::normalized([array_intersect_key($context['graph']['confirmation'] ?? [], $attributes)]) !== ConsentEvidence::normalized([$attributes])) {
                    throw new ConsentException(503);
                }
            }
            $this->terminal($principal, $context);

            return ['status' => $context['graph']['status']];
        });
    }

    private function context(CustomerPrincipal $principal, User $actor, int $version): array
    {
        $consentProof = new ConsentEvidence;
        $preferences = $this->preferences->read($principal, $actor);
        if ($preferences['purposes'][0]['version'] !== $version) {
            throw new ConsentException(409);
        }
        $proof = new SuppressionEvidence;
        $users = $proof->capture('users', 'id=?', [$principal->userId]);
        if (count($users) !== 1) {
            throw new ConsentException(503);
        }
        $email = $users[0]['email'];
        $recipient = CustomerIdentityPolicy::email($email);
        $states = $proof->capture('customer_consent_states', 'customer_account_id=? AND purpose=?', [$principal->accountId, ConsentPolicy::PURPOSE]);
        $events = $proof->capture('customer_consent_events', 'customer_account_id=? AND purpose=?', [$principal->accountId, ConsentPolicy::PURPOSE], 2, 'revision DESC');
        $policies = [];
        foreach ($events as $event) {
            if ($event['policy_id'] !== null && ! isset($policies[$event['policy_id']])) {
                $rows = $proof->capture('customer_consent_policies', 'id=?', [$event['policy_id']]);
                if (count($rows) !== 1) {
                    throw new ConsentException(503);
                }
                $policies[$event['policy_id']] = $rows[0];
            }
        }
        $binding = $this->binding();
        $policy = SuppressionPolicy::capture();
        $outboxProof = new SuppressionEvidence;
        $graph = (new SuppressionOutbox)->graph($principal, $recipient, $outboxProof, $policy);
        $bound = $policy['hash'] !== null && is_string($binding) && hash_equals($policy['hash'], $binding);
        $configuration = config('customer-preferences');

        return compact('consentProof', 'proof', 'outboxProof', 'email', 'recipient', 'states', 'events', 'policies', 'policy', 'graph', 'bound', 'binding', 'configuration');
    }

    private function terminal(CustomerPrincipal $principal, array $context): void
    {
        app(CustomerAccess::class)->current($principal);
        $accessPolicy = app(CustomerAccessPolicy::class);
        $binding = $this->binding();
        // All resolver/adapter hooks precede cached-source admission, then pure policy/row proof.
        $context['proof']->admit();
        $context['outboxProof']->admit();
        // No extensible/model/query callbacks after these pure checks and primary row closure.
        if ($binding !== $context['binding'] || config('customer-preferences') !== $context['configuration']) {
            throw new ConsentException(503);
        }
        SuppressionPolicy::current($context['policy']);
        $accessPolicy->requireEnabled();
        $context['consentProof']->prove($principal, $context['email'], $context['states'], $context['events'], array_values($context['policies']), null);
        $context['proof']->prove();
        $context['outboxProof']->prove();
    }

    private function binding(): ?string
    {
        try {
            return $this->adapter->boundTo();
        } catch (Throwable) {
            throw new ConsentException(503);
        }
    }

    private function topLevel(int $version): void
    {
        if ($version < 0 || $version > ConsentPolicy::MAX_REVISION) {
            throw new ConsentException;
        }
        if (DB::connection()->transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new ConsentException(503);
        }
    }

    private function transaction(Closure $callback): array
    {
        return DB::transaction(function () use ($callback): array {
            $frame = new SuppressionEvidence(true);
            try {
                $result = $callback();
                $frame->admit();

                return $result;
            } catch (Throwable $exception) {
                $frame->cleanupInterrupted();
                throw $exception;
            }
        });
    }

    private function at(string $previous): string
    {
        $at = now()->utc()->format('Y-m-d H:i:s');
        if ($at < $previous) {
            throw new ConsentException(503);
        }

        return $at;
    }
}
