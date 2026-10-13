<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberActivationHistoryReader;
use App\Domain\Grants\Member\MemberActivationHistorySnapshot;
use App\Domain\Memberships\Billing\BillingFreshnessWindow;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Models\User;
use App\Support\CanonicalJson;
use PDO;

/** Internal owned first-award composition; proof tokens never cross the regular commit. */
final class MembershipAwardContext
{
    private ProductionCustomerAccess $access;

    private array $identity;

    private array $historical;

    private MemberActivationHistoryReader $memberHistoryReader;

    private MemberActivationHistorySnapshot $memberHistory;

    private MembershipPaidInvoiceProof $invoiceProof;

    private ?BillingFreshnessWindow $invoiceFreshness = null;

    private MembershipPolicyFactsProof $policyProof;

    private MembershipInvoiceBinding $invoice;

    private MembershipPolicyBinding $facts;

    private array $plan;

    private array $period;

    private array $events;

    private array $redemptions;

    private array $tables;

    private array $projection;

    public function __construct(
        private readonly MembershipRows $rows,
        private readonly MembershipAwardConfiguration $configuration,
        private readonly MembershipPolicy $policy,
        private readonly array $membershipConfiguration,
        private readonly BillingPolicy $billingPolicy,
        private readonly array $billingConfiguration,
        private readonly ProductionCustomerPrincipal $principal,
        private readonly User $actor,
        private readonly int $deadline,
        string $locator,
    ) {
        MembershipAwards::consumeContextPermit($rows, $configuration, $policy, $membershipConfiguration,
            $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $locator);
        $this->admit();
        $this->memberHistoryReader = new MemberActivationHistoryReader;
        $this->access = new ProductionCustomerAccess;
        $this->identity = $this->access->lock($principal, $actor, $rows->current());
        // The invoice producer acquires its intake fence before policy/subscription/invoice locks.
        $this->invoiceProof = $configuration->invoices->lock($locator, $principal, $actor, $rows);
        $this->invoice = $this->invoiceProof->binding();
        $this->invoiceFreshness = $configuration->invoices->freshnessWindow($this->invoiceProof, $principal, $actor, $rows);
        MembershipException::require($this->invoice->planVersionId !== null && $this->invoice->paidAt !== null
            && $this->invoice->invoiceId !== null && $this->invoice->evidenceDeadline !== null, 'source_facts_absent');
        $this->policyProof = $configuration->policies->lock($this->invoice->planVersionId, $rows);
        $this->facts = $this->policyProof->binding();
        $this->plan = $rows->one(MembershipRecords::TABLE, 'id = ?', [$this->facts->planVersionId]);
        $document = MembershipPolicyVersions::document($this->plan);
        MembershipException::require($document->binding($this->facts->planVersionId) == $this->facts, 'changed_policy_facts');
        $this->match();
        $this->historical = $this->access->verifyHistoricalBinding($this->invoice->originalBuyerBinding, $rows->current());
        $database = $rows->driver() === 'mysql' ? $rows->identity()->query('SELECT DATABASE()')->fetchColumn() : 'main';
        MembershipException::require(is_string($database) && preg_match('/\A[a-zA-Z0-9_]+\z/D', $database) === 1, 'changed_schema');
        foreach ([MembershipRecords::TABLE, MembershipPeriodRecords::PERIODS, MembershipReservationRecords::TABLE, MembershipPeriodRecords::EVENTS] as $table) {
            $physical = (new MembershipSchema)->table($table);
            $this->tables[$table] = $rows->driver() === 'mysql' ? '`'.$database.'`.'.$physical : $physical;
        }
        $this->period = $rows->one(MembershipPeriodRecords::PERIODS, 'source_invoice_hash = ?', [$this->invoice->sourceInvoiceHash]);
        if ($this->period === []) {
            [$period, $event] = MembershipPeriodRecords::create($this->invoice, $this->facts,
                (int) $this->historical['origin']['id'], $this->access->durableBinding($principal));
            $this->proveSources();
            $this->insert(MembershipPeriodRecords::PERIODS, $period);
            $this->insert(MembershipPeriodRecords::EVENTS, $event);
            $this->period = $rows->one(MembershipPeriodRecords::PERIODS, 'id = ?', [$period['id']]);
        }
        $this->redemptions = $rows->rows(MembershipReservationRecords::TABLE, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1);
        $this->events = $rows->rows(MembershipPeriodRecords::EVENTS, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1);
        $this->memberHistory = $this->memberHistoryReader->capturePeriod($this->period['id'], $rows);
        $this->assertHistoryScope($this->period, $this->events, $this->redemptions);
        $this->projection = MembershipPeriodRecords::verify($this->period, $this->events, $this->invoice, $this->facts,
            (int) $this->historical['origin']['id'], $this->redemptions, $this->memberHistory->value());
        $this->proveCurrent();
    }

