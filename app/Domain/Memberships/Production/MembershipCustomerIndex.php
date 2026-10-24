<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Grants\Member\MemberActivationHistoryReader;
use App\Domain\Grants\Member\MemberGrantCurrentSchema;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Domain\Grants\Member\MemberGrantRows;
use App\Domain\Grants\Member\MemberOriginalFinishBoundary;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;
use WeakMap;

/**
 * Bounded, read-only customer index of the authenticated owner's retained membership periods (C4d, locking revised in
 * C4e after review N1/N2).
 *
 * Locks. One transaction takes exactly one set of row locks: the current owner's T23 identity rows
 * (`ProductionCustomerAccess::lock`, the same rows and order every membership writer locks first and holds to commit).
 * Every other read in the frame (the period window, each period's events, redemptions and member graph, and the
 * shared plan-version, member-profile and member-definition rows) is a plain consistent read with no lock. So the
 * index never waits for, and never holds, any lock another customer's transaction needs: not the global billing award
 * fence, not a shared plan or definition row, and no row or gap of another owner's ranges. After the owner lock it
 * requests no further lock, so it cannot be part of a lock-order cycle with any writer, activation or other index.
 *
 * Correctness. All consistent reads of one REPEATABLE READ transaction see one transactionally consistent snapshot, so
 * no torn state between period, events, redemptions and member graph can be audited. While the owner lock is held no
 * writer of this owner's rows can commit. If the snapshot predates a writer that committed before the owner lock was
 * granted, the audited state is consistent but older; the terminal proof after commit (a new read-only committed frame,
 * whose snapshot follows the index commit) re-reads the window, every period's complete history and the support rows
 * and refuses on any difference, and the pre-bytes proof repeats the whole read. Plan, profile and definition rows are
 * append-only and immutable (their UPDATE and DELETE triggers always refuse and are re-proved by every guarded read),
 * so a missing row can only refuse. Every fetched period, the sentinel included, is authenticated through its complete
 * retained ledger audit (MembershipLedgerAudit::verify) against the retained invoice semantics, the period's immutable
 * plan and the current owner's original buyer binding. The output carries period, redemption and definition
 * identifiers only: no provider or billing-ledger identifier, hash, ciphertext or seal. A listed id confers nothing;
 * every writer and reader re-runs its own complete source closure.
 */
final class MembershipCustomerIndex
{
    /** Newest periods shown; one further sentinel row only reports that older periods exist. */
    public const LIMIT = MembershipPolicy::MAX_PERIODS;

    /** @var WeakMap<MembershipCustomerRead, array>|null */
    private static ?WeakMap $reads = null;

    public function index(ProductionCustomerPrincipal $principal, User $actor, ?MembershipCustomerRead $read = null): array
    {
        // One original 60-second budget covers this read and any later pre-bytes proof; it is never renewed.
        $deadline = hrtime(true) + 60_000_000_000;
        if ($read !== null) {
            self::$reads ??= new WeakMap;
            MembershipException::require(! isset(self::$reads[$read]), 'foreign_customer_read');
            // Claimed before any work: a failed index leaves the read unusable, never capturable later.
            self::$reads[$read] = ['state' => 'claimed'];
        }
        [$projection, $baseline] = $this->collect($principal, $actor, $deadline);
        if ($read !== null) {
            self::$reads[$read] = ['state' => 'captured', 'index' => $this, 'principal' => $principal, 'actor' => $actor,
                'deadline' => $deadline, 'projection' => $projection, 'baseline' => $baseline];
        }

        return $projection;
    }

    /**
     * Pre-bytes proof for a prebuilt index response (operations step 6). It re-runs the complete owned read, including
     * the current-owner lock, gates, window, every ledger audit and the committed terminal proof, under the original
     * deadline, and releases only when the fresh projection and gate baselines equal the captured ones. One use.
     *
     * @internal Called only through MembershipCustomerRead::proveBeforeBytes().
     */
    public static function proveCapturedBeforeBytes(MembershipCustomerRead $read): void
    {
        $entry = self::$reads[$read] ?? null;
        if (self::$reads !== null && isset(self::$reads[$read])) {
            self::$reads[$read] = ['state' => 'used'];
        }
        MembershipException::require(is_array($entry) && $entry['state'] === 'captured', 'foreign_customer_read');
        MembershipException::require(hrtime(true) < $entry['deadline'], 'operation_deadline');
        [$projection, $baseline] = $entry['index']->collect($entry['principal'], $entry['actor'], $entry['deadline']);
        MembershipException::require(CanonicalJson::encode($projection) === CanonicalJson::encode($entry['projection'])
            && $baseline === $entry['baseline'], 'changed_before_bytes');
        MembershipException::require(hrtime(true) < $entry['deadline'], 'operation_deadline');
    }

