<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingDatabaseClock;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingHintRecovery;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingSettlement;
use App\Domain\Memberships\Billing\BillingValues;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/** Synthetic exact legacy formats, authenticated before upgrade; not old-writer or host provenance. */
class BillingLegacyObservationTransitionTest extends TestCase
{
    use FinalizationDatabaseMigrations { migrateFreshUsing as private baselineMigrateFreshUsing; }

    private const LEGACY_TIME = '2099-01-01 00:00:00';

    protected function migrateFreshUsing(): array
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn (string $path): bool => basename($path) < '2026_10_13_263000_membership_billing_retrieval_identity.php'));

        return [...$this->baselineMigrateFreshUsing(), '--path' => $paths, '--realpath' => true];
    }

    public static function legacyVersions(): array
    {
        return ['original v1 envelope' => [1], 'original v2 row binding' => [2]];
    }

    #[DataProvider('legacyVersions')]
    public function test_authenticated_future_legacy_tail_is_preserved_but_only_a_new_database_interval_is_current(int $version): void
    {
        F::configure();
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->assertOwned($pdo);
        $binding = F::binding();
        $hint = $this->legacyHint();
        $legacy = $this->legacySettledObservation($binding, $version);
        $before = $this->snapshot();
        $this->assertSame($version, BillingEvidenceRecord::read($legacy, BillingEvidenceRecord::OBSERVATION)['schema_version']);
        $this->assertGreaterThan($hint['hint_position'], $legacy['retrieval_position']);
        $this->assertGreaterThan(BillingDatabaseClock::sample($pdo, DB::getDriverName())->stamp19(), $legacy['created_at']);
        $this->assertArrayNotHasKey('retrieval_token', $legacy);

        (new BillingRetrievalSchema)->upgrade();
        $upgraded = $this->snapshot();
        $this->assertOriginalRows($before);
        $storedLegacy = $this->observation($legacy['id']);
        $this->assertSame([null, null, null], [$storedLegacy['retrieval_token'], $storedLegacy['start_allocation_token'], $storedLegacy['end_allocation_token']]);
        $legacyPayload = BillingEvidenceRecord::read($storedLegacy, BillingEvidenceRecord::OBSERVATION);
        $this->assertSame($version, $legacyPayload['schema_version']);
        $ledger = new BillingLedger;
        $this->assertNull($ledger->currentSettled($legacy['invoice_id']), 'A future legacy deadline never supplies database-clock freshness.');
        $configuration = (new BillingPolicy)->current();
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE], (new BillingHintRecovery)->pending($hint, $configuration));

        $beforeRead = BillingDatabaseClock::sample($pdo, DB::getDriverName());
        $fresh = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        $afterRead = BillingDatabaseClock::sample($pdo, DB::getDriverName());
        $freshPayload = BillingEvidenceRecord::verify($fresh, BillingEvidenceRecord::OBSERVATION);
        $this->assertSame(['settled', 2, $legacy['seal']], [$fresh['outcome'], $fresh['sequence'], $fresh['prior_seal']]);
        $this->assertSame(3, $freshPayload['schema_version']);
        $this->assertSame(CanonicalJson::encode($legacyPayload['facts']), CanonicalJson::encode($freshPayload['facts']), 'Provider paid facts are unchanged by the new clock basis.');
        $this->assertSame('2026-10-07 00:00:00', $freshPayload['facts']['paid_at']);
        $this->assertGreaterThanOrEqual($beforeRead->stamp19(), $fresh['retrieved_at']);
        $this->assertLessThanOrEqual($afterRead->stamp19(), $fresh['retrieved_at']);
        $this->assertLessThan($legacy['created_at'], $fresh['created_at']);
        $this->assertSame($fresh['id'], $ledger->currentSettled($legacy['invoice_id'])['id']);
        $ledger->assertRetainedObservationInterval($fresh);
        $this->assertNull((new BillingHintRecovery)->pending($hint, $configuration));
        $this->assertCount(2, $ledger->observations($legacy['invoice_id']));
        $this->assertOriginalRows($before);
        $this->assertOriginalRows($upgraded);
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    private function legacySettledObservation(array $binding, int $version): array
    {
        $invoice = ['id' => BillingValues::id(), 'subscription_binding_id' => $binding['id'],
            'invoice_ref_hash' => BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE),
            'source_invoice_hash' => BillingValues::hash('source-invoice', F::ACCOUNT, 'test', F::INVOICE),
            'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test',
            'payload_ciphertext' => BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_invoice',
                'invoice_ref' => F::INVOICE, 'subscription_binding_id' => $binding['id']]), 'created_at' => self::LEGACY_TIME];
        $invoice['seal'] = BillingValues::seal($invoice);
        DB::table(BillingSchema::TABLES[1])->insert($invoice);
        $verdict = BillingSettlement::evaluate(F::snapshots(F::graph(), strtotime(self::LEGACY_TIME.' UTC')), F::expectation());
        $this->assertSame('settled', $verdict->outcome);
        $facts = ['outcome' => $verdict->outcome, 'reason' => $verdict->reason, 'facts' => $verdict->facts];
        $row = ['id' => BillingValues::id(), 'invoice_id' => $invoice['id'], 'sequence' => 1, 'outcome' => 'settled',
            'facts_hash' => CanonicalJson::hash($facts), 'line_period_start' => $verdict->facts['line_period_start'],
            'line_period_end' => $verdict->facts['line_period_end'], 'amount_minor' => $verdict->facts['amount_minor'], 'currency' => $verdict->facts['currency'],
            'retrieved_at' => self::LEGACY_TIME, 'retrieval_started_at' => self::LEGACY_TIME.'.000000',
            'retrieval_position' => $this->legacyPosition('retrieval'), 'retrieval_end_position' => $this->legacyPosition('retrieval'),
            'freshness_deadline' => '2099-01-01 00:10:00', 'api_version' => BillingProviderPin::API_VERSION,
            'sdk_reference' => BillingProviderPin::SDK_REFERENCE, 'prior_seal' => str_repeat('0', 64), 'created_at' => self::LEGACY_TIME];
        $row['payload_ciphertext'] = BillingValues::encrypt(['schema_version' => $version, 'purpose' => BillingEvidenceRecord::OBSERVATION,
            ...$facts, 'provenance' => IdentityPolicy::REHEARSAL, ...($version === 2 ? ['row_binding' => $row] : [])]);
        $row['seal'] = BillingValues::seal($row);
        DB::table(BillingSchema::TABLES[2])->insert($row);

        return $row;
    }

    private function legacyHint(): array
    {
        $row = ['id' => BillingValues::id(), 'provider_event_ref_hash' => BillingValues::hash('event', F::ACCOUNT, 'test', 'evt_SYNTHETICFUTURE'),
            'type' => 'invoice.paid', 'mode' => 'test', 'provider_account_hash' => BillingValues::hash('provider-account', F::ACCOUNT, 'test', F::ACCOUNT),
            'invoice_ref_hash' => BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE), 'received_at' => self::LEGACY_TIME,
            'payload_hash' => hash('sha256', 'synthetic legacy hint'), 'disposition' => 'retrieval_hint', 'created_at' => self::LEGACY_TIME,
            'hint_position' => $this->legacyPosition('hint')];
        $row['payload_ciphertext'] = BillingEvidenceRecord::encrypt($row, ['purpose' => BillingEvidenceRecord::HINT,
            'event_ref' => 'evt_SYNTHETICFUTURE', 'type' => 'invoice.paid', 'api_version' => BillingProviderPin::API_VERSION,
            'created' => F::PERIOD_START, 'invoice_ref' => F::INVOICE, 'subscription_ref' => F::SUBSCRIPTION]);
        $row['seal'] = BillingValues::seal($row);
        DB::table(BillingSchema::TABLES[3])->insert($row);

        return $row;
    }

    private function legacyPosition(string $kind): int
    {
        return DB::table(BillingSchema::TABLES[4])->insertGetId(['kind' => $kind, 'created_at' => self::LEGACY_TIME]);
    }

    private function observation(string $id): array
    {
        return BillingValues::integers((array) DB::table(BillingSchema::TABLES[2])->where('id', $id)->sole(),
            ['sequence', 'amount_minor', 'retrieval_position', 'retrieval_end_position']);
    }

    private function snapshot(): array
    {
        $all = [];
        foreach (BillingSchema::TABLES as $table) {
            $all[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $all;
    }

    private function assertOriginalRows(array $before): void
    {
        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                $actual = (array) DB::table($table)->where('id', $row['id'])->sole();
                $this->assertSame($row, array_intersect_key($actual, $row), 'Every original column, ciphertext and seal stays byte-identical.');
            }
        }
    }
}
