<?php

namespace App\Domain\Grants\Free\Production;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantIdentity;
use App\Domain\Grants\Free\FreeGrantOriginalIdentity;
use App\Domain\Grants\Free\FreeGrantRows;
use App\Models\User;

/** Typed T23 owner and original-lineage adapter, deliberately unbound/default off. */
final class ProductionFreeGrantIdentity implements FreeGrantIdentity, FreeGrantOriginalIdentity
{
    public function principal(User $actor): ProductionCustomerPrincipal
    {
        $configuration = (new ProductionFreeGrantIdentityPolicy)->current();
        try {
            $principal = (new ProductionCustomerAccess)->principal($actor);
            FreeGrantException::require($principal->provenance === $configuration['provenance']
                && (new ProductionFreeGrantIdentityPolicy)->current() === $configuration, 403);

            return $principal;
        } catch (IdentityException) {
            throw new FreeGrantException(403);
        }
    }

    public function lock(object $principal, User $actor, FreeGrantRows $rows): array
    {
        $principal = $this->typed($principal);
        ProductionFreeGrantBoundary::admit($rows);
        $configuration = (new ProductionFreeGrantIdentityPolicy)->current();
        try {
            $identity = (new ProductionCustomerAccess)->lock($principal, $actor, $rows->current());
            $raw = compact('configuration', 'identity');
            $this->provePrimary($principal, $actor, $rows, $raw);

            return $raw;
        } catch (IdentityException) {
            throw new FreeGrantException(403);
        }
    }

    public function proveCurrent(object $principal, User $actor, FreeGrantRows $rows, array $expected): void
    {
        $this->provePrimary($principal, $actor, $rows, $expected);
    }

    public function provePrimary(object $principal, User $actor, FreeGrantRows $rows, array $expected): void
    {
        $principal = $this->typed($principal);
        ProductionFreeGrantBoundary::admit($rows);
        FreeGrantException::require(array_keys($expected) === ['configuration', 'identity'] && is_array($expected['identity'])
            && (new ProductionFreeGrantIdentityPolicy)->current() === $expected['configuration']
            && $principal->provenance === $expected['configuration']['provenance'], 403);
        try {
            (new ProductionCustomerAccess)->proveCurrent($principal, $actor, $rows->current(), $expected['identity']);
        } catch (IdentityException) {
            throw new FreeGrantException(403);
        }
        $rows->assertCurrent();
    }

    public function durableBinding(object $principal): array
    {
        $principal = $this->typed($principal);
        $configuration = (new ProductionFreeGrantIdentityPolicy)->current();
        FreeGrantException::require($configuration['provenance'] === $principal->provenance, 403);

        return ['family_schema_version' => 1, 'family' => $configuration['family'], 'identity_version' => $configuration['version'],
            'purpose' => $configuration['purpose'], 'user_id' => $principal->userId, 'account_id' => $principal->accountId,
            'provenance' => $principal->provenance, 'legal_identity_verified' => false, 'buyer_binding' => $principal->durableBinding()];
    }

    public function lockOriginal(object $principal, User $actor, array $binding, FreeGrantRows $rows): array
    {
        $principal = $this->typed($principal);
        ProductionFreeGrantBoundary::admit($rows);
        $configuration = (new ProductionFreeGrantIdentityPolicy)->current();
        FreeGrantException::require($actor->id === $principal->userId && $principal->provenance === $configuration['provenance'], 403);
        try {
            return (new ProductionFreeGrantLineage)->lock($principal, $binding, $rows);
        } catch (IdentityException) {
            throw new FreeGrantException(403);
        }
    }

    public function proveOriginalPrimary(object $principal, User $actor, array $binding, FreeGrantRows $rows, array $expected): void
    {
        $principal = $this->typed($principal);
        ProductionFreeGrantBoundary::admit($rows);
        $configuration = (new ProductionFreeGrantIdentityPolicy)->current();
        FreeGrantException::require($actor->id === $principal->userId && $principal->provenance === $configuration['provenance'], 403);
        try {
            (new ProductionFreeGrantLineage)->prove($principal, $binding, $rows, $expected);
        } catch (IdentityException) {
            throw new FreeGrantException(403);
        }
    }

    private function typed(object $principal): ProductionCustomerPrincipal
    {
        FreeGrantException::require($principal instanceof ProductionCustomerPrincipal, 403);

        return $principal;
    }
}
