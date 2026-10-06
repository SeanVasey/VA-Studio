<?php

namespace Tests\Feature;

use App\Domain\Notifications\Models\TransactionalNotice;
use App\Domain\Notifications\Models\TransactionalNoticeAttempt;
use App\Domain\Notifications\PrivateNotificationCapture;
use App\Domain\Notifications\TestTransactionalNotifications;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalNotificationFixtures as F;
use Tests\TestCase;

class TransactionalNotificationTest extends TestCase
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

    public function test_owned_activated_order_has_one_encrypted_minimal_intent_and_true_replay_changes_nothing(): void
    {
        $f = F::ready();
        $notice = TransactionalNotice::sole();
        $raw = DB::table('transactional_notices')->sole();
        $this->assertSame($f['account']->id, $notice->account_id);
        $this->assertNull($notice->claim_id);
        $capture = json_decode(Crypt::decryptString($notice->capture_ciphertext), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => $f['user']->email], $capture['recipient']);
        $this->assertSame(['account_id' => $f['account']->public_id, 'activation_id' => $f['fulfillment_activation']->public_id,
            'order_id' => $f['order']->public_id, 'schema_version' => 1, 'template_version' => 'test-order-ready-v1',
            'test_only' => true, 'type' => 'test_order_ready'], $capture['payload']);
        $this->assertSame(CanonicalJson::hash($capture['payload']), $notice->payload_hash);
        $this->assertStringNotContainsString($f['user']->email, json_encode($raw, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($f['user']->email, json_encode($notice, JSON_THROW_ON_ERROR));
        $audit = AuditEvent::where('action', 'notification.test.intent_enqueued')->sole();
        $this->assertSame(['test_only' => true, 'payload_hash' => $notice->payload_hash], $audit->context);
        $before = $this->graph();
        $this->travel(5)->seconds();
        $retry = app(TestTransactionalNotifications::class)->enqueueOrderReady($f['order']->public_id, $f['principal']);
        $this->assertSame(array_replace($f['notice'], ['replayed' => true]), $retry);
        $this->assertSame($before, $this->graph());
        $this->assertSame('pending', app(TestTransactionalNotifications::class)->status($notice->public_id)['state']);
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_private_capture_acceptance_is_exact_private_bytes_and_repeated_dispatch_adds_no_attempt_audit_or_write(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $this->assertSame('accepted', $service->dispatch($f['notice']['notificationId'])['state']);
        $notice = TransactionalNotice::sole();
        $attempt = TransactionalNoticeAttempt::sole();
        $path = Storage::disk('local')->path('transactional-notification-capture/'.$notice->public_id.'.json');
        $bytes = file_get_contents($path);
        $this->assertSame(Crypt::decryptString($notice->capture_ciphertext), $bytes);
        $this->assertSame(hash('sha256', $bytes), $attempt->receipt_hash);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        $this->assertSame(1, $attempt->number);
        $before = $this->graph();
        $stat = stat($path);
        $this->travel(4)->seconds();
        $this->assertSame('accepted', $service->dispatch($notice->public_id)['state']);
        $this->assertSame('accepted', $service->reconcile($notice->public_id)['state']);
        $this->assertSame($before, $this->graph());
        clearstatcache(true, $path);
        $this->assertSame($stat, stat($path));
        $this->assertSame($bytes, file_get_contents($path));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_claimed_guest_order_uses_exact_retained_claim_without_moving_original_owner_or_access(): void
    {
        $f = F::claimed();
        $notice = TransactionalNotice::sole();
        $this->assertSame($f['claim']->id, $notice->claim_id);
        $this->assertNotSame($f['principal']->ownerKey, $f['order']->owner_key);
        $before = DB::table('orders')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame('accepted', app(TestTransactionalNotifications::class)->dispatch($notice->public_id)['state']);
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertDatabaseCount('customer_purchase_claims', 1);
    }

    public function test_actual_capture_then_lost_acknowledgement_stays_uncertain_until_positive_read_only_reconciliation(): void
    {
        $f = F::ready();
        $transport = new class extends PrivateNotificationCapture
        {
            public int $calls = 0;

            public function store(string $notificationId, string $capture): array
            {
                $this->calls++;
                parent::store($notificationId, $capture);
                throw new \RuntimeException('Synthetic lost acknowledgement containing no real customer data.');
            }
        };
        app()->instance(PrivateNotificationCapture::class, $transport);
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $this->assertSame('uncertain', $service->dispatch($id)['state']);
        $this->assertSame(1, $transport->calls);
        $before = $this->graph();
        $path = Storage::disk('local')->path('transactional-notification-capture/'.$id.'.json');
        $bytes = file_get_contents($path);
        $this->assertSame('uncertain', $service->dispatch($id, retryKnownFailure: true)['state']);
        $this->assertSame(1, $transport->calls);
        $this->assertSame($before, $this->graph());
        $this->assertSame('accepted', $service->reconcile($id)['state']);
        $this->assertSame('capture_reconciled', TransactionalNoticeAttempt::sole()->reason);
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame(1, $transport->calls);
        $this->assertDatabaseCount('transactional_notice_attempts', 1);
        $after = $this->graph();
        $this->assertSame('accepted', $service->reconcile($id)['state']);
        $this->assertSame($after, $this->graph());
    }

    private function graph(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ['transactional_notices', 'transactional_notice_attempts', 'audit_events']);
    }
}
