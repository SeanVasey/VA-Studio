<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingValues;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Historical260 retention only: synthetic original-format rows grant no paid authority and do not exercise upgrade263. */
class BillingRetrievalAttemptsLegacyUpgradeTest extends TestCase
{
    use FinalizationDatabaseMigrations {
        migrateFreshUsing as private baselineMigrateFreshUsing;
    }

    private const STAMP = '2026-10-07 01:00:00';

    protected function migrateFreshUsing(): array
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn (string $path): bool => basename($path) !== '2026_10_13_263000_membership_billing_retrieval_identity.php'));

        return [...$this->baselineMigrateFreshUsing(), '--path' => $paths, '--realpath' => true];
    }

    public function test_forward_migration_preserves_the_existing_five_tables_and_rows_and_is_idempotent(): void
    {
        F::configure();
        $binding = F::binding();
        $this->legacyHint();
        $this->legacyObservation($binding);
        $pdo = DB::connection()->getPdo();
        $attempts = BillingSchema::TABLES[5];
        $this->assertSame(0, DB::table($attempts)->count(), 'Only an empty disposable attempts table is removed.');
        $oldTables = array_slice(BillingSchema::TABLES, 0, 5);
        $snapshot = fn (): array => array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all(), $oldTables);
        $before = $snapshot();
        $this->assertSame([1, 1, 1, 1, 3], array_map(count(...), $before));
        $pdo->exec('DROP TABLE '.$attempts);

        $migration = require base_path('database/migrations/2026_10_12_260000_membership_billing_retrieval_attempts.php');
        $migration->up();
        $this->assertSame($before, $snapshot(), 'Adding attempts must retain every original scalar, ciphertext and seal.');
        $migration->up();
        $this->assertSame($before, $snapshot(), 'A repeated historical260 migration must not rewrite its five retained tables.');
        $this->assertSame(0, DB::table($attempts)->count());
        (new BillingSchema)->assertOwned($pdo);
        try {
            $migration->down();
            $this->fail('Attempt evidence needs explicit retention handling, never a destructive down migration.');
        } catch (LogicException $error) {
            $this->assertSame('Production membership billing evidence requires an explicit retention migration.', $error->getMessage());
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, DB::table($attempts)->count());
        (new BillingSchema)->assertOwned($pdo);
    }

    private function legacyHint(): void
    {
        $row = ['id' => BillingValues::id(), 'provider_event_ref_hash' => BillingValues::hash('event', F::ACCOUNT, 'test', 'evt_SYNTHETIC260'),
            'type' => 'invoice.paid', 'mode' => 'test', 'provider_account_hash' => BillingValues::hash('provider-account', F::ACCOUNT, 'test', F::ACCOUNT),
            'invoice_ref_hash' => BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE), 'received_at' => self::STAMP,
            'payload_hash' => hash('sha256', 'Synthetic historical260 hint body, not a provider receipt.'), 'disposition' => 'retrieval_hint',
            'created_at' => self::STAMP, 'hint_position' => $this->position('hint')];
        $row['payload_ciphertext'] = BillingValues::encrypt(['schema_version' => 2, 'purpose' => 'production_membership_billing_event_hint',
            'event_ref' => 'evt_SYNTHETIC260', 'type' => 'invoice.paid', 'api_version' => BillingProviderPin::API_VERSION,
            'created' => F::PERIOD_START, 'invoice_ref' => F::INVOICE, 'subscription_ref' => F::SUBSCRIPTION, 'row_binding' => $row]);
        $row['seal'] = BillingValues::seal($row);
        DB::table(BillingSchema::TABLES[3])->insert($row);
    }

    private function legacyObservation(array $binding): void
    {
        $invoice = ['id' => BillingValues::id(), 'subscription_binding_id' => $binding['id'],
            'invoice_ref_hash' => BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE),
            'source_invoice_hash' => BillingValues::hash('source-invoice', F::ACCOUNT, 'test', F::INVOICE),
            'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test',
            'payload_ciphertext' => BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_invoice',
                'invoice_ref' => F::INVOICE, 'subscription_binding_id' => $binding['id']]), 'created_at' => self::STAMP];
        $invoice['seal'] = BillingValues::seal($invoice);
        DB::table(BillingSchema::TABLES[1])->insert($invoice);
        $facts = ['outcome' => 'not_settled', 'reason' => 'invoice_open', 'facts' => ['invoice_ref' => F::INVOICE]];
        $row = ['id' => BillingValues::id(), 'invoice_id' => $invoice['id'], 'sequence' => 1, 'outcome' => $facts['outcome'],
            'facts_hash' => CanonicalJson::hash($facts), 'line_period_start' => null, 'line_period_end' => null, 'amount_minor' => null, 'currency' => null,
            'retrieved_at' => self::STAMP, 'retrieval_started_at' => self::STAMP.'.000000',
            'retrieval_position' => $this->position('retrieval'), 'retrieval_end_position' => $this->position('retrieval'),
            'freshness_deadline' => '2026-10-07 01:10:00', 'api_version' => BillingProviderPin::API_VERSION,
            'sdk_reference' => BillingProviderPin::SDK_REFERENCE, 'prior_seal' => str_repeat('0', 64), 'created_at' => self::STAMP];
        $row['payload_ciphertext'] = BillingValues::encrypt(['schema_version' => 2, 'purpose' => 'production_membership_billing_observation',
            ...$facts, 'provenance' => IdentityPolicy::REHEARSAL, 'row_binding' => $row]);
        $row['seal'] = BillingValues::seal($row);
        DB::table(BillingSchema::TABLES[2])->insert($row);
    }

    private function position(string $kind): int
    {
        return DB::table(BillingSchema::TABLES[4])->insertGetId(['kind' => $kind, 'created_at' => self::STAMP]);
    }
}
