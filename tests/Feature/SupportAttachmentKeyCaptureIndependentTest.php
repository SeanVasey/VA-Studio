<?php

namespace Tests\Feature;

use App\Domain\SupportAttachments\SupportAttachments;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures;
use Tests\TestCase;

/** Actor ownership derives from app.key as well as the captured session marker. */
class SupportAttachmentKeyCaptureIndependentTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_service_resolution_key_rotation_cannot_adopt_a_previously_minted_visitor_actor(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32)), 'support-attachments.fixture_enabled' => true]);
        $secret = str_repeat('b', 64);
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, (string) config('app.key'));
        $fixture = InquiryConversationFixtures::create($owner);
        $callbacks = 0;
        $this->app->afterResolving(SupportAttachments::class, function () use (&$callbacks): void {
            $callbacks++;
            config(['app.key' => 'base64:'.base64_encode(str_repeat('X', 32))]);
        });
        $response = $this->get('/private-support/inquiries/'.$fixture['inquiry']->public_id.'/attachments', ['Accept' => 'application/json']);
        $this->assertSame(1, $callbacks);
        $this->assertSame(str_repeat('b', 64), session()->get('_inquiry_owner.secret'));
        $this->assertNotSame($owner, hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, (string) config('app.key')));
        $this->assertDatabaseCount('support_attachments', 0);
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }
}
