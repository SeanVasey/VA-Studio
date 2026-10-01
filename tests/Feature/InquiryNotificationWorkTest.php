<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryNotificationIntent;
use App\Domain\Inquiries\Notifications\InquiryAlertNotSubmitted;
use App\Domain\Inquiries\Notifications\InquiryAlertTransport;
use App\Domain\Inquiries\Notifications\InquiryNotificationWork;
use App\Domain\Inquiries\Notifications\OperatorInquiryAlert;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Jobs\NotifyInquiryOperatorJob;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

/** Disposable migrations prove actual committed handoff boundaries, not test-held transactions. */
class InquiryNotificationWorkTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $operator;

    private array $body;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Mail::fake();
        Queue::fake();
        $this->operator = LicenseFixtures::admin();
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC NOTIFICATION NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-TEST-POLICY', 'inquiries.operator_user_id' => $this->operator->id,
            'inquiries.operator_notifications_enabled' => false]);
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content(), 'Synthetic alert fixture', $this->operator);
        app(SiteContent::class)->publish($release->id, 0, $this->operator);
        $this->body = ['name' => 'Private sender', 'email' => 'private-sender@example.test', 'subject' => 'Private subject',
            'message' => 'Private message', 'website' => '', 'requestKey' => (string) Str::uuid()];
    }

    private function save(): InquiryNotificationIntent
    {
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));

        return InquiryNotificationIntent::latest('id')->firstOrFail();
    }

    private function adapter(?callable $effect = null): object
    {
        $adapter = new class($effect) implements InquiryAlertTransport
        {
            public array $alerts = [];

            public function __construct(private $effect)
            {
            }

            public function submit(OperatorInquiryAlert $alert): void
            {
                foreach (DB::getConnections() as $connection) {
                    if ($connection->transactionLevel() !== 0) {
                        throw new LogicException('Adapter received an uncommitted claim.');
                    }
                }
                $this->alerts[] = $alert;
                if ($this->effect !== null) {
                    ($this->effect)();
                }
            }
        };
        $this->app->instance(InquiryAlertTransport::class, $adapter);
        config(['inquiries.operator_notifications_enabled' => true]);

        return $adapter;
    }

    public function test_inquiry_save_atomically_retains_one_minimal_intent_and_exact_replay_never_dispatches_by_default(): void
    {
        $first = app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $second = app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $this->assertSame($first['receipt'], $second['receipt']);
        $this->assertTrue($second['replayed']);
        $intent = InquiryNotificationIntent::sole();
        $inquiry = CustomerInquiry::sole();
        $this->assertSame((int) $inquiry->id, $intent->customer_inquiry_id);
        $this->assertSame((int) $inquiry->operator_user_id, $intent->operator_user_id);
        $this->assertSame('pending', $intent->state);
        $raw = json_encode(DB::table('inquiry_notification_intents')->sole());
        foreach (['Private sender', 'private-sender@example.test', 'Private subject', 'Private message', 'SYNTHETIC NOTIFICATION NOTICE', 'SYNTHETIC-TEST-POLICY'] as $private) {
            $this->assertStringNotContainsString($private, $raw);
        }
        $this->assertSame('disabled', app(InquiryNotificationWork::class)->process($intent->id));
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $original = $inquiry->getRawOriginal();
        $this->adapter();
        $this->body['requestKey'] = (string) Str::uuid();
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        Queue::assertPushed(NotifyInquiryOperatorJob::class, 1);
        Queue::assertPushed(NotifyInquiryOperatorJob::class, function (NotifyInquiryOperatorJob $job): bool {
            $this->assertSame(InquiryNotificationIntent::latest('id')->firstOrFail()->id, $job->intentId);
            foreach (['Private sender', 'private-sender@example.test', 'Private subject', 'Private message', 'SYNTHETIC NOTIFICATION NOTICE', 'SYNTHETIC-TEST-POLICY'] as $private) {
                $this->assertStringNotContainsString($private, serialize($job));
            }

            return true;
        });
        $this->assertSame('submitted', app(InquiryNotificationWork::class)->process($intent->id));
        $this->assertSame($original, $inquiry->fresh()->getRawOriginal());
    }

    public function test_rollback_removes_intent_and_failed_queue_wakeup_does_not_undo_saved_confirmation(): void
    {
        $this->adapter();
        try {
            DB::transaction(function (): void {
                $this->save();
                throw new RuntimeException('Synthetic transaction rollback');
            });
            $this->fail('Synthetic rollback did not happen.');
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->assertDatabaseCount('inquiry_notification_intents', 0);
        Queue::assertNothingPushed();
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Private synthetic queue error'));
        $saved = app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $this->assertSame('saved', $saved['state']);
        $intent = InquiryNotificationIntent::sole();
        $work = app(InquiryNotificationWork::class);
        $this->assertSame([$intent->id], $work->eligible(1));
        $this->assertSame('submitted', $work->process($intent->id));
        $this->assertSame([], $work->eligible(1));
    }

    public function test_duplicate_claims_original_recipient_authority_and_stale_workers_are_fenced_without_external_content(): void
    {
        $intent = $this->save();
        $adapter = $this->adapter();
        $work = app(InquiryNotificationWork::class);
        $claim = $work->claim($intent->id);
        $this->assertNotNull($claim);
        $this->assertNull($work->claim($intent->id));
        $this->travel(InquiryNotificationWork::LEASE_SECONDS)->seconds();
        $this->assertNull($work->claim($intent->id));
        $this->assertSame('unknown', $intent->fresh()->state);
        $this->body['requestKey'] = (string) Str::uuid();
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $intent = InquiryNotificationIntent::latest('id')->firstOrFail();
        $this->assertSame('submitted', $work->process($intent->id));
        $this->assertSame('submitted', $work->process($intent->id));
        $this->assertCount(1, $adapter->alerts);
        $alert = $adapter->alerts[0];
        $this->assertSame($this->operator->id, $alert->operatorId);
        $this->assertSame('/admin/customer-inquiries/'.CustomerInquiry::findOrFail($intent->customer_inquiry_id)->public_id, $alert->path());
        $this->assertSame(['operatorId', 'receipt'], array_keys(get_object_vars($alert)));
        $this->assertSame('handed_off', $intent->fresh()->outcome);
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.notification.submitted')->count());
        $this->assertStringNotContainsString('Private', json_encode($alert));

        $this->body['requestKey'] = (string) Str::uuid();
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $retained = InquiryNotificationIntent::latest('id')->firstOrFail();
        User::whereKey($this->operator->id)->update(['is_admin' => false]);
        config(['inquiries.operator_user_id' => LicenseFixtures::admin()->id]);
        $this->assertSame('blocked', $work->process($retained->id));
        $this->assertSame('blocked', $retained->fresh()->state);
        $this->assertSame($this->operator->id, $retained->fresh()->operator_user_id);
        $this->assertCount(1, $adapter->alerts);
        $this->assertSame([], $work->eligible(100));
    }

    public function test_only_definite_non_submission_retries_while_ambiguous_errors_and_expired_claims_remain_unknown(): void
    {
        $intent = $this->save();
        $adapter = $this->adapter(fn () => throw new InquiryAlertNotSubmitted);
        $work = app(InquiryNotificationWork::class);
        $this->assertSame('retry', $work->process($intent->id));
        $this->assertSame([], $work->eligible(100));
        $this->travel(30)->seconds();
        $this->assertSame('retry', $work->process($intent->id));
        $this->travel(60)->seconds();
        $this->assertSame('blocked', $work->process($intent->id));
        $this->assertSame('retry_exhausted', $intent->fresh()->outcome);
        $this->assertCount(3, $adapter->alerts);

        $this->body['requestKey'] = (string) Str::uuid();
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $ambiguous = InquiryNotificationIntent::latest('id')->firstOrFail();
        $uncertainSink = $this->adapter(fn () => throw new RuntimeException('Private provider output'));
        $this->assertSame('unknown', $work->process($ambiguous->id));
        $this->assertSame('handoff_uncertain', $ambiguous->fresh()->outcome);
        $this->assertSame('unknown', $work->process($ambiguous->id));
        $this->assertCount(1, $uncertainSink->alerts);

        $this->body['requestKey'] = (string) Str::uuid();
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $expired = InquiryNotificationIntent::latest('id')->firstOrFail();
        $sink = $this->adapter();
        $claim = $work->claim($expired->id);
        $this->travel(InquiryNotificationWork::LEASE_SECONDS)->seconds();
        $this->assertSame([$expired->id], $work->eligible(100));
        $this->assertNull($work->claim($expired->id));
        $this->assertSame('unknown', $expired->fresh()->state);
        $this->assertSame('lease_expired', $expired->fresh()->outcome);
        $this->assertSame('unknown', $work->process($expired->id));
        $this->assertSame([], $work->eligible(100));
        $this->assertSame([], $sink->alerts);
        $this->body['requestKey'] = (string) Str::uuid();
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $inFlight = InquiryNotificationIntent::latest('id')->firstOrFail();
        $late = $this->adapter(function () use ($work, $inFlight): void {
            $this->travel(InquiryNotificationWork::LEASE_SECONDS)->seconds();
            $this->assertNull($work->claim($inFlight->id));
        });
        $this->assertSame('stale', $work->process($inFlight->id));
        $this->assertSame('unknown', $inFlight->fresh()->state);
        $this->assertSame('lease_expired', $inFlight->fresh()->outcome);
        $this->assertSame('unknown', $work->process($inFlight->id));
        $this->assertCount(1, $late->alerts);
        foreach (AuditEvent::where('action', 'like', 'inquiry.notification.%')->get() as $audit) {
            $this->assertStringNotContainsString('Private provider output', json_encode($audit->context));
        }
    }

    public function test_verification_mfa_and_configuration_withdrawals_hold_original_claims_without_retargeting(): void
    {
        $sink = $this->adapter();
        $work = app(InquiryNotificationWork::class);
        $handoff = new ReflectionMethod($work, 'handoff'); // Private worker step; no replay entry point exists in production.
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        try {
            foreach (['verification', 'mfa', 'configuration', 'tampered_claim'] as $withdrawal) {
                $this->operator->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
                $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
                config(['inquiries.operator_user_id' => $this->operator->id, 'inquiries.operator_notifications_enabled' => true]);
                if ($withdrawal === 'mfa') {
                    $this->operator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
                    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
                }
                $this->body['requestKey'] = (string) Str::uuid();
                $intent = $this->save();
                $claim = $work->claim($intent->id);
                $this->assertNotNull($claim);
                if ($withdrawal === 'verification') {
                    User::whereKey($this->operator->id)->update(['email_verified_at' => null]);
                } elseif ($withdrawal === 'mfa') {
                    User::findOrFail($this->operator->id)->saveAppAuthenticationSecret(null);
                } elseif ($withdrawal === 'configuration') {
                    config(['inquiries.operator_notifications_enabled' => false]);
                } else {
                    $replacement = LicenseFixtures::admin();
                    User::whereKey($this->operator->id)->update(['is_admin' => false]);
                    $claim->operator_user_id = $replacement->id;
                    $claim->customer_inquiry_id = 0;
                    $claim->attempts = 0;
                }
                $this->assertSame('stale', $handoff->invoke($work, $claim));
                $this->assertSame('blocked', $intent->fresh()->state);
                $this->assertSame($withdrawal === 'configuration' ? 'configuration_withdrawn' : 'authority_withdrawn', $intent->fresh()->outcome);
                $this->assertSame($this->operator->id, $intent->fresh()->operator_user_id);
                $this->assertSame([], $sink->alerts);
            }
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_unbound_transport_and_outer_transaction_cannot_start_a_handoff_or_leak_private_queue_payload(): void
    {
        $intent = $this->save();
        config(['inquiries.operator_notifications_enabled' => true]);
        $work = app(InquiryNotificationWork::class);
        $this->assertSame('disabled', $work->process($intent->id));
        $sink = $this->adapter();
        DB::beginTransaction();
        try {
            $work->process($intent->id);
            $this->fail('Uncommitted handoff was accepted.');
        } catch (LogicException) {
            $this->assertSame([], $sink->alerts);
        } finally {
            DB::rollBack();
        }
        config(['database.connections.inquiry_secondary' => config('database.connections.'.config('database.default'))]);
        $secondary = DB::connection('inquiry_secondary');
        $secondary->beginTransaction();
        try {
            $work->process($intent->id);
            $this->fail('A secondary transaction allowed a handoff.');
        } catch (LogicException) {
            $this->assertSame([], $sink->alerts);
        } finally {
            $secondary->rollBack();
            DB::purge('inquiry_secondary');
        }
        $job = new NotifyInquiryOperatorJob($intent->id);
        $this->assertSame('inquiry-alerts', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertSame(1, $job->tries);
        $this->assertStringNotContainsString('private-sender@example.test', serialize($job));
        $this->assertStringNotContainsString('Private message', serialize($job));
        $this->artisan('vasey:process-inquiry-alerts', ['--limit' => '0'])->assertFailed();
        $this->assertSame('pending', $intent->fresh()->state);
    }
}
