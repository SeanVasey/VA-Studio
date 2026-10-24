<?php

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\CustomerSegments;
use App\Domain\Reporting\SalesReport;
use App\Filament\Pages\CustomerSegmentsPage;
use App\Filament\Pages\SalesReportPage;
use App\Filament\Widgets\CommerceAttentionWidget;
use App\Filament\Widgets\ContentPipelineWidget;
use App\Filament\Widgets\QueueHealthWidget;
use App\Filament\Widgets\UnrecordedEvidenceWidget;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * Every reporting widget, page and export refuses a guest, a customer, staff without the MFA the panel requires and staff
 * whose authority was removed after the component mounted. The checks run on each Livewire request, not only at mount.
 */
class ReportingAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const COMPONENTS = [QueueHealthWidget::class, CommerceAttentionWidget::class, ContentPipelineWidget::class,
        UnrecordedEvidenceWidget::class, SalesReportPage::class, CustomerSegmentsPage::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['queue.default' => 'database']);
        $this->mfaRequired(false);
    }

    private function mfaRequired(bool $required): void
    {
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
    }

    private function staff(bool $enrolled): User
    {
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret($enrolled ? 'JBSWY3DPEHPK3PXP' : null);

        return $admin;
    }

    public function test_every_widget_and_page_refuses_guests_customers_unenrolled_staff_and_authority_removed_after_mount(): void
    {
        foreach (self::COMPONENTS as $component) {
            Livewire::test($component)->assertForbidden();
        }
        $this->actingAs(User::factory()->create());
        foreach (self::COMPONENTS as $component) {
            Livewire::test($component)->assertForbidden();
        }
        // Staff without the enrollment the panel requires.
        $unenrolled = $this->staff(false);
        $this->actingAs($unenrolled);
        $this->mfaRequired(true);
        foreach (self::COMPONENTS as $component) {
            Livewire::test($component)->assertForbidden();
        }
        // Enrolled staff pass, and a component mounted before the role or enrollment is removed refuses the next request.
        $enrolled = $this->staff(true);
        $this->actingAs($enrolled);
        $mounted = [];
        foreach (self::COMPONENTS as $component) {
            $mounted[$component] = Livewire::test($component)->assertSuccessful();
        }
        $enrolled->saveAppAuthenticationSecret(null);
        foreach (self::COMPONENTS as $component) {
            $mounted[$component]->call('$refresh')->assertForbidden();
        }
        $this->mfaRequired(false);
        $admin = $this->staff(false);
        $this->actingAs($admin);
        $mounted = [];
        foreach (self::COMPONENTS as $component) {
            $mounted[$component] = Livewire::test($component)->assertSuccessful();
        }
        User::whereKey($admin->id)->update(['is_admin' => false]);
        foreach (self::COMPONENTS as $component) {
            $mounted[$component]->call('$refresh')->assertForbidden();
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'reporting.%')->count());
    }

    public function test_pages_over_http_and_the_dashboard_show_widgets_only_to_staff(): void
    {
        foreach (['/admin/sales-report', '/admin/customer-segments'] as $path) {
            $this->get($path)->assertRedirect();
        }
        $this->actingAs(User::factory()->create());
        foreach (['/admin', '/admin/sales-report', '/admin/customer-segments'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->actingAs($this->staff(true));
        foreach (['/admin/sales-report', '/admin/customer-segments'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/admin')->assertOk()->assertSeeLivewire(QueueHealthWidget::class)->assertSeeLivewire(CommerceAttentionWidget::class)
            ->assertSeeLivewire(ContentPipelineWidget::class)->assertSeeLivewire(UnrecordedEvidenceWidget::class);
    }

    public function test_exports_need_staff_authority_and_an_enrolled_authenticator_in_every_environment_and_refusals_write_nothing(): void
    {
        config(['reporting.customer_segments.enabled' => true, 'reporting.customer_segments.export_enabled' => true]);
        $range = ['2026-10-01', '2026-10-24', 'day'];
        foreach ([null, User::factory()->create()] as $actor) {
            foreach ([fn () => app(SalesReport::class)->export($actor, ...$range), fn () => app(CustomerSegments::class)->export($actor, 'purchasers')] as $export) {
                try {
                    $export();
                    $this->fail('An unauthorized actor exported.');
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        // The panel does not require MFA here, but an export still does.
        $unenrolled = $this->staff(false);
        $this->actingAs($unenrolled);
        $this->assertSame(0, app(CustomerSegments::class)->overview($unenrolled)['accounts']);
        foreach ([fn () => app(SalesReport::class)->export($unenrolled, ...$range), fn () => app(CustomerSegments::class)->export($unenrolled, 'purchasers')] as $export) {
            try {
                $export();
                $this->fail('Staff without an enrolled authenticator exported.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([SalesReportPage::class, CustomerSegmentsPage::class] as $page) {
            Livewire::test($page)->callAction('exportCsv')->assertNotified('Export refused')->assertNoFileDownloaded();
        }
        // Direct Livewire calls by non-staff are refused before the action runs.
        $this->actingAs(User::factory()->create());
        foreach ([SalesReportPage::class, CustomerSegmentsPage::class] as $page) {
            Livewire::test($page)->assertForbidden();
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'reporting.%')->count());
    }
}
