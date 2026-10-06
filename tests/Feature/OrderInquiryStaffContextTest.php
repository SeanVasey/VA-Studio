<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\OrderInquiry;
use App\Filament\Resources\CustomerInquiryResource;
use App\Filament\Resources\CustomerInquiryResource\Pages\ViewCustomerInquiry;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderInquiryFixtures as Fixture;
use Tests\TestCase;

/** Runs on the composed backend + independently owned order-support resource source. */
class OrderInquiryStaffContextTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_actual_staff_page_renders_minimal_linked_and_generic_context_without_changing_the_original(): void
    {
        $fixture = Fixture::configure();
        $order = Fixture::guest();
        $saved = app(OrderInquiry::class)->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, Fixture::body());
        $inquiry = CustomerInquiry::where('public_id', $saved['receipt'])->sole();
        $original = $inquiry->getAttributes();
        $this->actingAs($fixture['actor']);
        Livewire::test(ViewCustomerInquiry::class, ['record' => $inquiry->public_id])
            ->assertSee('Retained test order reference')->assertSee('Linked test order '.$order->public_id)
            ->assertSee('This retained reference does not confirm current payment, download access or usage rights.')
            ->assertDontSee($order->owner_key)->assertDontSee($order->payload_hash);
        Livewire::test(ViewCustomerInquiry::class, ['record' => $fixture['inquiry']->public_id])->assertSee('This inquiry has no linked test order.');
        $this->assertSame($original, $inquiry->fresh()->getAttributes());
        $this->assertSame([$fixture['actor']->id], AuditEvent::where('action', 'inquiry.order_context_viewed')->distinct()->pluck('actor_id')->all());
    }

    #[DataProvider('withdrawals')]
    public function test_actual_retained_context_entry_rechecks_authority_even_after_filament_cached_its_state(string $kind): void
    {
        $fixture = Fixture::configure();
        $fixture['actor']->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $order = Fixture::guest();
            $saved = app(OrderInquiry::class)->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, Fixture::body());
            $inquiry = CustomerInquiry::where('public_id', $saved['receipt'])->sole();
            $this->actingAs($fixture['actor']);
            $entry = CustomerInquiryResource::infolist(Schema::make()->record($inquiry))->getComponentByStatePath('order_context');
            $this->assertNotNull($entry);
            $this->assertStringContainsString($order->public_id, $entry->getState());
            if ($kind === 'role') {
                $fixture['actor']->forceFill(['is_admin' => false])->save();
            } else {
                $fixture['actor']->saveAppAuthenticationSecret(null);
            }
            $before = AuditEvent::where('action', 'inquiry.order_context_viewed')->count();
            try {
                $entry->getState();
                $this->fail('Cached reference escaped current authorization.');
            } catch (AuthorizationException) {
                $this->assertSame($before, AuditEvent::where('action', 'inquiry.order_context_viewed')->count());
            }
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public static function withdrawals(): array
    {
        return [['role'], ['mfa']];
    }
}