    public function projection(): array
    {
        return $this->projection;
    }

    public function reader(): CurrentRows
    {
        return new CurrentRows($this->rows->identity(), $this->rows->driver());
    }

    public function proveCurrent(): void
    {
        $this->memberHistoryReader->currentCallbacks($this->memberHistory, $this->rows);
        $this->proveSources();
        $billing = MembershipBillingEvidence::currentSnapshot($this->rows, $this->invoice, $this->billingConfiguration, $this->invoiceFreshness);
        $plan = $this->rows->one(MembershipRecords::TABLE, 'id = ?', [$this->facts->planVersionId]);
        MembershipPolicyVersions::document($plan);
        MembershipPeriodRecords::verify($this->period, $this->events, $this->invoice, $this->facts,
            (int) $this->historical['origin']['id'], $this->redemptions, $this->memberHistory->value());
        $this->access->proveCurrent($this->principal, $this->actor, $this->rows->current(), $this->identity);
        $this->assertHistoryScope($this->period, $this->events, $this->redemptions);
        // Nothing below decrypts, resolves a capability or invokes a clock hook.
        $this->memberHistoryReader->currentRaw($this->memberHistory, $this->rows);
        $this->assertEffects(false);
        MembershipBillingEvidence::assertCurrentSnapshot($this->rows, $billing);
        $this->admit();
        $this->rows->assertCurrent();
        $this->admit();
    }

    public function proveCommitted(IdentityCommittedFrame $frame): void
    {
        $frame->assertActive();
        MembershipException::require($frame->reader()->identityPrimary() === $this->rows->identity(), 'changed_primary');
        $this->admit();
        (new MembershipSchema)->assertOwned($this->rows->identity());
        $this->memberHistoryReader->committedCallbacks($this->memberHistory, $frame);
        $billing = MembershipBillingEvidence::committed($frame, $this->invoice, $this->billingConfiguration, $this->deadline, $this->invoiceFreshness);
        $plan = $this->read(MembershipRecords::TABLE, 'id = ?', [$this->facts->planVersionId], 2, false);
        MembershipException::require(count($plan) === 1, 'changed_policy_facts');
        MembershipPolicyVersions::document($plan[0]);
        $period = $this->read(MembershipPeriodRecords::PERIODS, 'id = ?', [$this->period['id']], 2, false);
        MembershipException::require(count($period) === 1, 'changed_period');
        $events = $this->read(MembershipPeriodRecords::EVENTS, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1, false);
        $redemptions = $this->read(MembershipReservationRecords::TABLE, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1, false);
        MembershipPeriodRecords::verify($period[0], $events, $this->invoice, $this->facts, (int) $this->historical['origin']['id'], $redemptions, $this->memberHistory->value());
        $this->access->proveHistoricalBindingCommitted($this->invoice->originalBuyerBinding, $frame, $this->historical);
        $this->access->proveCommitted($this->principal, $this->actor, $frame, $this->identity);
        $this->assertHistoryScope($period[0], $events, $redemptions);
        // Finish all callback-capable proof work before the final raw retained-row closure.
        $this->memberHistoryReader->committedRaw($this->memberHistory, $frame);
        $this->assertEffects(true);
        MembershipBillingEvidence::assertCommittedSnapshot($frame, $billing);
        $this->admit();
        $frame->finish();
        $this->admit();
    }

