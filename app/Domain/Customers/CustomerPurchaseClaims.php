<?php

namespace App\Domain\Customers;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\Models\CustomerPurchaseChallenge;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/** One explicit original-browser proof saves one paid test purchase. Original ownership and rights never move. */
final class CustomerPurchaseClaims
{
    public function stage(string $orderId, #[SensitiveParameter] string $owner): array
    {
        $this->outside();
        if (! OrderRequest::uuid($orderId) || ! preg_match('/\A[a-f0-9]{64}\z/D', $owner)) {
            throw new CustomerAccessException;
        }

        return DB::transaction(function () use ($orderId, $owner): array {
            $order = Order::where('public_id', $orderId)->lockForUpdate()->first();
            if (! $order || ! hash_equals($order->owner_key, $owner) || CustomerAccount::where('owner_key', $owner)->exists()
                || CustomerPurchaseClaim::where('order_id', $order->id)->exists()) {
                throw new CustomerAccessException;
            }
            $this->paid($order);
            $at = now()->toImmutable()->utc()->startOfSecond();
            // The order mutex bounds durable challenges across browser sessions and retry keys.
            if (CustomerPurchaseChallenge::where('order_id', $order->id)->where('created_at', '>', $at->subHour())->count() >= 4) {
                throw new CustomerAccessException;
            }
            $proof = bin2hex(random_bytes(32));
            $challenge = CustomerPurchaseChallenge::create(['public_id' => (string) Str::uuid(), 'order_id' => $order->id,
                'owner_key' => $owner, 'order_hash' => $order->payload_hash, 'proof_hash' => hash('sha256', $proof),
                'policy_version' => CustomerPurchaseClaimPolicy::VERSION, 'created_at' => $at,
                'expires_at' => $at->addSeconds(CustomerPurchaseClaimPolicy::TTL_SECONDS)]);
            app(CustomerPurchaseClaimPolicy::class)->requireEnabled();

            return ['id' => $challenge->public_id, 'proof' => $proof, 'orderId' => $orderId];
        }, 5);
    }

    /** The server session alone retains this bearer proof; no URL, JSON projection, cookie or local storage receives it. */
    public function pending(#[SensitiveParameter] array $marker): CustomerPurchaseChallenge
    {
        app(CustomerPurchaseClaimPolicy::class)->requireEnabled();
        if (array_diff(array_keys($marker), ['id', 'proof', 'orderId', 'accountId', 'accessVersion', 'stamp'])
            || ! is_string($marker['id'] ?? null) || ! is_string($marker['orderId'] ?? null)
            || ! OrderRequest::uuid($marker['id']) || ! OrderRequest::uuid($marker['orderId'])
            || ! is_string($marker['proof'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $marker['proof'])) {
            throw new CustomerAccessException;
        }
        $challenge = CustomerPurchaseChallenge::where('public_id', $marker['id'])->first();
        if (! $challenge || ! hash_equals($challenge->public_id, $marker['id'])
            || ! hash_equals($challenge->proof_hash, hash('sha256', $marker['proof']))
            || $challenge->policy_version !== CustomerPurchaseClaimPolicy::VERSION
            || $challenge->created_at->isFuture() || $challenge->expires_at->lessThanOrEqualTo(now())
            || ! $challenge->expires_at->equalTo($challenge->created_at->addSeconds(CustomerPurchaseClaimPolicy::TTL_SECONDS))) {
            throw new CustomerAccessException;
        }
        $order = Order::find($challenge->order_id);
        if (! $order || $order->public_id !== $marker['orderId'] || $order->owner_key !== $challenge->owner_key
            || $order->payload_hash !== $challenge->order_hash) {
            throw new CustomerAccessException;
        }

        return $challenge;
    }

    public function bind(#[SensitiveParameter] array $marker, CustomerPrincipal $principal): array
    {
        $this->pending($marker);
        app(CustomerAccess::class)->current($principal);
        if (isset($marker['accountId'])) {
            $this->binding($marker, $principal);
        }

        return $marker + ['accountId' => $principal->accountId, 'accessVersion' => $principal->accessVersion, 'stamp' => $principal->credentialStamp];
    }

    public function view(#[SensitiveParameter] array $marker, CustomerPrincipal $principal): array
    {
        $this->binding($marker, $principal);
        $challenge = $this->pending($marker);
        $claim = CustomerPurchaseClaim::where('order_id', $challenge->order_id)->first();
        if ($claim) {
            $this->retry($claim, $challenge, $principal);
        }

        return ['orderId' => $marker['orderId'], 'expiresAt' => $challenge->expires_at->toIso8601ZuluString(), 'saved' => $claim !== null];
    }

    public function complete(#[SensitiveParameter] array $marker, string $orderId, CustomerPrincipal $principal, User $actor): CustomerPurchaseClaim
    {
        $this->outside();
        $this->binding($marker, $principal);
        $locator = $this->pending($marker);
        if ($marker['orderId'] !== $orderId) {
            throw new CustomerAccessException;
        }

        return DB::transaction(function () use ($marker, $locator, $principal, $actor): CustomerPurchaseClaim {
            // All customer mutation paths fence user -> account before order -> challenge. No external I/O.
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
            $order = Order::whereKey($locator->order_id)->lockForUpdate()->firstOrFail();
            CustomerPurchaseChallenge::whereKey($locator->id)->lockForUpdate()->firstOrFail();
            $challenge = $this->pending($marker);
            $this->paid($order);
            if (CustomerAccount::where('owner_key', $order->owner_key)->exists()) {
                throw new CustomerAccessException;
            }
            $prior = CustomerPurchaseClaim::where('order_id', $order->id)->lockForUpdate()->first();
            if ($prior) {
                $this->retry($prior, $challenge, $principal);

                return $prior;
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            $columns = ['public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'challenge_id' => $challenge->id,
                'account_id' => $principal->accountId, 'claimed_at' => $at];
            $capture = $this->capture($columns, $challenge, $order, $principal);
            $ciphertext = Crypt::encryptString(CanonicalJson::encode($capture));
            $claim = CustomerPurchaseClaim::create($columns + ['evidence_ciphertext' => $ciphertext, 'evidence_hash' => hash('sha256', $ciphertext)]);
            AuditEvent::recordAttributed('customer.test_purchase.saved', $claim,
                ['claim_public_id' => $claim->public_id, 'order_public_id' => $order->public_id, 'test_only' => true], $principal->userId);
            $this->verify($claim, $order, $principal);
            $this->pending($marker); // Audit observers cannot extend expiry or bypass a gate withdrawn at commit.
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);

            return $claim;
        }, 5);
    }

    public function verify(CustomerPurchaseClaim $claim, Order $order, CustomerPrincipal $principal): array
    {
        app(CustomerPurchaseClaimPolicy::class)->requireEnabled();
        app(CustomerAccess::class)->current($principal);
        $challenge = CustomerPurchaseChallenge::find($claim->challenge_id);
        if (! $challenge || $challenge->policy_version !== CustomerPurchaseClaimPolicy::VERSION
            || ! $challenge->expires_at->equalTo($challenge->created_at->addSeconds(CustomerPurchaseClaimPolicy::TTL_SECONDS))
            || $challenge->created_at->micro !== 0 || $challenge->expires_at->micro !== 0
            || ! OrderRequest::uuid($challenge->public_id) || ! preg_match('/\A[a-f0-9]{64}\z/D', $challenge->proof_hash)
            || $claim->order_id !== $order->id || $claim->account_id !== $principal->accountId
            || $challenge->order_id !== $order->id || $challenge->owner_key !== $order->owner_key
            || $challenge->order_hash !== $order->payload_hash || ! OrderRequest::uuid($claim->public_id)
            || $claim->claimed_at->lessThan($challenge->created_at) || ! $claim->claimed_at->lessThan($challenge->expires_at)
            || $claim->claimed_at->isFuture() || $claim->claimed_at->micro !== 0
            || strlen($claim->evidence_ciphertext) > 16384 || ! hash_equals($claim->evidence_hash, hash('sha256', $claim->evidence_ciphertext))) {
            throw new CustomerAccessException;
        }
        try {
            $canonical = Crypt::decryptString($claim->evidence_ciphertext);
            if (strlen($canonical) > 8192) {
                throw new CustomerAccessException;
            }
            $saved = json_decode($canonical, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($saved) || ! is_int($saved['access_version'] ?? null) || $saved['access_version'] < 1
                || ! is_string($saved['credential_stamp'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $saved['credential_stamp'])) {
                throw new CustomerAccessException;
            }
            $original = new CustomerPrincipal($principal->accountId, $principal->userId, $principal->ownerKey, $saved['access_version'], $saved['credential_stamp']);
            if (! hash_equals($canonical, CanonicalJson::encode($this->capture($claim->only(['public_id', 'order_id', 'challenge_id', 'account_id', 'claimed_at']), $challenge, $order, $original)))) {
                throw new CustomerAccessException;
            }
            $this->paid($order);
            app(CustomerPurchaseClaimPolicy::class)->requireEnabled();
            app(CustomerAccess::class)->current($principal);

            return $saved;
        } catch (QueryException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CustomerAccessException;
        }
    }

    private function capture(array $columns, CustomerPurchaseChallenge $challenge, Order $order, CustomerPrincipal $principal): array
    {
        return ['schema_version' => 1, 'purpose' => CustomerPurchaseClaimPolicy::VERSION,
            'claim_id' => $columns['public_id'], 'order_id' => $order->public_id, 'order_hash' => $order->payload_hash,
            'original_owner' => $order->owner_key, 'challenge_id' => $challenge->public_id, 'proof_hash' => $challenge->proof_hash,
            'challenge_policy' => $challenge->policy_version, 'challenge_created_at' => $challenge->created_at->toIso8601ZuluString(),
            'challenge_expires_at' => $challenge->expires_at->toIso8601ZuluString(),
            'account_id' => $principal->accountId, 'user_id' => $principal->userId, 'account_owner' => $principal->ownerKey,
            'access_version' => $principal->accessVersion, 'credential_stamp' => $principal->credentialStamp,
            'claimed_at' => $columns['claimed_at']->toIso8601ZuluString()];
    }

    private function retry(CustomerPurchaseClaim $claim, CustomerPurchaseChallenge $challenge, CustomerPrincipal $principal): void
    {
        $saved = $this->verify($claim, Order::findOrFail($challenge->order_id), $principal);
        if ($claim->challenge_id !== $challenge->id || $saved['access_version'] !== $principal->accessVersion
            || ! hash_equals($saved['credential_stamp'], $principal->credentialStamp)) {
            throw new CustomerAccessException;
        }
    }

    private function binding(#[SensitiveParameter] array $marker, CustomerPrincipal $principal): void
    {
        app(CustomerAccess::class)->current($principal);
        if (($marker['accountId'] ?? null) !== $principal->accountId || ($marker['accessVersion'] ?? null) !== $principal->accessVersion
            || ! is_string($marker['stamp'] ?? null) || ! hash_equals($marker['stamp'], $principal->credentialStamp)) {
            throw new CustomerAccessException;
        }
    }

    private function paid(Order $order): void
    {
        $state = app(ReadOrder::class)->present($order);
        if ($state['paymentStatus'] !== 'verified' || $state['finalizationStatus'] !== 'paid' || $state['contractStatus'] !== 'issued') {
            throw new CustomerAccessException;
        }
    }

    private function outside(): void
    {
        app(CustomerPurchaseClaimPolicy::class)->requireEnabled();
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Purchase claims require their own transaction.');
        }
    }
}
