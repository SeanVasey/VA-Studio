<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Billing\BillingFreshnessWindow;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use PDO;
use WeakMap;

/** Internal owned reservation lifecycle. A read-only inspection never receives mutation authority. */
final class MembershipReservationContext
{
    private ProductionCustomerAccess $access;

    private array $identity;

    private array $historical;

    private MembershipPaidInvoiceProof $invoiceProof;

    private ?BillingFreshnessWindow $invoiceFreshness = null;

    private MembershipPolicyFactsProof $policyProof;

    private MembershipInvoiceBinding $invoice;

    private MembershipPolicyBinding $facts;

    private array $plan;

    private array $period;

    private array $events;

    private array $tables;

    private array $projection;

    private array $redemptions;

    private array $audit;

    private ?string $redemptionId = null;

    private ?CurrentEligibleLicenses $licenses = null;

    private ?MembershipEligibleLicenseProof $licenseProof = null;

    private ?MembershipCatalogSnapshot $catalog = null;

    private int $authorityDeadline = PHP_INT_MAX;

    /** Honor alone survives detached member work; catalog/invoice leases do not cross frames. */
    private int $honorDeadlineNs = PHP_INT_MAX;

    private ?array $newEvent = null;

    private int $createdAtNs;

    private int $createdAtMicros;

    private static ?WeakMap $inspectionPermits = null;

    private static ?WeakMap $reservationPermits = null;

    /** Exact issuer-owned capture; phase entry never supplies a mutation permit. */
    private ?MembershipMemberCapture $memberCapture = null;

