<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Notifications\Mail\OrderReadyMail;
use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Models\TransactionalMailDelivery;
use App\Domain\Notifications\NotificationException;
use App\Jobs\DeliverTransactionalMail;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\MailFake;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalMailFixtures as M;
use Tests\Support\TransactionalNotificationFixtures as N;
use Tests\TestCase;

/** FP-075: default-off order-ready email; fake transport only; at most one send per intent; no PII in logs. */
class TransactionalMailTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logs[] = $event->message.' '.json_encode($event->context));
        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob(storage_path('framework/testing/mail-preflight-*'), GLOB_ONLYDIR) ?: [] as $directory) {
                (new Filesystem)->deleteDirectory($directory);
            }
        });
    }

    private function assertNoDeliveries(): void
    {
        $this->assertDatabaseCount('transactional_mail_deliveries', 0);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'notification.mail.%')->count());
    }

    public static function brokenConfiguration(): array
    {
        return [
            'disabled' => [fn () => config(['transactional-mail.enabled' => false])],
            'string enabled' => [fn () => config(['transactional-mail.enabled' => 'true'])],
            'no mailer' => [fn () => config(['transactional-mail.mailer' => null])],
            'unknown mailer' => [fn () => config(['transactional-mail.mailer' => 'missing'])],
            'log transport' => [fn () => config(['transactional-mail.mailer' => 'log'])],
            'array transport' => [fn () => config(['transactional-mail.mailer' => 'array'])],
            'url mailer' => [fn () => config(['mail.mailers.'.M::MAILER.'.url' => 'smtp://user:pass@smtp.example.com:587'])],
            'test host' => [fn () => config(['mail.mailers.'.M::MAILER.'.host' => 'smtp.example.test'])],
            'no sender' => [fn () => config(['transactional-mail.sender' => null])],
            'sender differs from facts' => [fn () => config(['transactional-mail.sender' => 'receipts@example.com'])],
            'host differs from facts' => [fn () => config(['mail.mailers.'.M::MAILER.'.host' => 'mail.example.com'])],
            'port differs from facts' => [fn () => config(['mail.mailers.'.M::MAILER.'.port' => 465])],
            'security differs from facts' => [fn () => config(['mail.mailers.'.M::MAILER.'.scheme' => 'smtps'])],
            'facts hash mismatch' => [fn () => config(['transactional-mail.preflight.facts_sha256' => str_repeat('0', 64)])],
            'receipt hash mismatch' => [fn () => config(['transactional-mail.preflight.receipt_sha256' => str_repeat('0', 64)])],
            'relative facts path' => [fn () => config(['transactional-mail.preflight.facts_path' => 'facts.json'])],
            'no receipt' => [fn () => config(['transactional-mail.preflight.receipt_path' => null])],
            'blocked receipt' => [function (): void {
                $path = config('transactional-mail.preflight.receipt_path');
                $report = json_decode(file_get_contents($path), true);
                $report['result'] = 'BLOCKED';
                $report['checks'][4]['status'] = 'blocked';
                file_put_contents($path, json_encode($report));
                config(['transactional-mail.preflight.receipt_sha256' => hash_file('sha256', $path)]);
            }],
            'template receipt' => [function (): void {
                $path = config('transactional-mail.preflight.receipt_path');
                $report = json_decode(file_get_contents($path), true);
                $report['scope'] = 'template';
                $report['result'] = 'TEMPLATE_VALID';
                file_put_contents($path, json_encode($report));
                config(['transactional-mail.preflight.receipt_sha256' => hash_file('sha256', $path)]);
            }],
            'symlinked facts' => [function (): void {
                $path = config('transactional-mail.preflight.facts_path');
                symlink($path, $path.'.link');
                config(['transactional-mail.preflight.facts_path' => $path.'.link']);
            }],
        ];
    }

    #[DataProvider('brokenConfiguration')]
    public function test_unset_or_inconsistent_configuration_refuses_without_rows_or_mail(callable $break): void
    {
        Mail::fake();
        $f = N::ready();
        M::configure();
        $break();
        try {
            app(TransactionalMailAdapter::class)->queueOrderReady($f['notice']['notificationId']);
            $this->fail('Mail was queued without a complete configuration.');
        } catch (NotificationException $error) {
            $this->assertSame('mail_not_configured', $error->reason);
        }
        $this->artisan('vasey:transactional-mail-queue')->expectsOutput('refused=mail_not_configured')->assertFailed();
        $this->assertNoDeliveries();
        Mail::assertNothingOutgoing();
    }

    public function test_shipped_defaults_refuse_and_an_unfaked_mailer_is_refused_in_testing(): void
    {
        Mail::fake();
        $f = N::ready();
        $this->artisan('vasey:transactional-mail-queue')->expectsOutput('refused=mail_not_configured')->assertFailed();
        $this->assertFalse(config('transactional-mail.enabled'));
        M::configure();
        Mail::swap(new MailManager(app()));
        try {
            app(TransactionalMailAdapter::class)->queueOrderReady($f['notice']['notificationId']);
            $this->fail('A real mailer was admitted in testing.');
        } catch (NotificationException $error) {
            $this->assertSame('mail_not_faked', $error->reason);
        }
        $this->assertNoDeliveries();
    }

    public function test_one_plain_text_order_ready_message_is_sent_per_intent_and_never_again(): void
    {
        Mail::fake();
        $f = N::ready();
        $receipt = M::configure()['report'];
        $this->assertSame(['host_verified' => false, 'provider_authenticated' => false, 'activation_ready' => false],
            array_intersect_key($receipt, array_flip(['host_verified', 'provider_authenticated', 'activation_ready'])));
        $adapter = app(TransactionalMailAdapter::class);

        $queued = $adapter->queueOrderReady($f['notice']['notificationId']);
        $this->assertFalse($queued['replayed']);
        Mail::assertNothingOutgoing();
        $this->assertSame(1, $this->runQueuedMail());
        $delivery = TransactionalMailDelivery::sole();
        $this->assertSame(['accepted', null, 2], [$delivery->state, $delivery->reason, $delivery->version]);
        Mail::assertSentCount(1);
        Mail::assertSent(OrderReadyMail::class, function (OrderReadyMail $mail) use ($f, $delivery): bool {
            return $mail->hasTo($f['user']->email) && $mail->hasFrom(M::SENDER, 'VASEY.AUDIO') && $mail->mailer === M::MAILER
                && $mail->orderReference === $f['order']->public_id && $mail->deliveryId === $delivery->public_id;
        });
        $text = (new OrderReadyMail($f['order']->public_id, M::SENDER, $delivery->public_id))->render();
        $this->assertStringContainsString($f['order']->public_id, $text);
        $this->assertStringContainsString('TEST ORDER', $text);
        $this->assertStringContainsString('support@vasey.audio', $text);
        foreach (['http', '/account/', 'token', '$', 'price'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, $text);
        }

        // Replayed queue calls, a duplicated job and the batch command never send a second message.
        $this->assertSame(['deliveryId' => $delivery->public_id, 'state' => 'accepted', 'replayed' => true], $adapter->queueOrderReady($f['notice']['notificationId']));
        (new DeliverTransactionalMail($delivery->public_id))->handle($adapter);
        $this->assertSame('accepted', $adapter->deliver($delivery->public_id)['state']);
        $this->artisan('vasey:transactional-mail-queue')->expectsOutput('queued=0 skipped=0 superseded=0')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame(1, TransactionalMailDelivery::count());
        $this->assertSame(['notification.mail.queued', 'notification.mail.submitting', 'notification.mail.accepted'],
            AuditEvent::where('action', 'like', 'notification.mail.%')->orderBy('id')->pluck('action')->all());
        $this->assertNoPersonalData($f['user']->email);
    }

    public function test_queued_mail_is_not_sent_after_access_withdrawal_or_a_configuration_change(): void
    {
        Mail::fake();
        Queue::fake();
        $first = N::ready(suffix: 'ONE');
        $second = N::ready(suffix: 'TWO');
        M::configure();
        $adapter = app(TransactionalMailAdapter::class);
        $one = $adapter->queueOrderReady($first['notice']['notificationId'])['deliveryId'];
        $two = $adapter->queueOrderReady($second['notice']['notificationId'])['deliveryId'];
        Queue::assertPushed(DeliverTransactionalMail::class, 2);
        Mail::assertNothingOutgoing();

        DB::transaction(function () use ($first): void {
            $account = CustomerAccount::whereKey($first['account']->id)->lockForUpdate()->sole();
            $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
        });
        $this->assertSame(['deliveryId' => $one, 'state' => 'failed', 'reason' => 'authority_withdrawn'], $adapter->deliver($one));

        M::configure(['sender' => 'receipts@example.com']);
        config(['transactional-mail.sender' => 'receipts@example.com']);
        $this->assertSame(['deliveryId' => $two, 'state' => 'failed', 'reason' => 'configuration_changed'], $adapter->deliver($two));
        // A later disable also refuses any delivery still queued, without contacting a transport.
        Mail::assertNothingOutgoing();
        $this->assertSame(['failed', 'failed'], TransactionalMailDelivery::orderBy('id')->pluck('state')->all());
    }

    public function test_a_transport_error_is_recorded_as_uncertain_and_never_retried_and_logs_hold_no_recipient(): void
    {
        $f = N::ready();
        M::configure();
        Mail::swap(new class(app(MailManager::class)) extends MailFake
        {
            public int $attempts = 0;

            protected function sendMail($view, $shouldQueue = false)
            {
                $this->attempts++;
                throw new RuntimeException('Synthetic SMTP failure for '.TransactionalMailFixturesRecipient::$email);
            }
        });
        TransactionalMailFixturesRecipient::$email = $f['user']->email;
        $adapter = app(TransactionalMailAdapter::class);
        $delivery = $adapter->queueOrderReady($f['notice']['notificationId'])['deliveryId'];
        $this->runQueuedMail();
        $this->assertSame(['deliveryId' => $delivery, 'state' => 'uncertain', 'reason' => 'transport_uncertain'], $adapter->status($delivery));
        $adapter->deliver($delivery);
        $this->artisan('vasey:transactional-mail-queue')->expectsOutput('queued=0 skipped=0 superseded=0')->assertSuccessful();
        $this->assertSame(1, Mail::getFacadeRoot()->attempts);
        $this->assertNotEmpty($this->logs);
        $this->assertNoPersonalData($f['user']->email);
    }

    public function test_the_command_queues_pending_intents_in_bounded_batches(): void
    {
        Mail::fake();
        $intents = [N::ready(suffix: 'A'), N::ready(suffix: 'B'), N::ready(suffix: 'C')];
        M::configure();
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 2])->expectsOutput('queued=2 skipped=0 superseded=0')->assertSuccessful();
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 2])->expectsOutput('queued=1 skipped=0 superseded=0')->assertSuccessful();
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 101])->assertExitCode(2);
        $this->assertSame(3, $this->runQueuedMail());
        Mail::assertSentCount(3);
        foreach ($intents as $intent) {
            Mail::assertSent(OrderReadyMail::class, fn (OrderReadyMail $mail): bool => $mail->hasTo($intent['user']->email));
        }
    }

    public function test_refused_intents_ahead_of_a_valid_one_never_hold_the_batch_and_only_superseded_access_leaves_the_pending_set(): void
    {
        Mail::fake();
        Queue::fake();
        $withdrawn = [N::ready(suffix: 'W1'), N::ready(suffix: 'W2')];
        $changed = [N::ready(suffix: 'E1'), N::ready(suffix: 'E2')];
        $valid = N::ready(suffix: 'OK');
        M::configure();
        // Oldest first: two intents whose customer access was withdrawn (the account's access version moved on, so they can never
        // verify again), then two whose recipient changed (refused today, but reversible), then one valid intent.
        foreach ($withdrawn as $f) {
            DB::transaction(function () use ($f): void {
                $account = CustomerAccount::whereKey($f['account']->id)->lockForUpdate()->sole();
                $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
            });
        }
        $emails = array_map(fn (array $f): string => $f['user']->email, $changed);
        foreach ($changed as $i => $f) {
            $f['user']->forceFill(['email' => 'changed-'.$i.'@example.invalid'])->save();
        }
        $deliveryFor = fn (array $f): bool => TransactionalMailDelivery::where('notice_public_id', $f['notice']['notificationId'])->exists();

        // Before the fix the batch held the four refused intents forever and the valid one was never queued.
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 2])->expectsOutput('queued=1 skipped=2 reasons=unavailable=2 superseded=2')->assertSuccessful();
        $this->assertSame([false, false, false, false, true], array_map($deliveryFor, [...$withdrawn, ...$changed, $valid]));
        // Refused intents record nothing and stay pending; superseded ones are counted, never retried and never written.
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 2])->expectsOutput('queued=0 skipped=2 reasons=unavailable=2 superseded=2')->assertSuccessful();
        $this->assertSame(1, TransactionalMailDelivery::count());

        // A transient refusal (the intent policy switched off) leaves every pending intent pending, with nothing recorded.
        $changed[0]['user']->forceFill(['email' => $emails[0]])->save();
        config(['transactional-notifications.test_enabled' => false]);
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 2])->expectsOutput('queued=0 skipped=2 reasons=unavailable=2 superseded=2')->assertSuccessful();
        $this->assertSame(1, TransactionalMailDelivery::count());
        // Once the policy is back and the recipient restored, the refused intent is queued after all.
        config(['transactional-notifications.test_enabled' => true]);
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 2])->expectsOutput('queued=1 skipped=1 reasons=unavailable=1 superseded=2')->assertSuccessful();
        $this->assertSame([true, false], array_map($deliveryFor, $changed));
        $this->assertSame(2, $this->runQueuedMail());
        Mail::assertSentCount(2);
    }

    public function test_database_guards_allow_one_forward_path_per_intent(): void
    {
        Mail::fake();
        Queue::fake();
        $f = N::ready();
        M::configure();
        $delivery = app(TransactionalMailAdapter::class)->queueOrderReady($f['notice']['notificationId'])['deliveryId'];
        $row = DB::table('transactional_mail_deliveries')->sole();
        $attempts = [
            'second delivery for the intent' => fn () => DB::table('transactional_mail_deliveries')->insert(['public_id' => '44444444-4444-4444-8444-444444444444',
                'notice_id' => $row->notice_id, 'notice_public_id' => $row->notice_public_id, 'template_version' => 'order-ready-mail-v1',
                'capability' => $row->capability, 'state' => 'queued', 'version' => 0, 'queued_at' => now()]),
            'unknown intent' => fn () => DB::table('transactional_mail_deliveries')->insert(['public_id' => '55555555-5555-4555-8555-555555555555',
                'notice_id' => 999999, 'notice_public_id' => '66666666-6666-4666-8666-666666666666', 'template_version' => 'order-ready-mail-v1',
                'capability' => $row->capability, 'state' => 'queued', 'version' => 0, 'queued_at' => now()]),
            'skip to accepted' => fn () => DB::table('transactional_mail_deliveries')->where('id', $row->id)->update(['state' => 'accepted',
                'version' => 1, 'message_hash' => str_repeat('a', 64), 'finished_at' => now()]),
            'rebind intent' => fn () => DB::table('transactional_mail_deliveries')->where('id', $row->id)->update(['notice_id' => $row->notice_id + 1, 'version' => 1]),
            'delete' => fn () => DB::table('transactional_mail_deliveries')->where('id', $row->id)->delete(),
        ];
        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                $this->fail("The database accepted: {$case}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        app(TransactionalMailAdapter::class)->deliver($delivery);
        $accepted = DB::table('transactional_mail_deliveries')->sole();
        $this->assertSame('accepted', $accepted->state);
        foreach (['reopen' => ['state' => 'queued', 'version' => 3], 'retry' => ['state' => 'submitting', 'version' => 3]] as $case => $change) {
            try {
                DB::table('transactional_mail_deliveries')->where('id', $accepted->id)->update($change);
                $this->fail("The database accepted: {$case}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        Mail::assertSentCount(1);
    }

    /** The customer fixtures fake the queue; run each pushed one-attempt wake-up (and any duplicate push) exactly as a worker would. */
    private function runQueuedMail(): int
    {
        $jobs = Queue::pushed(DeliverTransactionalMail::class);
        foreach ($jobs as $job) {
            $this->assertSame(1, $job->tries);
            $this->assertTrue($job->afterCommit);
            app()->call([$job, 'handle']);
        }

        return $jobs->count();
    }

    private function assertNoPersonalData(string $email): void
    {
        $this->assertStringNotContainsString($email, implode("\n", $this->logs));
        $this->assertStringNotContainsString($email, json_encode(DB::table('transactional_mail_deliveries')->get(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($email, AuditEvent::where('action', 'like', 'notification.mail.%')->get()->toJson());
    }
}

/** Carries the synthetic recipient into the throwing transport so the test can prove the exception text is never logged. */
final class TransactionalMailFixturesRecipient
{
    public static string $email = '';
}
