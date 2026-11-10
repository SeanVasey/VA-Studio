<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Mail\TransactionalMailPolicy;
use App\Domain\Notifications\Models\TransactionalMailDelivery;
use App\Domain\Notifications\NotificationException;
use App\Filament\Resources\StaffMailDeliveryResource\Pages\ListStaffMailDeliveries;
use App\Jobs\DeliverTransactionalMail;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\MailFake;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\TransactionalMailFixtures as M;
use Tests\Support\TransactionalNotificationFixtures as N;
use Tests\TestCase;

/**
 * BL-47 (FP-075, DD-43): the paid-but-email-failed recovery path and the transport shape. Fake transport only; every address,
 * host and order is synthetic. The order stays reachable from the customer's account whatever happens to the email.
 */
class TransactionalMailRequeueTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob(storage_path('framework/testing/mail-preflight-*'), GLOB_ONLYDIR) ?: [] as $directory) {
                (new Filesystem)->deleteDirectory($directory);
            }
        });
    }

    private function staff(): User
    {
        $staff = LicenseFixtures::admin();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $staff;
    }

    private function runQueuedMail(): int
    {
        $ran = 0;
        foreach (Queue::pushed(DeliverTransactionalMail::class) as $job) {
            app()->call([$job, 'handle']);
            $ran++;
        }
        Queue::fake();

        return $ran;
    }

    /** Queue one delivery, then change the declared sender so the delivery fails with configuration_changed before any transport. */
    private function failedDelivery(string $notice): TransactionalMailDelivery
    {
        $adapter = app(TransactionalMailAdapter::class);
        $id = $adapter->queueOrderReady($notice)['deliveryId'];
        M::configure(['sender' => 'receipts@example.com']);
        config(['transactional-mail.sender' => 'receipts@example.com']);
        $this->assertSame('failed', $adapter->deliver($id)['state']);
        Mail::assertNothingOutgoing();
        // Restore the admitted configuration so a requeue is allowed.
        M::configure();
        Queue::fake();

        return TransactionalMailDelivery::where('public_id', $id)->sole();
    }

    public function test_shipped_defaults_send_nothing_and_a_requeue_is_refused_without_rows(): void
    {
        Mail::fake();
        $f = N::ready();
        $staff = $this->staff();
        $this->assertFalse(config('transactional-mail.enabled'));
        $this->assertSame(['smtps', 465, null], [config('mail.mailers.transactional.scheme'), config('mail.mailers.transactional.port'), config('mail.mailers.transactional.host')]);
        $this->artisan('vasey:transactional-mail-queue')->expectsOutput('refused=mail_not_configured')->assertFailed();
        try {
            app(TransactionalMailAdapter::class)->requeueOrderReady($f['notice']['notificationId'], $staff);
            $this->fail('A requeue was admitted with the shipped defaults.');
        } catch (NotificationException $error) {
            $this->assertSame('mail_not_configured', $error->reason);
        }
        $this->assertDatabaseCount('transactional_mail_deliveries', 0);
        Mail::assertNothingOutgoing();
        Queue::assertNotPushed(DeliverTransactionalMail::class);
    }

    public function test_production_admits_only_implicit_tls_on_port_465(): void
    {
        Mail::fake();
        N::ready();
        $policy = app(TransactionalMailPolicy::class);
        $this->app['env'] = 'production';
        try {
            $this->assertProductionTransport($policy);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    private function assertProductionTransport(TransactionalMailPolicy $policy): void
    {
        // Port 587 with STARTTLS passes the preflight binding elsewhere, but production refuses it.
        foreach ([[[], []], [['port' => 465, 'security' => 'starttls'], ['port' => 465]], [['port' => 587, 'security' => 'implicit_tls'], ['scheme' => 'smtps']]] as [$planned, $mailer]) {
            M::configure($planned, $mailer);
            try {
                $policy->configuration();
                $this->fail('Production admitted a transport other than implicit TLS on 465.');
            } catch (NotificationException $error) {
                $this->assertSame('mail_not_configured', $error->reason);
            }
        }
        M::configure(['port' => 465, 'security' => 'implicit_tls'], ['port' => 465, 'scheme' => 'smtps']);
        $this->assertSame(M::MAILER, $policy->configuration()['mailer']);
    }

    public function test_a_failed_message_is_recorded_requeued_and_sent_exactly_once(): void
    {
        Mail::fake();
        $f = N::ready();
        M::configure();
        $staff = $this->staff();
        $adapter = app(TransactionalMailAdapter::class);
        $failed = $this->failedDelivery($f['notice']['notificationId']);
        $this->assertSame(['failed', 'configuration_changed', 1], [$failed->state, $failed->reason, $failed->attempt]);

        $requeued = $adapter->requeueOrderReady($f['notice']['notificationId'], $staff);
        $this->assertSame([false, 'queued', 2], [$requeued['replayed'], $requeued['state'], $requeued['attempt']]);
        Mail::assertNothingOutgoing();
        $this->assertSame(1, $this->runQueuedMail());
        Mail::assertSentCount(1);
        $this->assertSame(['failed', 'accepted'], TransactionalMailDelivery::orderBy('id')->pluck('state')->all());

        // A second requeue, a repeated job and the batch command never produce another message.
        try {
            $adapter->requeueOrderReady($f['notice']['notificationId'], $staff);
            $this->fail('A delivered message was requeued.');
        } catch (NotificationException $error) {
            $this->assertSame('already_delivered', $error->reason);
        }
        $adapter->deliver($requeued['deliveryId']);
        $this->artisan('vasey:transactional-mail-queue')->expectsOutput('queued=0 skipped=0 superseded=0')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame(2, TransactionalMailDelivery::count());
        $audit = AuditEvent::where('action', 'notification.mail.requeued')->sole();
        $this->assertSame($staff->id, $audit->actor_id);
        $this->assertSame($failed->public_id, $audit->context['previous_delivery_id']);
        $this->assertSame(1, AuditEvent::where('action', 'notification.mail.requeued')->count());
    }

    public function test_a_second_requeue_while_the_first_is_pending_returns_it_and_queues_no_second_job(): void
    {
        Mail::fake();
        $f = N::ready();
        M::configure();
        $staff = $this->staff();
        $adapter = app(TransactionalMailAdapter::class);
        $this->failedDelivery($f['notice']['notificationId']);

        $first = $adapter->requeueOrderReady($f['notice']['notificationId'], $staff);
        $second = $adapter->requeueOrderReady($f['notice']['notificationId'], $staff);
        $this->assertSame([$first['deliveryId'], true], [$second['deliveryId'], $second['replayed']]);
        Queue::assertPushed(DeliverTransactionalMail::class, 1);
        $this->assertSame(2, TransactionalMailDelivery::count());
        $this->assertSame(1, $this->runQueuedMail());
        Mail::assertSentCount(1);
    }

    public function test_a_delivered_message_is_never_requeued(): void
    {
        Mail::fake();
        $f = N::ready();
        M::configure();
        $staff = $this->staff();
        $adapter = app(TransactionalMailAdapter::class);
        $adapter->queueOrderReady($f['notice']['notificationId']);
        $this->runQueuedMail();
        Mail::assertSentCount(1);
        try {
            $adapter->requeueOrderReady($f['notice']['notificationId'], $staff, true);
            $this->fail('A delivered message was requeued.');
        } catch (NotificationException $error) {
            $this->assertSame('already_delivered', $error->reason);
        }
        $this->assertSame(1, TransactionalMailDelivery::count());
        $this->assertSame(0, AuditEvent::where('action', 'notification.mail.requeued')->count());
        Mail::assertSentCount(1);
    }

    public function test_an_uncertain_message_needs_the_provider_log_statement_and_the_audit_records_it(): void
    {
        $f = N::ready();
        M::configure();
        $staff = $this->staff();
        Mail::swap(new class(app(MailManager::class)) extends MailFake
        {
            public int $attempts = 0;

            protected function sendMail($view, $shouldQueue = false)
            {
                $this->attempts++;
                throw new RuntimeException('Synthetic SMTP failure');
            }
        });
        $adapter = app(TransactionalMailAdapter::class);
        $id = $adapter->queueOrderReady($f['notice']['notificationId'])['deliveryId'];
        $this->runQueuedMail();
        $this->assertSame('uncertain', $adapter->status($id)['state']);

        try {
            $adapter->requeueOrderReady($f['notice']['notificationId'], $staff);
            $this->fail('An uncertain message was requeued without the provider-log statement.');
        } catch (NotificationException $error) {
            $this->assertSame('uncertain_unchecked', $error->reason);
        }
        $this->assertSame(1, TransactionalMailDelivery::count());
        $result = $adapter->requeueOrderReady($f['notice']['notificationId'], $staff, true);
        $this->assertSame(2, $result['attempt']);
        $this->assertTrue(AuditEvent::where('action', 'notification.mail.requeued')->sole()->context['provider_log_checked']);
        // The new attempt is a single further transport call; the uncertain row is untouched.
        $this->assertSame(1, $this->runQueuedMail());
        $this->assertSame(2, Mail::getFacadeRoot()->attempts);
        $this->assertSame('uncertain', $adapter->status($id)['state']);
    }

    public function test_the_order_page_is_reachable_from_the_account_without_the_email(): void
    {
        Mail::fake();
        $f = N::ready();
        M::configure();
        $this->failedDelivery($f['notice']['notificationId']);
        Mail::assertNothingOutgoing();

        $this->postJson('/account/sign-in', ['email' => $f['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
        $order = $f['order']->public_id;
        $this->getJson('/orders/history')->assertOk()->assertJsonPath('history.orders.0.id', $order);
        $this->getJson('/orders/'.$order.'/status')->assertOk()->assertJsonPath('order.paymentStatus', 'verified');
        $this->getJson('/orders/'.$order.'/items')->assertOk()->assertJsonPath('items.orderId', $order);
        $this->get('/orders/'.$order.'/delivery', ['Accept' => 'application/json'])->assertOk()->assertJsonPath('delivery.orderId', $order);
        Mail::assertNothingOutgoing();
    }

    public function test_non_staff_and_staff_without_mfa_are_refused_and_nothing_is_queued(): void
    {
        Mail::fake();
        $f = N::ready();
        M::configure();
        $staff = $this->staff();
        $adapter = app(TransactionalMailAdapter::class);
        $this->failedDelivery($f['notice']['notificationId']);

        try {
            $adapter->requeueOrderReady($f['notice']['notificationId'], $f['user']);
            $this->fail('A customer requeued an email.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            try {
                $adapter->requeueOrderReady($f['notice']['notificationId'], $staff);
                $this->fail('Staff without enrolled MFA requeued an email.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Admin multi-factor authentication is required.', $exception->getMessage());
            }
            $this->actingAs($staff);
            Livewire::test(ListStaffMailDeliveries::class)->assertForbidden();
            $this->assertSame(1, TransactionalMailDelivery::count());
            Queue::assertNotPushed(DeliverTransactionalMail::class);

            $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->assertSame(2, $adapter->requeueOrderReady($f['notice']['notificationId'], $staff)['attempt']);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_the_staff_action_requeues_a_failed_message_and_is_not_offered_for_an_accepted_one(): void
    {
        Mail::fake();
        $f = N::ready();
        M::configure();
        $staff = $this->staff();
        $failed = $this->failedDelivery($f['notice']['notificationId']);
        $this->actingAs($staff);

        Livewire::test(ListStaffMailDeliveries::class)->assertCanSeeTableRecords([$failed])
            ->assertTableActionVisible('requeue', $failed)->callTableAction('requeue', $failed)->assertNotified('Email requeued');
        $this->assertSame(2, TransactionalMailDelivery::count());
        $this->assertSame(1, $this->runQueuedMail());
        Mail::assertSentCount(1);

        $accepted = TransactionalMailDelivery::orderByDesc('id')->firstOrFail();
        $this->assertSame('accepted', $accepted->state);
        Livewire::test(ListStaffMailDeliveries::class)->assertTableActionHidden('requeue', $accepted)->assertTableActionHidden('requeue', $failed);
        $this->assertSame(1, AuditEvent::where('action', 'notification.mail.requeued')->count());
        $this->assertSame(2, DB::table('transactional_mail_deliveries')->count());
    }
}
