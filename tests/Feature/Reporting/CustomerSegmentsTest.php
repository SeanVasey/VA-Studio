<?php

namespace Tests\Feature\Reporting;

use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Models\ConsentEvent;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Reporting\CustomerConsentStatus;
use App\Domain\Reporting\CustomerSegments;
use App\Domain\Reporting\ReportingException;
use App\Filament\Pages\CustomerSegmentsPage;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

/**
 * Consent-aware segmentation. Unknown consent stays unknown, a grant that is no longer bound to the current notice or
 * recipient (or has a suppression record) is excluded from marketing, and the export carries no contact data.
 */
class CustomerSegmentsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        R::configure();
        ConsentFixtures::configure();
        Queue::fake();
        R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        config(['reporting.customer_segments.enabled' => true, 'reporting.customer_segments.export_enabled' => true, 'reporting.customer_segments.marketing_segment_enabled' => true]);
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
    }

    private function consent(array $customer, string $action, int $version = 0): void
    {
        app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], $action === 'grant' ? ConsentFixtures::grant($version) : ConsentFixtures::withdraw($version));
    }

    private function csv(string $segment): array
    {
        $export = app(CustomerSegments::class)->export($this->admin, $segment);

        return $export + ['table' => array_map('str_getcsv', explode("\n", trim($export['csv'])))];
    }

    public function test_marketing_segment_admits_only_verified_granted_consent_and_the_export_is_minimal_labelled_and_audited(): void
    {
        $c1 = CustomerFixtures::account();      // repeat purchaser, verified granted
        $c2 = CustomerFixtures::account();      // purchaser, no recorded choice
        $c4 = CustomerFixtures::account();      // no purchase, withdrawn
        $c5 = CustomerFixtures::account();      // no purchase, verified granted
        $c6 = CustomerFixtures::account();      // purchaser, granted, then the account email changed
        $c7 = CustomerFixtures::account();      // no purchase, withdrew then granted again: suppression record exists
        $o1 = CustomerFixtures::ready($c1['user'], 'ONE');
        $o1b = CustomerFixtures::ready($c1['user'], 'ONEB');
        $o2 = CustomerFixtures::ready($c2['user'], 'TWO');
        $o6 = CustomerFixtures::ready($c6['user'], 'SIX');
        $this->consent($c1, 'grant');
        $this->consent($c4, 'withdraw');
        $this->consent($c5, 'grant');
        $this->consent($c6, 'grant');
        $this->consent($c7, 'withdraw');
        $this->consent($c7, 'grant', 1);
        User::whereKey($c6['user']->id)->update(['email' => 'moved-away@example.test']);
        $paid = fn (array ...$orders): int => array_sum(array_map(fn (array $o): int => $o['payment']->amount_minor, $orders));

        // Drift guard: the verified status depends on reproducing the capture-time recipient binding exactly.
        $event = ConsentEvent::where('customer_account_id', $c1['account']->id)->sole();
        $this->assertSame($event->recipient_hmac, CustomerConsentStatus::recipientHash($c1['account']->id, strtolower($c1['user']->email)));

        $overview = app(CustomerSegments::class)->overview($this->admin);
        $this->assertTrue($overview['available']);
        $this->assertSame(6, $overview['accounts']);
        $this->assertSame(['purchasers' => 3, 'repeat_purchasers' => 1, 'refunded_purchasers' => 0, 'no_purchase' => 3, 'marketing_email_eligible' => 2],
            array_map(fn (array $segment): ?int => $segment['count'], $overview['segments']));
        $this->assertSame(['unknown' => 1, 'withdrawn' => 1, 'granted' => 2, 'granted_unverified' => 2], $overview['consent']);

        // Marketing: exactly the two verified accounts; unknown, withdrawn, stale-recipient and suppressed accounts are absent.
        $marketing = $this->csv('marketing_email_eligible');
        $this->assertSame(2, $marketing['customers']);
        $references = array_column(array_slice($marketing['table'], 1), 0);
        sort($references);
        $expected = [$c1['account']->public_id, $c5['account']->public_id];
        sort($expected);
        $this->assertSame($expected, $references);
        $this->assertSame(['granted'], array_values(array_unique(array_column(array_slice($marketing['table'], 1), 4))));
        foreach ([$c2, $c4, $c6, $c7] as $excluded) {
            $this->assertStringNotContainsString($excluded['account']->public_id, $marketing['csv']);
        }
        $this->assertSame(['customer_reference', 'environment', 'segment', 'account_active', 'email_marketing_consent', 'orders', 'first_order_date_utc',
            'last_order_date_utc', 'currency', 'paid_minor', 'refunded_minor', 'net_minor'], $marketing['table'][0]);

        // Purchasers include unknown and unverified accounts but label their consent; spend is exact per account and currency.
        $purchasers = $this->csv('purchasers');
        $byReference = [];
        foreach (array_slice($purchasers['table'], 1) as $row) {
            $byReference[$row[0]] = $row;
        }
        $this->assertCount(3, $byReference);
        $this->assertSame(['granted', '2', 'USD', (string) $paid($o1, $o1b), '0', (string) $paid($o1, $o1b)],
            [$byReference[$c1['account']->public_id][4], ...array_slice($byReference[$c1['account']->public_id], 5, 1), $byReference[$c1['account']->public_id][8],
                $byReference[$c1['account']->public_id][9], $byReference[$c1['account']->public_id][10], $byReference[$c1['account']->public_id][11]]);
        $this->assertSame(['unknown', (string) $paid($o2)], [$byReference[$c2['account']->public_id][4], $byReference[$c2['account']->public_id][9]]);
        $this->assertSame(['granted_unverified', (string) $paid($o6)], [$byReference[$c6['account']->public_id][4], $byReference[$c6['account']->public_id][9]]);
        $noPurchase = $this->csv('no_purchase');
        $this->assertSame(['0||0'], array_values(array_unique(array_map(fn (array $row): string => $row[5].'|'.$row[8].'|'.$row[9], array_slice($noPurchase['table'], 1)))));
        $this->assertSame(3, $noPurchase['customers']);

        // Minimal fields: no contact data, names, access keys or order identifiers leave the application.
        foreach ([$marketing['csv'], $purchasers['csv'], $noPurchase['csv']] as $csv) {
            foreach ([$c1, $c2, $c5, $c6] as $customer) {
                foreach ([$customer['user']->email, $customer['user']->name, $customer['account']->owner_key] as $private) {
                    $this->assertStringNotContainsString($private, $csv);
                }
            }
            foreach ([$o1, $o1b, $o2, $o6] as $order) {
                $this->assertStringNotContainsString($order['order']->public_id, $csv);
            }
            $this->assertStringNotContainsString('moved-away@example.test', $csv);
        }

        // One audit row per export, bound to the file and carrying no customer content.
        $audits = AuditEvent::where('action', 'reporting.customer_segment_exported')->orderBy('id')->get();
        $this->assertCount(3, $audits);
        $this->assertSame(['marketing_email_eligible', 2, 2, hash('sha256', $marketing['csv']), 'verified_granted_only', false],
            [$audits[0]->context['segment'], $audits[0]->context['rows'], $audits[0]->context['customers'], $audits[0]->context['sha256'], $audits[0]->context['consent_filter'], $audits[0]->context['contact_fields']]);
        $this->assertSame([$this->admin->id, 'labelled_only'], [$audits[1]->actor_id, $audits[1]->context['consent_filter']]);
        $this->assertStringNotContainsString($c1['user']->email, json_encode($audits->pluck('context')));

        // The page shows counts and consent labels, never rows; the action downloads the selected segment (audited).
        Livewire::test(CustomerSegmentsPage::class)->assertSee('Granted but not verified (excluded)')->assertSee('Verified granted consent only.')
            ->assertDontSee($c1['account']->public_id)->set('segment', 'marketing_email_eligible')->callAction('exportCsv')
            ->assertFileDownloaded('customer-segment-marketing_email_eligible.csv');
        $this->assertSame(4, AuditEvent::where('action', 'reporting.customer_segment_exported')->count());

        // A changed notice never promotes an old grant: nobody is marketing-eligible and the export is header-only.
        config(['customer-preferences.email_marketing' => ConsentFixtures::policy('synthetic-notice-v2')]);
        $this->assertSame(0, app(CustomerSegments::class)->overview($this->admin)['segments']['marketing_email_eligible']['count']);
        $this->assertSame(0, $this->csv('marketing_email_eligible')['customers']);
        // So does turning grants off, or never configuring a notice.
        config(['customer-preferences.email_marketing' => ConsentFixtures::policy(), 'customer-preferences.test_grants_enabled' => false]);
        $this->assertSame(0, app(CustomerSegments::class)->overview($this->admin)['segments']['marketing_email_eligible']['count']);
        config(['customer-preferences.test_grants_enabled' => true, 'customer-preferences.email_marketing' => null]);
        $this->assertSame(0, app(CustomerSegments::class)->overview($this->admin)['segments']['marketing_email_eligible']['count']);
        ConsentFixtures::configure();
        $this->assertSame(2, app(CustomerSegments::class)->overview($this->admin)['segments']['marketing_email_eligible']['count']);

        // Purchase segments are unavailable, not "everyone without a purchase", when no test account is configured.
        config(['payments.stripe.account_id' => null]);
        $segments = app(CustomerSegments::class)->overview($this->admin)['segments'];
        $this->assertSame([null, null, null, null, 2], array_values(array_map(fn (array $segment): ?int => $segment['count'], $segments)));
        $this->assertStringContainsString('not configured', $segments['no_purchase']['reason']);
        try {
            app(CustomerSegments::class)->export($this->admin, 'no_purchase');
            $this->fail('An unavailable segment was exported.');
        } catch (ReportingException $error) {
            $this->assertSame('unavailable', $error->reason);
        }
    }

    public function test_the_mechanism_is_default_off_and_each_flag_refuses_before_anything_is_read_or_written(): void
    {
        $c1 = CustomerFixtures::account();
        $this->consent($c1, 'grant');
        $audits = fn (): int => AuditEvent::where('action', 'like', 'reporting.%')->count();
        $defaults = require base_path('config/reporting.php');
        $this->assertSame([false, false, false], [$defaults['customer_segments']['enabled'], $defaults['customer_segments']['export_enabled'], $defaults['customer_segments']['marketing_segment_enabled']]);

        config(['reporting.customer_segments.enabled' => false]);
        $sql = [];
        DB::listen(function ($query) use (&$sql): void { $sql[] = $query->sql; });
        $overview = app(CustomerSegments::class)->overview($this->admin);
        $this->assertFalse($overview['available']);
        $this->assertStringContainsString('U-13', $overview['reason']);
        $this->assertSame([], $overview['segments']);
        try {
            app(CustomerSegments::class)->export($this->admin, 'purchasers');
            $this->fail('A disabled export ran.');
        } catch (ReportingException $error) {
            $this->assertSame('disabled', $error->reason);
        }
        // Only the staff authority reads happen; no customer table is touched while the mechanism is off.
        $this->assertSame([], array_values(array_filter($sql, fn (string $statement): bool => preg_match('/customer_|orders|order_finalizations/', $statement) === 1)));
        Livewire::test(CustomerSegmentsPage::class)->assertSee('Customer segmentation is off')->callAction('exportCsv')->assertNotified('Export refused')->assertNoFileDownloaded();

        config(['reporting.customer_segments.enabled' => true, 'reporting.customer_segments.export_enabled' => false]);
        $this->assertTrue(app(CustomerSegments::class)->overview($this->admin)['available']);
        try {
            app(CustomerSegments::class)->export($this->admin, 'purchasers');
            $this->fail('An export ran with the export flag off.');
        } catch (ReportingException $error) {
            $this->assertSame('disabled', $error->reason);
        }
        config(['reporting.customer_segments.export_enabled' => true, 'reporting.customer_segments.marketing_segment_enabled' => false]);
        $segments = app(CustomerSegments::class)->overview($this->admin)['segments'];
        $this->assertNull($segments['marketing_email_eligible']['count']);
        $this->assertStringContainsString('REPORTING_MARKETING_SEGMENT_ENABLED', $segments['marketing_email_eligible']['reason']);
        foreach (['marketing_email_eligible' => 'marketing_disabled', 'not-a-segment' => 'unknown_segment'] as $segment => $reason) {
            try {
                app(CustomerSegments::class)->export($this->admin, $segment);
                $this->fail('A refused segment was exported.');
            } catch (ReportingException $error) {
                $this->assertSame($reason, $error->reason);
            }
        }
        $this->assertSame(0, $audits());
    }
}