    private ?array $memberPhase = null;

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
        private readonly string $operation,
        string $locator,
        private readonly array $arguments,
    ) {
        if ($operation === 'inspect') {
            $permit = self::$inspectionPermits[$rows] ?? null;
            if (self::$inspectionPermits !== null) {
                unset(self::$inspectionPermits[$rows]);
            }
            MembershipException::require($permit === [$rows->identity(), $configuration, $policy, $membershipConfiguration,
                $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $operation, $locator, $arguments], 'owned_invocation_required');
        } else {
            MembershipReservationOperations::consumeContextPermit($rows, $configuration, $policy, $membershipConfiguration,
                $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $operation, $locator, $arguments);
        }
        MembershipException::require(in_array($operation, ['reserve', 'release', 'expire', 'inspect'], true), 'unsupported_operation');
        $this->admit();
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
        if (in_array($operation, ['reserve', 'inspect'], true)) {
            $licenses = app(MembershipEligibleLicenseAuthority::class);
            $configuration->admit();
            MembershipException::require($licenses instanceof CurrentEligibleLicenses, 'unsupported_license_authority');
            $this->licenses = $licenses;
            $this->licenseProof = $licenses->lock($arguments['selection'], $arguments['expected_revision'], $principal, $actor, $rows);
            $this->catalog = $licenses->snapshot($this->licenseProof, $principal, $actor, $rows);
        }
        $database = $rows->driver() === 'mysql' ? $rows->identity()->query('SELECT DATABASE()')->fetchColumn() : 'main';
        MembershipException::require(is_string($database) && preg_match('/\A[a-zA-Z0-9_]+\z/D', $database) === 1, 'changed_schema');
        foreach ([MembershipRecords::TABLE, MembershipPeriodRecords::PERIODS, MembershipReservationRecords::TABLE, MembershipPeriodRecords::EVENTS] as $table) {
            $physical = (new MembershipSchema)->table($table);
            $this->tables[$table] = $rows->driver() === 'mysql' ? '`'.$database.'`.'.$physical : $physical;
        }
        $this->period = $rows->one(MembershipPeriodRecords::PERIODS, 'source_invoice_hash = ?', [$this->invoice->sourceInvoiceHash]);
        MembershipException::require($this->period !== [], 'paid_period_absent');
        $this->refreshLedger();
        $this->createdAtNs = hrtime(true);
        $capturedAt = CarbonImmutable::now('UTC');
        $this->createdAtMicros = (int) $capturedAt->format('Uu');
        $at = $capturedAt->format('Y-m-d H:i:s');
        if ($operation === 'reserve') {
            $this->reserve($at);
        } elseif ($operation === 'release') {
            $this->release($at);
        } elseif ($operation === 'expire') {
            $this->expire($at);
        } else {
            $this->redemptionId = $arguments['redemption_id'];
            $reservation = $this->reservation();
            MembershipException::require(CanonicalJson::encode($reservation['payload']['intent']) === CanonicalJson::encode(MembershipReservationRecords::intent(
                $this->period, $this->invoice, $this->facts, $this->licenseProof->binding())), 'changed_reservation_intent');
            $this->projection = [];
        }
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
        $this->currentRaw($this->currentCallbacks());
    }

    public function proveCommitted(IdentityCommittedFrame $frame): void
    {
        $this->committedRaw($frame, $this->committedCallbacks($frame));
        $frame->finish();
        $this->admit();
    }

    /** Bind exactly once while the concrete issuer still owns this newly created inspection. */
    public function bindMemberCapture(MembershipMemberCapture $capture): void
    {
        MembershipException::require($this->operation === 'inspect' && $this->memberCapture === null, 'foreign_member_capture');
        MembershipReservations::consumeMemberContextPermit($capture, $this, $this->rows);
        $this->memberCapture = $capture;
    }

    public function memberCurrentCallbacks(MembershipMemberCapture $capture): void
    {
        $this->member($capture);
        $this->memberPhase = null;
        $billing = $this->currentCallbacks();
        $this->memberPhase = ['current', $billing];
    }

    public function memberCurrentRaw(MembershipMemberCapture $capture): void
    {
        $this->member($capture);
        $phase = $this->memberPhase;
        $this->memberPhase = null;
        MembershipException::require(is_array($phase) && $phase[0] === 'current', 'incomplete_member_phase');
        $this->currentRaw($phase[1]);
    }

    public function memberCommittedCallbacks(MembershipMemberCapture $capture, IdentityCommittedFrame $frame): void
    {
        $this->member($capture);
        $this->memberPhase = null;
        $billing = $this->committedCallbacks($frame);
        $this->memberPhase = ['committed', $frame, $billing];
    }

    public function memberCommittedRaw(MembershipMemberCapture $capture, IdentityCommittedFrame $frame): void
    {
        $this->member($capture);
        $phase = $this->memberPhase;
        $this->memberPhase = null;
        MembershipException::require(is_array($phase) && $phase[0] === 'committed' && $phase[1] === $frame, 'incomplete_member_phase');
        $this->committedRaw($frame, $phase[2]);
    }

    public function retireMemberCapture(MembershipMemberCapture $capture): void
    {
        $this->member($capture);
        $this->memberCapture = null;
        $this->memberPhase = null;
    }

    /** Copied values only. Original invoice/eligibility leases stay in this private inspection. */
    public function memberSourceValues(MembershipMemberCapture $capture): array
    {
        $this->member($capture);
        $reservation = $this->reservation();
        MembershipException::require($reservation['terminal'] === null, 'reservation_terminal');
        $record = $reservation['record'];

        return ['buyer_binding' => $this->invoice->originalBuyerBinding,
            'invoice' => MembershipPeriodRecords::semantic($this->invoice),
            'period' => ['id' => $this->period['id'], 'seal' => $this->period['seal']],
            'redemption' => ['id' => $record['id'], 'seal' => $record['seal'], 'intent_hash' => $record['intent_hash'],
                'credit_amount' => (int) $record['credit_amount'], 'created_at' => $record['created_at'], 'honor_deadline' => $record['honor_deadline']],
            'reserve_event' => ['id' => $reservation['reserve']['id'], 'seal' => $reservation['reserve']['seal']],
            'selection' => $reservation['payload']['intent']['selection']];
    }

    /** Live producer objects are available only to the exact registered issuing capture. */
    public function memberIntent(MembershipMemberCapture $capture, MembershipReservationProof $reservation): MemberGrantIntent
    {
        $this->member($capture);
        MembershipException::require($this->licenseProof !== null && $reservation->binding() == $this->reservationBinding(), 'foreign_member_capture');

        return new MemberGrantIntent($this->invoiceProof, $this->licenseProof, $reservation);
    }

    public function memberHonorDeadline(MembershipMemberCapture $capture): int
    {
        $this->member($capture);
        MembershipException::require($this->honorDeadlineNs !== PHP_INT_MAX, 'foreign_member_capture');

        return $this->honorDeadlineNs;
    }

    /** Final elapsed/configuration admission only; never calls a closed Rows proof or renews a producer. */
    public function memberPostFinish(MembershipMemberCapture $capture): void
    {
        $this->member($capture);
        $this->admit();
    }

    private function member(MembershipMemberCapture $capture): void
    {
        MembershipException::require($this->memberCapture === $capture && $this->operation === 'inspect', 'foreign_member_capture');
    }

    private function currentCallbacks(): array
    {
        $this->proveSources();
        $billing = MembershipBillingEvidence::currentSnapshot($this->rows, $this->invoice, $this->billingConfiguration, $this->invoiceFreshness);
        $plan = $this->rows->one(MembershipRecords::TABLE, 'id = ?', [$this->facts->planVersionId]);
        MembershipPolicyVersions::document($plan);
        MembershipLedgerAudit::verify($this->period, $this->events, $this->redemptions, $this->invoice, $this->facts,
            (int) $this->historical['origin']['id']);
        $this->checkDeadlines();
        $this->access->proveCurrent($this->principal, $this->actor, $this->rows->current(), $this->identity);

        return $billing;
    }

    private function currentRaw(array $billing): void
    {
        // All participating producer callbacks must have completed before entering this phase.
        $this->assertEffects(false);
        MembershipBillingEvidence::assertCurrentSnapshot($this->rows, $billing);
        $this->assertCatalog(true);
        $this->admit();
        $this->rows->assertCurrent();
        $this->admit();
    }

    private function committedCallbacks(IdentityCommittedFrame $frame): array
    {
        $frame->assertActive();
        MembershipException::require($frame->reader()->identityPrimary() === $this->rows->identity(), 'changed_primary');
        $this->admit();
        (new MembershipSchema)->assertOwned($this->rows->identity());
        $billing = MembershipBillingEvidence::committed($frame, $this->invoice, $this->billingConfiguration, $this->deadline, $this->invoiceFreshness);
        $plan = $this->read(MembershipRecords::TABLE, 'id = ?', [$this->facts->planVersionId], 2, false);
        MembershipException::require(count($plan) === 1, 'changed_policy_facts');
        MembershipPolicyVersions::document($plan[0]);
        $period = $this->read(MembershipPeriodRecords::PERIODS, 'id = ?', [$this->period['id']], 2, false);
        MembershipException::require(count($period) === 1, 'changed_period');
        $events = $this->read(MembershipPeriodRecords::EVENTS, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1, false);
        $redemptions = $this->read(MembershipReservationRecords::TABLE, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1, false);
        MembershipLedgerAudit::verify($period[0], $events, $redemptions, $this->invoice, $this->facts, (int) $this->historical['origin']['id']);
        if ($this->catalog !== null) {
            MembershipCatalogEvidence::proveBytes($this->catalog);
        }
        $this->checkDeadlines();
        $this->access->proveHistoricalBindingCommitted($this->invoice->originalBuyerBinding, $frame, $this->historical);
        $this->access->proveCommitted($this->principal, $this->actor, $frame, $this->identity);

        return $billing;
    }

    private function committedRaw(IdentityCommittedFrame $frame, array $billing): void
    {
        $this->assertEffects(true);
        MembershipBillingEvidence::assertCapturedSnapshot($this->rows->identity(), $billing);
        $this->assertCatalog(false);
        $this->admit();
    }

    /** Value only: the issuing registry and original frame separately establish authority. */
    public function reservationBinding(): MembershipReservationBinding
    {
        $reservation = $this->reservation();
        $record = $reservation['record'];
        MembershipException::require($reservation['terminal'] === null, 'reservation_terminal');

        return new MembershipReservationBinding($record['id'], $this->period['id'], $reservation['reserve']['id'],
            $record['intent_hash'], (int) $record['credit_amount'], $record['honor_deadline'], $this->invoice->originalBuyerBinding);
    }

    /** Read-only load for an authority's lock(); this path can never select a mutation operation. */
    public static function inspect(string $redemptionId, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): self
    {
        return self::inspectAt($redemptionId, $principal, $actor, $rows, hrtime(true) + 60_000_000_000);
    }

    public static function inspectMember(MembershipMemberCapture $capture, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows, int $deadline): self
    {
        MembershipReservations::consumeMemberInspectionPermit($capture, $redemptionId, $principal, $actor, $rows, $deadline);

        return self::inspectAt($redemptionId, $principal, $actor, $rows, $deadline);
    }

    private static function inspectAt(string $redemptionId, ProductionCustomerPrincipal $principal, User $actor,
        MembershipRows $rows, int $deadline): self
    {
        MembershipValues::id($redemptionId);
        MembershipException::require(hrtime(true) < $deadline, 'operation_deadline');
        $policy = new MembershipPolicy;
        $membership = $policy->current();
        $billingPolicy = new BillingPolicy;
        $billing = $billingPolicy->current();
        $configuration = new MembershipAwardConfiguration(requireComplete: true);
        $policy->current();
        $configuration->admit();
        $rows->assertCurrent();
        (new BillingSchema)->assertOwned($rows->identity());
        // Nonlocking routing supplies no authority; the constructor locks and authenticates it afresh.
        $query = $rows->identity()->prepare('SELECT r.payload_ciphertext, i.id invoice_id FROM '
            .(new MembershipSchema)->table(MembershipReservationRecords::TABLE).' r JOIN '
            .(new MembershipSchema)->table(MembershipPeriodRecords::PERIODS).' p ON p.id = r.period_id JOIN '
            .(new BillingSchema)->table(BillingSchema::TABLES[1])
            .' i ON i.source_invoice_hash = p.source_invoice_hash WHERE r.id = ? LIMIT 2');
        $query->execute([$redemptionId]);
        $route = $query->fetchAll(PDO::FETCH_ASSOC);
        MembershipException::require(count($route) === 1 && is_string($route[0]['payload_ciphertext'])
            && strlen($route[0]['payload_ciphertext']) <= 131072, 'reservation_absent');
        try {
            $plain = Crypt::decryptString($route[0]['payload_ciphertext']);
            MembershipException::require(strlen($plain) <= 65536, 'tampered');
            $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
            $selection = $payload['intent']['selection'];
            MembershipException::require(is_int($selection['offer_id']) && is_int($selection['offer_revision_id']), 'tampered');
        } catch (\Throwable) {
            throw new MembershipException('tampered');
        }
        $arguments = ['redemption_id' => $redemptionId, 'selection' => (string) $selection['offer_id'],
            'expected_revision' => $selection['offer_revision_id']];
        $locator = $route[0]['invoice_id'];
        self::$inspectionPermits ??= new WeakMap;
        self::$inspectionPermits[$rows] = [$rows->identity(), $configuration, $policy, $membership,
            $billingPolicy, $billing, $principal, $actor, $deadline, 'inspect', $locator, $arguments];
        try {
            return new self($rows, $configuration, $policy, $membership, $billingPolicy, $billing,
                $principal, $actor, $deadline, 'inspect', $locator, $arguments);
        } finally {
            unset(self::$inspectionPermits[$rows]);
        }
    }

    /** Only this context's owned reserve path can authorize this exact single proof mint. */
    public static function consumeReservationPermit(MembershipReservations $issuer, MembershipPaidInvoiceProof $invoice,
        MembershipEligibleLicenseProof $license, string $requestKey, ProductionCustomerPrincipal $principal,
        User $actor, MembershipRows $rows): self
    {
        $permit = self::$reservationPermits[$rows] ?? null;
        if (self::$reservationPermits !== null) {
            unset(self::$reservationPermits[$rows]);
        }
        MembershipException::require(is_array($permit) && count($permit) === 8
            && array_slice($permit, 0, 7) === [$issuer, $invoice, $license, $requestKey, $principal, $actor, $rows->identity()], 'owned_invocation_required');

        return $permit[7];
    }

    private function reserve(string $at): void
    {
        $key = $this->arguments['request_key'];
        $hash = MembershipReservationRecords::requestKey($this->invoice, $key);
        $existing = $this->rows->one(MembershipReservationRecords::TABLE, 'request_key_hash = ?', [$hash]);
        $intent = MembershipReservationRecords::intent($this->period, $this->invoice, $this->facts, $this->licenseProof->binding());
        if ($existing !== []) {
            MembershipException::require($existing['period_id'] === $this->period['id']
                && $existing['intent_hash'] === CanonicalJson::hash($intent), 'conflicting_request');
            $this->redemptionId = $existing['id'];
            $reservation = $this->reservation();
            MembershipException::require($reservation['terminal'] === null && $at < $existing['honor_deadline'], 'reservation_terminal');
        } else {
            $record = MembershipReservationRecords::create($this->period, $this->invoice, $this->facts,
                $this->licenseProof->binding(), $key, $at);
            $event = MembershipReservationRecords::reserveEvent($this->audit, $record, $this->access->durableBinding($this->principal), $at);
            $this->newEvent = $event;
            $this->proveSources();
            $this->insert(MembershipReservationRecords::TABLE, $record);
            $this->insert(MembershipPeriodRecords::EVENTS, $event);
            $this->redemptionId = $record['id'];
            $this->refreshLedger();
        }
        $issuer = new MembershipReservations;
        self::$reservationPermits ??= new WeakMap;
        self::$reservationPermits[$this->rows] = [$issuer, $this->invoiceProof, $this->licenseProof, $key,
            $this->principal, $this->actor, $this->rows->identity(), $this];
        try {
            $binding = $issuer->reserve($this->invoiceProof, $this->licenseProof, $key, $this->principal, $this->actor, $this->rows)->binding();
        } finally {
            unset(self::$reservationPermits[$this->rows]);
        }
        $this->projection = ['period_id' => $binding->periodId, 'redemption_id' => $binding->redemptionId,
            'reservation_event_id' => $binding->reservationId, 'credit_amount' => $binding->creditAmount,
            'honor_deadline' => $binding->honorDeadline, 'status' => 'reserved', 'balances' => $this->audit['balances']];
    }

    private function release(string $at): void
    {
        MembershipValues::id($this->arguments['redemption_id']);
        $this->redemptionId = $this->arguments['redemption_id'];
        $reservation = $this->reservation();
        MembershipException::require($at >= $reservation['record']['honor_deadline'], 'honor_not_expired');
        if ($reservation['terminal'] === null) {
            $event = MembershipReservationRecords::releaseEvent($this->audit, $this->redemptionId, $this->facts,
                $this->access->durableBinding($this->principal), $at);
            $this->newEvent = $event;
            $this->proveSources();
            $this->insert(MembershipPeriodRecords::EVENTS, $event);
            $this->refreshLedger();
            $reservation = $this->reservation();
        }
        MembershipException::require($reservation['terminal']['kind'] === 'release', 'reservation_terminal');
        $this->projection = ['period_id' => $this->period['id'], 'redemption_id' => $this->redemptionId,
            'reservation_event_id' => $reservation['reserve']['id'], 'release_event_id' => $reservation['terminal']['id'],
            'status' => 'released', 'balances' => $this->audit['balances']];
    }

    private function expire(string $at): void
    {
        MembershipException::require($this->period['credit_expires_at'] !== null
            && $at >= $this->period['credit_expires_at'], 'credit_not_expired');
        if ($this->audit['balances']['available'] > 0) {
            $event = MembershipReservationRecords::expireEvent($this->audit, $this->facts,
                $this->access->durableBinding($this->principal), $at);
            $this->newEvent = $event;
            $this->proveSources();
            $this->insert(MembershipPeriodRecords::EVENTS, $event);
            $this->refreshLedger();
        }
        $expired = array_values(array_filter($this->events, static fn (array $event): bool => $event['kind'] === 'expire'));
        $this->projection = ['period_id' => $this->period['id'], 'expiry_event_id' => $expired[0]['id'] ?? null,
            'status' => 'available_credit_expired', 'balances' => $this->audit['balances']];
    }

    private function refreshLedger(): void
    {
        $this->redemptions = $this->rows->rows(MembershipReservationRecords::TABLE, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1);
        $this->events = $this->rows->rows(MembershipPeriodRecords::EVENTS, 'period_id = ?', [$this->period['id']], MembershipPolicy::MAX_EVENTS + 1);
        $this->audit = MembershipLedgerAudit::verify($this->period, $this->events, $this->redemptions, $this->invoice, $this->facts,
            (int) $this->historical['origin']['id']);
    }

    private function reservation(): array
    {
        MembershipException::require($this->redemptionId !== null && isset($this->audit['reservations'][$this->redemptionId]), 'reservation_absent');

        return $this->audit['reservations'][$this->redemptionId];
    }

    private function checkDeadlines(): void
    {
        $startedNs = hrtime(true);
        $now = CarbonImmutable::now('UTC');
        if (in_array($this->operation, ['reserve', 'inspect'], true)) {
            $reservation = $this->reservation();
            MembershipException::require($reservation['terminal'] === null
                && $now->format('Y-m-d H:i:s') < $reservation['record']['honor_deadline'], 'reservation_terminal');
            $honorMicros = (int) (new \DateTimeImmutable($reservation['record']['honor_deadline'], new \DateTimeZone('UTC')))->format('Uu');
            $this->honorDeadlineNs = min($this->honorDeadlineNs,
                $this->createdAtNs + ($honorMicros - $this->createdAtMicros) * 1000,
                $startedNs + ($honorMicros - (int) $now->format('Uu')) * 1000);
            $this->capWallDeadline($honorMicros, (int) $now->format('Uu'), $startedNs);
        }
        $expiry = $this->period['credit_expires_at'];
        if ($this->newEvent !== null && $expiry !== null) {
            $returnsAvailable = $this->newEvent['kind'] === 'release'
                && (int) $this->newEvent['after_available'] > (int) $this->newEvent['before_available'];
            if ($this->newEvent['kind'] === 'reserve' || $returnsAvailable) {
                MembershipException::require($now->format('Y-m-d H:i:s') < $expiry, 'credit_expired');
                $expiryMicros = (int) (new \DateTimeImmutable($expiry, new \DateTimeZone('UTC')))->format('Uu');
                $this->capWallDeadline($expiryMicros, (int) $now->format('Uu'), $startedNs);
            } else {
                MembershipException::require($now->format('Y-m-d H:i:s') >= $expiry, 'credit_not_expired');
            }
        }
        if ($this->operation === 'release') {
            MembershipException::require($now->format('Y-m-d H:i:s') >= $this->reservation()['record']['honor_deadline'], 'honor_not_expired');
        }
        if ($this->operation === 'expire') {
            MembershipException::require($expiry !== null && $now->format('Y-m-d H:i:s') >= $expiry, 'credit_not_expired');
        }
        if ($this->catalog !== null) {
            MembershipException::require((int) $now->format('Uu') < $this->catalog->binding->validUntilUnixMicros
                && hrtime(true) < $this->catalog->deadlineNs, 'expired_license_proof');
            $this->authorityDeadline = min($this->authorityDeadline, $this->catalog->deadlineNs);
            $this->capWallDeadline($this->catalog->binding->validUntilUnixMicros, (int) $now->format('Uu'), $startedNs);
        }
    }

    private function capWallDeadline(int $wallMicros, int $nowMicros, int $startedNs): void
    {
        // Preserve the first paired clock capture made with the retained event time, before encrypt/proof callbacks.
        $this->authorityDeadline = min($this->authorityDeadline,
            $this->createdAtNs + ($wallMicros - $this->createdAtMicros) * 1000,
            $startedNs + ($wallMicros - $nowMicros) * 1000);
    }

    private function assertCatalog(bool $locking): void
    {
        if ($this->catalog !== null) {
            MembershipCatalogEvidence::assertCurrent($this->catalog, $this->rows->identity(), $this->rows->driver(), $locking);
            MembershipException::require(hrtime(true) < $this->catalog->deadlineNs, 'expired_license_proof');
        }
    }

    private function proveSources(): void
    {
        $this->admit();
        $this->configuration->invoices->proveCurrent($this->invoiceProof, $this->principal, $this->actor, $this->rows);
        $this->configuration->policies->proveCurrent($this->policyProof, $this->rows);
        if ($this->licenseProof !== null) {
            $this->licenses->proveCurrent($this->licenseProof, $this->principal, $this->actor, $this->rows);
        }
        $this->access->proveCurrent($this->principal, $this->actor, $this->rows->current(), $this->identity);
        $this->admit();
        $this->rows->assertCurrent();
    }

    private function match(): void
    {
        $buyer = $this->invoice->originalBuyerBinding;
        $current = $this->access->durableBinding($this->principal);
        MembershipException::require($this->facts->supports($this->operation === 'inspect' ? 'reserve' : $this->operation)
            && $this->facts->redemptionTerms !== null && $this->facts->awardTerms !== null
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
        MembershipException::require(hrtime(true) < min($this->deadline, $this->authorityDeadline), 'operation_deadline');
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

    private function read(string $table, string $where, array $bindings, int $limit, bool $locking): array
    {
        MembershipAwardConfiguration::plainPrimary($this->rows->identity());
        $statement = $this->rows->identity()->prepare('SELECT * FROM '.$this->tables[$table].' WHERE '.$where.' ORDER BY id LIMIT '.$limit
            .($locking && $this->rows->driver() === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
