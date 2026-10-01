<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryNotificationIntent;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryNotificationRace;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

/** Observed InnoDB intent-row contention between independent processes and connections. */
class InquiryNotificationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent inquiry notification claim locking requires MySQL, not SQLite.');
        }
    }

    public function test_independent_workers_serialize_one_handoff_without_duplicate_attempt_or_submission(): void
    {
        $operator = LicenseFixtures::admin();
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC NOTIFICATION RACE NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-RACE-POLICY', 'inquiries.operator_user_id' => $operator->id]);
        $site = app(SiteContent::class);
        $release = $site->create(SiteEditorialFixtures::content(), 'Synthetic notification race', $operator);
        $site->publish($release->id, 0, $operator);
        app(SubmitInquiry::class)->handle(['name' => 'Synthetic sender', 'email' => 'sender@example.test', 'subject' => 'Synthetic subject',
            'message' => 'Private synthetic race message', 'website' => '', 'requestKey' => (string) Str::uuid(),
            'noticeToken' => app(InquiryPolicy::class)->publicSetup($site->current())['noticeToken']], hash('sha256', 'synthetic-race-owner'));
        $intent = InquiryNotificationIntent::sole();
        $race = InquiryNotificationRace::run($this, [['intent_id' => $intent->id], ['intent_id' => $intent->id]]);
        $this->assertSame('submitted', $race['results'][$race['winner']]['state']);
        $this->assertContains($race['results'][1 - $race['winner']]['state'], ['processing', 'submitted']);
        $this->assertSame([['operator_id' => $operator->id, 'receipt' => CustomerInquiry::sole()->public_id]], $race['handoffs']);
        $current = $intent->fresh();
        $this->assertSame('submitted', $current->state);
        $this->assertSame('handed_off', $current->outcome);
        $this->assertSame(1, $current->attempts);
        $this->assertNull($current->claim_token);
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.notification.submitted')->count());
        $this->assertSame($operator->id, $current->operator_user_id);
        $this->assertDatabaseCount('inquiry_notification_intents', 1);
    }
}
