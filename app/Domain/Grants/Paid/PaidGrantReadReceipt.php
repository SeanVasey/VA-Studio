<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use Closure;
use LogicException;

/** An original in-transaction read can close commit callbacks; it never renews owner/source authority. */
final readonly class PaidGrantReadReceipt implements \JsonSerializable
{
    private function __construct(private Closure $proof) {}

    public static function capture(PaidGrantRows $rows, ProductionCustomerPrincipal $principal, User $actor,
        array $authority, array $policy, array $snapshots): self
    {
        $rows->assertCurrent();
        (new ProductionCustomerAccess)->proveCurrent($principal, $actor, $rows->current(), $authority);
        $owner = [
            ['users', 'id = ?', [$authority['user']['id']], 2, [$authority['user']]],
            ['customer_accounts', 'id = ?', [$authority['account']['id']], 2, [$authority['account']]],
            ['production_identity_origins', 'id = ?', [$authority['origin']['id']], 2, [$authority['origin']]],
            ['production_identity_verifications', 'origin_id = ?', [$authority['origin']['id']], 129, $authority['verification_history']],
        ];
        foreach (array_unique(array_column($authority['verification_history'], 'challenge_id')) as $challengeId) {
            $challenge = $rows->one('production_identity_challenges', 'id = ?', [$challengeId]);
            PaidGrantException::require($challenge !== [], 403);
            $owner[] = ['production_identity_challenges', 'id = ?', [$challengeId], 2, [$challenge]];
        }
        $actorId = $actor->getKey();

        return new self(static function (bool $closed) use ($rows, $actor, $actorId, $owner, $snapshots, $policy): void {
            // Resolvers/environment/model access precede every final raw owner/source comparison.
            app(PaidGrantPolicy::class);
            $closed ? $rows->committedCallbackPhase() : $rows->callbackPhase();
            PaidGrantException::require($actor::class === User::class && $actor->exists && $actor->getKey() === $actorId, 403);
            foreach ([...$owner, ...$snapshots] as [$table, $where, $bindings, $limit, $expected]) {
                PaidGrantException::require($rows->rows($table, $where, $bindings, $limit, $closed) === $expected, 403);
            }
            PaidGrantPolicy::provePure($policy, $rows->configuration, $rows->environment);
            $closed ? $rows->assertCommitted() : $rows->assertCurrent();
        });
    }

    public function proveLive(): void
    {
        ($this->proof)(false);
    }

    public function proveClosed(): void
    {
        ($this->proof)(true);
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