    private function collect(ProductionCustomerPrincipal $principal, User $actor, int $deadline): array
    {
        try {
            return $this->collectOwned($principal, $actor, $deadline);
        } catch (MembershipException $error) {
            throw $error;
        } catch (IdentityException $error) {
            throw new MembershipException('owner_refused', $error);
        } catch (Throwable $error) {
            throw new MembershipException('index_refused', $error);
        }
    }

    private function collectOwned(ProductionCustomerPrincipal $principal, User $actor, int $deadline): array
    {
        MembershipException::require(hrtime(true) < $deadline, 'operation_deadline');
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $this->outsideTransactions();
        $policy = new MembershipPolicy;
        $membership = $policy->current();
        $configuration = new MembershipAwardConfiguration(requireComplete: true);
        // Resolve the full gate against the already-captured complete registration baseline.
        $policy->current();
        $this->outsideTransactions();
        $configuration->admit();
        $policy->proveConfiguration($membership);
        MembershipException::require(DB::connection() === $connection && $connection->getRawPdo() === $primary, 'changed_primary');
        $grants = new MemberGrantPolicy;
        $finish = MemberOriginalFinishBoundary::capture();
        $history = new MemberActivationHistoryReader;
        $state = ['snapshots' => []];
        $admit = function () use ($configuration, $policy, $membership, $deadline): void {
            $configuration->admit();
            $policy->proveConfiguration($membership);
            MembershipException::require(hrtime(true) < $deadline, 'operation_deadline');
        };
        try {
            $projection = DB::transaction(function () use ($principal, $actor, $primary, $grants, $finish, $history, $admit, $membership, &$state): array {
                $rows = new MembershipRows;
                MembershipException::require($rows->identity() === $primary, 'changed_primary');
                $access = new ProductionCustomerAccess;
                // The owner's T23 rows are the only locks this frame takes; it serializes every writer of the owner's
                // rows (F3). Everything after it is a consistent read, so the index never waits on another customer.
                $identity = $access->lock($principal, $actor, $rows->current());
                $grantConfiguration = $grants->currentFor($rows);
                MembershipException::require($membership['provenance'] === $principal->provenance
                    && $grantConfiguration['provenance'] === $principal->provenance, 'provenance');
                $admit();
                $current = $access->durableBinding($principal);
                // Shared immutable facts (plan versions, member profiles and definitions): consistent reads, no locks.
                $facts = new MemberGrantRows($rows, MemberGrantCurrentSchema::pin($rows));
                $table = $this->periodTable($rows);
                $window = $this->window($rows->identity(), $rows->driver(), $table, $principal->accountId);
                $periods = [];
                foreach ($window as $row) {
                    $periods[] = $this->period($rows, $facts, $history, $access, $row, $principal, $identity, $current, $state);
                    $admit();
                }
                $definition = $this->definition($facts, $grantConfiguration);
                $state += ['rows' => $rows, 'access' => $access, 'identity' => $identity, 'table' => $table, 'account' => $principal->accountId,
                    'window' => $window, 'periods' => $periods, 'definition' => $definition, 'grants' => $grantConfiguration];
                $result = $this->projection($periods, $definition, $principal);
                // Current proof: callback-capable work first, then raw comparisons on the captured primary only.
                foreach ($periods as $period) {
                    $history->currentCallbacks($period['snapshot'], $rows);
                    $this->audit($period);
                    $access->proveHistoricalBindingCurrent($period['invoice']->originalBuyerBinding, $rows->current(), $period['historical']);
                }
                $grants->proveCurrent($grantConfiguration, $rows);
                $access->proveCurrent($principal, $actor, $rows->current(), $identity);
                $admit();
                $finish->assertFrame($rows->identity(), false);
                foreach ($periods as $period) {
                    $history->currentRaw($period['snapshot'], $rows);
                }
                $this->raw($rows->identity(), $rows->driver(), $state, false);
                $rows->assertCurrent();
                $finish->assertFrame($rows->identity(), false);
                $admit();

                return $result;
            });
            // Terminal proof after the ordinary commit and every committed delegate/callback.
            $admit();
            $frame = IdentityCommittedFrame::begin(new CurrentRows($primary, $state['rows']->driver()), $deadline);
            try {
                $frame->assertActive();
                MembershipException::require($frame->reader()->identityPrimary() === $primary, 'changed_primary');
                foreach ($state['periods'] as $period) {
                    $history->committedCallbacks($period['snapshot'], $frame);
                    $this->audit($period);
                    $state['access']->proveHistoricalBindingCommitted($period['invoice']->originalBuyerBinding, $frame, $period['historical']);
                }
                $state['access']->proveCommitted($principal, $actor, $frame, $state['identity']);
                $grants->proveCapturedConfiguration($state['grants']);
                $admit();
                $finish->assertFrame($primary, true);
                foreach ($state['periods'] as $period) {
                    $history->committedRaw($period['snapshot'], $frame);
                }
                $frame->assertActive();
                $this->raw($primary, $state['rows']->driver(), $state, true);
                $finish->assertFrame($primary, true);
                $admit();
                $frame->finish();
                $finish->assertCurrent();
                $admit();
            } finally {
                $frame->close();
            }

            return [$projection, ['membership' => $membership, 'member_grants' => $state['grants']]];
        } finally {
            foreach ($state['snapshots'] as $snapshot) {
                $history->retire($snapshot);
            }
        }
    }

