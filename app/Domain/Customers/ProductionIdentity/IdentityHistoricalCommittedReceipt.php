<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use JsonSerializable;
use Throwable;

/** One original historical-prefix closure after the matching producer-observed commit. No renewed access. */
final class IdentityHistoricalCommittedReceipt implements JsonSerializable
{
    public const VERSION = 'identity-historical-committed-receipt-v1';

    private string $phase = 'held';

    private ?IdentityHistoricalCommitSeal $seal = null;

    private array $privateRaw;

    private function __construct(private readonly array $binding, private readonly CurrentRows $reader,
        private readonly array $expectedHistoricalRaw, private readonly int $originalDeadlineNs,
        private readonly IdentityOriginalCommitWitness $witness) {}

    public static function capture(array $binding, CurrentRows $reader, array $expectedHistoricalRaw,
        int $originalDeadlineNs, IdentityOriginalCommitWitness $witness): self
    {
        $witness->assertHeld();
        $receipt = new self($binding, $reader, $expectedHistoricalRaw, $originalDeadlineNs, $witness);
        if (! $witness->matches($reader, $originalDeadlineNs)) {
            throw new IdentityException('historical_commit_required');
        }
        $access = new ProductionCustomerAccess;
        $access->proveHistoricalBindingCurrent($binding, $reader, $expectedHistoricalRaw);
        $plain = IdentityHistoricalPlainRows::fromWitness($witness);
        $receipt->privateRaw = $receipt->rawPrefix($plain);
        $account = $receipt->privateRaw['account'];
        if (! hash_equals($expectedHistoricalRaw['account']['owner_digest'], IdentityPolicy::digest('owner', $account['owner_key'] ?? ''))) {
            throw new IdentityException('historical_commit_required');
        }
        $receipt->provePrefix($plain);
        $witness->register($receipt);

        return $receipt;
    }

    public function sealOriginalCommit(): IdentityHistoricalCommitSeal
    {
        if ($this->phase !== 'held') {
            throw new IdentityException('historical_commit_required');
        }
        try {
            $this->witness->assertHeld();
            $plain = IdentityHistoricalPlainRows::fromWitness($this->witness);
            (new ProductionCustomerAccess)->proveHistoricalBindingPlain($this->binding, $plain, $this->expectedHistoricalRaw);
            $seal = IdentityHistoricalCommitSeal::capture($this, $this->witness, $this->originalDeadlineNs);
            // Deep parsing/configuration work precedes the last fixed raw prefix/schema/context comparison.
            $this->provePrefix($plain);
            $this->witness->sealed($this);
            $this->phase = 'sealed';
            $this->seal = $seal;

            return $seal;
        } catch (Throwable $error) {
            $this->invalidate();
            throw $error;
        }
    }

    public function observeOriginalCommitted(IdentityHistoricalCommitSeal $seal): void
    {
        try {
            if ($this->phase !== 'sealed' || $seal !== $this->seal || ! $seal->matches($this, $this->witness, $this->originalDeadlineNs)) {
                throw new IdentityException('historical_commit_required');
            }
            $this->witness->assertClosed();
            $this->phase = 'committed';
        } catch (Throwable $error) {
            $this->invalidate();
            throw $error;
        }
    }

    /** One use, depth zero, captured permanent plain SELECT; starts no transaction or statement lock. */
    public function proveClosed(): void
    {
        if ($this->phase !== 'committed') {
            throw new IdentityException('historical_commit_required');
        }
        $this->phase = 'used';
        try {
            $this->witness->assertClosed();
            $plain = IdentityHistoricalPlainRows::fromWitness($this->witness);
            (new ProductionCustomerAccess)->proveHistoricalBindingPlain($this->binding, $plain, $this->expectedHistoricalRaw);
            $this->provePrefix($plain);
        } catch (Throwable $error) {
            $this->invalidate();
            throw $error;
        }
    }

    /** @internal Identity of an existing server capture; never caller evidence or a new deadline. */
    public function belongsTo(IdentityOriginalCommitWitness $witness, CurrentRows $reader, int $deadlineNs): bool
    {
        return $this->witness === $witness && $this->reader === $reader && $this->originalDeadlineNs === $deadlineNs;
    }

    public function invalidate(): void
    {
        $this->phase = 'invalid';
        $this->witness->invalidate();
    }

    private function provePrefix(IdentityHistoricalPlainRows $plain): void
    {
        $plain->assertPermanent();
        if ($this->strings($this->rawPrefix($plain)) !== $this->strings($this->privateRaw)) {
            throw new IdentityException('historical_commit_required');
        }
        $plain->assertPermanent();
        $this->witness->assertReading();
    }

    private function rawPrefix(IdentityHistoricalPlainRows $plain): array
    {
        $origin = $plain->one('production_identity_origins', (int) $this->expectedHistoricalRaw['origin']['id']);
        $prefix = $challenges = [];
        foreach ($this->expectedHistoricalRaw['verification_prefix'] as $entry) {
            $prefix[] = $plain->one('production_identity_verifications', (int) $entry['id']);
        }
        foreach ($this->expectedHistoricalRaw['challenges'] as $entry) {
            $challenges[] = $plain->one('production_identity_challenges', (int) $entry['id']);
        }
        // Original act survives legitimate credential/access changes; owner relation/key is closed last.
        $user = $plain->one('users', $this->binding['user_id']);
        $account = $plain->one('customer_accounts', $this->binding['account_id']);

        return ['user_id' => $user['id'] ?? null,
            'account' => array_intersect_key($account, array_flip(['id', 'public_id', 'user_id', 'owner_key'])),
            'origin' => $origin, 'verification_prefix' => $prefix, 'challenges' => $challenges];
    }

    private function strings(mixed $value): mixed
    {
        return is_array($value) ? array_map($this->strings(...), $value) : ($value === null ? null : (string) $value);
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new IdentityException('historical_commit_required');
    }

    public function jsonSerialize(): never
    {
        throw new IdentityException('historical_commit_required');
    }

    public function __debugInfo(): array
    {
        return ['original_historical_receipt' => true];
    }
}
