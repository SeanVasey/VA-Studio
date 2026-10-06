<?php

namespace Tests\Support;

use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use Illuminate\Support\Str;

final class InquiryConversationFixtures
{
    public const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function create(?string $owner = null): array
    {
        $actor = LicenseFixtures::admin();
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC PRIVATE CONVERSATION NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-CONVERSATION-RETENTION', 'inquiries.operator_user_id' => $actor->id]);
        $site = app(SiteContent::class);
        $release = $site->create(SiteEditorialFixtures::content(), 'Synthetic conversation fixture', $actor);
        $site->publish($release->id, 0, $actor);
        $body = ['name' => 'Synthetic Sender', 'email' => 'synthetic-sender@example.test', 'subject' => 'Synthetic private conversation',
            'message' => "Synthetic initial message.\nOriginal text.", 'website' => '', 'requestKey' => (string) Str::uuid(),
            'noticeToken' => app(InquiryPolicy::class)->publicSetup($site->current())['noticeToken']];
        app(SubmitInquiry::class)->handle($body, $owner ?? self::OWNER);

        return ['actor' => $actor, 'inquiry' => CustomerInquiry::sole(), 'body' => $body];
    }

    public static function message(string $text = "Synthetic reply.\nSecond line."): array
    {
        return ['message' => $text, 'requestKey' => (string) Str::uuid()];
    }
}