    /** One fetched period (sentinel included): exact scope, retained invoice semantics and complete audit. */
    private function period(MembershipRows $rows, MemberGrantRows $facts, MemberActivationHistoryReader $history, ProductionCustomerAccess $access,
        array $row, ProductionCustomerPrincipal $principal, array $identity, array $current, array &$state): array
    {
        MembershipException::require(is_string($row['id'] ?? null), 'tampered');
        MembershipValues::id($row['id']);
        MembershipException::require((string) $row['account_id'] === (string) $principal->accountId
            && (string) $row['user_id'] === (string) $principal->userId
            && (string) $row['identity_origin_id'] === (string) $identity['origin']['id'], 'foreign_period');
        // Consistent reads only (N1, N2): no lock on the owner's member rows or on the shared facts they reference.
        $snapshot = $history->capturePeriod($row['id'], $rows, consistentRead: true);
        $state['snapshots'][] = $snapshot;
        $scope = $snapshot->scope();
        MembershipException::require(MembershipRecords::strings($scope['period']) === MembershipRecords::strings($row), 'changed_period');
        $rows->assertCurrent();
        $plan = $facts->plan($row['plan_version_id'], true);
        $rows->assertCurrent();
        MembershipException::require($plan !== [], 'policy_facts_absent');
        $policy = MembershipPolicyVersions::document($plan)->binding($plan['id']);
        MembershipException::require($policy->provenance === $principal->provenance, 'provenance');
        $awards = array_values(array_filter($scope['events'], static fn (array $event): bool => $event['kind'] === 'award'));
        MembershipException::require(count($awards) === 1, 'incomplete_history');
        $invoice = MembershipPeriodRecords::historicalInvoice($scope['period'], $awards[0], $policy, (int) $identity['origin']['id']);
        $buyer = $invoice->originalBuyerBinding;
        // The retained original buyer is the current durable owner (same account, user, origin and public account).
        MembershipException::require($buyer['account_id'] === $principal->accountId && $buyer['user_id'] === $principal->userId
            && $buyer['origin_id'] === $current['origin_id'] && $buyer['account_public_id'] === $current['account_public_id']
            && $invoice->provenance === $principal->provenance, 'foreign_period');
        $historical = $access->verifyHistoricalBinding($buyer, $rows->current());
        MembershipException::require((string) $historical['origin']['id'] === (string) $identity['origin']['id'], 'foreign_period');
        $period = ['snapshot' => $snapshot, 'plan' => $plan, 'policy' => $policy, 'invoice' => $invoice,
            'historical' => $historical, 'origin_id' => (int) $identity['origin']['id']];
        $period['audit'] = $this->audit($period);

        return $period;
    }

    private function audit(array $period): array
    {
        $scope = $period['snapshot']->scope();

        return MembershipLedgerAudit::verify($scope['period'], $scope['events'], $scope['redemptions'],
            $period['invoice'], $period['policy'], $period['origin_id'], $period['snapshot']->value());
    }

    /** The single approved member definition, applicable only to periods of its own plan version. */
    private function definition(MemberGrantRows $reader, array $grants): array
    {
        $definition = $reader->definitionByHash($grants['approved_definition_hash'], true);
        MembershipException::require($definition !== [], 'facts_absent');
        $profile = $reader->profile($definition['profile_id'], true);
        MembershipException::require($profile !== [], 'facts_absent');
        $snapshot = MemberGrantRecords::snapshot($profile, $definition);
        $planId = $snapshot->definitionDocument->value()['plan_version_id'];
        $plan = $reader->plan($planId, true);
        SealedMemberGrantFacts::plan($plan, $snapshot);
        MembershipException::require($snapshot->factsBinding->definitionHash === $grants['approved_definition_hash']
            && $snapshot->profileBinding->profileHash === $grants['approved_profile_hash']
            && $snapshot->factsBinding->originalTermsHash === $grants['approved_original_terms_hash']
            && $snapshot->factsBinding->provenance === $grants['provenance'], 'facts_not_approved');

        return ['id' => $definition['id'], 'plan_version_id' => $planId, 'definition' => $definition, 'profile' => $profile,
            'plan' => $plan, 'reader' => $reader];
    }

