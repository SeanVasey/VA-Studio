<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\PaidGrantExportSection;
use App\Domain\Customers\DataRights\ProductionCheckoutExportSection;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\OrderInquiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\OrderInquiryFixtures;
use Tests\TestCase;

/** FP-076 / U-13 first increment: the retention schedule and its read-only dry-run report. Nothing is deleted. */
class CustomerDataRetentionReportTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const RETENTION = ['policy_reference' => 'Synthetic retention schedule SR-1 (test only)',
        'financial_records_years' => '7', 'consent_records_years' => 3, 'correspondence_records_years' => '2', 'security_records_years' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        F::configure();
        OrderFixtures::configure();
        $this->travelTo(now()->startOfSecond());
        config(['customer-data-rights.enabled' => true]);
    }

    public function test_every_retained_family_and_every_table_data_rights_reads_is_classified_exactly_once(): void
    {
        $tables = array_column(RetentionSchedule::ENTRIES, 'table');
        $this->assertSame(array_values(array_unique($tables)), $tables, 'A table is classified more than once.');
        $this->assertSame(['financial' => 'financial_records_years', 'consent' => 'consent_records_years', 'correspondence' => 'correspondence_records_years',
            'security' => 'security_records_years', 'indefinite' => null, 'not_applicable' => null], RetentionSchedule::CLASSES);
        $byTable = RetentionSchedule::byTable();

        // Every RETAINED family resolves to at least one table, and no table loosens its family's class: it keeps the
        // RETAINED period or is held indefinitely.
        foreach (CustomerDataRights::RETAINED as $family => $key) {
            $members = array_filter(RetentionSchedule::ENTRIES, fn (array $entry): bool => $entry['family'] === $family);
            $this->assertNotEmpty($members, "RETAINED record {$family} has no table in the retention schedule.");
            foreach ($members as $entry) {
                $this->assertContains($entry['class'], [array_search($key, RetentionSchedule::CLASSES, true), 'indefinite'], $entry['table']);
            }
        }

        // Every table the data-rights code reads or changes: literal table names and joins, the models it uses, and the
        // production checkout and paid-grant export sections.
        $read = array_merge(array_keys(ProductionCheckoutExportSection::RECORDS),
            ...array_values(array_map(fn (array $chain): array => array_values($chain['tables']), PaidGrantExportSection::CHAINS)));
        foreach (glob(app_path('Domain/Customers/DataRights/*.php')) as $file) {
            $source = file_get_contents($file);
            preg_match_all("/(?:table|join|leftJoin|from|hasColumn)\\(\\s*'([a-z0-9_]+)/", $source, $literal);
            array_push($read, ...$literal[1]);
            preg_match_all('/^use (App\\\\[\\w\\\\]+);$/m', $source, $uses);
            foreach ($uses[1] as $class) {
                if (class_exists($class) && is_subclass_of($class, Model::class)) {
                    $read[] = (new $class)->getTable();
                }
            }
        }
        $this->assertContains('customer_saved_tracks', $read);
        $this->assertContains('customer_inquiries', $read);
        foreach (array_unique($read) as $table) {
            $this->assertArrayHasKey($table, $byTable, "Data-rights table {$table} has no retention class.");
        }

        foreach (RetentionSchedule::ENTRIES as $entry) {
            $this->assertSame(['table', 'family', 'class', 'clock', 'rationale'], array_keys($entry));
            $this->assertArrayHasKey($entry['class'], RetentionSchedule::CLASSES);
            $this->assertTrue($entry['family'] === null || array_key_exists($entry['family'], CustomerDataRights::RETAINED), $entry['table']);
            $this->assertGreaterThan(20, strlen($entry['rationale']));
            $this->assertTrue(Schema::hasTable($entry['table']), $entry['table']);
            if (RetentionSchedule::CLASSES[$entry['class']] === null) {
                // Indefinite and not-applicable records have no clock: nothing could ever count them as past.
                $this->assertNull($entry['clock'], $entry['table']);

                continue;
            }
            $clock = $entry['clock'];
            if ($clock === null) {
                continue;
            }
            if (isset($clock['via'])) {
                $this->assertSame(['via'], array_keys($clock));
                $this->assertTrue(Schema::hasColumn($entry['table'], $clock['via'][0]));
                $parent = $byTable[$clock['via'][1]];
                $this->assertSame($entry['class'], $parent['class']);
                $this->assertArrayHasKey('columns', $parent['clock']);

                continue;
            }
            $this->assertNotEmpty($clock['columns']);
            $this->assertTrue(Schema::hasColumns($entry['table'], [...$clock['columns'], 'id']), $entry['table']);
            if (isset($clock['unless_later'])) {
                $this->assertTrue(Schema::hasColumns($clock['unless_later'][0], array_slice($clock['unless_later'], 1)));
            }
            foreach ($clock['unless_referenced'] ?? [] as [$holder, $column]) {
                $this->assertTrue(Schema::hasColumn($holder, $column));
            }
        }

        // The records AGENTS.md calls immutable, and every other record of a purchase, are never counted.
        foreach (['orders', 'order_lines', 'order_finalizations', 'license_grants', 'grant_contracts', 'verified_payments', 'customer_purchase_claims',
            'production_checkout_orders', 'production_tax_checkout_orders', 'production_checkout_reviews', 'production_checkout_exemption_bases',
            'paid_order_origins', 'paid_grant_origins', 'paid_originals', 'paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_originals',
            'inquiry_order_contexts'] as $immutable) {
            $this->assertSame('indefinite', $byTable[$immutable]['class'], $immutable);
        }
    }

    public static function invalidRetention(): array
    {
        return [
            'unset (the shipped default)' => [null],
            'missing period' => [array_diff_key(self::RETENTION, ['security_records_years' => true])],
            'missing reference' => [array_replace(self::RETENTION, ['policy_reference' => ' '])],
            'zero years' => [array_replace(self::RETENTION, ['consent_records_years' => 0])],
            'over 100 years' => [array_replace(self::RETENTION, ['financial_records_years' => 101])],
            'words' => [array_replace(self::RETENTION, ['correspondence_records_years' => 'two'])],
            'fraction' => [array_replace(self::RETENTION, ['financial_records_years' => 7.5])],
            'not an array' => ['7 years'],
        ];
    }

    #[DataProvider('invalidRetention')]
    public function test_report_refuses_when_retention_configuration_is_missing_or_invalid(mixed $retention): void
    {
        if ($retention !== null) {
            config(['customer-data-rights.retention' => $retention]);
        }
        $queries = $this->recordQueries();

        $exit = Artisan::call('vasey:retention-report');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('refused=retention_policy_unset', $output);
        $this->assertStringContainsString('periods are never inferred', $output);
        $this->assertStringNotContainsString('past_period', $output);
        $this->assertSame([], $queries->all, 'The refusal reads nothing.');
    }

    public function test_shipped_configuration_carries_no_retention_periods(): void
    {
        // Periods are set per host, never in Git: each value reads its environment variable with no default, and the
        // example environment leaves every one empty.
        $config = file_get_contents(base_path('config/customer-data-rights.php'));
        $example = file_get_contents(base_path('.env.example'));
        foreach (['POLICY_REFERENCE', 'FINANCIAL_YEARS', 'CONSENT_YEARS', 'CORRESPONDENCE_YEARS', 'SECURITY_YEARS'] as $suffix) {
            $this->assertStringContainsString("env('VASEY_CUSTOMER_DATA_RETENTION_{$suffix}')", $config);
            $this->assertMatchesRegularExpression("/^(?:# )?VASEY_CUSTOMER_DATA_RETENTION_{$suffix}=\\s*$/m", $example);
        }
    }

    public function test_report_refuses_when_a_scheduled_table_is_missing(): void
    {
        config(['customer-data-rights.retention' => self::RETENTION]);
        Schema::drop('inquiry_notification_intents');

        $this->assertSame(1, Artisan::call('vasey:retention-report'));
        $this->assertStringContainsString('refused=retention_schedule_table_missing', Artisan::output());
    }

    public function test_report_counts_past_period_records_per_class_read_only_without_personal_data(): void
    {
        config(['customer-data-rights.retention' => self::RETENTION]);
        $start = now()->toImmutable();
        $alice = F::account(['name' => 'Synthetic Retention Alice', 'email' => 'retention.alice@example.test']);
        $bob = F::account(['name' => 'Synthetic Retention Bob', 'email' => 'retention.bob@example.test']);
        $ready = F::ready($alice['user'], 'RETAIN');
        ConsentFixtures::configure();
        app(CustomerConsentPreferences::class)->change($alice['principal'], $alice['user'], ConsentFixtures::grant());
        app(IssueTestDelivery::class)->handle($ready['order']->public_id, $ready['principal']->ownerKey, $ready['grant']->public_id, 'contract',
            (string) Str::uuid(), $alice['user'], $ready['principal']);
        $export = app(CustomerDataRights::class)->requestExport($alice['principal'], $alice['user']);
        app(CustomerDataRights::class)->download($alice['principal'], $alice['user'], $export['requestId'], $export['downloadToken']);
        app(CustomerDataRights::class)->requestDeletion($bob['principal'], $bob['user']);
        // One conversation without an order (2-year clock) and one about an order (kept with the order).
        $conversation = OrderInquiryFixtures::configure();
        app(OrderInquiry::class)->submit(OrderInquiryFixtures::guest()->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER,
            OrderInquiryFixtures::body());
        $this->assertSame([2, 1], [DB::table('customer_inquiries')->count(), DB::table('inquiry_order_contexts')->count()]);
        $this->assertSame([1, 1, 1], [DB::table('test_delivery_authorizations')->count(), DB::table('customer_data_exports')->whereNotNull('purged_at')->count(),
            DB::table('customer_data_requests')->whereNotNull('resolved_at')->count()]);
        $this->assertSame(1, DB::table('customer_data_requests')->whereNull('resolved_at')->count());
        $audit = DB::table('audit_events')->count();
        $this->assertGreaterThan(0, $audit);
        $resolvedEvents = DB::table('customer_data_request_events as e')->join('customer_data_requests as r', 'r.id', '=', 'e.request_id')
            ->whereNotNull('r.resolved_at')->count();
        $notices = DB::table('transactional_notices')->count();
        $finished = DB::table('transactional_mail_deliveries')->whereNotNull('finished_at')->count();
        $private = ['retention.alice@example.test', 'Synthetic Retention Alice', 'retention.bob@example.test', 'Synthetic Retention Bob',
            'synthetic-sender@example.test', 'Synthetic Sender', 'support-sender@example.test', 'Synthetic support sender',
            $ready['order']->public_id, $alice['account']->public_id, $export['requestId'], $conversation['inquiry']->public_id];

        // One year and a day later only the security class (1 year) has run out.
        $this->travelTo($start->addYear()->addDay());
        [$report, $output] = $this->report();
        $this->assertSame(['past_period' => $audit, 'clock_index' => 'missing'], array_intersect_key($report['audit_events'], array_flip(['past_period', 'clock_index'])));
        $this->assertSame($start->addYear()->toDateString(), $report['audit_events']['oldest_due']);
        $this->assertSame(1, $report['customer_data_requests']['past_period'], 'The open deletion request is never past.');
        $this->assertSame(1, $report['customer_data_exports']['past_period']);
        $this->assertSame(['past_period' => $resolvedEvents, 'follows' => 'customer_data_requests'], $report['customer_data_request_events']);
        foreach (['test_delivery_authorizations', 'customer_inquiries', 'inquiry_messages', 'transactional_notices'] as $table) {
            $this->assertSame(0, $report[$table]['past_period'], $table);
        }
        $this->assertStringContainsString('class=security years=1 cutoff='.$start->addDay()->toDateString(), $output);

        // A staff reply moves the conversation's clock to the reply.
        $this->travelTo($start->addYear()->addDays(2));
        app(InquiryConversation::class)->reply($conversation['inquiry']->id, InquiryConversationFixtures::message(), $conversation['actor']);
        $replied = now()->toImmutable();
        $this->travelTo($start->addYears(3));
        [$report] = $this->report();
        $this->assertSame(0, $report['customer_inquiries']['past_period'], 'A reply within the period holds the conversation.');

        // Eight years later every class with a period has run out; held and indefinite records are still never counted.
        $this->travelTo($start->addYears(8));
        $before = $this->counts();
        $queries = $this->recordQueries();
        [$report, $output] = $this->report();
        $this->assertSame($before, $this->counts(), 'The report changes nothing.');
        $this->assertNotEmpty($queries->all);
        foreach ($queries->all as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*select\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/for update|lock in share mode|for share/i', $sql);
        }
        $this->assertSame(0, DB::transactionLevel());

        $this->assertSame(['past_period' => 1, 'oldest_due' => $start->addYears(7)->toDateString(), 'newest_due' => $start->addYears(7)->toDateString(),
            'clock_index' => 'missing'], $report['test_delivery_authorizations']);
        $this->assertSame(0, $report['test_delivery_redemptions']['past_period']);
        $this->assertStringContainsString('class=financial years=7 cutoff='.$start->addYear()->toDateString().' note=minimum_period_license_lifetime_still_applies', $output);
        $this->assertSame(['past_period' => 1, 'oldest_due' => $replied->addYears(2)->toDateString(), 'newest_due' => $replied->addYears(2)->toDateString(),
            'clock_index' => 'missing'], $report['customer_inquiries'], 'Only the conversation without an order, dated by its last message.');
        $this->assertSame(DB::table('inquiry_messages')->where('inquiry_id', $conversation['inquiry']->id)->count(), $report['inquiry_messages']['past_period']);
        $this->assertSame($notices, $report['transactional_notices']['past_period']);
        $this->assertSame($finished, $report['transactional_mail_deliveries']['past_period']);
        foreach (['customer_consent_events', 'customer_consent_states', 'customer_suppression_targets', 'support_attachments'] as $held) {
            $this->assertSame(['held' => 'clock_not_modeled'], $report[$held], $held);
        }
        $this->assertGreaterThan(0, DB::table('customer_consent_events')->count());
        foreach (RetentionSchedule::ENTRIES as $entry) {
            if (in_array($entry['class'], ['indefinite', 'not_applicable'], true)) {
                $this->assertArrayNotHasKey($entry['table'], $report, $entry['table'].' must never be reported as past its period.');
                $this->assertMatchesRegularExpression('/^class='.$entry['class'].' \w+=(?:[a-z_]+,)*'.$entry['table'].'(?:,|$)/m', $output);
            }
        }
        $this->assertGreaterThan(0, DB::table('orders')->count());
        $this->assertStringContainsString('dry_run=true writes=none', $output);
        $this->assertStringContainsString('policy_reference="Synthetic retention schedule SR-1 (test only)"', $output);
        $this->assertStringContainsString('any purge or anonymization is a later, separately authorized step', $output);
        foreach ($private as $value) {
            $this->assertStringNotContainsString($value, $output);
        }
    }

    /** @return array{0: array<string, array<string, int|string>>, 1: string} report lines keyed by table */
    private function report(): array
    {
        $this->assertSame(0, Artisan::call('vasey:retention-report'));
        $output = Artisan::output();
        $tables = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^  ([a-z_]+) (.+)$/', $line, $match) === 1) {
                parse_str(str_replace(' ', '&', $match[2]), $fields);
                $tables[$match[1]] = array_map(fn (string $value): int|string => ctype_digit($value) ? (int) $value : $value, $fields);
            }
        }

        return [$tables, $output];
    }

    private function counts(): array
    {
        $counts = [];
        foreach (RetentionSchedule::ENTRIES as $entry) {
            $counts[$entry['table']] = DB::table($entry['table'])->count();
        }

        return $counts;
    }

    private function recordQueries(): object
    {
        $queries = new class
        {
            public array $all = [];
        };
        DB::listen(function ($query) use ($queries): void {
            $queries->all[] = $query->sql;
        });

        return $queries;
    }
}
