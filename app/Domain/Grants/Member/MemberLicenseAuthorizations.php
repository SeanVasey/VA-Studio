<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use Carbon\CarbonImmutable;
use WeakMap;

/** Actual same-process owner confirmation. Detached displays and shaped tokens confer no authority. */
final class MemberLicenseAuthorizations
{
    private WeakMap $tickets;

    private WeakMap $claims;

    private WeakMap $proofs;

    private WeakMap $closures;

    private static ?WeakMap $entries = null;

    public function __construct(private readonly MemberGrantActivations $owner, private readonly MemberOriginalSourceCapture $sources)
    {
        $this->tickets = new WeakMap;
        $this->claims = new WeakMap;
        $this->proofs = new WeakMap;
        $this->closures = new WeakMap;
    }

    public function review(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor): MemberLicenseReviewDisplay
    {
        $budget = MemberOriginalBudget::start();
        $entry = MemberLicenseReviewEntryPermit::value();
        self::$entries ??= new WeakMap;
        self::$entries[$entry] = [$this, $this->sources, $definitionId, $redemptionId, null, $principal, $actor, $budget];
        try {
            return $this->sources->captureForReview($entry, $definitionId, $redemptionId, $principal, $actor, $budget)->display;
        } finally {
            unset(self::$entries[$entry]);
            $this->sources->retireWorkflow($budget);
        }
    }

    public function confirm(string $definitionId, string $redemptionId, MemberLicenseConfirmationInput $input,
        ProductionCustomerPrincipal $principal, User $actor): MemberLicenseAuthorization
    {
        $budget = MemberOriginalBudget::start();
        $entry = MemberLicenseConfirmationEntryPermit::value();
        self::$entries ??= new WeakMap;
        self::$entries[$entry] = [$this, $this->sources, $definitionId, $redemptionId, $input, $principal, $actor, $budget];
        $success = false;
        try {
            $capture = $this->sources->captureForConfirmation($entry, $definitionId, $redemptionId, $input, $principal, $actor, $budget);
            // Time/UUID/document work already finished inside the owned inspection. No callback here.
            $capture->configuration->admit();
            $budget->assertCurrent();
            $ticket = MemberLicenseAuthorization::value();
            $this->tickets[$ticket] = ['source' => $capture->source, 'authorization' => $capture->authorization(),
                'configuration' => $capture->configuration, 'principal' => $principal, 'actor' => $actor, 'budget' => $budget,
                'definition' => $definitionId, 'redemption' => $redemptionId];
            $success = true;

            return $ticket;
        } finally {
            unset(self::$entries[$entry]);
            if (! $success) {
                $this->sources->retireWorkflow($budget);
            }
        }
    }

    public static function consumeEntry(MemberLicenseReviewEntryPermit|MemberLicenseConfirmationEntryPermit $entry,
        self $issuer, MemberOriginalSourceCapture $sources, string $definitionId, string $redemptionId,
        ?MemberLicenseConfirmationInput $input, ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget): void
    {
        $expected = self::$entries[$entry] ?? null;
        if (self::$entries !== null) {
            unset(self::$entries[$entry]);
        }
        MemberGrantException::require($expected === [$issuer, $sources, $definitionId, $redemptionId, $input, $principal, $actor, $budget]
            && (($entry instanceof MemberLicenseReviewEntryPermit) === ($input === null)), 'owned_confirmation_required');
        $budget->assertCurrent();
    }

    public function claim(MemberLicenseAuthorization $ticket, MemberActivationEntryPermit $permit,
        ProductionCustomerPrincipal $principal, User $actor): MemberLicenseAuthorizationClaim
    {
        $data = $this->tickets[$ticket] ?? null;
        // Consume even an attempted workflow that later fails. No callback may reuse the ticket.
        unset($this->tickets[$ticket]);
        MemberGrantException::require(is_array($data) && $data['principal'] === $principal && $data['actor'] === $actor,
            'foreign_authorization');
        $this->owner->assertEntry($permit, $this, $ticket, $data['definition'], $data['redemption'], $principal, $actor);
        $data['configuration']->admit();
        $data['budget']->assertCurrent();
        $claim = MemberLicenseAuthorizationClaim::value();
        $this->claims[$claim] = $data;

        return $claim;
    }

    /** Original issuer-owned workflow allowance only, never a caller-selected or renewed budget. */
    public function budget(MemberLicenseAuthorizationClaim $claim, MemberGrantActivations $owner): MemberOriginalBudget
    {
        MemberGrantException::require($owner === $this->owner, 'foreign_authorization');

        return $this->claimData($claim)['budget'];
    }

    public function configuration(MemberLicenseAuthorizationClaim $claim, MemberGrantActivations $owner): MemberActivationConfiguration
    {
        MemberGrantException::require($owner === $this->owner, 'foreign_authorization');

        return $this->claimData($claim)['configuration'];
    }

    public function authorization(MemberLicenseAuthorizationClaim $claim, MemberGrantActivations $owner): array
    {
        MemberGrantException::require($owner === $this->owner, 'foreign_authorization');

        return $this->claimData($claim)['authorization'];
    }

