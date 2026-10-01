<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Filament\Resources\CustomerInquiryResource;
use App\Filament\Resources\CustomerInquiryResource\Pages\ListCustomerInquiries;
use App\Filament\Resources\CustomerInquiryResource\Pages\ViewCustomerInquiry;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class CustomerInquiryAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private CustomerInquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->operator = LicenseFixtures::admin();
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC PRIVATE INQUIRY TEST NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-TEST-REFERENCE', 'inquiries.operator_user_id' => $this->operator->id]);
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content(), 'Synthetic test contact', $this->operator);
        app(SiteContent::class)->publish($release->id, 0, $this->operator);
        app(SubmitInquiry::class)->handle(['name' => 'Private synthetic buyer', 'email' => 'private@example.test', 'subject' => 'Private test subject',
            'message' => '<script>synthetic body only</script>', 'website' => '', 'requestKey' => (string) Str::uuid()], str_repeat('a', 64));
        $this->inquiry = CustomerInquiry::sole();
    }

    public function test_guest_customer_and_unverified_staff_cannot_list_view_or_apply_private_actions(): void
    {
        $paths = ['/admin/customer-inquiries', '/admin/customer-inquiries/'.$this->inquiry->public_id];
        foreach ($paths as $path) {
            $response = $this->get($path)->assertRedirect('/admin/login');
            $response->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('private@example.test', false);
        }
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        foreach ([User::factory()->create(), $unverified] as $actor) {
            foreach ($paths as $path) {
                $this->actingAs($actor)->get($path)->assertForbidden()->assertDontSee('private@example.test', false);
            }
            try {
                app(InquiryAdministration::class)->transition($this->inquiry->id, 'archived', 0, $actor);
                $this->fail('Private action was allowed.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame('new', $this->inquiry->fresh()->state);
        $this->assertSame(0, AuditEvent::where('action', 'inquiry.archived')->count());
    }

    public function test_actual_staff_inbox_detail_and_actions_persist_and_audit_without_sending_or_mutating_original_input(): void
    {
        $this->actingAs($this->operator);
        $expectedUrl = url('/admin/customer-inquiries/'.$this->inquiry->public_id);
        Livewire::test(ListCustomerInquiries::class)->assertCanSeeTableRecords([$this->inquiry])
            ->assertSee('Private test subject')->assertTableActionHasUrl('view', $expectedUrl, $this->inquiry);
        $response = $this->get(CustomerInquiryResource::getUrl('view', ['record' => $this->inquiry]))->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private')->assertSee('private@example.test', false)
            ->assertDontSee('<script>synthetic body only</script>', false)->assertSee('&lt;script&gt;synthetic body only&lt;/script&gt;', false);
        $this->assertSame($this->operator->id, AuditEvent::where('action', 'inquiry.viewed')->sole()->actor_id);
        $payload = $this->inquiry->payload;
        Livewire::test(ListCustomerInquiries::class)->callTableAction('markRead', $this->inquiry)->assertHasNoTableActionErrors();
        $this->assertSame('read', $this->inquiry->fresh()->state);
        $this->assertSame(1, $this->inquiry->fresh()->version);
        Livewire::test(ViewCustomerInquiry::class, ['record' => $this->inquiry->public_id])->callAction('archive')->assertHasNoActionErrors();
        $this->assertSame('archived', $this->inquiry->fresh()->state);
        $this->assertSame(2, $this->inquiry->fresh()->version);
        $this->assertSame($payload, $this->inquiry->fresh()->payload);
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.read')->count());
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.archived')->count());
        Livewire::test(ListCustomerInquiries::class)->assertCanNotSeeTableRecords([$this->inquiry]);
        foreach (AuditEvent::where('action', 'like', 'inquiry.%')->get() as $audit) {
            $this->assertStringNotContainsString('private@example.test', json_encode($audit->context));
            $this->assertStringNotContainsString('synthetic body only', json_encode($audit->context));
        }
    }

    public function test_detail_actions_update_visible_state_and_action_availability_without_a_page_reload(): void
    {
        $this->actingAs($this->operator);
        $detail = Livewire::test(ViewCustomerInquiry::class, ['record' => $this->inquiry->public_id]);
        $detail->assertActionVisible('markRead')->assertActionVisible('archive')
            ->callAction('markRead')->assertHasNoActionErrors()
            ->assertActionHidden('markRead')->assertActionVisible('archive');
        $this->assertSame('read', $detail->instance()->getRecord()->state);
        $this->assertSame(1, $detail->instance()->getRecord()->version);
        $detail->callAction('archive')->assertHasNoActionErrors()
            ->assertActionHidden('markRead')->assertActionHidden('archive');
        $this->assertSame('archived', $detail->instance()->getRecord()->state);
        $this->assertSame(2, $detail->instance()->getRecord()->version);
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.read')->count());
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.archived')->count());
    }

    #[DataProvider('withdrawals')]
    public function test_retained_staff_actions_and_livewire_refresh_recheck_persisted_authority(string $field, mixed $value): void
    {
        $this->actingAs($this->operator);
        $inbox = Livewire::test(ListCustomerInquiries::class);
        User::findOrFail($this->operator->id)->forceFill([$field => $value])->save();
        $inbox->call('$refresh')->assertForbidden();
        try {
            app(InquiryAdministration::class)->transition($this->inquiry->id, 'archived', 0, $this->operator);
            $this->fail('Revoked actor applied private action.');
        } catch (AuthorizationException) {
        }
        $this->assertSame('new', $this->inquiry->fresh()->state);
        $this->assertSame(0, AuditEvent::where('action', 'inquiry.archived')->count());
    }

    public static function withdrawals(): array
    {
        return ['role' => ['is_admin', false], 'verification' => ['email_verified_at', null]];
    }

    public function test_required_mfa_withdrawal_denies_retained_private_component_and_direct_action(): void
    {
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->operator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->actingAs($this->operator);
            $inbox = Livewire::test(ListCustomerInquiries::class);
            User::findOrFail($this->operator->id)->saveAppAuthenticationSecret(null);
            $inbox->call('$refresh')->assertForbidden();
            try {
                app(InquiryAdministration::class)->transition($this->inquiry->id, 'archived', 0, $this->operator);
                $this->fail('Removed enrollment applied private action.');
            } catch (AuthorizationException) {
            }
            $this->assertSame('new', $this->inquiry->fresh()->state);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_stale_actions_and_archive_replay_do_not_duplicate_audits_or_reopen_inquiries(): void
    {
        $service = app(InquiryAdministration::class);
        $service->transition($this->inquiry->id, 'read', 0, $this->operator);
        try {
            $service->transition($this->inquiry->id, 'archived', 0, $this->operator);
            $this->fail('Stale action was allowed.');
        } catch (ValidationException) {
        }
        $service->transition($this->inquiry->id, 'archived', 1, $this->operator);
        $service->transition($this->inquiry->id, 'archived', 2, $this->operator);
        try {
            $service->transition($this->inquiry->id, 'read', 2, $this->operator);
            $this->fail('Archive reopened.');
        } catch (ValidationException) {
        }
        $this->assertSame('archived', $this->inquiry->fresh()->state);
        $this->assertSame(2, $this->inquiry->fresh()->version);
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.archived')->count());
    }

    public function test_failed_audit_rolls_back_archive_and_prevents_private_view_result(): void
    {
        AuditEvent::creating(function (AuditEvent $event): void {
            if (in_array($event->action, ['inquiry.archived', 'inquiry.viewed'], true)) {
                throw new RuntimeException('Synthetic private failure.');
            }
        });
        foreach ([fn () => app(InquiryAdministration::class)->transition($this->inquiry->id, 'archived', 0, $this->operator),
            fn () => app(InquiryAdministration::class)->view($this->inquiry->id, $this->operator)] as $operation) {
            try {
                $operation();
                $this->fail('Un-audited private operation completed.');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame('new', $this->inquiry->fresh()->state);
        $this->assertSame(0, $this->inquiry->fresh()->version);
        $this->assertSame(0, AuditEvent::whereIn('action', ['inquiry.archived', 'inquiry.viewed'])->count());
    }

    public function test_inbox_has_bounded_pagination_and_no_create_edit_delete_or_bulk_abilities(): void
    {
        $this->actingAs($this->operator);
        foreach (['create', 'update', 'delete', 'deleteAny', 'forceDelete', 'restore'] as $ability) {
            $this->assertFalse(CustomerInquiryResource::getAuthorizationResponse($ability, $this->inquiry)->allowed());
        }
        $this->assertSame(['index', 'view'], array_keys(CustomerInquiryResource::getPages()));
        $inbox = Livewire::test(ListCustomerInquiries::class)->set('tableRecordsPerPage', 'all');
        $this->assertSame(25, $inbox->instance()->getTableRecordsPerPage());
    }
}