    private function projection(array $periods, array $definition, ProductionCustomerPrincipal $principal): array
    {
        $entries = [];
        foreach (array_slice($periods, 0, self::LIMIT) as $period) {
            $scope = $period['snapshot']->scope();
            $row = $scope['period'];
            $audit = $period['audit'];
            $reservations = [];
            foreach ($audit['reservations'] as $id => $reservation) {
                $selection = $reservation['payload']['intent']['selection'];
                MembershipException::require(is_int($selection['offer_id'] ?? null) && is_int($selection['offer_revision_id'] ?? null), 'tampered');
                $terminal = $reservation['terminal'];
                $reservations[] = ['id' => (string) $id, 'offerId' => $selection['offer_id'], 'offerRevisionId' => $selection['offer_revision_id'],
                    'creditAmount' => MembershipLedgerAudit::integer($reservation['record']['credit_amount']),
                    'reservedAt' => $reservation['record']['created_at'], 'honorDeadline' => $reservation['record']['honor_deadline'],
                    'status' => match ($terminal['kind'] ?? null) {
                        null => 'reserved',
                        'release' => 'released',
                        'consume' => 'consumed',
                    },
                    'closedAt' => $terminal['created_at'] ?? null];
            }
            usort($reservations, static fn (array $left, array $right): int => [$right['reservedAt'], $right['id']] <=> [$left['reservedAt'], $left['id']]);
            $entries[] = ['id' => $row['id'], 'planVersionId' => $row['plan_version_id'], 'periodStart' => $row['period_start'],
                'periodEnd' => $row['period_end'], 'creditExpiresAt' => $row['credit_expires_at'],
                'allowance' => MembershipLedgerAudit::integer($row['allowance']), 'balances' => $audit['balances'],
                'applicableDefinition' => $definition['plan_version_id'] === $row['plan_version_id'] ? ['id' => $definition['id']] : null,
                'reservations' => $reservations];
        }

        return ['schemaVersion' => 1, 'periodLimit' => self::LIMIT, 'hasMore' => count($periods) > self::LIMIT,
            'provenance' => $principal->provenance, 'periods' => $entries];
    }

    /** Exact bounded window and immutable support rows, compared byte for byte on the captured primary only. */
    private function raw(PDO $primary, string $driver, array $state, bool $committed): void
    {
        MembershipAwardConfiguration::plainPrimary($primary);
        $window = $this->window($primary, $driver, $state['table'], $state['account']);
        MembershipException::require(self::rows($window) === self::rows($state['window']), 'changed_period_window');
        $definition = $state['definition'];
        $reader = $definition['reader'];
        // Consistent reads in the current phase too (N1, N2); byte-identical or refused.
        MembershipException::require(MembershipRecords::strings($reader->definition($definition['id'], true)) === MembershipRecords::strings($definition['definition'])
            && MembershipRecords::strings($reader->profile($definition['profile']['id'], true)) === MembershipRecords::strings($definition['profile'])
            && MembershipRecords::strings($reader->plan($definition['plan']['id'], true)) === MembershipRecords::strings($definition['plan']), 'changed_definition');
        foreach ($state['periods'] as $period) {
            $plan = $reader->plan($period['plan']['id'], true);
            MembershipException::require(MembershipRecords::strings($plan) === MembershipRecords::strings($period['plan']), 'changed_policy_facts');
        }
        MembershipAwardConfiguration::plainPrimary($primary);
    }

    /** The owner's newest LIMIT periods plus one sentinel, in one fixed order; rows only, never authority. */
    private function window(PDO $primary, string $driver, string $table, int $accountId): array
    {
        MembershipAwardConfiguration::plainPrimary($primary);
        $statement = $primary->prepare('SELECT * FROM '.$table.' WHERE account_id = ? ORDER BY period_start DESC, id DESC LIMIT '
            .(self::LIMIT + 1));
        $statement->execute([$accountId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        MembershipException::require(count($rows) <= self::LIMIT + 1, 'technical_bound');

        return $rows;
    }

    private function periodTable(MembershipRows $rows): string
    {
        $physical = (new MembershipSchema)->table(MembershipPeriodRecords::PERIODS);
        if ($rows->driver() !== 'mysql') {
            return $physical;
        }
        $database = $rows->identity()->query('SELECT DATABASE()')->fetchColumn();
        MembershipException::require(is_string($database) && preg_match('/\A[a-zA-Z0-9_]+\z/D', $database) === 1, 'changed_schema');

        return '`'.$database.'`.'.$physical;
    }

    private static function rows(array $rows): array
    {
        return array_map(MembershipRecords::strings(...), $rows);
    }

    private function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $source) {
            $sourcePrimary = $source->getPdo();
            MembershipAwardConfiguration::plainPrimary($sourcePrimary);
            MembershipException::require($source->transactionLevel() === 0 && ! $sourcePrimary->inTransaction(), 'transaction_forbidden');
        }
    }
}
