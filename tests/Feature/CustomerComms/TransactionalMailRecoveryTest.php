<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Notifications\Mail\OrderReadyMail;
use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Models\TransactionalMailDelivery;
use App\Jobs\DeliverTransactionalMail;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalMailFixtures as M;
use Tests\Support\TransactionalNotificationFixtures as N;
use Tests\TestCase;

class TransactionalMailRecoveryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Mail::fake();
        Queue::fake();
        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob(storage_path('framework/testing/mail-preflight-*'), GLOB_ONLYDIR) ?: [] as $path) {
                (new Filesystem)->deleteDirectory($path);
            }
        });
    }

    public static function pushWindows(): array
    {
        return ['before broker push' => [false], 'after broker push' => [true]];
    }

    #[DataProvider('pushWindows')]
    public function test_actual_after_commit_push_failure_recovers_queued_work_without_duplicate_transport(bool $afterPush): void
    {
        $f = N::ready(suffix: 'PUSH'.($afterPush ? 'AFTER' : 'BEFORE'));
        M::configure();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => DB::getDefaultConnection()]);
        Queue::swap(new QueueManager(app()));
        $armed = true;
        $hits = 0;
        Event::listen($afterPush ? JobQueued::class : JobQueueing::class, function (object $event) use (&$armed, &$hits): void {
            if ($armed && $event->job instanceof DeliverTransactionalMail) {
                $hits++;
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::getPdo()->inTransaction());
                $this->assertDatabaseHas('transactional_mail_deliveries', ['public_id' => $event->job->deliveryId, 'state' => 'queued', 'version' => 0]);
                throw new RuntimeException('Synthetic push acknowledgment failure');
            }
        });
        try {
            app(TransactionalMailAdapter::class)->queueOrderReady($f['notice']['notificationId']);
            $this->fail('The actual post-commit dispatch fault was not reached.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic push acknowledgment failure', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame(1, $hits);
        $id = TransactionalMailDelivery::sole()->public_id;
        $this->assertDatabaseCount('jobs', $afterPush ? 1 : 0);
        Mail::assertNothingOutgoing();
        $this->artisan('vasey:transactional-mail-wake-queued')->expectsOutput('requested=1 skipped=0')->assertSuccessful();
        $jobs = DB::table('jobs')->orderBy('id')->get();
        $this->assertCount($afterPush ? 2 : 1, $jobs);
        foreach ($jobs as $row) {
            $payload = json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR);
            $job = unserialize($payload['data']['command'], ['allowed_classes' => [DeliverTransactionalMail::class]]);
            $this->assertInstanceOf(DeliverTransactionalMail::class, $job);
            $this->assertSame($id, $job->deliveryId);
            $this->assertSame(1, $job->tries);
            $this->assertTrue($job->afterCommit);
            $job->handle(app(TransactionalMailAdapter::class));
        }
        Mail::assertSentCount(1);
        Mail::assertSent(OrderReadyMail::class, fn (OrderReadyMail $mail): bool => $mail->hasTo($f['user']->email));
        $this->assertDatabaseCount('transactional_mail_deliveries', 1);
        $this->assertSame('accepted', app(TransactionalMailAdapter::class)->status($id)['state']);
    }

    public function test_only_queued_rows_are_woken_and_terminal_or_submitting_rows_are_never_reopened(): void
    {
        $fixtures = [];
        foreach (['queued', 'submitting', 'uncertain', 'failed', 'accepted'] as $state) {
            $fixtures[$state] = N::ready(suffix: 'STATE'.strtoupper($state));
        }
        M::configure();
        $adapter = app(TransactionalMailAdapter::class);
        $ids = [];
        foreach ($fixtures as $state => $f) {
            $ids[$state] = $adapter->queueOrderReady($f['notice']['notificationId'])['deliveryId'];
            if ($state === 'failed') {
                DB::table('transactional_mail_deliveries')->where('public_id', $ids[$state])->update([
                    'state' => 'failed', 'reason' => 'not_configured', 'version' => 1, 'finished_at' => now()]);
            } elseif ($state !== 'queued') {
                DB::table('transactional_mail_deliveries')->where('public_id', $ids[$state])->update([
                    'state' => 'submitting', 'version' => 1, 'started_at' => now()]);
                if ($state !== 'submitting') {
                    DB::table('transactional_mail_deliveries')->where('public_id', $ids[$state])->update([
                        'state' => $state, 'version' => 2, 'finished_at' => now(),
                        'reason' => $state === 'uncertain' ? 'transport_uncertain' : null,
                        'message_hash' => $state === 'accepted' ? str_repeat('a', 64) : null]);
                }
            }
        }
        $before = DB::table('transactional_mail_deliveries')->where('state', '!=', 'queued')->orderBy('id')->get()->toJson();
        Queue::fake();
        $this->artisan('vasey:transactional-mail-wake-queued')->expectsOutput('requested=1 skipped=0')->assertSuccessful();
        Queue::assertPushed(DeliverTransactionalMail::class, 1);
        Queue::assertPushed(DeliverTransactionalMail::class, fn (DeliverTransactionalMail $job): bool => $job->deliveryId === $ids['queued']);
        $this->assertSame($before, DB::table('transactional_mail_deliveries')->where('state', '!=', 'queued')->orderBy('id')->get()->toJson());
        foreach (Queue::pushed(DeliverTransactionalMail::class) as $job) {
            $job->handle($adapter);
            $job->handle($adapter);
        }
        Mail::assertSentCount(1);
        foreach (array_diff_key($ids, ['queued' => true]) as $id) {
            $this->assertFalse($adapter->wakeQueued($id));
        }
        $this->assertSame($before, DB::table('transactional_mail_deliveries')->whereIn('public_id', array_values(array_diff_key($ids, ['queued' => true])))->orderBy('id')->get()->toJson());
    }

    public function test_default_off_and_changed_authority_keep_original_refusal_and_failed_classification(): void
    {
        $f = N::ready(suffix: 'RECOVERAUTH');
        $this->artisan('vasey:transactional-mail-wake-queued')->expectsOutput('refused=mail_not_configured')->assertFailed();
        M::configure();
        $adapter = app(TransactionalMailAdapter::class);
        $id = $adapter->queueOrderReady($f['notice']['notificationId'])['deliveryId'];
        Queue::fake();
        $f['user']->forceFill(['email_verified_at' => null])->save();
        $this->artisan('vasey:transactional-mail-wake-queued')->expectsOutput('requested=1 skipped=0')->assertSuccessful();
        foreach (Queue::pushed(DeliverTransactionalMail::class) as $job) {
            $job->handle($adapter);
        }
        $this->assertSame(['deliveryId' => $id, 'state' => 'failed', 'reason' => 'authority_withdrawn'], $adapter->status($id));
        $this->assertFalse($adapter->wakeQueued($id));
        Mail::assertNothingOutgoing();
        $this->artisan('vasey:transactional-mail-wake-queued', ['--limit' => 0])->assertExitCode(2);
    }
}
