<?php

namespace App\Domain\Grants\Member;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipLedgerAudit;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\MembershipSchema;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Model;
use PDO;
use ReflectionProperty;

/** Owned historical inspection. No current invoice, catalog, honor or approved-definition admission. */
final class MemberActivationHistoryInspection
{
    private ProductionCustomerAccess $access;

    private array $identity;

    private array $historical;

    private array $ownerRows;

    private array $plan;

    private MembershipPolicyBinding $policy;

    private MembershipInvoiceBinding $invoice;

    private MemberActivationHistoryReader $history;

    private MemberActivationHistorySnapshot $snapshot;

    private ?MemberActivatedOriginal $original = null;

    private ?MemberGrantActivationResult $result = null;

    private bool $retired = false;

    private bool $committed = false;

    private string $schema;

    private string $planTable;

    public function __construct(MemberActivationReads $issuer, private readonly MembershipRows $rows,
        private readonly MemberActivationReadConfiguration $configuration, private readonly MemberOriginalFinishBoundary $finish,
        private readonly MemberOriginalBudget $budget, private readonly int $deadline, string $definitionId,
        string $redemptionId, private readonly ProductionCustomerPrincipal $principal, private readonly User $actor,
        ?array $expectedAuthorization, ?array $expectedState, private readonly MemberRetainedOriginals $originals,
        private readonly ?MemberActivatedOriginal $observedOriginal, private readonly ?MemberRetainedOriginalObservation $observation)
    {
        MemberActivationReads::consumeInspection($issuer, $rows, $configuration, $finish, $budget, $deadline,
            $definitionId, $redemptionId, $principal, $actor, $expectedAuthorization, $expectedState,
            $originals, $observedOriginal, $observation);
        MemberGrantDocumentValues::id($definitionId);
        MemberGrantDocumentValues::id($redemptionId);
        $this->access = new ProductionCustomerAccess;
        $this->history = new MemberActivationHistoryReader;
        try {
            $this->identity = $this->access->lock($principal, $actor, $rows->current());
            MemberGrantException::require($principal->provenance === $configuration->provenance(), 'provenance');
            $this->schema = $rows->driver() === 'mysql' ? $rows->identity()->query('SELECT DATABASE()')->fetchColumn() : 'main';
            MemberGrantException::require(preg_match('/\A[a-zA-Z0-9_]+\z/D', $this->schema) === 1, 'changed_schema');
            $this->planTable = $rows->driver() === 'mysql'
                ? '`'.$this->schema.'`.'.(new MembershipSchema)->table(MembershipRecords::TABLE)
                : (new MembershipSchema)->table(MembershipRecords::TABLE);
            $redemption = $rows->one(MembershipReservationRecords::TABLE, 'id = ?', [$redemptionId]);
            MemberGrantException::require($redemption !== [], 'reservation_absent');
            $this->snapshot = $this->history->capturePeriod($redemption['period_id'], $rows);
            $scope = $this->snapshot->scope();
            $period = $scope['period'];
            MemberGrantException::require((string) $period['account_id'] === (string) $principal->accountId
                && (string) $period['user_id'] === (string) $principal->userId
                && (string) $period['identity_origin_id'] === (string) $this->identity['origin']['id'], 'foreign_activation');
            $this->plan = $rows->one(MembershipRecords::TABLE, 'id = ?', [$period['plan_version_id']]);
            $this->policy = MembershipPolicyVersions::document($this->plan)->binding($this->plan['id']);
            $awards = array_values(array_filter($scope['events'], static fn (array $event): bool => $event['kind'] === 'award'));
            MemberGrantException::require(count($awards) === 1, 'incomplete_history');
            $this->invoice = MembershipPeriodRecords::historicalInvoice($period, $awards[0], $this->policy, (int) $this->identity['origin']['id']);
            $this->historical = $this->access->verifyHistoricalBinding($this->invoice->originalBuyerBinding, $rows->current());
            MemberGrantException::require($this->policy->provenance === $principal->provenance
                && (string) $this->historical['origin']['id'] === (string) $this->identity['origin']['id'], 'foreign_activation');
            $audit = $this->audit();
            MemberGrantException::require(isset($audit['reservations'][$redemptionId]), 'reservation_absent');
            $reservation = $audit['reservations'][$redemptionId];
            if ($reservation['terminal'] !== null) {
                MemberGrantException::require($reservation['terminal']['kind'] === 'consume', 'reservation_terminal');
                $this->original = $this->snapshot->value()->original($redemptionId);
                $ids = $this->original->identities();
                MemberGrantException::require($ids['definition_id'] === $definitionId, 'changed_activation_intent');
                if ($expectedAuthorization !== null) {
                    $expectedAuthorization = MemberLicenseAuthorizationRecords::verifyRetained($expectedAuthorization, $this->original->source(), $this->policy);
                    $origin = array_values(array_filter($this->snapshot->value()->rows()['origins'],
                        static fn (array $row): bool => $row['id'] === $redemptionId))[0];
                    $retained = MemberActivationRecords::readOrigin($origin, $this->policy);
                    MemberGrantException::require($retained['authorization']['intent_hash'] === $expectedAuthorization['intent_hash'], 'changed_activation_intent');
                }
                $this->result = new MemberGrantActivationResult($ids['origin_id'], $ids['redemption_id'], $ids['definition_id'],
                    $ids['consume_event_id'], $ids['activation_id'], $ids['artifact_manifest_hash'], $ids['activation_hash']);
            }
            $this->ownerRows = [
                ['users', 'id = ?', [$principal->userId], 2, [$this->identity['user']]],
                ['customer_accounts', 'user_id = ?', [$principal->userId], 2, [$this->identity['account']]],
                ['production_identity_origins', 'account_id = ?', [$principal->accountId], 2, [$this->identity['origin']]],
                ['production_identity_verifications', 'origin_id = ?', [(int) $this->identity['origin']['id']], 129, $this->identity['verification_history']],
            ];
            foreach (array_unique(array_column($this->identity['verification_history'], 'challenge_id')) as $id) {
                $challenges = $this->read('`'.$this->schema.'`.`production_identity_challenges`', 'id = ?', [(int) $id], 2, false);
                MemberGrantException::require(count($challenges) === 1, 'changed_owner');
                $this->ownerRows[] = ['production_identity_challenges', 'id = ?', [(int) $id], 2, $challenges];
            }
            if ($expectedState !== null) {
                MemberGrantException::require($this->state() === $expectedState, 'changed_retained_effect');
                MemberGrantException::require($this->original !== null && $observedOriginal !== null
                    && $this->original->identities() === $observedOriginal->identities()
                    && $this->original->source()->document() === $observedOriginal->source()->document()
                    && $this->original->artifact() === $observedOriginal->artifact(), 'changed_retained_effect');
            }
            $this->admit();
        } catch (\Throwable $error) {
            $this->retire();
            throw $error;
        }
    }

