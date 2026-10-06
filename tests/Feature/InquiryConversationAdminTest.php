<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\Models\InquiryMessage;
use App\Filament\Resources\CustomerInquiryResource\Pages\ViewCustomerInquiry;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\TestCase;

class InquiryConversationAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_real_staff_reply_followup_projection_and_archive(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = Fixture::create();
        $this->actingAs($actor);
        $page = Livewire::test(ViewCustomerInquiry::class, ['record' => $inquiry->public_id]);
        $page->assertSee('No replies yet.')->callAction('reply', ['message' => '<script>Synthetic staff reply</script>'])->assertHasNoActionErrors();
        $this->assertSame('<script>Synthetic staff reply</script>', InquiryMessage::sole()->body);
        $this->get('/admin/customer-inquiries/'.$inquiry->public_id)->assertOk()
            ->assertSee('&lt;script&gt;Synthetic staff reply&lt;/script&gt;', false)->assertDontSee('<script>Synthetic staff reply</script>', false);
        app(InquiryConversation::class)->followUp($inquiry->public_id, Fixture::OWNER, Fixture::message('Synthetic follow-up'));
        $page->call('$refresh')->assertSee('Synthetic follow-up')->callAction('archive')->assertHasNoActionErrors()->assertActionHidden('reply');
        $this->assertFalse(app(InquiryConversation::class)->owner($inquiry->public_id, Fixture::OWNER)['canReply']);
        $this->assertDatabaseCount('inquiry_messages', 2);
        $this->assertSame($actor->id, AuditEvent::where('action', 'inquiry.message_saved')->orderBy('id')->first()->actor_id);
    }

    public function test_mounted_reply_is_denied_after_archive_or_authority_withdrawal(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = Fixture::create();
        $this->actingAs($actor);
        $page = Livewire::test(ViewCustomerInquiry::class, ['record' => $inquiry->public_id])->mountAction('reply');
        app(InquiryAdministration::class)->transition($inquiry->id, 'archived', 0, $actor);
        $page->setActionData(['message' => 'Late reply'])->callMountedAction();
        $this->assertDatabaseCount('inquiry_messages', 0);
        $actor->forceFill(['is_admin' => false])->save();
        $page->call('$refresh')->assertForbidden();
    }
}
