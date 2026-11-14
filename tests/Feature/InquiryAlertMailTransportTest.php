<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryNotificationIntent;
use App\Domain\Inquiries\Notifications\InquiryAlertMail;
use App\Domain\Inquiries\Notifications\InquiryAlertNotSubmitted;
use App\Domain\Inquiries\Notifications\InquiryAlertTransport;
use App\Domain\Inquiries\Notifications\InquiryNotificationWork;
use App\Domain\Inquiries\Notifications\MailInquiryAlertTransport;
use App\Domain\Inquiries\Notifications\OperatorInquiryAlert;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Jobs\NotifyInquiryOperatorJob;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\TransactionalMailFixtures as M;
use Tests\TestCase;

/** FP-013: default-off operator alert mail for the inquiry intent outbox; fake transport only; one send per intent; no PII. */
class InquiryAlertMailTransportTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const PRIVATE = ['Private sender', 'private-sender@example.test', 'Service request', 'Private message',
        'SYNTHETIC NOTIFICATION NOTICE', 'SYNTHETIC-TEST-POLICY'];

    private User $operator;

    private array $body;

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Mail::fake();
        Queue::fake();
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logs[] = $event->message.' '.json_encode($event->context));
        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob(storage_path('framework/testing/mail-preflight-*'), GLOB_ONLYDIR) ?: [] as $directory) {
                (new Filesystem)->deleteDirectory($directory);
            }
        });
        $this->operator = LicenseFixtures::admin();
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC NOTIFICATION NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-TEST-POLICY', 'inquiries.operator_user_id' => $this->operator->id]);
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content(), 'Synthetic alert fixture', $this->operator);
        app(SiteContent::class)->publish($release->id, 0, $this->operator);
        $this->body = ['name' => 'Private sender', 'email' => 'private-sender@example.test', 'subject' => 'Service request',
            'message' => 'Private message', 'website' => '', 'requestKey' => (string) Str::uuid(),
            'noticeToken' => app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current())['noticeToken']];
    }

    private function save(): InquiryNotificationIntent
    {
        $this->body['requestKey'] = (string) Str::uuid();
        app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));

        return InquiryNotificationIntent::latest('id')->firstOrFail();
    }

    /** Sets the flag the way a host would and lets the real provider decide the binding. */
    private function enable(bool $mail = true): void
    {
        config(['inquiries.operator_notifications_enabled' => true]);
        (new AppServiceProvider($this->app))->register();
        if ($mail) {
            M::configure();
        }
    }

    private function runJob(InquiryNotificationIntent $intent): void
    {
        (new NotifyInquiryOperatorJob($intent->id))->handle(app(InquiryNotificationWork::class));
    }

    private function assertNoPrivateData(string $text): void
    {
        foreach ([...self::PRIVATE, $this->body['noticeToken'], $this->operator->email, $this->operator->name] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
    }

    public function test_the_shipped_default_binds_nothing_and_sends_nothing(): void
    {
        $this->assertFalse(config('inquiries.operator_notifications_enabled'));
        $this->assertFalse($this->app->bound(InquiryAlertTransport::class));
        // Mail being fully configured for order-ready email does not bind or send an operator alert.
        M::configure();
        (new AppServiceProvider($this->app))->register();
        $this->assertFalse(MailInquiryAlertTransport::bindWhenConfigured($this->app));
        $this->assertFalse($this->app->bound(InquiryAlertTransport::class));

        $intent = $this->save();
        Queue::assertNothingPushed();
        $work = app(InquiryNotificationWork::class);
        $this->assertFalse($work->enabled());
        $this->assertSame('disabled', $work->process($intent->id));
        $this->runJob($intent);
        $this->artisan('vasey:process-inquiry-alerts')->assertFailed();
        $this->assertSame(['pending', 0], [$intent->fresh()->state, $intent->fresh()->attempts]);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'inquiry.notification.%')->count());
        Mail::assertNothingOutgoing();
    }

    public static function unboundConfiguration(): array
    {
        return [
            'string flag' => ['true', 'operator'],
            'integer flag' => [1, 'operator'],
            'null flag' => [null, 'operator'],
            'no operator' => [true, null],
            'blank operator' => [true, ''],
            'zero operator' => [true, 0],
            'non-numeric operator' => [true, 'support@vasey.audio'],
            'array operator' => [true, [1]],
        ];
    }

    #[DataProvider('unboundConfiguration')]
    public function test_only_the_literal_flag_with_a_configured_operator_binds_the_transport(mixed $flag, mixed $operator): void
    {
        M::configure();
        config(['inquiries.operator_notifications_enabled' => $flag,
            'inquiries.operator_user_id' => $operator === 'operator' ? $this->operator->id : $operator]);
        (new AppServiceProvider($this->app))->register();
        $this->assertFalse(MailInquiryAlertTransport::bindWhenConfigured($this->app));
        $this->assertFalse($this->app->bound(InquiryAlertTransport::class));
        $this->assertFalse(app(InquiryNotificationWork::class)->enabled());
        Mail::assertNothingOutgoing();
    }

    public function test_one_plain_text_alert_reaches_the_operator_and_carries_only_the_inquiry_reference(): void
    {
        $this->enable();
        $this->assertTrue($this->app->bound(InquiryAlertTransport::class));
        $this->assertInstanceOf(MailInquiryAlertTransport::class, app(InquiryAlertTransport::class));
        $intent = $this->save();
        $receipt = CustomerInquiry::findOrFail($intent->customer_inquiry_id)->public_id;
        Queue::assertPushed(NotifyInquiryOperatorJob::class, 1);
        Mail::assertNothingOutgoing();

        $this->runJob($intent);
        $this->assertSame(['submitted', 'handed_off', 1], [$intent->fresh()->state, $intent->fresh()->outcome, $intent->fresh()->attempts]);
        Mail::assertSentCount(1);
        Mail::assertNothingQueued();
        $sent = null;
        Mail::assertSent(InquiryAlertMail::class, function (InquiryAlertMail $mail) use ($receipt, &$sent): bool {
            $sent = $mail;

            return $mail->hasTo($this->operator->email) && count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === []
                && $mail->hasFrom(M::SENDER, 'VASEY.AUDIO') && $mail->mailer === M::MAILER && $mail->inquiryReference === $receipt
                && $mail->hasSubject('A new inquiry is saved in your private inbox');
        });

        // Plain text only, and nothing but the opaque inquiry reference identifies anything: no content, name, address or link.
        $this->assertNull($sent->content()->view);
        $this->assertNull($sent->content()->html);
        $this->assertNull($sent->content()->markdown);
        $this->assertSame([[], [], []], [$sent->attachments, $sent->rawAttachments, $sent->diskAttachments]);
        $text = $sent->render();
        $this->assertStringContainsString('Inquiry reference: '.$receipt, $text);
        $this->assertSame(1, substr_count($text, $receipt));
        $this->assertSame(['X-Vasey-Inquiry-Alert' => $receipt], $sent->headers()->text);
        foreach ([$text, $sent->envelope()->subject, json_encode($sent->headers()->text)] as $part) {
            $this->assertNoPrivateData($part);
            foreach (['@', 'http', '://', '/admin', 'token'] as $absent) {
                $this->assertStringNotContainsStringIgnoringCase($absent, $part);
            }
        }
        // Every UUID-shaped value in the body is the receipt itself.
        preg_match_all('/[0-9a-f]{8}-[0-9a-f-]{27}/', $text, $ids);
        $this->assertSame([$receipt], $ids[0]);
        $this->assertNoPrivateData(implode("\n", $this->logs));
        $this->assertNoPrivateData(AuditEvent::where('action', 'like', 'inquiry.notification.%')->get()->toJson());
    }

    public function test_a_duplicate_wakeup_replay_or_scan_never_sends_a_second_alert_and_states_only_move_forward(): void
    {
        $this->enable();
        $intent = $this->save();
        $work = app(InquiryNotificationWork::class);
        $this->assertSame('submitted', $work->process($intent->id));
        Mail::assertSentCount(1);

        // The same intent again: direct processing, duplicated jobs, the bounded scan and an exact replay of the submission.
        $this->assertSame('submitted', $work->process($intent->id));
        $this->runJob($intent);
        $this->runJob($intent);
        $this->assertNull($work->claim($intent->id));
        $this->artisan('vasey:process-inquiry-alerts', ['--limit' => '100'])->assertSuccessful();
        $replay = app(SubmitInquiry::class)->handle($this->body, str_repeat('a', 64));
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, InquiryNotificationIntent::count());
        $this->assertSame([], $work->eligible(100));
        $this->assertSame(1, $intent->fresh()->attempts);
        Mail::assertSentCount(1);
        $this->assertSame(['inquiry.notification.submitted'],
            AuditEvent::where('action', 'like', 'inquiry.notification.%')->orderBy('id')->pluck('action')->all());

        // The database refuses a second intent for the inquiry and any step back from the terminal state.
        $row = (array) DB::table('inquiry_notification_intents')->where('id', $intent->id)->sole();
        foreach ([fn () => DB::table('inquiry_notification_intents')->insert(['id' => null, 'state' => 'pending', 'attempts' => 0, 'outcome' => null] + $row),
            fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(['state' => 'pending', 'attempts' => 0, 'outcome' => null]),
            fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(['state' => 'retry', 'outcome' => 'definitely_not_submitted',
                'next_attempt_at' => now()->addMinute(), 'updated_at' => now()]),
            fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->delete()] as $backward) {
            try {
                $backward();
                $this->fail('A submitted alert intent moved backward.');
            } catch (QueryException) {
                $this->assertSame($row, (array) DB::table('inquiry_notification_intents')->where('id', $intent->id)->sole());
            }
        }
        Mail::assertSentCount(1);
    }

    public function test_a_refusal_before_the_transport_retries_and_sends_once_when_mail_is_configured(): void
    {
        // Flag on and bound, but the reviewed mail configuration is absent: nothing can have been handed off.
        $this->enable(mail: false);
        $intent = $this->save();
        $work = app(InquiryNotificationWork::class);
        $this->assertSame('retry', $work->process($intent->id));
        $this->assertSame(['retry', 'definitely_not_submitted', 1], [$intent->fresh()->state, $intent->fresh()->outcome, $intent->fresh()->attempts]);
        $this->assertSame('retry', $work->process($intent->id), 'The retry is not due yet.');
        $this->assertSame(1, $intent->fresh()->attempts);
        Mail::assertNothingOutgoing();

        M::configure();
        $this->travel(30)->seconds();
        $this->artisan('vasey:process-inquiry-alerts')->expectsOutput($intent->id.' submitted')->assertSuccessful();
        $this->assertSame(['submitted', 'handed_off', 2], [$intent->fresh()->state, $intent->fresh()->outcome, $intent->fresh()->attempts]);
        Mail::assertSentCount(1);
        Mail::assertSent(InquiryAlertMail::class, fn (InquiryAlertMail $mail): bool => $mail->hasTo($this->operator->email));
        $this->artisan('vasey:process-inquiry-alerts')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertStringContainsString('mail_not_configured', implode("\n", $this->logs));
        $this->assertNoPrivateData(implode("\n", $this->logs));
    }

    public static function refusalBeforeTransport(): array
    {
        return [
            'mail disabled' => [fn () => config(['transactional-mail.enabled' => false])],
            'log mailer' => [fn () => config(['transactional-mail.mailer' => 'log'])],
            'sender differs from facts' => [fn () => config(['transactional-mail.sender' => 'alerts@example.com'])],
            'unfaked mailer in testing' => [fn () => Mail::swap(new MailManager(app()))],
            'global recipient override' => [fn () => config(['mail.to' => ['address' => 'redirect@example.com', 'name' => 'Redirect']])],
            'malformed global recipient override' => [fn () => config(['mail.to' => 'redirect@example.com'])],
            'flag withdrawn inside the transport' => [fn () => null, true],
        ];
    }

    #[DataProvider('refusalBeforeTransport')]
    public function test_each_refusal_before_the_transport_is_reported_as_not_submitted_and_exhausts_without_mail(callable $break, bool $direct = false): void
    {
        $this->enable();
        $intent = $this->save();
        $receipt = CustomerInquiry::findOrFail($intent->customer_inquiry_id)->public_id;
        $break();
        if ($direct) {
            // The outbox refuses a withdrawn flag itself; the transport refuses again if it is ever called directly.
            $transport = app(InquiryAlertTransport::class);
            config(['inquiries.operator_notifications_enabled' => false]);
            foreach ([$receipt, 'not-a-receipt private-sender@example.test', strtoupper($receipt)] as $index => $reference) {
                config(['inquiries.operator_notifications_enabled' => $index !== 0]);
                try {
                    $transport->submit(new OperatorInquiryAlert($this->operator->id, $reference));
                    $this->fail('The transport accepted a refused alert.');
                } catch (InquiryAlertNotSubmitted) {
                    Mail::assertNothingOutgoing();
                }
            }
            $this->assertSame('pending', $intent->fresh()->state);
            // Only a value that is a receipt is ever logged as the inquiry reference.
            $this->assertCount(3, $this->logs);
            $this->assertStringContainsString($receipt, $this->logs[0]);
            foreach ([1, 2] as $refused) {
                $this->assertStringNotContainsString('inquiry', strtolower(str_replace('Inquiry alert was not submitted.', '', $this->logs[$refused])));
                $this->assertStringNotContainsStringIgnoringCase('not-a-receipt', $this->logs[$refused]);
                $this->assertStringNotContainsStringIgnoringCase($receipt, $this->logs[$refused]);
            }
            $this->assertNoPrivateData(implode("\n", $this->logs));

            return;
        }
        $work = app(InquiryNotificationWork::class);
        $this->assertSame('retry', $work->process($intent->id));
        $this->travel(30)->seconds();
        $this->assertSame('retry', $work->process($intent->id));
        $this->travel(60)->seconds();
        $this->assertSame('blocked', $work->process($intent->id));
        $this->assertSame(['blocked', 'retry_exhausted', 3], [$intent->fresh()->state, $intent->fresh()->outcome, $intent->fresh()->attempts]);
        $this->travel(1)->days();
        $this->assertSame('blocked', $work->process($intent->id));
        $this->assertSame([], $work->eligible(100));
        if (Mail::getFacadeRoot() instanceof MailFake) {
            Mail::assertNothingOutgoing();
        }
    }

    public function test_an_operator_without_a_usable_verified_address_gets_no_alert_and_no_other_address_is_used(): void
    {
        $this->enable();
        $transport = app(InquiryAlertTransport::class);
        $receipt = (string) Str::uuid();
        $unverified = User::factory()->unverified()->create();
        $malformed = User::factory()->create(['email' => 'Operator <operator@example.com>']);
        foreach ([$unverified->id, $malformed->id, 999999] as $operatorId) {
            try {
                $transport->submit(new OperatorInquiryAlert($operatorId, $receipt));
                $this->fail('An alert was submitted without a verified operator address.');
            } catch (InquiryAlertNotSubmitted) {
                Mail::assertNothingOutgoing();
            }
        }
        $this->assertStringContainsString('operator_address_missing', implode("\n", $this->logs));
        $this->assertStringNotContainsString($unverified->email, implode("\n", $this->logs));

        // The address is read when the alert is sent: it is the operator account's own current address, nothing configured elsewhere.
        config(['mail.from.address' => 'fallback@example.com', 'mail.reply_to' => ['address' => 'fallback@example.com']]);
        $transport->submit(new OperatorInquiryAlert($this->operator->id, $receipt));
        Mail::assertSentCount(1);
        Mail::assertSent(InquiryAlertMail::class, fn (InquiryAlertMail $mail): bool => $mail->hasTo($this->operator->email)
            && ! $mail->hasTo('fallback@example.com') && $mail->hasFrom(M::SENDER));
    }

    public function test_a_transport_error_leaves_the_intent_unknown_and_is_never_resent(): void
    {
        $this->enable();
        $fake = new class(new MailManager(app())) extends MailFake
        {
            public int $attempts = 0;

            public string $recipient = '';

            protected function sendMail($view, $shouldQueue = false)
            {
                $this->attempts++;
                throw new RuntimeException('Synthetic SMTP failure for '.$this->recipient);
            }
        };
        $fake->recipient = $this->operator->email;
        Mail::swap($fake);
        $intent = $this->save();
        $work = app(InquiryNotificationWork::class);
        $this->assertSame('unknown', $work->process($intent->id));
        $this->assertSame(['unknown', 'handoff_uncertain', 1], [$intent->fresh()->state, $intent->fresh()->outcome, $intent->fresh()->attempts]);

        $this->assertSame('unknown', $work->process($intent->id));
        $this->runJob($intent);
        $this->travel(1)->days();
        $this->artisan('vasey:process-inquiry-alerts', ['--limit' => '100'])->assertSuccessful();
        $this->assertSame([], $work->eligible(100));
        $this->assertSame(1, $fake->attempts);
        $this->assertSame(1, $intent->fresh()->attempts);
        $this->assertStringContainsString('Inquiry alert outcome is uncertain.', implode("\n", $this->logs));
        $this->assertStringContainsString('RuntimeException', implode("\n", $this->logs));
        $this->assertNoPrivateData(implode("\n", $this->logs));
        $this->assertNoPrivateData(AuditEvent::where('action', 'like', 'inquiry.notification.%')->get()->toJson());
    }

    public function test_the_stuck_listing_reports_stale_claims_and_unknown_outcomes_and_writes_nothing(): void
    {
        $this->enable();
        $work = app(InquiryNotificationWork::class);
        $sent = $this->save();
        $this->assertSame('submitted', $work->process($sent->id));
        $this->artisan('vasey:inquiry-alerts-stuck')->expectsOutput('stuck=0 unknown=0 stale=0 minutes=15')->assertSuccessful();

        // One worker claimed an intent and stopped; a second claim is younger than the threshold.
        $stale = $this->save();
        $this->assertNotNull($work->claim($stale->id));
        $claimedAt = now()->format('Y-m-d H:i:s');
        $this->travel(20)->minutes();
        $recent = $this->save();
        $this->assertNotNull($work->claim($recent->id));
        $this->travel(5)->minutes();
        $receipt = fn (InquiryNotificationIntent $intent): string => CustomerInquiry::findOrFail($intent->customer_inquiry_id)->public_id;
        $staleLine = 'intent='.$stale->id.' inquiry='.$receipt($stale).' state=processing outcome=none attempts=1 updated_at='.$claimedAt;
        $before = [DB::table('inquiry_notification_intents')->orderBy('id')->get()->toJson(), AuditEvent::count()];

        $this->artisan('vasey:inquiry-alerts-stuck')->expectsOutput('stuck=1 unknown=0 stale=0 minutes=15')->expectsOutput($staleLine)
            ->doesntExpectOutputToContain('intent='.$recent->id.' ')->doesntExpectOutputToContain('intent='.$sent->id.' ')->assertSuccessful();
        $this->artisan('vasey:inquiry-alerts-stuck', ['--minutes' => 2])->expectsOutput('stuck=2 unknown=0 stale=0 minutes=2')
            ->expectsOutput($staleLine)->expectsOutputToContain('intent='.$recent->id.' inquiry='.$receipt($recent))->assertSuccessful();
        $this->artisan('vasey:inquiry-alerts-stuck', ['--minutes' => 2, '--max' => 1])->expectsOutput('stuck=2 unknown=0 stale=0 minutes=2')
            ->expectsOutputToContain('intent='.$recent->id.' ')->doesntExpectOutput($staleLine)->expectsOutput('more=1')->assertSuccessful();
        foreach (['0', '10081', 'x'] as $invalid) {
            $this->artisan('vasey:inquiry-alerts-stuck', ['--minutes' => $invalid])->assertExitCode(2);
            $this->artisan('vasey:inquiry-alerts-stuck', ['--max' => $invalid === '10081' ? '101' : $invalid])->assertExitCode(2);
        }
        $this->assertSame($before, [DB::table('inquiry_notification_intents')->orderBy('id')->get()->toJson(), AuditEvent::count()], 'The listing is read-only.');

        // The scan settles both expired claims as unknown; they stay listed and are never resent.
        $this->artisan('vasey:process-inquiry-alerts')->assertSuccessful();
        $this->artisan('vasey:inquiry-alerts-stuck')->expectsOutput('stuck=0 unknown=2 stale=0 minutes=15')
            ->expectsOutputToContain('intent='.$stale->id.' inquiry='.$receipt($stale).' state=unknown outcome=lease_expired attempts=1')
            ->doesntExpectOutputToContain('intent='.$sent->id.' ')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    /** The disposable database teardown must run as `testing` again, so every test that changes the environment restores it. */
    private function in(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
    }

    protected function tearDown(): void
    {
        $this->app?->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    public static function environments(): array
    {
        return ['local' => ['local', true], 'testing' => ['testing', true], 'staging' => ['staging', true], 'production' => ['production', false],
            'preview' => ['preview', false], 'Staging' => ['Staging', false], 'production-eu' => ['production-eu', false]];
    }

    #[DataProvider('environments')]
    public function test_production_and_unknown_environments_bind_nothing_and_send_nothing_with_every_flag_set(string $environment, bool $admitted): void
    {
        $this->assertFalse(config('inquiries.operator_alert_mail_production_admitted'));
        // A code literal, never an environment read.
        $this->assertMatchesRegularExpression("/^    'operator_alert_mail_production_admitted' => false,$/m", file_get_contents(config_path('inquiries.php')));
        $intent = $this->save();
        $receipt = CustomerInquiry::findOrFail($intent->customer_inquiry_id)->public_id;
        M::configure();
        config(['inquiries.operator_notifications_enabled' => true]);
        $this->in($environment);
        $this->assertSame($admitted, MailInquiryAlertTransport::admitted());
        (new AppServiceProvider($this->app))->register();
        $this->assertSame($admitted, MailInquiryAlertTransport::bindWhenConfigured($this->app));
        $this->assertSame($admitted, $this->app->bound(InquiryAlertTransport::class));
        $work = app(InquiryNotificationWork::class);
        $this->assertSame($admitted, $work->enabled());
        if ($admitted) {
            // Staging requires staff MFA, which this synthetic operator does not have; the handoff itself is proven where it does not.
            if ($environment !== 'staging') {
                $this->assertSame('submitted', $work->process($intent->id));
                Mail::assertSentCount(1);
            }

            return;
        }
        $this->assertSame('disabled', $work->process($intent->id));
        $this->runJob($intent);
        $this->artisan('vasey:process-inquiry-alerts')->assertFailed();
        $this->assertSame(['pending', 0], [$intent->fresh()->state, $intent->fresh()->attempts]);
        // Even a transport constructed or bound by other code refuses before any mail call.
        try {
            (new MailInquiryAlertTransport)->submit(new OperatorInquiryAlert($this->operator->id, $receipt));
            $this->fail('The transport sent in an environment that is not admitted.');
        } catch (InquiryAlertNotSubmitted) {
            Mail::assertNothingOutgoing();
        }
        $this->assertStringContainsString('alert_not_admitted', implode("\n", $this->logs));

        // Only the literal true, in production itself, lifts the refusal; a truthy value or another environment never does.
        foreach (['true', 1, 'yes'] as $truthy) {
            config(['inquiries.operator_alert_mail_production_admitted' => $truthy]);
            $this->assertFalse(MailInquiryAlertTransport::bindWhenConfigured($this->app));
        }
        config(['inquiries.operator_alert_mail_production_admitted' => true]);
        $this->assertSame($environment === 'production', MailInquiryAlertTransport::bindWhenConfigured($this->app));
        Mail::assertNothingOutgoing();
    }

    public function test_the_alert_uses_its_own_mailer_with_a_bounded_timeout_and_never_touches_the_shared_mailer(): void
    {
        // The policy admits a real mailer only outside local and testing, so this runs as production with the literal gate set.
        // Each SMTP transport the manager creates records its sends instead of opening a connection, with the timeout its
        // configuration asked for, which is what Laravel's own SMTP factory applies to the stream.
        $log = new \ArrayObject;
        $factory = function (array $config) use ($log): EsmtpTransport {
            $transport = new class($config['host'], (int) $config['port']) extends EsmtpTransport
            {
                public ?\ArrayObject $log = null;

                protected function doSend(SentMessage $message): void
                {
                    $this->log[] = ['transport' => spl_object_id($this), 'timeout' => $this->getStream()->getTimeout(),
                        'message' => $message->getOriginalMessage(), 'envelope' => $message->getEnvelope()];
                }
            };
            $transport->log = $log;
            $transport->getStream()->setTimeout((float) ($config['timeout'] ?? 60));

            return $transport;
        };
        $receipt = (string) Str::uuid();
        $this->enable();
        // Production transactional mail must be implicit TLS on 465 (BL-47, DD-43), so the synthetic facts declare exactly that.
        M::configure(['port' => 465, 'security' => 'implicit_tls'], ['timeout' => 60, 'port' => 465, 'scheme' => 'smtps']);
        config(['inquiries.operator_alert_mail_production_admitted' => true]);
        $this->in('production');
        $manager = new MailManager($this->app);
        $manager->extend('smtp', $factory);
        Mail::swap($manager);
        $transport = app(InquiryAlertTransport::class);

        // Order-ready mail already holds the shared mailer and its transport (scenario 1); an alert sends; order-ready mail
        // sends again on the same worker (scenario 2). The shared transport is the same object throughout, at its own timeout.
        $shared = $manager->mailer(M::MAILER);
        $sharedTransport = $shared->getSymfonyTransport();
        $shared->raw('synthetic order-ready', fn ($message) => $message->to('buyer@example.com')->from(M::SENDER));
        $transport->submit(new OperatorInquiryAlert($this->operator->id, $receipt));
        $shared->raw('synthetic order-ready', fn ($message) => $message->to('buyer@example.com')->from(M::SENDER));
        $this->assertSame($sharedTransport, $manager->mailer(M::MAILER)->getSymfonyTransport());
        $this->assertSame(60.0, $sharedTransport->getStream()->getTimeout());
        $this->assertCount(3, $log);
        [$before, $sent, $after] = $log->getArrayCopy();
        $this->assertSame([spl_object_id($sharedTransport), 60.0], [$before['transport'], $before['timeout']]);
        $this->assertSame([spl_object_id($sharedTransport), 60.0], [$after['transport'], $after['timeout']]);
        $this->assertNotSame(spl_object_id($sharedTransport), $sent['transport'], 'The alert never uses the shared transport.');
        $this->assertSame((float) MailInquiryAlertTransport::TRANSPORT_TIMEOUT_SECONDS, $sent['timeout']);
        $this->assertLessThan((new NotifyInquiryOperatorJob(1))->timeout, MailInquiryAlertTransport::TRANSPORT_TIMEOUT_SECONDS);
        $this->assertSame([$this->operator->email], array_map(fn ($address) => $address->getAddress(), $sent['envelope']->getRecipients()));
        $this->assertSame([$this->operator->email], array_map(fn ($address) => $address->getAddress(), $sent['message']->getTo()));
        $this->assertSame([[], [], []], [$sent['message']->getCc(), $sent['message']->getBcc(), $sent['message']->getReplyTo()]);
        $this->assertSame([M::SENDER], array_map(fn ($address) => $address->getAddress(), $sent['message']->getFrom()));
        $this->assertNull($sent['message']->getHtmlBody());
        $this->assertSame([], $sent['message']->getAttachments());
        $this->assertStringContainsString('Inquiry reference: '.$receipt, $sent['message']->getTextBody());
        $this->assertSame($receipt, $sent['message']->getHeaders()->get('X-Vasey-Inquiry-Alert')->getBodyAsString());
        $this->assertNoPrivateData($sent['message']->getTextBody());

        // A shorter configured timeout is kept, never raised to the cap.
        config(['mail.mailers.'.M::MAILER.'.timeout' => 5]);
        $transport->submit(new OperatorInquiryAlert($this->operator->id, (string) Str::uuid()));
        $this->assertSame(5.0, $log[3]['timeout']);
        $this->assertSame(60.0, $sharedTransport->getStream()->getTimeout());

        // With Laravel's real SMTP factory (no connection is opened by building), the alert's mailer is a separate SMTP
        // transport whose socket stream carries the cap, while the shared mailer's stream keeps its configured timeout.
        config(['mail.mailers.'.M::MAILER.'.timeout' => 60]);
        $real = new MailManager($this->app);
        Mail::swap($real);
        $alertMailer = (new \ReflectionMethod(MailInquiryAlertTransport::class, 'alertMailer'))->invoke($transport, M::MAILER);
        $sharedReal = $real->mailer(M::MAILER)->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $alertMailer->getSymfonyTransport());
        $this->assertNotSame($sharedReal, $alertMailer->getSymfonyTransport());
        $this->assertSame((float) MailInquiryAlertTransport::TRANSPORT_TIMEOUT_SECONDS, $alertMailer->getSymfonyTransport()->getStream()->getTimeout());
        $this->assertSame(60.0, $sharedReal->getStream()->getTimeout());
        $this->assertSame(M::HOST, $alertMailer->getSymfonyTransport()->getStream()->getHost());
        Mail::swap($manager);

        // A global recipient refuses before any send: configured through mail.to, or carried by the alert's own mailer.
        config(['mail.to' => ['address' => 'redirect@example.com', 'name' => 'Redirect']]);
        $this->assertRefusedWithoutSend($transport, $log, 'configured mail.to');
        config(['mail.to' => null]);
        $redirecting = new class($this->app) extends MailManager
        {
            public function build($config)
            {
                return tap(parent::build($config), fn ($mailer) => $mailer->alwaysTo('redirect@example.com'));
            }
        };
        $redirecting->extend('smtp', $factory);
        Mail::swap($redirecting);
        $this->assertRefusedWithoutSend($transport, $log, 'alwaysTo on the alert mailer');
        $this->assertStringContainsString('recipient_override_configured', implode("\n", $this->logs));
        $this->assertStringNotContainsString('redirect@example.com', implode("\n", $this->logs));
        // An alwaysTo on the shared mailer does not reach the alert, which still goes to the operator only.
        Mail::swap($manager);
        $manager->mailer(M::MAILER)->alwaysTo('redirect@example.com');
        $transport->submit(new OperatorInquiryAlert($this->operator->id, (string) Str::uuid()));
        $this->assertSame([$this->operator->email], array_map(fn ($address) => $address->getAddress(), $log[count($log) - 1]['envelope']->getRecipients()));
    }

    private function assertRefusedWithoutSend(InquiryAlertTransport $transport, \ArrayObject $log, string $case): void
    {
        $count = count($log);
        try {
            $transport->submit(new OperatorInquiryAlert($this->operator->id, (string) Str::uuid()));
            $this->fail('An alert was sent with a global recipient: '.$case);
        } catch (InquiryAlertNotSubmitted) {
            $this->assertCount($count, $log, $case);
        }
    }

    public function test_enabling_alerts_never_releases_a_backlog_older_than_the_window(): void
    {
        $this->assertSame(1440, config('inquiries.operator_alert_max_age_minutes'));
        // Inquiries taken while alerts were off: two beyond the window by the time alerts are enabled, one just inside it.
        $stale = [$this->save(), $this->save()];
        $this->travel(1440)->minutes();
        $edge = $this->save();
        $this->travel(1)->seconds();
        $this->enable();
        $work = app(InquiryNotificationWork::class);
        $this->assertSame([$edge->id], $work->eligible(100));
        // The bounded scan is not held by stale rows ahead of a fresh one, and a direct or late job never claims a stale intent.
        $this->artisan('vasey:process-inquiry-alerts', ['--limit' => '1'])->expectsOutput($edge->id.' submitted')->assertSuccessful();
        foreach ($stale as $intent) {
            $this->assertNull($work->claim($intent->id));
            $this->assertSame('pending', $work->process($intent->id));
            $this->runJob($intent);
            $this->assertSame(['pending', 0, null], [$intent->fresh()->state, $intent->fresh()->attempts, $intent->fresh()->outcome]);
        }
        Mail::assertSentCount(1);
        $this->artisan('vasey:inquiry-alerts-stuck')->expectsOutput('stuck=0 unknown=0 stale=2 minutes=15')->assertSuccessful();

        // A retry that outlives the window stops retrying; an expired claim of any age is still settled as unknown, unsent.
        config(['transactional-mail.enabled' => false]);
        $retry = $this->save();
        $this->assertSame('retry', $work->process($retry->id));
        $claimed = $this->save();
        $this->assertNotNull($work->claim($claimed->id));
        config(['transactional-mail.enabled' => true]);
        $this->travel(1441)->minutes();
        $this->assertSame([$claimed->id], $work->eligible(100));
        $this->artisan('vasey:process-inquiry-alerts', ['--limit' => '100'])->expectsOutput($claimed->id.' unknown')->assertSuccessful();
        $this->assertSame('retry', $work->process($retry->id));
        $this->assertSame(['retry', 1], [$retry->fresh()->state, $retry->fresh()->attempts]);
        $this->artisan('vasey:inquiry-alerts-stuck')->expectsOutput('stuck=0 unknown=1 stale=3 minutes=15')->assertSuccessful();
        Mail::assertSentCount(1);

        // The window is a whole number of minutes from 1 to 10080; anything else sends nothing at all.
        $fresh = $this->save();
        foreach ([null, 0, -5, '60', 1.5, 10081, true] as $invalid) {
            config(['inquiries.operator_alert_max_age_minutes' => $invalid]);
            $this->assertNull($work->oldestSendable());
            $this->assertSame([], $work->eligible(100));
            $this->assertSame('pending', $work->process($fresh->id));
        }
        config(['inquiries.operator_alert_max_age_minutes' => 1]);
        $this->assertSame('submitted', $work->process($fresh->id));
        Mail::assertSentCount(2);
    }
}