    private function proveSources(): void
    {
        $this->admit();
        $this->configuration->invoices->proveCurrent($this->invoiceProof, $this->principal, $this->actor, $this->rows);
        $this->configuration->policies->proveCurrent($this->policyProof, $this->rows);
        $this->access->proveCurrent($this->principal, $this->actor, $this->rows->current(), $this->identity);
        $this->admit();
        $this->rows->assertCurrent();
    }

    private function match(): void
    {
        $buyer = $this->invoice->originalBuyerBinding;
        $current = $this->access->durableBinding($this->principal);
        MembershipException::require($this->facts->supports('paid_period_award') && $this->facts->awardTerms !== null
            && $this->facts->planVersionId === $this->invoice->planVersionId
            && $this->facts->policyHash === $this->invoice->planPolicyHash
            && $this->facts->policyHash === $this->membershipConfiguration['approved_policy_hash']
            && $this->facts->originalTermsHash === $this->invoice->originalTermsHash
            && $this->facts->billingPriceMinor === $this->invoice->amountMinor && $this->facts->billingCurrency === $this->invoice->currency
            && $this->facts->provenance === $this->invoice->provenance && $this->facts->provenance === $this->principal->provenance
            && $this->facts->provenance === $this->membershipConfiguration['provenance']
            && $buyer['account_id'] === $this->principal->accountId && $buyer['user_id'] === $this->principal->userId
            && $buyer['origin_id'] === $current['origin_id'] && $buyer['account_public_id'] === $current['account_public_id'], 'conflicting_invoice');
        MembershipException::require($this->facts->awardTerms->admitsPayment($this->invoice->periodEnd, $this->invoice->paidAt), 'late_payment');
    }

    private function admit(): void
    {
        $this->configuration->admit();
        $this->policy->proveConfiguration($this->membershipConfiguration);
        $this->billingPolicy->proveConfiguration($this->billingConfiguration);
        MembershipAwardConfiguration::plainPrimary($this->rows->identity());
        MembershipException::require(hrtime(true) < $this->deadline, 'operation_deadline');
        $this->invoiceFreshness?->assertDeadline();
    }

    private function insert(string $table, array $row): void
    {
        $this->rows->execute('INSERT INTO '.$this->tables[$table].' ('.implode(', ', array_keys($row)).') VALUES ('
            .implode(', ', array_fill(0, count($row), '?')).')', array_values($row));
    }

    private function assertEffects(bool $committed): void
    {
        foreach ([[MembershipRecords::TABLE, 'id = ?', [$this->facts->planVersionId], 2, [$this->plan]],
            [MembershipPeriodRecords::PERIODS, 'source_invoice_hash = ?', [$this->invoice->sourceInvoiceHash], 2, [$this->period]],
            [MembershipReservationRecords::TABLE, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1, $this->redemptions],
            [MembershipPeriodRecords::EVENTS, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1, $this->events]] as [$table, $where, $bindings, $limit, $expected]) {
            $actual = $this->read($table, $where, $bindings, $limit, ! $committed);
            MembershipException::require(CanonicalJson::encode(array_map(MembershipRecords::strings(...), $actual))
                === CanonicalJson::encode(array_map(MembershipRecords::strings(...), $expected)), 'changed_retained_effect');
        }
    }

    /** The complete member graph and ordinary credit audit must use the exact same 257 scope. */
    private function assertHistoryScope(array $period, array $events, array $redemptions): void
    {
        $sort = static function (array $rows): array {
            usort($rows, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

            return array_map(MembershipRecords::strings(...), $rows);
        };
        $scope = $this->memberHistory->scope();
        MembershipException::require(MembershipRecords::strings($period) === MembershipRecords::strings($scope['period'])
            && $sort($events) === $sort($scope['events']) && $sort($redemptions) === $sort($scope['redemptions']), 'changed_retained_effect');
    }

    private function read(string $table, string $where, array $bindings, int $limit, bool $locking): array
    {
        MembershipAwardConfiguration::plainPrimary($this->rows->identity());
        $statement = $this->rows->identity()->prepare('SELECT * FROM '.$this->tables[$table].' WHERE '.$where.' ORDER BY id LIMIT '.$limit
            .($locking && $this->rows->driver() === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
