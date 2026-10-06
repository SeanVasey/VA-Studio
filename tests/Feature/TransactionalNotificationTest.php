<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Notifications\Models\TransactionalNotice;
use App\Domain\Notifications\Models\TransactionalNoticeAttempt;
use App\Domain\Notifications\NotificationException;
use App\Domain\Notifications\NotificationLease;
use App\Domain\Notifications\PrivateNotificationCapture;
use App\Domain\Notifications\TestTransactionalNotifications;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
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

    public function test_generic_exception_before_any_capture_is_uncertain_and_never_treated_as_a_known_failure(): void
    {
        $f = F::ready();
        $transport = new class extends PrivateNotificationCapture
        {
            public int $calls = 0;

            public function store(string $notificationId, string $capture): array
            {
                $this->calls++;
                throw new \RuntimeException('Synthetic-private-email-and-source-must-not-be-logged');
            }
        };
        app()->instance(PrivateNotificationCapture::class, $transport);
        Log::spy();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $this->assertSame('uncertain', $service->dispatch($id)['state']);
        Log::shouldHaveReceived('warning')->once()->with('Private test notification capture could not be confirmed.',
            ['exception_class' => \RuntimeException::class]);
        $this->assertSame('capture_unknown', TransactionalNoticeAttempt::sole()->reason);
        $before = $this->graph();
        $this->assertSame('uncertain', $service->dispatch($id, retryKnownFailure: true)['state']);
        $this->assertSame('uncertain', $service->reconcile($id)['state']);
        $this->assertSame(1, $transport->calls);
        $this->assertSame($before, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public function test_expired_unacknowledged_lease_never_authorizes_a_new_attempt_or_capture(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $lease = $service->claim($id);
        $this->assertNotNull($lease);
        $before = $this->graph();
        $this->assertNull($service->claim($id));
        $this->assertSame($before, $this->graph());
        $this->travel(31)->seconds();
        $this->assertSame('uncertain', $service->status($id)['state']);
        $this->assertNull($service->claim($id, retryKnownFailure: true));
        $this->assertSame('lease_expired', TransactionalNoticeAttempt::sole()->reason);
        $after = $this->graph();
        $this->assertSame('uncertain', $service->dispatch($id, retryKnownFailure: true)['state']);
        $this->assertSame('uncertain', $service->reconcile($id)['state']);
        $this->assertSame($after, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public function test_late_positive_completion_is_uncertain_and_cannot_override_the_reconciled_winner(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $lease = $service->claim($id);
        $receipt = app(PrivateNotificationCapture::class)->store($id, $lease->capture());
        $path = Storage::disk('local')->path('transactional-notification-capture/'.$id.'.json');
        $bytes = file_get_contents($path);
        $this->travel(31)->seconds();
        $this->assertSame('uncertain', $service->complete($lease, $receipt['receiptHash'])['state']);
        $this->assertSame('lease_expired', TransactionalNoticeAttempt::sole()->reason);
        $this->assertSame('accepted', $service->reconcile($id)['state']);
        $before = $this->graph();
        $this->assertSame('accepted', $service->complete($lease, $receipt['receiptHash'])['state']);
        $this->assertSame($before, $this->graph());
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertDatabaseCount('transactional_notice_attempts', 1);
    }

    public function test_only_explicit_pre_message_refusal_retries_and_three_attempts_are_a_hard_bound(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $root = Storage::disk('local')->path('transactional-notification-capture');
        mkdir($root, 0755);
        chmod($root, 0755);
        $this->assertSame('failed', $service->dispatch($id)['state']);
        $before = $this->graph();
        $this->assertSame('failed', $service->dispatch($id)['state']);
        $this->assertSame($before, $this->graph());
        $this->assertSame('failed', $service->dispatch($id, retryKnownFailure: true)['state']);
        $this->assertSame('failed', $service->dispatch($id, retryKnownFailure: true)['state']);
        $this->assertSame([1, 2, 3], TransactionalNoticeAttempt::orderBy('number')->pluck('number')->all());
        $bound = $this->graph();
        chmod($root, 0700);
        $this->assertSame('failed', $service->dispatch($id, retryKnownFailure: true)['state']);
        $this->assertSame($bound, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public function test_forged_claim_token_or_changed_private_capture_cannot_acknowledge_the_actual_claim(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $lease = $service->claim($id);
        $receipt = app(PrivateNotificationCapture::class)->store($id, $lease->capture());
        $before = $this->graph();
        $forged = new NotificationLease($id, $lease->attemptId, $lease->expiresAt, str_repeat('a', 64), $lease->capture());
        $this->assertSame('stale', $service->complete($forged, $receipt['receiptHash'])['state']);
        $this->assertSame($before, $this->graph());
        $changed = new NotificationLease($id, $lease->attemptId, $lease->expiresAt, $lease->token(), $lease->capture().' ');
        $this->refused(fn () => $service->complete($changed, $receipt['receiptHash']));
        $this->assertSame($before, $this->graph());
        $this->assertSame('accepted', $service->complete($lease, $receipt['receiptHash'])['state']);
        $this->assertStringNotContainsString($f['user']->email, print_r($lease, true));
        $this->assertStringNotContainsString($lease->token(), print_r($lease, true));
        $this->refused(fn () => json_encode($lease, JSON_THROW_ON_ERROR));
        $this->refused(fn () => serialize($lease));
    }

    public function test_another_account_and_an_unactivated_order_cannot_mint_any_intent(): void
    {
        $f = F::ready(enqueue: false);
        $other = CustomerFixtures::account(['email' => 'notification-other@example.invalid']);
        $unactivated = CustomerFixtures::prepared($f['user']);
        $before = $this->graph();
        $service = app(TestTransactionalNotifications::class);
        $this->refused(fn () => $service->enqueueOrderReady($f['order']->public_id, $other['principal']));
        $this->refused(fn () => $service->enqueueOrderReady($unactivated->public_id, $f['principal']));
        $this->assertSame($before, $this->graph());
        $this->assertDatabaseCount('transactional_notices', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public static function gates(): array
    {
        return ['disabled' => ['disabled'], 'smtp' => ['smtp'], 'log' => ['log'],
            'changed policy' => ['policy'], 'production' => ['production'], 'customer access disabled' => ['customer']];
    }

    #[DataProvider('gates')]
    public function test_every_disabled_or_non_private_policy_refuses_even_with_a_ready_owned_order(string $gate): void
    {
        $f = F::ready(enqueue: false);
        match ($gate) {
            'disabled' => config(['transactional-notifications.test_enabled' => false]),
            'smtp', 'log' => config(['transactional-notifications.transport' => $gate]),
            'policy' => config(['transactional-notifications.policy_version' => 'different-version']),
            'customer' => config(['customer.test_accounts_enabled' => false]),
            'production' => app()->instance('env', 'production'),
        };
        $before = $this->graph();
        $this->refused(fn () => app(TestTransactionalNotifications::class)->enqueueOrderReady($f['order']->public_id, $f['principal']));
        $this->assertSame($before, $this->graph());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        app()->instance('env', 'testing');
    }

    public static function authorityChanges(): array
    {
        return ['withdrawn' => ['withdraw'], 'different recipient' => ['recipient'], 'unverified recipient' => ['unverified'],
            'staff account' => ['admin'], 'withdraw and restore' => ['aba'], 'policy withdrawal' => ['policy']];
    }

    #[DataProvider('authorityChanges')]
    public function test_current_authority_or_recipient_change_never_retargets_an_immutable_intent(string $change): void
    {
        $f = F::ready();
        match ($change) {
            'withdraw', 'aba' => CustomerFixtures::withdraw($f),
            'recipient' => DB::table('users')->where('id', $f['user']->id)->update(['email' => 'different-recipient@example.invalid']),
            'unverified' => DB::table('users')->where('id', $f['user']->id)->update(['email_verified_at' => null]),
            'admin' => DB::table('users')->where('id', $f['user']->id)->update(['is_admin' => true]),
            'policy' => config(['transactional-notifications.test_enabled' => false]),
        };
        if ($change === 'aba') {
            $f['account']->fresh()->update(['active' => true, 'access_version' => 3]);
        }
        $before = $this->graph();
        $service = app(TestTransactionalNotifications::class);
        $this->refused(fn () => $service->enqueueOrderReady($f['order']->public_id, $f['principal']));
        $this->refused(fn () => $service->dispatch($f['notice']['notificationId']));
        $this->assertSame($before, $this->graph());
        $this->assertDatabaseCount('transactional_notice_attempts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public function test_stale_credentials_cannot_enqueue_but_fresh_current_authority_does_not_duplicate_credentials_in_capture(): void
    {
        $f = F::ready(enqueue: false);
        $hash = Hash::make('Synthetic-new-password-only');
        DB::table('users')->where('id', $f['user']->id)->update(['password' => $hash]);
        $service = app(TestTransactionalNotifications::class);
        $this->refused(fn () => $service->enqueueOrderReady($f['order']->public_id, $f['principal']));
        $this->assertDatabaseCount('transactional_notices', 0);
        $fresh = app(CustomerAccess::class)->principal($f['user']->fresh());
        $service->enqueueOrderReady($f['order']->public_id, $fresh);
        $bytes = Crypt::decryptString(TransactionalNotice::sole()->capture_ciphertext);
        foreach ([$hash, $fresh->credentialStamp, $fresh->ownerKey, CustomerFixtures::PASSWORD] as $private) {
            $this->assertStringNotContainsString($private, $bytes);
        }
    }

    public static function enqueueCallbacks(): array
    {
        return ['password after audit' => ['password'], 'withdraw after audit' => ['withdraw'],
            'policy after audit' => ['policy'], 'extra claim after audit' => ['attempt']];
    }

    #[DataProvider('enqueueCallbacks')]
    public function test_enqueue_callbacks_cannot_change_authority_policy_or_expected_empty_attempt_graph(string $change): void
    {
        $f = F::ready(enqueue: false);
        $before = $this->graph();
        $this->withEvents(function () use ($f, $change): void {
            AuditEvent::created(function (AuditEvent $event) use ($f, $change): void {
                if ($event->action !== 'notification.test.intent_enqueued') {
                    return;
                }
                match ($change) {
                    'password' => DB::table('users')->where('id', $f['user']->id)->update(['password' => Hash::make('Synthetic-callback-only')]),
                    'withdraw' => CustomerFixtures::withdraw($f),
                    'policy' => config(['transactional-notifications.test_enabled' => false]),
                    'attempt' => DB::table('transactional_notice_attempts')->insert(['public_id' => (string) Str::uuid(),
                        'notice_id' => $event->subject_id, 'number' => 1, 'token_hash' => str_repeat('a', 64),
                        'started_at' => now(), 'lease_expires_at' => now()->addSeconds(30), 'state' => 'leased',
                        'reason' => null, 'receipt_hash' => null, 'finished_at' => null]),
                };
            });
            $this->refused(fn () => app(TestTransactionalNotifications::class)->enqueueOrderReady($f['order']->public_id, $f['principal']));
        });
        $this->assertSame($before, $this->graph());
        $this->assertSame($f['user']->password, $f['user']->fresh()->password);
        $this->assertTrue($f['account']->fresh()->active);
        $this->assertSame(1, $f['account']->fresh()->access_version);
    }

    public static function claimCallbacks(): array
    {
        return ['withdraw after lease audit' => ['withdraw'], 'password after lease audit' => ['password'],
            'unknown claim after lease audit' => ['attempt']];
    }

    #[DataProvider('claimCallbacks')]
    public function test_claim_callbacks_are_rechecked_before_any_private_capture_can_run(string $change): void
    {
        $f = F::ready();
        $before = $this->graph();
        $this->withEvents(function () use ($f, $change): void {
            AuditEvent::created(function (AuditEvent $event) use ($f, $change): void {
                if ($event->action !== 'notification.test.lease_claimed') {
                    return;
                }
                match ($change) {
                    'withdraw' => CustomerFixtures::withdraw($f),
                    'password' => DB::table('users')->where('id', $f['user']->id)->update(['password' => Hash::make('Synthetic-callback-only')]),
                    'attempt' => DB::table('transactional_notice_attempts')->where('id', $event->subject_id)
                        ->update(['state' => 'uncertain', 'reason' => 'capture_unknown', 'finished_at' => now()]),
                };
            });
            $this->refused(fn () => app(TestTransactionalNotifications::class)->dispatch($f['notice']['notificationId']));
        });
        $this->assertSame($before, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public function test_later_retrieved_observer_cannot_make_an_earlier_customer_credential_check_stale(): void
    {
        $f = F::ready(enqueue: false);
        $before = $this->graph();
        $this->withEvents(function () use ($f): void {
            $armed = true;
            TestFulfillmentActivation::retrieved(function () use ($f, &$armed): void {
                if ($armed) {
                    $armed = false;
                    DB::table('users')->where('id', $f['user']->id)->update(['password' => Hash::make('Synthetic-later-retrieved-only')]);
                }
            });
            $this->refused(fn () => app(TestTransactionalNotifications::class)->enqueueOrderReady($f['order']->public_id, $f['principal']));
        });
        $this->assertSame($before, $this->graph());
        $this->assertSame($f['user']->password, $f['user']->fresh()->password);
    }

    public static function invalidFiles(): array
    {
        return ['symlink' => ['symlink'], 'hardlink' => ['hardlink'], 'public permissions' => ['public'], 'partial crash' => ['partial']];
    }

    #[DataProvider('invalidFiles')]
    public function test_links_wrong_permissions_or_partial_crash_files_are_never_overwritten_or_accepted(string $kind): void
    {
        $f = F::ready();
        $id = $f['notice']['notificationId'];
        $bytes = Crypt::decryptString(TransactionalNotice::sole()->capture_ciphertext);
        $root = Storage::disk('local')->path('transactional-notification-capture');
        mkdir($root, 0700);
        chmod($root, 0700);
        $outside = Storage::disk('local')->path('synthetic-outside-capture.json');
        file_put_contents($outside, $bytes);
        chmod($outside, 0600);
        $path = $root.'/'.$id.'.json';
        match ($kind) {
            'symlink' => symlink($outside, $path),
            'hardlink' => link($outside, $path),
            'public' => file_put_contents($path, $bytes),
            'partial' => file_put_contents($path, substr($bytes, 0, 20)),
        };
        if ($kind === 'public' || $kind === 'partial') {
            chmod($path, $kind === 'public' ? 0644 : 0600);
        }
        $before = file_get_contents($path);
        $service = app(TestTransactionalNotifications::class);
        $this->assertSame('uncertain', $service->dispatch($id)['state']);
        $this->assertSame($before, file_get_contents($path));
        $this->assertSame($bytes, file_get_contents($outside));
        $ledger = $this->graph();
        $this->refused(fn () => $service->reconcile($id));
        $this->assertSame($ledger, $this->graph());
        $this->assertDatabaseCount('transactional_notice_attempts', 1);
    }

    public function test_missing_or_changed_accepted_file_never_claims_success_from_database_metadata_alone(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $this->assertSame('accepted', $service->dispatch($id)['state']);
        $before = $this->graph();
        $path = Storage::disk('local')->path('transactional-notification-capture/'.$id.'.json');
        $original = file_get_contents($path);
        file_put_contents($path, $original.' ');
        $this->refused(fn () => $service->status($id));
        $this->refused(fn () => $service->dispatch($id));
        $this->assertSame($before, $this->graph());
        unlink($path);
        $this->refused(fn () => $service->status($id));
        $this->assertSame($before, $this->graph());
        $this->assertFileDoesNotExist($path);
    }

    public function test_capture_schema_has_no_arbitrary_message_link_or_marketing_extension(): void
    {
        $f = F::ready();
        $id = $f['notice']['notificationId'];
        $capture = json_decode(Crypt::decryptString(TransactionalNotice::sole()->capture_ciphertext), true, 8, JSON_THROW_ON_ERROR);
        foreach (['url' => 'https://example.invalid/proof', 'message' => 'arbitrary', 'marketingConsent' => true] as $key => $value) {
            $changed = $capture;
            $changed['payload'][$key] = $value;
            $this->refused(fn () => app(PrivateNotificationCapture::class)->store($id, CanonicalJson::encode($changed)));
        }
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
        $this->assertDatabaseCount('transactional_notice_attempts', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_uuid_aliases_and_outer_transactions_cannot_enter_any_claim_or_enqueue_path(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $before = $this->graph();
        foreach ([strtoupper($id), $id.' ', "\t".$id] as $alias) {
            $this->refused(fn () => $service->claim($alias));
            $this->refused(fn () => $service->status($alias));
            $this->refused(fn () => $service->reconcile($alias));
        }
        $this->refused(fn () => $service->enqueueOrderReady(strtoupper($f['order']->public_id), $f['principal']));
        DB::beginTransaction();
        try {
            $this->refused(fn () => $service->claim($id));
            $this->refused(fn () => $service->enqueueOrderReady($f['order']->public_id, $f['principal']));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    private function refused(callable $call): void
    {
        try {
            $call();
        } catch (NotificationException|CustomerAccessException|\LogicException) {
            $this->assertTrue(true);

            return;
        }
        $this->fail('Unsafe notification operation was accepted.');
    }

    public static function postQueryChanges(): array
    {
        return ['withdrawal after framework proof' => ['withdraw'], 'recipient after framework proof' => ['recipient'],
            'attempt range after framework proof' => ['attempt'], 'policy after framework proof' => ['policy'],
            'activation account after framework proof' => ['activation_account']];
    }

    #[DataProvider('postQueryChanges')]
    public function test_query_callbacks_after_the_framework_range_proof_cannot_commit_an_intent_with_changed_authority_or_attempts(string $change): void
    {
        $f = F::ready(enqueue: false);
        $before = $this->graph();
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $events);
        $triggered = false;
        $denied = null;
        try {
            $connection->listen(function (QueryExecuted $query) use ($f, $change, &$triggered): void {
                if ($triggered || ! str_starts_with($query->sql, 'select ') || ! str_contains($query->sql, 'transactional_notice_attempts')) {
                    return;
                }
                $triggered = true;
                match ($change) {
                    'withdraw' => CustomerFixtures::withdraw($f),
                    'recipient' => DB::table('users')->where('id', $f['user']->id)->update(['email' => 'notification-mutated@example.invalid']),
                    'policy' => config(['transactional-notifications.test_enabled' => false]),
                    'activation_account' => config(['payments.stripe.account_id' => 'acct_NOTIFICATIONCHANGED']),
                    'attempt' => DB::table('transactional_notice_attempts')->insert(['public_id' => (string) Str::uuid(),
                        'notice_id' => DB::table('transactional_notices')->sole()->id, 'number' => 1, 'token_hash' => str_repeat('a', 64),
                        'started_at' => now(), 'lease_expires_at' => now()->addSeconds(30), 'state' => 'leased',
                        'reason' => null, 'receipt_hash' => null, 'finished_at' => null]),
                };
            });
            try {
                app(TestTransactionalNotifications::class)->enqueueOrderReady($f['order']->public_id, $f['principal']);
            } catch (NotificationException|CustomerAccessException $error) {
                $denied = $error::class;
            }
        } finally {
            $connection->setEventDispatcher($events);
        }
        $this->assertTrue($triggered, 'The actual framework QueryExecuted mutation was never reached.');
        if ($denied === null) {
            echo json_encode(['post_framework_notification_probe' => ['mutation' => $change, 'triggered' => $triggered,
                'committed_notices' => DB::table('transactional_notices')->count(),
                'committed_attempts' => DB::table('transactional_notice_attempts')->count(),
                'current_access_version' => $f['account']->fresh()->access_version]], JSON_THROW_ON_ERROR).PHP_EOL;
        }
        $this->assertNotNull($denied, 'Framework callback changes committed notification work after the preceding proof.');
        $this->assertSame($before, $this->graph());
        $this->assertSame($f['user']->email, $f['user']->fresh()->email);
        $this->assertTrue($f['account']->fresh()->active);
        $this->assertSame(1, $f['account']->fresh()->access_version);
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
    }

    public static function postQueryOperations(): array
    {
        return ['claim withdrawal' => ['claim', 'withdraw', 2], 'claim cursor transition' => ['claim', 'cursor', 2],
            'completion withdrawal' => ['complete', 'withdraw', 2], 'reconciliation withdrawal' => ['reconcile', 'withdraw', 4],
            'status withdrawal' => ['status', 'withdraw', 2]];
    }

    #[DataProvider('postQueryOperations')]
    public function test_every_existing_intent_operation_ends_with_an_observer_free_authority_and_claim_proof(string $operation, string $change, int $targetRead): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        if (in_array($operation, ['complete', 'reconcile'], true)) {
            $lease = $service->claim($id);
            $stored = app(PrivateNotificationCapture::class)->store($id, $lease->capture());
            if ($operation === 'reconcile') {
                $this->travel(31)->seconds();
            }
        }
        $before = $this->graph();
        $files = Storage::disk('local')->allFiles('transactional-notification-capture');
        $fileHashes = array_map(fn ($path) => hash_file('sha256', Storage::disk('local')->path($path)), $files);
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $events);
        $reads = 0;
        $triggered = false;
        $denied = null;
        try {
            $connection->listen(function (QueryExecuted $query) use ($f, $change, $targetRead, &$reads, &$triggered): void {
                if ($triggered || ! str_starts_with($query->sql, 'select ') || ! str_contains($query->sql, 'transactional_notice_attempts')
                    || ! str_contains($query->sql, 'order by') || str_contains($query->sql, ' desc')) {
                    return;
                }
                if (++$reads !== $targetRead) {
                    return;
                }
                $triggered = true;
                if ($change === 'cursor') {
                    DB::table('transactional_notice_attempts')->update(['state' => 'uncertain', 'reason' => 'capture_unknown', 'finished_at' => now()]);
                } else {
                    CustomerFixtures::withdraw($f);
                }
            });
            try {
                match ($operation) {
                    'claim' => $service->claim($id),
                    'complete' => $service->complete($lease, $stored['receiptHash']),
                    'reconcile' => $service->reconcile($id),
                    'status' => $service->status($id),
                };
            } catch (NotificationException|CustomerAccessException $error) {
                $denied = $error::class;
            }
        } finally {
            $connection->setEventDispatcher($events);
        }
        $this->assertTrue($triggered, 'The actual final framework range/cursor callback was never reached.');
        $this->assertNotNull($denied, 'An existing-intent operation returned after its framework callback changed the final proof.');
        $this->assertSame($before, $this->graph());
        $this->assertTrue($f['account']->fresh()->active);
        $this->assertSame(1, $f['account']->fresh()->access_version);
        $this->assertSame($files, Storage::disk('local')->allFiles('transactional-notification-capture'));
        $this->assertSame($fileHashes, array_map(fn ($path) => hash_file('sha256', Storage::disk('local')->path($path)), $files));
    }

    private function withEvents(callable $call): void
    {
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        try {
            $call();
        } finally {
            User::setEventDispatcher($dispatcher);
        }
    }

    private function graph(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ['transactional_notices', 'transactional_notice_attempts', 'audit_events']);
    }
}