    public function lock(MemberLicenseAuthorizationClaim $claim, MemberOriginalSourceValues $source,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberLicenseAuthorizationProof
    {
        $data = $this->claimData($claim);
        MemberGrantException::require($data['principal'] === $principal && $data['actor'] === $actor
            && $data['source']->document() === $source->document(), 'changed_authorization_intent');
        $policy = $this->sources->admitAuthorizationLock($this, $claim, $source, $principal, $actor, $rows);
        MemberLicenseAuthorizationRecords::verifyRetained($data['authorization'], $source, $policy);
        $proof = MemberLicenseAuthorizationProof::value();
        $this->proofs[$proof] = ['claim' => $claim, 'source' => $source, 'policy' => $policy,
            'rows' => $rows, 'pdo' => $rows->identity(), 'principal' => $principal, 'actor' => $actor,
            'state' => 'current', 'frame' => null];
        $this->currentCallbacks($proof, $rows);
        $this->currentRaw($proof, $rows);

        return $proof;
    }

    public function closure(MemberLicenseAuthorizationProof $proof, ProductionCustomerPrincipal $principal,
        User $actor, MembershipRows $rows): MemberLicenseAuthorizationClosure
    {
        $data = $this->current($proof, $rows);
        MemberGrantException::require($data['principal'] === $principal && $data['actor'] === $actor, 'foreign_authorization');
        $closure = MemberLicenseAuthorizationClosure::value();
        $this->closures[$closure] = ['proof' => $proof, 'data' => $data];

        return $closure;
    }

    public function currentCallbacks(MemberLicenseAuthorizationProof $proof, MembershipRows $rows): void
    {
        $data = $this->current($proof, $rows);
        $rows->assertCurrent();
        $this->callbacks($data);
    }

    public function currentRaw(MemberLicenseAuthorizationProof $proof, MembershipRows $rows): void
    {
        $data = $this->current($proof, $rows);
        $this->sources->admitAuthorizationLock($this, $data['claim'], $data['source'], $data['principal'], $data['actor'], $rows);
        $this->raw($data);
        $rows->assertCurrent();
        $this->raw($data);
    }

    public function committedCallbacks(MemberLicenseAuthorizationClosure $closure, IdentityCommittedFrame $frame): void
    {
        $entry = $this->closures[$closure] ?? null;
        MemberGrantException::require(is_array($entry) && $entry['data']['state'] === 'current'
            && $frame->reader()->identityPrimary() === $entry['data']['pdo'], 'foreign_authorization');
        unset($this->proofs[$entry['proof']]);
        $entry['data']['state'] = 'committed';
        $entry['data']['frame'] = $frame;
        $this->closures[$closure] = $entry;
        $this->callbacks($entry['data']);
    }

    public function committedRaw(MemberLicenseAuthorizationClosure $closure, IdentityCommittedFrame $frame): void
    {
        $entry = $this->closures[$closure] ?? null;
        MemberGrantException::require(is_array($entry) && $entry['data']['state'] === 'committed'
            && $entry['data']['frame'] === $frame, 'foreign_authorization');
        $this->raw($entry['data']);
        $entry['data']['state'] = 'finished';
        $this->closures[$closure] = $entry;
    }

    public function postFinish(MemberLicenseAuthorizationClosure $closure, IdentityCommittedFrame $frame): void
    {
        $entry = $this->closures[$closure] ?? null;
        MemberGrantException::require(is_array($entry) && $entry['data']['state'] === 'finished'
            && $entry['data']['frame'] === $frame && ! $entry['data']['pdo']->inTransaction(), 'foreign_authorization');
        $this->raw($entry['data']);
        unset($this->closures[$closure]);
    }

    private function callbacks(array $data): void
    {
        $claim = $this->claimData($data['claim']);
        MemberLicenseAuthorizationRecords::verifyRetained($claim['authorization'], $data['source'], $data['policy']);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        MemberGrantException::require($now >= $claim['authorization']['confirmed_at']
            && $now < $data['source']->document()['redemption']['honor_deadline'], 'authorization_expired');
    }

    private function raw(array $data): void
    {
        $this->claimData($data['claim']);
    }

    private function current(MemberLicenseAuthorizationProof $proof, MembershipRows $rows): array
    {
        $data = $this->proofs[$proof] ?? null;
        MemberGrantException::require(is_array($data) && $data['state'] === 'current' && $data['rows'] === $rows
            && $data['pdo'] === $rows->identity(), 'foreign_authorization');

        return $data;
    }

    private function claimData(MemberLicenseAuthorizationClaim $claim): array
    {
        $data = $this->claims[$claim] ?? null;
        MemberGrantException::require(is_array($data), 'foreign_authorization');
        $data['configuration']->admit();
        $data['budget']->assertCurrent();

        return $data;
    }

    public function retire(MemberLicenseAuthorizationClaim $claim): void
    {
        foreach ($this->proofs as $proof => $data) {
            if ($data['claim'] === $claim) {
                unset($this->proofs[$proof]);
            }
        }
        foreach ($this->closures as $closure => $entry) {
            if ($entry['data']['claim'] === $claim) {
                unset($this->closures[$closure]);
            }
        }
        unset($this->claims[$claim]);
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_authorization_issuer');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_authorization_issuer');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_authorization_issuer');
    }
}
