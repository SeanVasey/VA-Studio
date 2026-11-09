<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Commerce\PriceQuote;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\PaidGrantExportSection;
use App\Domain\Customers\DataRights\ProductionCheckoutExportSection;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\OrderInquiry;
use Carbon\CarbonImmutable;
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
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** FP-076 / U-13: the retention schedule and its read-only dry-run report, with the 2026-11-09 decisions. Nothing is deleted. */
class CustomerDataRetentionReportTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    /** Privacy request records (2026-11-09, review of #255): 7 years after the request closes. */
    private const EVIDENCE = ['customer_data_requests', 'customer_data_request_events', 'customer_data_exports'];

    /** Download and redemption records (2026-11-09): held with the order, never on their own authorization timestamps. */
    private const DOWNLOAD_RECORDS = ['test_delivery_authorizations', 'test_delivery_redemptions', 'paid_authorizations', 'paid_redemptions',
        'paid_tax_authorizations', 'paid_tax_redemptions'];

    /**
     * Every migrated table that is neither scheduled nor reviewed as not customer data, as the report prints it. A new migration
     * changes this list, which forces a deliberate classification (RetentionSchedule::ENTRIES or NOT_CUSTOMER_DATA) or an
     * explicit addition here, documented in docs/customer-data-rights.md as fitting no PRIVACY section 7 row.
     */
    private const UNCLASSIFIED = ['production_account_feature_bindings', 'production_buyer_assent_observations', 'production_listening_libraries'];

    private const RETENTION = ['policy_reference' => 'Synthetic retention schedule SR-1 (test only)',
        'financial_records_years' => '7', 'consent_records_years' => 3, 'correspondence_records_years' => '2', 'security_records_years' => 1,
        'evidence_records_years' => '7', 'abandoned_checkout_days' => 30, 'operational_records_days' => '14'];

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
            'security' => 'security_records_years', 'evidence' => 'evidence_records_years', 'abandoned_checkout' => 'abandoned_checkout_days',
            'operational' => 'operational_records_days', 'indefinite' => null, 'not_applicable' => null], RetentionSchedule::CLASSES);
        $byTable = RetentionSchedule::byTable();

        // Every RETAINED family resolves to at least one table, and no table loosens its family's class: it keeps the
        // RETAINED period or is held indefinitely.
        foreach (CustomerDataRights::RETAINED as $family => $key) {
            $members = array_filter(RetentionSchedule::ENTRIES, fn (array $entry): bool => $entry['family'] === $family);
            $this->assertNotEmpty($members, "RETAINED record {$family} has no table in the retention schedule.");
            foreach ($members as $entry) {
                $allowed = RetentionSchedule::RETAINED_LABELS[$key] ?? [array_search($key, RetentionSchedule::CLASSES, true), 'indefinite'];
                $this->assertContains($entry['class'], $allowed, $entry['table']);
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
                $this->assertSame(isset($clock['when']) ? ['via', 'when'] : ['via'], array_keys($clock));
                $this->assertTrue(Schema::hasColumns($entry['table'], [$clock['via'][0], 'id', ...(isset($clock['when']) ? [$clock['when'][0]] : [])]));
                // The chain of parents ends at a `columns` clock of the same class, without a cycle.
                $seen = [$entry['table']];
                for ($link = $clock; isset($link['via']); $link = $parent['clock']) {
                    $parent = $byTable[$link['via'][1]];
                    $this->assertNotContains($parent['table'], $seen, $entry['table']);
                    $seen[] = $parent['table'];
                    $this->assertSame($entry['class'], $parent['class'], $entry['table']);
                    $this->assertTrue(Schema::hasColumn($parent['table'], $link['via'][2] ?? 'id'), $entry['table']);
                    $this->assertNotNull($parent['clock'], $entry['table']);
                }
                $this->assertNotEmpty($link['columns'], $entry['table']);

                continue;
            }
            $this->assertNotEmpty($clock['columns']);
            $this->assertContains($clock['format'] ?? 'datetime', ['datetime', 'iso8601', 'epoch']);
            $this->assertTrue(Schema::hasColumns($entry['table'], [...$clock['columns'], 'id']), $entry['table']);
            if (isset($clock['unless_later'])) {
                $this->assertTrue(Schema::hasColumns($clock['unless_later'][0], array_slice($clock['unless_later'], 1)));
            }
            foreach ($clock['unless_referenced'] ?? [] as $hold) {
                $this->assertTrue(Schema::hasColumn($hold[0], $hold[1]));
                $this->assertTrue(Schema::hasColumn($entry['table'], $hold[2] ?? 'id'));
            }
        }

        // Privacy request records: 7 years after the request closes (an open request is never past). Audit events prove records
        // kept indefinitely (license approvals, exclusive activations, payments, refunds), so they are kept with them.
        $this->assertSame(['indefinite', null], [$byTable['audit_events']['class'], $byTable['audit_events']['clock']]);
        $this->assertSame('indefinite', CustomerDataRights::RETAINED['audit_events']);
        $this->assertSame(['indefinite'], RetentionSchedule::RETAINED_LABELS['indefinite']);
        // The production identity chain every production read re-verifies is never counted: verifications are kept with the
        // origin, and a challenge an origin or a verification references is held.
        $this->assertSame(['indefinite', null], [$byTable['production_identity_verifications']['class'], $byTable['production_identity_verifications']['clock']]);
        $this->assertSame([['production_identity_origins', 'initial_challenge_id'], ['production_identity_verifications', 'challenge_id']],
            $byTable['production_identity_challenges']['clock']['unless_referenced']);
        $this->assertSame(['columns' => ['resolved_at']], $byTable['customer_data_requests']['clock']);
        $this->assertSame(['via' => ['request_id', 'customer_data_requests']], $byTable['customer_data_request_events']['clock']);
        $this->assertSame(['via' => ['request_id', 'customer_data_requests']], $byTable['customer_data_exports']['clock']);
        foreach (self::EVIDENCE as $table) {
            $this->assertSame('evidence', $byTable[$table]['class'], $table);
        }
        // An abandoned checkout or quote is counted only when no order was built from it.
        $this->assertSame([['orders', 'quote_id']], $byTable['quotes']['clock']['unless_referenced']);
        $this->assertSame([['production_checkout_orders', 'review_id']], $byTable['production_checkout_reviews']['clock']['unless_referenced']);
        // Consent has no "address no longer held" signal, so it stays held.
        foreach (['customer_consent_events', 'customer_consent_states', 'production_consent_events', 'production_suppression_targets'] as $held) {
            $this->assertSame(['consent', null], [$byTable[$held]['class'], $byTable[$held]['clock']], $held);
        }
        foreach (['jobs', 'failed_jobs', 'job_batches'] as $table) {
            $this->assertSame('operational', $byTable[$table]['class'], $table);
        }
        $this->assertSame('security', $byTable['password_reset_tokens']['class']);
        foreach (['cache', 'cache_locks', 'test_delivery_controls', 'test_unpaid_releases'] as $table) {
            $this->assertSame('not_applicable', $byTable[$table]['class'], $table);
        }

        // The records AGENTS.md calls immutable, and every other record of a purchase, are never counted.
        foreach (['orders', 'order_lines', 'order_finalizations', 'license_grants', 'grant_contracts', 'verified_payments', 'customer_purchase_claims',
            'production_checkout_orders', 'production_tax_checkout_orders', 'production_checkout_exemption_bases',
            'paid_order_origins', 'paid_grant_origins', 'paid_originals', 'paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_originals',
            'inquiry_order_contexts', ...self::DOWNLOAD_RECORDS, 'order_attempts', 'checkout_intents', 'payment_observations', 'stripe_webhook_receipts',
            'refund_dispute_objects', 'fulfillment_outbox', 'exclusive_sales', 'free_authorizations', 'production_membership_billing_invoices',
            'legacy_history_orders'] as $immutable) {
            $this->assertSame('indefinite', $byTable[$immutable]['class'], $immutable);
        }
    }

    public static function invalidRetention(): array
    {
        $each = [];
        foreach (['evidence_records_years', 'abandoned_checkout_days', 'operational_records_days'] as $key) {
            $each["{$key} unset"] = [array_diff_key(self::RETENTION, [$key => true])];
            $each["{$key} empty"] = [array_replace(self::RETENTION, [$key => ''])];
            $each["{$key} zero"] = [array_replace(self::RETENTION, [$key => 0])];
            $each["{$key} over 100"] = [array_replace(self::RETENTION, [$key => '101'])];
        }

        return $each + [
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
        foreach (['POLICY_REFERENCE', 'FINANCIAL_YEARS', 'CONSENT_YEARS', 'CORRESPONDENCE_YEARS', 'SECURITY_YEARS', 'EVIDENCE_YEARS',
            'ABANDONED_CHECKOUT_DAYS', 'OPERATIONAL_DAYS'] as $suffix) {
            $this->assertStringContainsString("env('VASEY_CUSTOMER_DATA_RETENTION_{$suffix}')", $config);
            $this->assertMatchesRegularExpression("/^(?:# )?VASEY_CUSTOMER_DATA_RETENTION_{$suffix}=\\s*$/m", $example);
            foreach (['ops/production/env.production.example', 'ops/staging/env.staging.example'] as $template) {
                $this->assertMatchesRegularExpression("/^# VASEY_CUSTOMER_DATA_RETENTION_{$suffix}=$/m", file_get_contents(base_path($template)), $template);
            }
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
        // Attachments: one on each conversation and one on a service project, which never follows a conversation.
        $orderInquiry = DB::table('customer_inquiries')->where('id', DB::table('inquiry_order_contexts')->value('inquiry_id'))->value('public_id');
        $this->assertNotSame($conversation['inquiry']->public_id, $orderInquiry);
        foreach ([['inquiry', $conversation['inquiry']->public_id], ['inquiry', $orderInquiry], ['project', $conversation['inquiry']->public_id]] as [$kind, $source]) {
            $this->attachment($kind, $source);
        }
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

        // One year and a day later only the security class (1 year) and the day classes have run out.
        $this->travelTo($start->addYear()->addDay());
        [$report, $output] = $this->report();
        // Audit and data-request evidence is kept 7 years: nothing is past yet.
        $this->assertGreaterThan(0, $resolvedEvents);
        foreach (self::EVIDENCE as $table) {
            $this->assertSame(0, $report[$table]['past_period'], $table);
        }
        foreach (['customer_inquiries', 'inquiry_messages', 'support_attachments', 'transactional_notices'] as $table) {
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

        // Download records follow the order: never counted, however old.
        $this->assertSame(1, DB::table('test_delivery_authorizations')->count());
        foreach (self::DOWNLOAD_RECORDS as $table) {
            $this->assertArrayNotHasKey($table, $report, $table);
        }
        $this->assertMatchesRegularExpression('/^class=financial years=7 cutoff='.$start->addYear()->toDateString().'$/m', $output);
        $this->assertStringNotContainsString('minimum_period_license_lifetime_still_applies', $output);
        // Evidence: the closed export request, its events and its export metadata; never the open deletion request or its events.
        $closed = DB::table('customer_data_requests')->whereNotNull('resolved_at')->value('resolved_at');
        $due = CarbonImmutable::parse($closed, 'UTC')->addYears(7)->toDateString();
        $this->assertSame(['past_period' => 1, 'oldest_due' => $due, 'newest_due' => $due, 'clock_index' => 'missing'], $report['customer_data_requests']);
        $this->assertSame(['past_period' => $resolvedEvents, 'follows' => 'customer_data_requests'], $report['customer_data_request_events']);
        $this->assertSame(['past_period' => 1, 'follows' => 'customer_data_requests'], $report['customer_data_exports']);
        // Audit events, however old, are never counted: they prove records kept indefinitely.
        $this->assertGreaterThan(0, DB::table('audit_events')->where('created_at', '<=', $start->addYear()->format('Y-m-d H:i:s'))->count());
        $this->assertArrayNotHasKey('audit_events', $report);
        $this->assertStringContainsString('class=evidence years=7 cutoff='.$start->addYear()->toDateString(), $output);
        // Only the attachment of the conversation without an order follows it; the order conversation and the project attachment never.
        $this->assertSame(['past_period' => 1, 'follows' => 'customer_inquiries'], $report['support_attachments']);
        $this->assertSame(['past_period' => 1, 'oldest_due' => $replied->addYears(2)->toDateString(), 'newest_due' => $replied->addYears(2)->toDateString(),
            'clock_index' => 'missing'], $report['customer_inquiries'], 'Only the conversation without an order, dated by its last message.');
        $this->assertSame(DB::table('inquiry_messages')->where('inquiry_id', $conversation['inquiry']->id)->count(), $report['inquiry_messages']['past_period']);
        $this->assertSame($notices, $report['transactional_notices']['past_period']);
        $this->assertSame($finished, $report['transactional_mail_deliveries']['past_period']);
        $this->assertSame('customer_inquiries', $report['inquiry_messages']['follows']);
        foreach (['customer_consent_events', 'customer_consent_states', 'customer_suppression_targets', 'production_consent_events', 'password_reset_tokens'] as $held) {
            $this->assertSame(['held' => 'clock_not_modeled'], $report[$held], $held);
        }
        $this->assertGreaterThan(0, DB::table('audit_events')->count());
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

    public function test_day_and_evidence_clocks_count_exactly_at_their_boundaries_and_orders_and_open_requests_never(): void
    {
        config(['customer-data-rights.retention' => self::RETENTION]);
        $start = now()->toImmutable();
        // An abandoned quote (priced, never ordered) and a quote an order was built from.
        $abandoned = InventoryFixtures::selection()['quote'];
        app(PriceQuote::class)->create($abandoned->public_id, InventoryFixtures::OWNER);
        $buyer = F::account(['email' => 'retention.boundary@example.test']);
        $ready = F::ready($buyer['user'], 'BOUNDARY');
        $this->assertTrue(DB::table('orders')->where('quote_id', $ready['order']->quote_id)->exists());
        $this->assertFalse(DB::table('orders')->where('quote_id', $abandoned->id)->exists());
        $ordered = DB::table('quotes')->where('id', $ready['order']->quote_id)->sole();
        // The fixture's own unused selection quote is abandoned too; every quote here is created at the same instant.
        $unordered = DB::table('quotes')->whereNotIn('id', DB::table('orders')->select('quote_id'))->pluck('id')->all();
        $this->assertContains($abandoned->id, $unordered);
        $this->assertSame(1, DB::table('quotes')->count() - count($unordered));
        $this->assertSame(1, DB::table('quotes')->distinct()->count('expires_at'));
        $expires = CarbonImmutable::parse(DB::table('quotes')->where('id', $abandoned->id)->value('expires_at'), 'UTC');
        $this->assertTrue($expires->greaterThan($start));
        // A closed export request and an open deletion request, both created now.
        $export = app(CustomerDataRights::class)->requestExport($buyer['principal'], $buyer['user']);
        app(CustomerDataRights::class)->download($buyer['principal'], $buyer['user'], $export['requestId'], $export['downloadToken']);
        $other = F::account(['email' => 'retention.boundary.open@example.test']);
        app(CustomerDataRights::class)->requestDeletion($other['principal'], $other['user']);
        $this->assertSame([1, 1], [DB::table('customer_data_requests')->whereNotNull('resolved_at')->count(), DB::table('customer_data_requests')->whereNull('resolved_at')->count()]);
        // Framework job stores: a queued job (Unix-second clock) and a failed job (datetime clock), both now.
        DB::table('jobs')->insert(['queue' => 'retention', 'payload' => '{}', 'attempts' => 0, 'available_at' => $start->getTimestamp(), 'created_at' => $start->getTimestamp()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'sync', 'queue' => 'retention', 'payload' => '{}',
            'exception' => 'synthetic', 'failed_at' => $start->format('Y-m-d H:i:s')]);

        // Operational: one second before 14 days nothing, at exactly 14 days both.
        $this->travelTo($start->addDays(14)->subSecond());
        [$report] = $this->report();
        $this->assertSame([0, 0], [$report['jobs']['past_period'], $report['failed_jobs']['past_period']]);
        $this->travelTo($start->addDays(14));
        [$report, $output] = $this->report();
        $day = $start->addDays(14)->toDateString();
        $this->assertSame(['past_period' => 1, 'oldest_due' => $day, 'newest_due' => $day, 'clock_index' => 'missing'], $report['jobs']);
        $this->assertSame(['past_period' => 1, 'oldest_due' => $day, 'newest_due' => $day, 'clock_index' => 'missing'], $report['failed_jobs']);
        $this->assertSame(['held' => 'clock_not_modeled'], $report['job_batches']);
        $this->assertStringContainsString('class=operational days=14 cutoff='.$start->toDateString(), $output);

        // Abandoned checkout: 30 days after the quote expires, never for the quote that became an order.
        $this->travelTo($expires->addDays(30)->subSecond());
        [$report] = $this->report();
        $this->assertSame(0, $report['quotes']['past_period']);
        $this->travelTo($expires->addDays(30));
        [$report, $output] = $this->report();
        $this->assertSame(['past_period' => count($unordered), 'oldest_due' => $expires->addDays(30)->toDateString(),
            'newest_due' => $expires->addDays(30)->toDateString(), 'clock_index' => 'yes'], $report['quotes']);
        $this->assertSame(DB::table('quote_lines')->whereIn('quote_id', $unordered)->count(), $report['quote_lines']['past_period']);
        $this->assertSame(['past_period' => 1, 'follows' => 'quotes'], $report['quote_pricings']);
        $this->assertSame(['held' => 'clock_not_modeled'], $report['quote_owners']);
        $this->assertMatchesRegularExpression('/^class=abandoned_checkout days=30 cutoff='.$expires->toDateString().'$/m', $output);
        // Long after, the ordered quote, its lines and pricing, and its reservation, claim and promotion still count nothing.
        $this->travelTo(CarbonImmutable::parse($ordered->expires_at, 'UTC')->addYears(50));
        [$report] = $this->report();
        $this->assertSame(count($unordered), $report['quotes']['past_period'], 'A quote an order was built from is never counted.');
        $this->assertSame(DB::table('quote_lines')->whereIn('quote_id', $unordered)->count(), $report['quote_lines']['past_period']);
        $this->assertGreaterThan(0, DB::table('quote_lines')->where('quote_id', $ordered->id)->count());
        $this->assertSame(1, $report['quote_pricings']['past_period']);
        // The ordered quote's reservation follows it and is held by its order.
        $this->assertSame(0, DB::table('inventory_reservations')->whereIn('quote_id', $unordered)->count());
        $this->assertGreaterThan(0, DB::table('inventory_reservations')->where('quote_id', $ordered->id)->count());
        foreach (['inventory_reservations', 'inventory_claims', 'promotion_uses'] as $table) {
            $this->assertSame(0, $report[$table]['past_period'], $table);
        }

        // Evidence: one second before 7 years nothing, at exactly 7 years the closed request with its events and export metadata.
        $this->travelTo($start->addYears(7)->subSecond());
        [$report] = $this->report();
        foreach (self::EVIDENCE as $table) {
            $this->assertSame(0, $report[$table]['past_period'], $table);
        }
        $this->travelTo($start->addYears(7));
        [$report] = $this->report();
        $closed = DB::table('customer_data_requests')->whereNotNull('resolved_at')->value('id');
        $this->assertSame(1, $report['customer_data_requests']['past_period']);
        $this->assertSame(DB::table('customer_data_request_events')->where('request_id', $closed)->count(), $report['customer_data_request_events']['past_period']);
        $this->assertSame(1, $report['customer_data_exports']['past_period']);
        $this->assertGreaterThan(0, DB::table('audit_events')->where('created_at', '<=', $start->format('Y-m-d H:i:s'))->count());
        $this->assertArrayNotHasKey('audit_events', $report, 'Audit events are never counted.');
        // The open request is never past, however old; nor are its events.
        $this->travelTo($start->addYears(60));
        [$report] = $this->report();
        $this->assertSame(1, $report['customer_data_requests']['past_period'], 'An open request is never past its period.');
        $this->assertSame(DB::table('customer_data_request_events')->where('request_id', $closed)->count(), $report['customer_data_request_events']['past_period']);
        $this->assertSame(1, DB::table('customer_data_requests')->whereNull('resolved_at')->count());
        // Orders and download records are never counted.
        foreach (['orders', 'order_attempts', 'checkout_intents', ...self::DOWNLOAD_RECORDS] as $table) {
            $this->assertArrayNotHasKey($table, $report, $table);
        }
    }

    public function test_production_checkout_reviews_without_an_order_and_identity_records_follow_their_clocks(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('h', 32))]);
        $this->identitySetup();
        config(['production_checkout.fresh_checkout_enabled' => true, 'customer-data-rights.retention' => self::RETENTION]);
        $start = now()->toImmutable();
        // One review an order was accepted from, and a second review of the same buyer that was never accepted.
        $fixture = $this->payable();
        $fixture['checkout']->review($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['catalog']['candidate']->id, $fixture['catalog']['items'],
            $fixture['basis']['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-review-abandoned');
        $this->assertSame([2, 1], [DB::table('production_checkout_reviews')->count(), DB::table('production_checkout_orders')->count()]);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', DB::table('production_checkout_reviews')->value('created_at'));
        // A completed recovery extends the verified chain (sequence 2, a recover challenge that only a verification references),
        // and a second recovery is requested but never completed.
        $email = $fixture['buyer']['user']->email;
        $this->requestIdentity('recover', $email);
        $received = $this->smtp('accept', (int) DB::table('production_identity_notices')->orderByDesc('id')->value('id'));
        $this->assertSame(1, preg_match('~http://localhost/customer/access#recover\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $received['data'], $match));
        (new CompleteIdentity)->complete($match[1], $match[2], 'RecoveredRetentionPassword123', 'Recovered declared buyer', str_repeat('e', 64));
        $this->requestIdentity('recover', $email);
        $this->assertSame(2, DB::table('production_identity_verifications')->count());
        $chain = DB::table('production_identity_verifications')->pluck('challenge_id')->all();
        $initial = (int) DB::table('production_identity_origins')->value('initial_challenge_id');
        $this->assertCount(1, array_diff($chain, [$initial]), 'One chain challenge is referenced only by a verification.');
        $unchained = DB::table('production_identity_challenges')->whereNotIn('id', $chain)->count();
        $this->assertGreaterThan(0, $unchained);
        $abandoned = CarbonImmutable::parse(DB::table('production_checkout_reviews')->whereNotIn('id', DB::table('production_checkout_orders')->select('review_id'))->value('created_at'), 'UTC');
        $this->assertGreaterThan(0, DB::table('production_identity_notices')->count());
        $this->assertSame(1, DB::table('production_identity_origins')->count());

        // ISO-8601 text clock: one second before 30 days nothing (the same calendar day as the cutoff), at 30 days the abandoned review only.
        $this->travelTo($abandoned->addDays(30)->subSecond());
        [$report] = $this->report();
        $this->assertSame(0, $report['production_checkout_reviews']['past_period']);
        $this->travelTo($abandoned->addDays(30));
        [$report] = $this->report();
        $due = $abandoned->addDays(30)->toDateString();
        $this->assertSame(['past_period' => 1, 'oldest_due' => $due, 'newest_due' => $due, 'clock_index' => 'missing'], $report['production_checkout_reviews']);
        foreach (['production_checkout_orders', 'production_checkout_order_lines', 'production_checkout_provider_intents', 'production_identity_origins'] as $table) {
            $this->assertArrayNotHasKey($table, $report, $table);
        }

        // Identity records: 12 months after their last clock column (a send attempt's lease and a challenge's expiry included). The verified
        // chain (every verification and every challenge an origin or a verification references) is never counted.
        $this->travelTo($start->addYear()->addHour());
        [$report] = $this->report();
        $this->assertSame(1, $report['production_checkout_reviews']['past_period'], 'The review the order was accepted from is never counted.');
        foreach (['production_identity_notices', 'production_identity_attempts', 'production_identity_outcomes'] as $table) {
            $this->assertSame(DB::table($table)->count(), $report[$table]['past_period'], $table);
            $this->assertGreaterThan(0, $report[$table]['past_period'], $table);
        }
        $this->assertSame($unchained, $report['production_identity_challenges']['past_period'], 'Only challenges outside the verified chain count.');
        $this->assertArrayNotHasKey('production_identity_verifications', $report);
        // Long after, the chain still verifies from sequence 1 and is still never counted.
        $this->travelTo($start->addYears(30));
        [$report] = $this->report();
        $this->assertSame($unchained, $report['production_identity_challenges']['past_period']);
        $this->assertArrayNotHasKey('production_identity_verifications', $report);
        $this->assertSame(['held' => 'clock_not_modeled'], $report['production_identity_addresses']);
    }

    public function test_every_migrated_table_is_scheduled_reviewed_or_reported_as_unclassified(): void
    {
        config(['customer-data-rights.retention' => self::RETENTION]);
        $listing = Schema::getTableListing(Schema::getCurrentSchemaListing(), false);
        $this->assertSame(array_values(array_unique($listing)), $listing, 'The listing must come from this connection\'s schema only.');
        $scheduled = array_column(RetentionSchedule::ENTRIES, 'table');
        $reviewed = array_keys(RetentionSchedule::NOT_CUSTOMER_DATA);
        $this->assertSame([], array_values(array_intersect($scheduled, $reviewed)), 'A table is both scheduled and reviewed as not customer data.');
        foreach (RetentionSchedule::NOT_CUSTOMER_DATA as $table => $reason) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertGreaterThan(20, strlen($reason), $table);
        }
        // Sessions hold IP addresses and user agents; framework queues and caches can hold any payload: never reviewed as safe.
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'] as $table) {
            $this->assertArrayNotHasKey($table, RetentionSchedule::NOT_CUSTOMER_DATA, $table);
        }

        [, $output] = $this->report();
        $this->assertSame(1, preg_match_all('/^unclassified=([a-z0-9_,]*) count=(\d+) note=each_needs_owner_retention_decision$/m', $output, $match));
        $unclassified = $match[1][0] === '' ? [] : explode(',', $match[1][0]);
        $this->assertSame(count($unclassified), (int) $match[2][0]);
        $this->assertSame(self::UNCLASSIFIED, $unclassified, 'A migration added or removed a table: classify it deliberately.');

        // Nothing migrated is silently omitted: every table is scheduled, reviewed or printed as unclassified, exactly once.
        $accounted = [...$scheduled, ...$reviewed, ...$unclassified];
        sort($accounted);
        $tables = $listing;
        sort($tables);
        $this->assertSame($tables, $accounted);
    }

    public function test_a_new_unclassified_table_is_reported_by_name_without_failing(): void
    {
        config(['customer-data-rights.retention' => self::RETENTION]);
        Schema::create('retention_probe_records', function ($table): void {
            $table->id();
            $table->string('email');
        });
        DB::table('retention_probe_records')->insert(['email' => 'retention.probe@example.test']);
        // A temporary table lives outside the database's own schema (SQLite `temp`; MySQL never lists one) and is never listed.
        Schema::create('retention_foreign_records', function ($table): void {
            $table->temporary();
            $table->id();
        });
        $queries = $this->recordQueries();
        [, $output] = $this->report();
        $reads = $queries->all;

        $this->assertMatchesRegularExpression('/^unclassified=(?:[a-z0-9_]+,)*retention_probe_records(?:,[a-z0-9_]+)* count=\d+ /m', $output);
        $this->assertStringNotContainsString('retention_foreign_records', $output);
        $this->assertStringNotContainsString('retention.probe@example.test', $output);
        $this->assertNotEmpty($reads);
        foreach ($reads as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*select\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/for update|lock in share mode|for share/i', $sql);
        }
    }

    /** A support attachment row for a source; its own timestamps are irrelevant, since it follows its source. */
    private function attachment(string $kind, string $sourceId): void
    {
        DB::table('support_attachments')->insert(['public_id' => (string) Str::uuid(), 'source_kind' => $kind, 'source_id' => $sourceId,
            'source_family' => $kind === 'inquiry' ? 'original_inquiry_session_v1' : 'test_service_project_v1', 'source_hash' => str_repeat('a', 64),
            'origin_hash' => str_repeat('a', 64), 'actor_hash' => str_repeat('a', 64), 'request_key' => (string) Str::uuid(), 'policy_hash' => str_repeat('a', 64),
            'manifest_hash' => str_repeat('a', 64), 'source_binding' => 'synthetic opaque evidence', 'policy_binding' => 'synthetic opaque evidence',
            'manifest' => 'synthetic opaque evidence', 'source_version' => 0, 'bytes' => 12, 'expires_at' => 200, 'attempt' => 0, 'created_at' => 100,
            'updated_at' => 100, 'state' => 'ready']);
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
