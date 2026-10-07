<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\Models\InquiryNotificationIntent;
use App\Domain\Inquiries\Notifications\InquiryAlertTransport;
use App\Domain\Inquiries\Notifications\InquiryNotificationWork;
use App\Domain\Inquiries\Notifications\OperatorInquiryAlert;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

final class InquiryTerminalHandoffCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function changes(): array
    {
        return [['operator'], ['configuration'], ['claim']];
    }

    #[DataProvider('changes')]
    public function test_final_authority_callback_cannot_leave_an_invalid_external_handoff(string $change): void
    {
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        $operator = LicenseFixtures::admin();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC REVIEW NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC REVIEW POLICY', 'inquiries.operator_user_id' => $operator->id,
            'inquiries.operator_notifications_enabled' => false]);
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content(), 'Independent synthetic inquiry fixture', $operator);
        app(SiteContent::class)->publish($release->id, 0, $operator);
        app(SubmitInquiry::class)->handle(['name' => 'Synthetic reviewer', 'email' => 'review@example.test',
            'subject' => 'Synthetic inquiry', 'message' => 'Independent fixture message', 'website' => '',
            'requestKey' => (string) Str::uuid(),
            'noticeToken' => app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current())['noticeToken']], str_repeat('a', 64));
        $intent = InquiryNotificationIntent::sole();
        $sink = new class implements InquiryAlertTransport
        {
            public array $alerts = [];
            public function submit(OperatorInquiryAlert $alert): void { $this->alerts[] = $alert; }
        };
        $this->app->instance(InquiryAlertTransport::class, $sink);
        config(['inquiries.operator_notifications_enabled' => true]);
        $outsideReads = 0;
        $armed = true;
        $fired = false;
        DB::listen(function ($query) use ($change, $operator, $intent, &$outsideReads, &$armed, &$fired): void {
            if (! $armed || DB::transactionLevel() !== 0 || ! preg_match('/^select .* from ["`]users["`]/i', $query->sql)) {
                return;
            }
            if (++$outsideReads !== 3) { return; }
            $armed = false;
            $fired = true;
            if ($change === 'operator') {
                User::whereKey($operator->id)->update(['is_admin' => false]);
            } elseif ($change === 'configuration') {
                config(['inquiries.operator_notifications_enabled' => false]);
            } else {
                DB::table('inquiry_notification_intents')->where('id', $intent->id)->update([
                    'state' => 'unknown', 'outcome' => 'handoff_uncertain', 'claim_token' => null,
                    'lease_expires_at' => null, 'next_attempt_at' => null, 'updated_at' => now(),
                ]);
            }
        });
        try {
            app(InquiryNotificationWork::class)->process($intent->id);
            $this->assertTrue($fired, 'Probe must execute after the final framework authority row is read.');
            $this->assertSame([], $sink->alerts, 'A withdrawn authority/configuration/claim must not cross external I/O.');
        } finally {
            $armed = false;
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }
}
