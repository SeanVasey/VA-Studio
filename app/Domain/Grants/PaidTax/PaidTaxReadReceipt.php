<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use Closure;
use LogicException;
use PDO;

/** An original in-transaction read can close commit callbacks; it never renews owner/source authority. */
final readonly class PaidTaxReadReceipt implements \JsonSerializable
{
    private function __construct(private Closure $proof, private Closure $commitAdmission, private Closure $closedRaw, private PaidTaxRows $rows, private PaidTaxActor $actorOriginal) {}

    public static function capture(PaidTaxRows $rows, ProductionCustomerPrincipal $principal, User $actor,
        PaidTaxActor $actorOriginal, array $authority, array $policy, array $snapshots): self
    {
        $rows->assertCurrent();
        $actorOriginal->prove();
        (new ProductionCustomerAccess)->proveCurrent($principal, $actor, $rows->current(), $authority);
        $owner = [
            ['users', 'id = ?', [$authority['user']['id']], 2, [$authority['user']]],
            ['customer_accounts', 'id = ?', [$authority['account']['id']], 2, [$authority['account']]],
            ['production_identity_origins', 'id = ?', [$authority['origin']['id']], 2, [$authority['origin']]],
            ['production_identity_verifications', 'origin_id = ?', [$authority['origin']['id']], 129, $authority['verification_history']],
        ];
        foreach (array_unique(array_column($authority['verification_history'], 'challenge_id')) as $challengeId) {
            $challenge = $rows->one('production_identity_challenges', 'id = ?', [$challengeId]);
            PaidTaxException::require($challenge !== [], 403);
            $owner[] = ['production_identity_challenges', 'id = ?', [$challengeId], 2, [$challenge]];
        }
        $actorOriginal->prove();
        PaidTaxException::require($actorOriginal->id === (int) $authority['user']['id'], 403);

        $proof = static function (bool $closed) use ($rows, $actorOriginal, $owner, $snapshots, $policy): void {
            // Resolvers/environment/model access precede every final raw owner/source comparison.
            app(PaidTaxPolicy::class);
            $closed ? $rows->committedCallbackPhase() : $rows->callbackPhase();
            $actorOriginal->prove();
            foreach ([...$owner, ...$snapshots] as [$table, $where, $bindings, $limit, $expected]) {
                PaidTaxException::require($rows->rows($table, $where, $bindings, $limit, $closed) === $expected, 403);
            }
            PaidTaxPolicy::provePure($policy, $rows->configuration, $rows->environment);
            $closed ? $rows->assertCommitted() : $rows->assertCurrent();
        };
        $admission = static function (PDO $primary) use ($rows, $actorOriginal, $owner, $snapshots, $policy): void {
            // Original captured dependencies only. No container resolution, casts, provider getters or new row locks.
            PaidTaxException::require($primary === $rows->primary);
            $rows->assertPrepared();
            $actorOriginal->prove();
            foreach ([...$owner, ...$snapshots] as [$table, $where, $bindings, $limit, $expected]) {
                PaidTaxException::require($rows->preparedRows($table, $where, $bindings, $limit) === $expected, 403);
            }
            PaidTaxPolicy::provePure($policy, $rows->configuration, $rows->environment);
            $rows->assertPrepared();
        };

        $closedRaw = static function () use ($rows, $actorOriginal, $owner, $snapshots, $policy): void {
            $rows->assertCommitted();
            $actorOriginal->prove();
            foreach ([...$owner, ...$snapshots] as [$table, $where, $bindings, $limit, $expected]) {
                PaidTaxException::require($rows->rows($table, $where, $bindings, $limit, true) === $expected, 403);
            }
            PaidTaxPolicy::provePure($policy, $rows->configuration, $rows->environment);
            $rows->assertCommitted();
        };

        return new self($proof, $admission, $closedRaw, $rows, $actorOriginal);
    }

    public function proveActor(): void
    {
        $this->actorOriginal->prove();
    }

    public function proveLive(): void
    {
        ($this->proof)(false);
    }

    public function proveClosed(): void
    {
        ($this->proof)(true);
    }

    public function requireCommitAdmissionMint(): void
    {
        $this->rows->assertCurrent();
    }

    public function proveCommitAdmission(PDO $capturedPrimary): void
    {
        ($this->commitAdmission)($capturedPrimary);
    }

    public function proveRawClosed(): void
    {
        ($this->closedRaw)();
    }

    public function __serialize(): array
    {
        throw new LogicException('Paid read receipts cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Paid read receipts cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Paid read receipts are internal.');
    }

    private function __clone() {}

    public function __debugInfo(): array
    {
        return ['purpose' => 'paid-committed-owner-origin-read'];
    }
}
