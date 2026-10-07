<?php

namespace Tests\Feature;

use App\Domain\Notifications\Models\TransactionalNoticeAttempt;
use App\Domain\Notifications\NotificationException;
use App\Domain\Notifications\PrivateNotificationCapture;
use App\Domain\Notifications\TestTransactionalNotificationRecovery;
use App\Domain\Notifications\TestTransactionalNotifications;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalNotificationFixtures as F;
use Tests\TestCase;

class TransactionalNotificationRecoveryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
    }

    public function test_missed_dispatch_is_recovered_once_with_minimal_projection_and_read_only_replay(): void
    {
        $f = F::ready();
        $scanner = app(TestTransactionalNotificationRecovery::class);
        $result = $scanner->scan();
        $this->assertSame(['recoverySchema', 'testOnly', 'results', 'nextCursor', 'hasMore'], array_keys($result));
        $this->assertFalse($result['hasMore']);
        $this->assertSame([['notificationSchema' => 1, 'notificationId' => $f['notice']['notificationId'],
            'testOnly' => true, 'state' => 'accepted']], $result['results']);
        $this->assertStringNotContainsString($f['user']->email, json_encode($result, JSON_THROW_ON_ERROR));
        $before = $this->graph();
        $files = Storage::disk('local')->allFiles('transactional-notification-capture');
        $this->assertCount(1, $files);
        $path = Storage::disk('local')->path($files[0]);
        $stat = stat($path);
        $bytes = file_get_contents($path);
        $this->assertSame($result, $scanner->scan());
        $this->assertSame($before, $this->graph());
        clearstatcache(true, $path);
        $this->assertSame($stat, stat($path));
        $this->assertSame($bytes, file_get_contents($path));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_expired_ambiguous_lease_is_never_dispatched_or_replaced_without_original_bytes(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $service->claim($f['notice']['notificationId']);
        $this->travel(31)->seconds();
        $before = $this->graph();
        $result = app(TestTransactionalNotificationRecovery::class)->scan();
        $this->assertSame('uncertain', $result['results'][0]['state']);
        $this->assertSame($before, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
        $this->assertSame(1, TransactionalNoticeAttempt::count());
    }

    public function test_original_capture_after_lost_completion_is_reconciled_without_new_attempt_or_rewrite(): void
    {
        $f = F::ready();
        $lease = app(TestTransactionalNotifications::class)->claim($f['notice']['notificationId']);
        app(PrivateNotificationCapture::class)->store($lease->notificationId, $lease->capture());
        $path = Storage::disk('local')->path('transactional-notification-capture/'.$lease->notificationId.'.json');
        $bytes = file_get_contents($path);
        $stat = stat($path);
        $this->travel(31)->seconds();
        $scanner = app(TestTransactionalNotificationRecovery::class);
        $this->assertSame('accepted', $scanner->scan()['results'][0]['state']);
        $this->assertSame(1, TransactionalNoticeAttempt::count());
        $this->assertSame('capture_reconciled', TransactionalNoticeAttempt::sole()->reason);
        clearstatcache(true, $path);
        $this->assertSame($stat, stat($path));
        $this->assertSame($bytes, file_get_contents($path));
        $before = $this->graph();
        $scanner->scan();
        $this->assertSame($before, $this->graph());
    }

    public function test_active_lease_is_observed_without_attempt_or_capture(): void
    {
        $f = F::ready();
        app(TestTransactionalNotifications::class)->claim($f['notice']['notificationId']);
        $before = $this->graph();
        $this->assertSame('leased', app(TestTransactionalNotificationRecovery::class)->scan()['results'][0]['state']);
        $this->assertSame($before, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public function test_revoked_customer_is_isolated_and_cursor_progresses_to_next_authorized_notice(): void
    {
        $a = F::ready(suffix: 'A');
        $b = F::ready(suffix: 'B');
        $a['account']->update(['active' => false, 'access_version' => 2]);
        $scanner = app(TestTransactionalNotificationRecovery::class);
        $page = $scanner->scan(limit: 1);
        $this->assertTrue($page['hasMore']);
        $this->assertSame('unavailable', $page['results'][0]['state']);
        $this->assertSame(0, TransactionalNoticeAttempt::count());
        $next = $scanner->scan(after: $page['nextCursor'], limit: 1);
        $this->assertFalse($next['hasMore']);
        $this->assertSame($b['notice']['notificationId'], $next['results'][0]['notificationId']);
        $this->assertSame('accepted', $next['results'][0]['state']);
        $this->assertSame([], $scanner->scan(after: $next['nextCursor'])['results']);
    }

    public function test_known_storage_refusal_retries_only_with_existing_bounded_attempt_policy(): void
    {
        $f = F::ready();
        config(['filesystems.disks.local.driver' => 's3']);
        $scanner = app(TestTransactionalNotificationRecovery::class);
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame('failed', $scanner->scan()['results'][0]['state']);
            $this->assertSame(min($i, 3), TransactionalNoticeAttempt::count());
        }
        $before = $this->graph();
        config(['filesystems.disks.local.driver' => 'local']);
        $this->assertSame('failed', $scanner->scan()['results'][0]['state']);
        $this->assertSame($before, $this->graph());
    }

    #[DataProvider('invalidBounds')]
    public function test_invalid_bounds_are_refused_before_scan(int $after, int $limit): void
    {
        F::configure();
        $this->expectException(NotificationException::class);
        app(TestTransactionalNotificationRecovery::class)->scan($after, $limit);
    }

    public static function invalidBounds(): array
    {
        return [[-1, 1], [0, 0], [0, 26]];
    }

    public function test_outer_transaction_and_withdrawn_policy_refuse_even_empty_scans(): void
    {
        F::configure();
        DB::beginTransaction();
        try {
            try {
                app(TestTransactionalNotificationRecovery::class)->scan();
                $this->fail('Outer transaction was admitted.');
            } catch (NotificationException $e) {
                $this->assertSame('outer_transaction', $e->reason);
            }
        } finally {
            DB::rollBack();
        }
        config(['transactional-notifications.test_enabled' => false]);
        $this->expectException(NotificationException::class);
        app(TestTransactionalNotificationRecovery::class)->scan();
    }

    private function graph(): array
    {
        return ['attempts' => DB::table('transactional_notice_attempts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'audits' => AuditEvent::orderBy('id')->get()->toArray()];
    }
}
