<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\InquiryException;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class InquiryConversationTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->fixture = Fixture::create();
    }

    public function test_reply_followup_read_and_archive_preserve_original_and_encrypt_append_only_history(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = $this->fixture;
        $before = $inquiry->getAttributes();
        $service = app(InquiryConversation::class);
        $staff = Fixture::message('<script>synthetic staff reply</script>');
        $owner = Fixture::message('Synthetic follow-up');
        $saved = $service->reply($inquiry->id, $staff, $actor);
        $this->assertFalse($saved['replayed']);
        $this->assertSame($saved['messageId'], $service->reply($inquiry->id, $staff, $actor)['messageId']);
        $this->actingAs($actor); // Anonymous ownership must not inherit this ambient staff audit actor.
        $followup = $service->followUp($inquiry->public_id, Fixture::OWNER, $owner);
        $projection = $service->owner($inquiry->public_id, Fixture::OWNER);
        $this->assertSame(['staff', 'you'], array_column($projection['messages'], 'sender'));
        $this->assertSame([$staff['message'], $owner['message']], array_column($projection['messages'], 'message'));
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $raw = DB::table('inquiry_messages')->get()->toJson();
        $this->assertStringNotContainsString('synthetic staff reply', $raw);
        $this->assertStringNotContainsString('Synthetic follow-up', $raw);
        $this->assertSame([$actor->id, null], AuditEvent::where('action', 'inquiry.message_saved')->orderBy('id')->pluck('actor_id')->all());
        $this->assertStringNotContainsString('Synthetic follow-up', AuditEvent::all()->toJson());
        app(InquiryAdministration::class)->transition($inquiry->id, 'archived', 0, $actor);
        $this->assertFalse($service->owner($inquiry->public_id, Fixture::OWNER)['canReply']);
        $this->assertSame($projection['messages'], $service->staff($inquiry->id, $actor)['messages']);
        $this->assertSame($followup['messageId'], $service->followUp($inquiry->public_id, Fixture::OWNER, $owner)['messageId']);
        $this->refused(fn () => $service->followUp($inquiry->public_id, Fixture::OWNER, Fixture::message()), 409);
        $this->refused(fn () => $service->reply($inquiry->id, Fixture::message(), $actor), 409);
        $this->assertDatabaseCount('inquiry_messages', 2);
    }

    public function test_replay_binds_exact_body_sender_and_current_authority(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = $this->fixture;
        $service = app(InquiryConversation::class);
        $body = Fixture::message();
        $service->reply($inquiry->id, $body, $actor);
        $this->refused(fn () => $service->reply($inquiry->id, array_replace($body, ['message' => 'Changed']), $actor), 409);
        $other = LicenseFixtures::admin();
        $this->refused(fn () => $service->reply($inquiry->id, $body, $other), 409);
        $this->refused(fn () => $service->owner($inquiry->public_id, str_repeat('b', 64)), 404);
        $this->refused(fn () => $service->followUp(strtoupper($inquiry->public_id), Fixture::OWNER, $body), 404);
        $actor->forceFill(['is_admin' => false])->save();
        $this->expectException(AuthorizationException::class);
        $service->reply($inquiry->id, $body, $actor);
    }

    public function test_required_mfa_withdrawal_denies_new_and_retained_reads_and_replies(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = $this->fixture;
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $service = app(InquiryConversation::class);
            $body = Fixture::message();
            $service->reply($inquiry->id, $body, $actor);
            $actor->fresh()->saveAppAuthenticationSecret(null);
            foreach ([fn () => $service->staff($inquiry->id, $actor), fn () => $service->reply($inquiry->id, $body, $actor)] as $operation) {
                try {
                    $operation();
                    $this->fail('Withdrawn MFA was accepted.');
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_audit_failure_rolls_back_message_and_unknown_ack_replay_retains_one_message(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = $this->fixture;
        $service = app(InquiryConversation::class);
        $body = Fixture::message();
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'insert into ') && str_contains($sql, 'audit_events')) {
                $fail = false;
                throw new RuntimeException('synthetic audit failure');
            }
        });
        try {
            $service->reply($inquiry->id, $body, $actor);
            $this->fail('Audit failure was ignored.');
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('inquiry_messages', 0);
        $first = $service->reply($inquiry->id, $body, $actor);
        $this->assertSame($first['messageId'], $service->reply($inquiry->id, $body, $actor)['messageId']);
        $this->assertDatabaseCount('inquiry_messages', 1);
    }

    public function test_disabled_and_full_conversations_keep_history_readable_and_exact_replay_available(): void
    {
        ['inquiry' => $inquiry] = $this->fixture;
        $service = app(InquiryConversation::class);
        $body = Fixture::message();
        $service->followUp($inquiry->public_id, Fixture::OWNER, $body);
        config(['inquiries.enabled' => false]);
        $this->assertFalse($service->owner($inquiry->public_id, Fixture::OWNER)['canReply']);
        $this->assertTrue($service->followUp($inquiry->public_id, Fixture::OWNER, $body)['replayed']);
        $this->refused(fn () => $service->followUp($inquiry->public_id, Fixture::OWNER, Fixture::message()), 409);
        config(['inquiries.enabled' => true]);
        for ($i = 1; $i < 100; $i++) {
            $service->followUp($inquiry->public_id, Fixture::OWNER, Fixture::message());
        }
        $this->assertCount(100, $service->owner($inquiry->public_id, Fixture::OWNER)['messages']);
        $this->assertFalse($service->owner($inquiry->public_id, Fixture::OWNER)['canReply']);
        $this->refused(fn () => $service->followUp($inquiry->public_id, Fixture::OWNER, Fixture::message()), 409);
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_messages_do_not_write(array $changes): void
    {
        $this->refused(fn () => app(InquiryConversation::class)->followUp($this->fixture['inquiry']->public_id, Fixture::OWNER, array_replace(Fixture::message(), $changes)), 422);
        $this->assertDatabaseCount('inquiry_messages', 0);
    }

    public static function invalidBodies(): array
    {
        return ['empty' => [['message' => '']], 'spaces' => [['message' => '   ']], 'large' => [['message' => str_repeat('界', 4001)]],
            'control' => [['message' => "bad\0text"]], 'type' => [['message' => []]], 'key' => [['requestKey' => 'invalid']], 'extra' => [['extra' => true]]];
    }

    private function refused(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid conversation operation was accepted.');
        } catch (InquiryException $error) {
            $this->assertSame($status, $error->status);
        }
    }
}