    public function original(): ?MemberActivatedOriginal
    {
        return $this->original;
    }

    public function result(): ?MemberGrantActivationResult
    {
        return $this->result;
    }

    public function state(): array
    {
        return ['history' => $this->snapshot->value()->rows(), 'scope' => $this->snapshot->scope(),
            'plan' => MembershipRecords::strings($this->plan)];
    }

    public function reader(): CurrentRows
    {
        return new CurrentRows($this->rows->identity(), $this->rows->driver());
    }

    public function proveCurrent(): void
    {
        MemberGrantException::require(! $this->retired && ! $this->committed, 'foreign_inspection');
        $this->history->currentCallbacks($this->snapshot, $this->rows);
        MembershipPolicyVersions::document($this->plan);
        $this->audit();
        $this->access->proveHistoricalBindingCurrent($this->invoice->originalBuyerBinding, $this->rows->current(), $this->historical);
        $this->access->proveCurrent($this->principal, $this->actor, $this->rows->current(), $this->identity);
        $this->finish->assertFrame($this->rows->identity(), false);
        $this->history->currentRaw($this->snapshot, $this->rows);
        $this->raw(false);
        $this->rows->assertCurrent();
        $this->finish->assertFrame($this->rows->identity(), false);
        $this->admit();
    }

    public function proveCommitted(MemberActivationReadCommittedProof $proof, IdentityCommittedFrame $frame): void
    {
        MemberActivationReadCommittedProof::consume($proof, $this, $frame);
        MemberGrantException::require(! $this->retired && ! $this->committed, 'foreign_inspection');
        $this->committed = true;
        $this->history->committedCallbacks($this->snapshot, $frame);
        MembershipPolicyVersions::document($this->plan);
        $this->audit();
        $this->access->proveHistoricalBindingCommitted($this->invoice->originalBuyerBinding, $frame, $this->historical);
        $this->access->proveCommitted($this->principal, $this->actor, $frame, $this->identity);
        $this->finish->assertFrame($this->rows->identity(), true);
        $this->history->committedRaw($this->snapshot, $frame);
        $this->raw(true);
        $this->finish->assertFrame($this->rows->identity(), true);
        $this->admit();
        $frame->finish();
        $this->finish->assertCurrent();
        $this->admit();
    }

    private function audit(): array
    {
        $scope = $this->snapshot->scope();

        return MembershipLedgerAudit::verify($scope['period'], $scope['events'], $scope['redemptions'],
            $this->invoice, $this->policy, (int) $this->historical['origin']['id'], $this->snapshot->value());
    }

    private function raw(bool $committed): void
    {
        $this->admit();
        [$present] = (new IdentityMigrationOwnership)->inspect($this->rows->identity(), $this->rows->driver());
        MemberGrantException::require(! in_array(false, $present, true), 'changed_owner');
        $attributes = (new ReflectionProperty(Model::class, 'attributes'))->getValue($this->actor);
        MemberGrantException::require($this->actor->exists && ($attributes['id'] ?? null) === $this->principal->userId, 'changed_owner');
        $plan = $this->read($this->planTable, 'id = ?', [$this->plan['id']], 2, $committed);
        self::sameRows($plan, [$this->plan]);
        foreach ($this->ownerRows as [$table, $where, $bindings, $limit, $expected]) {
            self::sameRows($this->read('`'.$this->schema.'`.`'.$table.'`', $where, $bindings, $limit, $committed), $expected);
        }
        $this->admit();
    }

    private function read(string $table, string $where, array $bindings, int $limit, bool $committed): array
    {
        $statement = $this->rows->identity()->prepare('SELECT * FROM '.$table.' WHERE '.$where.' ORDER BY id LIMIT '.$limit
            .(! $committed && $this->rows->driver() === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function sameRows(array $actual, array $expected): void
    {
        MemberGrantException::require(CanonicalJson::encode(array_map(MembershipRecords::strings(...), $actual))
            === CanonicalJson::encode(array_map(MembershipRecords::strings(...), $expected)), 'changed_retained_effect');
    }

    private function admit(): void
    {
        $this->configuration->admit();
        $this->budget->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $this->deadline, 'source_deadline');
        if ($this->observation !== null) {
            MemberGrantException::require($this->observedOriginal !== null, 'foreign_retained_observation');
            $this->originals->assertCurrent($this->observedOriginal, $this->observation, $this->budget);
        }
    }

    public function retire(): void
    {
        $this->retired = true;
        if (isset($this->snapshot)) {
            $this->history->retire($this->snapshot);
        }
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_inspection');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_inspection');
    }
}
