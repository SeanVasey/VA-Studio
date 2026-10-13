<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use ReflectionProperty;
use WeakMap;

/** Real immutable facts issuer. It neither grants credits nor writes member activations. */
final class SealedMemberGrantFacts implements MemberGrantFactsAuthority
{
    private WeakMap $issued;

    private WeakMap $closures;

    public function __construct()
    {
        $this->issued = new WeakMap;
        $this->closures = new WeakMap;
    }

    public function __clone(): void
    {
        throw new MemberGrantException('clone_refused');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('serialization_refused');
    }

    public function __debugInfo(): array
    {
        return ['member_facts_issuer' => 'opaque'];
    }

    public function lock(string $definitionId, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberGrantFactsProof
    {
        $deadline = \hrtime(true) + 60_000_000_000;
        MemberGrantDocumentValues::id($definitionId);
        $ownerInputs = self::ownerInputs($actor);
        $policy = new MemberGrantPolicy;
        $configuration = $policy->currentFor($rows);
        $schema = MemberGrantCurrentSchema::pin($rows);
        $reader = new MemberGrantRows($rows, $schema);
        $owner = (new ProductionCustomerAccess)->lock($principal, $actor, $rows->current());
        $definition = $reader->definition($definitionId);
        MemberGrantException::require($definition !== [], 'facts_absent');
        $profile = $reader->profile($definition['profile_id']);
        MemberGrantException::require($profile !== [], 'facts_absent');
        $snapshot = MemberGrantRecords::snapshot($profile, $definition);
        $plan = $reader->plan($snapshot->definitionDocument->value()['plan_version_id']);
        self::plan($plan, $snapshot);
        MemberGrantException::require($snapshot->factsBinding->definitionHash === $configuration['approved_definition_hash']
            && $snapshot->profileBinding->profileHash === $configuration['approved_profile_hash']
            && $snapshot->factsBinding->originalTermsHash === $configuration['approved_original_terms_hash']
            && $snapshot->factsBinding->provenance === $configuration['provenance']
            && $owner['durable_binding']['provenance'] === $configuration['provenance'], 'facts_not_approved');
        $proof = new MemberGrantFactsToken($snapshot->factsBinding);
        $this->issued[$proof] = compact('principal', 'actor', 'rows', 'deadline', 'policy', 'configuration',
            'schema', 'reader', 'owner', 'definition', 'profile', 'snapshot', 'plan') + ['owner_inputs' => $ownerInputs];
        $this->proveCurrent($proof, $principal, $actor, $rows);

        return $proof;
    }

    public function proveCurrent(MemberGrantFactsProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
    {
        $closure = $this->closure($proof, $principal, $actor, $rows);
        try {
            $this->currentCallbacks($closure, $rows);
            $this->currentRaw($closure, $rows);
        } finally {
            $this->retire($closure);
        }
    }

    public function snapshot(MemberGrantFactsProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberGrantFactsSnapshot
    {
        $this->proveCurrent($proof, $principal, $actor, $rows);

        return $this->issued[$proof]['snapshot'];
    }

    public function closure(MemberGrantFactsProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberGrantFactsClosure
    {
        $issued = $this->issued[$proof] ?? null;
        MemberGrantException::require($proof instanceof MemberGrantFactsToken && is_array($issued)
            && $issued['principal'] === $principal && $issued['actor'] === $actor && $issued['rows'] === $rows, 'foreign_facts_proof');
        self::deadline($issued);
        $closure = new MemberGrantFactsClosure;
        $this->closures[$closure] = $issued + ['original_proof' => $proof, 'current_ready' => false, 'committed_frame' => null];

        return $closure;
    }

    public function currentCallbacks(MemberGrantFactsClosure $proof, MembershipRows $rows): void
    {
        $issued = $this->entry($proof);
        MemberGrantException::require($issued['rows'] === $rows && $issued['committed_frame'] === null, 'foreign_facts_proof');
        $issued['current_ready'] = false;
        $this->closures[$proof] = $issued;
        $issued['policy']->proveCurrent($issued['configuration'], $rows);
        (new ProductionCustomerAccess)->proveCurrent($issued['principal'], $issued['actor'], $rows->current(), $issued['owner']);
        $this->authenticate($issued, false);
        $issued['policy']->proveCurrent($issued['configuration'], $rows);
        self::deadline($issued);
        $issued['current_ready'] = true;
        $this->closures[$proof] = $issued;
    }

    public function currentRaw(MemberGrantFactsClosure $proof, MembershipRows $rows): void
    {
        $issued = $this->entry($proof);
        MemberGrantException::require($issued['rows'] === $rows && $issued['current_ready'] === true && $issued['committed_frame'] === null, 'facts_phase_required');
        $issued['current_ready'] = false;
        $this->closures[$proof] = $issued;
        MemberGrantCurrentSchema::assertCurrent($issued['schema'], $rows);
        $this->raw($issued, false);
        MemberGrantCurrentSchema::assertCurrent($issued['schema'], $rows);
        self::deadline($issued);
    }

    public function committedCallbacks(MemberGrantFactsClosure $proof, IdentityCommittedFrame $frame): void
    {
        $issued = $this->entry($proof);
        MemberGrantException::require($issued['committed_frame'] === null, 'facts_phase_required');
        $issued['current_ready'] = false;
        $this->closures[$proof] = $issued;
        $frame->assertActive();
        $issued['policy']->proveCapturedConfiguration($issued['configuration']);
        (new ProductionCustomerAccess)->proveCommitted($issued['principal'], $issued['actor'], $frame, $issued['owner']);
        $this->authenticate($issued, true);
        $frame->assertActive();
        self::deadline($issued);
        $issued['committed_frame'] = $frame;
        $this->closures[$proof] = $issued;
    }

    public function committedRaw(MemberGrantFactsClosure $proof, IdentityCommittedFrame $frame): void
    {
        $issued = $this->entry($proof);
        MemberGrantException::require($issued['committed_frame'] === $frame, 'facts_phase_required');
        // Consume the phase even if a terminal predicate refuses; do not allow renewed callbacks.
        unset($this->closures[$proof]);
        MemberGrantCurrentSchema::assertCommitted($issued['schema'], $frame);
        $this->raw($issued, true);
        MemberGrantCurrentSchema::assertCommitted($issued['schema'], $frame);
        self::deadline($issued);
    }

    public function retire(MemberGrantFactsClosure $proof): void
    {
        unset($this->closures[$proof]);
    }

    private function entry(MemberGrantFactsClosure $proof): array
    {
        $issued = $this->closures[$proof] ?? null;
        MemberGrantException::require(is_array($issued), 'foreign_facts_proof');
        self::deadline($issued);

        return $issued;
    }

    private function authenticate(array $issued, bool $committed): void
    {
        $reader = $issued['reader'];
        $profile = $reader->profile($issued['profile']['id'], $committed);
        $definition = $reader->definition($issued['definition']['id'], $committed);
        $snapshot = MemberGrantRecords::snapshot($profile, $definition);
        $plan = $reader->plan($snapshot->definitionDocument->value()['plan_version_id'], $committed);
        self::plan($plan, $snapshot);
        MemberGrantRows::equal($issued['profile'], $profile);
        MemberGrantRows::equal($issued['definition'], $definition);
        MemberGrantRows::equal($issued['plan'], $plan);
    }

    private function raw(array $issued, bool $committed): void
    {
        $issued['policy']->proveCapturedConfiguration($issued['configuration']);
        MemberGrantException::require(self::ownerInputs($issued['actor']) === $issued['owner_inputs'], 'changed_owner');
        $reader = $issued['reader'];
        $reader->owner($issued['owner'], $committed);
        MemberGrantRows::equal($issued['profile'], $reader->profile($issued['profile']['id'], $committed));
        MemberGrantRows::equal($issued['definition'], $reader->definition($issued['definition']['id'], $committed));
        MemberGrantRows::equal($issued['plan'], $reader->plan($issued['plan']['id'], $committed));
        $issued['policy']->proveCapturedConfiguration($issued['configuration']);
    }

    public static function plan(array $plan, MemberGrantFactsSnapshot $snapshot): void
    {
        MemberGrantException::require($plan !== [], 'plan_absent');
        $document = MembershipPolicyVersions::document($plan);
        $definition = $snapshot->definitionDocument->value();
        MemberGrantException::require($plan['id'] === $definition['plan_version_id'] && $document->hash() === $definition['policy_hash']
            && $document->facts['provenance'] === $definition['provenance'], 'conflicting_plan');
    }

    /** Detached original policy and owner argument values; no framework resolution in raw closure. */
    private static function ownerInputs(User $actor): array
    {
        $container = Container::getInstance();
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
        $repository = is_array($instances) ? ($instances['config'] ?? null) : null;
        MemberGrantException::require($repository instanceof Repository && get_class($repository) === Repository::class, 'changed_owner');
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($repository);
        MemberGrantException::require(is_array($items) && is_array($items['production-customer-identity'] ?? null), 'changed_owner');
        $remaining = 4096;
        $bytes = 1048576;
        $values = self::copyOwnerValues([
            'identity_configuration' => $items['production-customer-identity'],
            'actor_exists' => (new ReflectionProperty(Model::class, 'exists'))->getValue($actor),
            'actor_attributes' => (new ReflectionProperty(Model::class, 'attributes'))->getValue($actor),
            'actor_original' => (new ReflectionProperty(Model::class, 'original'))->getValue($actor),
        ], $remaining, $bytes);

        return ['container' => $container, 'repository' => $repository, 'values' => $values];
    }

    private static function copyOwnerValues(mixed $value, int &$remaining, int &$bytes, int $depth = 0): mixed
    {
        MemberGrantException::require(--$remaining >= 0 && $depth <= 16, 'changed_owner');
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::copyOwnerValues($child, $remaining, $bytes, $depth + 1);
            }

            return $copy;
        }
        MemberGrantException::require($value === null || is_string($value) || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value)), 'changed_owner');
        if (is_string($value)) {
            $bytes -= strlen($value);
            MemberGrantException::require($bytes >= 0, 'changed_owner');
        }

        return $value;
    }

    private static function deadline(array $issued): void
    {
        MemberGrantException::require(\hrtime(true) < $issued['deadline'], 'expired_facts_proof');
    }
}
