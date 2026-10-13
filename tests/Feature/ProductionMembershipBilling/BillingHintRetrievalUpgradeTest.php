<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingHintRecovery;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Event;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/** Synthetic retained-data upgrade and allocation replacement; no provider or real restore/failover is exercised. */
class BillingHintRetrievalUpgradeTest extends TestCase
{
    use FinalizationDatabaseMigrations {
        migrateFreshUsing as private baselineMigrateFreshUsing;
    }

    private const UPGRADE = '2026_10_13_263000_membership_billing_retrieval_identity.php';

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    private const LEGACY_STAMP = '2026-10-07 01:00:00';

    protected function migrateFreshUsing(): array
    {
        // Build the exact historical graph first; the forward migration runs explicitly after retained fixture rows exist.
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn (string $path): bool => basename($path) !== self::UPGRADE));

        return [...$this->baselineMigrateFreshUsing(), '--path' => $paths, '--realpath' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function legacyVersions(): array
    {
        return ['observation v1' => [1], 'observation v2' => [2]];
    }

    #[DataProvider('legacyVersions')]
    public function test_legacy_observation_stays_pending_until_a_new_interval_covers_the_original_hint(int $version): void
    {
        $binding = F::binding();
        $payload = $this->eventPayload();
        $hint = $this->legacyHint($payload);
        $legacy = $this->legacyObservation($binding, $version);
        $beforeHint = (array) DB::table(BillingSchema::TABLES[3])->sole();
        $beforeObservation = (array) DB::table(BillingSchema::TABLES[2])->sole();
        $this->assertGreaterThan($hint['hint_position'], $legacy['retrieval_position']);
        (new BillingRetrievalSchema)->upgrade();

        $expected = ['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE];
        $this->assertSame($expected, (new BillingHintRecovery)->pending($hint, (new BillingPolicy)->current()));
        $duplicate = $this->receive($payload);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($expected, $duplicate['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->assertSame($beforeHint, (array) DB::table(BillingSchema::TABLES[3])->sole());
        $upgradedLegacy = (array) DB::table(BillingSchema::TABLES[2])->sole();
        $this->assertSame($beforeObservation, array_intersect_key($upgradedLegacy, $beforeObservation));
        $this->assertSame([null, null, null], [$upgradedLegacy['retrieval_token'],
            $upgradedLegacy['start_allocation_token'], $upgradedLegacy['end_allocation_token']]);

        $current = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['settled', 2, $legacy['seal']], [$current['outcome'], $current['sequence'], $current['prior_seal']]);
        $this->assertSame(3, BillingEvidenceRecord::verify($current, BillingEvidenceRecord::OBSERVATION)['schema_version']);
        (new BillingLedger)->assertRetainedObservationInterval($current);
        $this->assertNull((new BillingHintRecovery)->pending($hint, (new BillingPolicy)->current()));
        $this->assertNull($this->receive($payload)['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 1);
        $this->assertSame($beforeHint, (array) DB::table(BillingSchema::TABLES[3])->sole());
        $this->assertSame($upgradedLegacy, (array) DB::table(BillingSchema::TABLES[2])->where('id', $legacy['id'])->sole());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_new_hint_uses_its_database_allocation_time_and_keeps_the_v2_envelope_on_redelivery(): void
    {
        (new BillingRetrievalSchema)->upgrade();
        F::binding();
        CarbonImmutable::setTestNow('2050-01-01 00:00:00');
        $payload = $this->eventPayload();
        $received = $this->receive($payload);
        $hint = $received['event'];
        $position = (array) DB::table(BillingSchema::TABLES[4])->where('id', $hint['hint_position'])->sole();
        $this->assertSame(['hint', 'hint', null], [$position['kind'], $position['phase'], $position['retrieval_token']]);
        $this->assertSame(substr($position['database_at'], 0, 19), $hint['received_at']);
        $this->assertSame($position['created_at'], $hint['created_at']);
        $this->assertNotSame(CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'), $hint['received_at']);
        $opened = BillingEvidenceRecord::verify($hint, 'production_membership_billing_event_hint');
        $this->assertSame(2, $opened['schema_version']);
        $this->assertArrayNotHasKey('retrieval_token', $opened['row_binding']);
        $before = (array) DB::table(BillingSchema::TABLES[3])->sole();

        CarbonImmutable::setTestNow('2000-01-01 00:00:00');
        $duplicate = $this->receive($payload);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($before, (array) DB::table(BillingSchema::TABLES[3])->sole());
        $this->assertSame(1, DB::table(BillingSchema::TABLES[4])->count());
        Queue::assertPushed(RetrieveMembershipInvoice::class, 2);
    }

    public static function endpoints(): array
    {
        return ['start allocation replaced' => ['retrieval_position'], 'end allocation replaced' => ['retrieval_end_position']];
    }

    #[DataProvider('endpoints')]
    public function test_v3_observation_does_not_cover_when_one_retained_endpoint_identity_changed(string $endpoint): void
    {
        (new BillingRetrievalSchema)->upgrade();
        $binding = F::binding();
        $payload = $this->eventPayload();
        $hint = $this->receive($payload)['event'];
        $observation = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->assertNull((new BillingHintRecovery)->pending($hint, (new BillingPolicy)->current()));
        $before = (array) DB::table(BillingSchema::TABLES[3])->sole();

        // Disposable allocation replacement simulation, not a real database restore. Restore the exact guard before either read.
        $this->replaceAllocationToken($observation[$endpoint]);
        $this->assertSame(3, BillingEvidenceRecord::verify($observation, BillingEvidenceRecord::OBSERVATION)['schema_version'],
            'The authentic v3 row alone cannot prove that its original endpoint is still retained.');
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE],
            (new BillingHintRecovery)->pending($hint, (new BillingPolicy)->current()));
        $this->assertNotNull($this->receive($payload)['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 2);
        $this->assertSame($before, (array) DB::table(BillingSchema::TABLES[3])->sole());
        $this->assertSame(1, DB::table(BillingSchema::TABLES[2])->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_a_new_authenticated_attempt_never_covers_its_original_hint(): void
    {
        (new BillingRetrievalSchema)->upgrade();
        $binding = F::binding();
        $payload = $this->eventPayload();
        $hint = $this->receive($payload)['event'];
        $job = Queue::pushed(RetrieveMembershipInvoice::class)->sole();
        try {
            $job->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')));
            $this->fail('The synthetic provider failure must remain visible to queue retry.');
        } catch (BillingException $error) {
            $this->assertSame('provider_unavailable', $error->reason);
        }
        $attempt = (array) DB::table(BillingSchema::TABLES[5])->sole();
        $this->assertSame($hint['id'], $attempt['hint_event_id']);
        $this->assertSame(2, BillingValues::decrypt($attempt['payload_ciphertext'])['schema_version']);
        $this->assertNotNull($attempt['retrieval_token']);
        $this->assertSame([0, 0], [DB::table(BillingSchema::TABLES[1])->count(), DB::table(BillingSchema::TABLES[2])->count()]);
        $this->assertSame(['binding_id' => $binding['id'], 'invoice_ref' => F::INVOICE],
            (new BillingHintRecovery)->pending($hint, (new BillingPolicy)->current()));
        $this->assertNotNull($this->receive($payload)['scheduled']);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 2);
        $this->assertSame($attempt, (array) DB::table(BillingSchema::TABLES[5])->sole());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    private function legacyHint(string $payload): array
    {
        $row = ['id' => BillingValues::id(), 'provider_event_ref_hash' => BillingValues::hash('event', F::ACCOUNT, 'test', 'evt_SYNTHETICUPGRADE'),
            'type' => 'invoice.paid', 'mode' => 'test', 'provider_account_hash' => BillingValues::hash('provider-account', F::ACCOUNT, 'test', F::ACCOUNT),
            'invoice_ref_hash' => BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE), 'received_at' => self::LEGACY_STAMP,
            'payload_hash' => hash('sha256', $payload), 'disposition' => 'retrieval_hint', 'created_at' => self::LEGACY_STAMP,
            'hint_position' => $this->legacyPosition('hint')];
        $row['payload_ciphertext'] = BillingEvidenceRecord::encrypt($row, ['purpose' => 'production_membership_billing_event_hint',
            'event_ref' => 'evt_SYNTHETICUPGRADE', 'type' => 'invoice.paid', 'api_version' => BillingProviderPin::API_VERSION,
            'created' => F::PERIOD_START, 'invoice_ref' => F::INVOICE, 'subscription_ref' => F::SUBSCRIPTION]);
        $row['seal'] = BillingValues::seal($row);
        DB::table(BillingSchema::TABLES[3])->insert($row);

        return $row;
    }

    private function legacyObservation(array $binding, int $version): array
    {
        $invoice = ['id' => BillingValues::id(), 'subscription_binding_id' => $binding['id'],
            'invoice_ref_hash' => BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE),
            'source_invoice_hash' => BillingValues::hash('source-invoice', F::ACCOUNT, 'test', F::INVOICE),
            'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test',
            'payload_ciphertext' => BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_invoice',
                'invoice_ref' => F::INVOICE, 'subscription_binding_id' => $binding['id']]), 'created_at' => self::LEGACY_STAMP];
        $invoice['seal'] = BillingValues::seal($invoice);
        DB::table(BillingSchema::TABLES[1])->insert($invoice);
        $facts = ['outcome' => 'not_settled', 'reason' => 'invoice_open', 'facts' => ['invoice_ref' => F::INVOICE]];
        $row = ['id' => BillingValues::id(), 'invoice_id' => $invoice['id'], 'sequence' => 1, 'outcome' => $facts['outcome'],
            'facts_hash' => CanonicalJson::hash($facts), 'line_period_start' => null, 'line_period_end' => null, 'amount_minor' => null, 'currency' => null,
            'retrieved_at' => self::LEGACY_STAMP, 'retrieval_started_at' => self::LEGACY_STAMP.'.000000',
            'retrieval_position' => $this->legacyPosition('retrieval'), 'retrieval_end_position' => $this->legacyPosition('retrieval'),
            'freshness_deadline' => '2026-10-07 01:10:00', 'api_version' => BillingProviderPin::API_VERSION,
            'sdk_reference' => BillingProviderPin::SDK_REFERENCE, 'prior_seal' => str_repeat('0', 64), 'created_at' => self::LEGACY_STAMP];
        $body = ['purpose' => 'production_membership_billing_observation', ...$facts, 'provenance' => IdentityPolicy::REHEARSAL];
        $row['payload_ciphertext'] = BillingValues::encrypt(['schema_version' => $version, ...$body,
            ...($version === 2 ? ['row_binding' => $row] : [])]);
        $row['seal'] = BillingValues::seal($row);
        DB::table(BillingSchema::TABLES[2])->insert($row);

        return $row;
    }

    private function legacyPosition(string $kind): int
    {
        return DB::table(BillingSchema::TABLES[4])->insertGetId(['kind' => $kind, 'created_at' => self::LEGACY_STAMP]);
    }

    private function replaceAllocationToken(int $id): void
    {
        $pdo = DB::connection()->getPdo();
        $guard = BillingSchema::TABLES[4].'_update';
        $definition = DB::getDriverName() === 'mysql'
            ? $pdo->query('SHOW CREATE TRIGGER '.$guard)->fetch(PDO::FETCH_ASSOC)['SQL Original Statement']
            : $pdo->query("SELECT sql FROM main.sqlite_master WHERE type = 'trigger' AND name = '{$guard}'")->fetchColumn();
        $this->assertIsString($definition);
        $pdo->exec('DROP TRIGGER '.$guard);
        try {
            DB::table(BillingSchema::TABLES[4])->where('id', $id)->update(['allocation_token' => bin2hex(random_bytes(32))]);
        } finally {
            $pdo->exec($definition);
        }
    }

    private function eventPayload(): string
    {
        return json_encode(F::sdk(Event::class, ['id' => 'evt_SYNTHETICUPGRADE', 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION,
            'created' => F::PERIOD_START, 'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null],
            'type' => 'invoice.paid', 'data' => ['object' => F::graph()['invoice']]]), JSON_THROW_ON_ERROR);
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }
}
