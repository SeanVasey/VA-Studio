<?php

namespace App\Domain\Notifications\Mail;

use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\QuoteException;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Notifications\Models\TransactionalMailDelivery;
use App\Domain\Notifications\Models\TransactionalNotice;
use App\Domain\Notifications\NotificationException;
use App\Domain\Notifications\TestTransactionalNotifications;
use App\Domain\Notifications\TransactionalNotificationPolicy;
use App\Jobs\DeliverTransactionalMail;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns private order-ready intents into at most one queued email each (FP-075). Default-off: nothing is recorded or sent
 * unless TransactionalMailPolicy admits the configuration. A delivery leaves "queued" exactly once; an uncertain transport
 * outcome is never retried automatically. Logs carry identifiers and exception classes only.
 */
final class TransactionalMailAdapter
{
    /** @return array{deliveryId: string, state: string, replayed: bool} */
    public function queueOrderReady(string $noticeId): array
    {
        $this->outside();
        $configuration = app(TransactionalMailPolicy::class)->configuration();
        if (! OrderRequest::uuid($noticeId)) {
            throw new NotificationException;
        }
        // The intent must still verify against current customer and purchase authority before anything is recorded.
        $this->verifyAuthority($noticeId);

        return DB::transaction(function () use ($noticeId, $configuration): array {
            $notice = TransactionalNotice::where('public_id', $noticeId)->lockForUpdate()->first();
            if ($notice === null || ! hash_equals($notice->public_id, $noticeId) || $notice->notification_type !== 'test_order_ready') {
                throw new NotificationException;
            }
            $existing = TransactionalMailDelivery::where('notice_id', $notice->id)->orderByDesc('id')->lockForUpdate()->first();
            if ($existing !== null) {
                return ['deliveryId' => $existing->public_id, 'state' => $existing->state, 'replayed' => true];
            }
            $delivery = TransactionalMailDelivery::create([
                'public_id' => (string) Str::uuid(), 'notice_id' => $notice->id, 'notice_public_id' => $notice->public_id,
                'template_version' => TransactionalMailPolicy::TEMPLATE, 'capability' => $configuration['capability'],
                'state' => 'queued', 'version' => 0, 'queued_at' => $this->now(),
            ]);
            AuditEvent::recordAttributed('notification.mail.queued', $delivery, ['delivery_id' => $delivery->public_id,
                'notice_id' => $notice->public_id, 'template' => TransactionalMailPolicy::TEMPLATE], null);
            DeliverTransactionalMail::dispatch($delivery->public_id);

            return ['deliveryId' => $delivery->public_id, 'state' => 'queued', 'replayed' => false];
        }, 3);
    }

    /**
     * Staff recovery for an order whose confirmation email did not go out (BL-47). A requeue is a NEW delivery attempt, never a
     * revival of the old row (the delivery guards keep rows forward-only). It is idempotent and cannot duplicate a send:
     *  - any earlier attempt "accepted" refuses (`already_delivered`); the message was handed to the provider;
     *  - a latest attempt still "queued" or "submitting" returns it as `replayed` and creates nothing;
     *  - only a latest "failed" attempt (it failed before any transport call) is requeued;
     *  - an "uncertain" attempt may have been accepted by the provider, so it is requeued only when the operator states that
     *    the provider's logs show it was not delivered ($uncertainChecked), and the audit event records that statement.
     * Needs a staff user with the admin panel's MFA enrollment (re-read under lock), the same admitted mail configuration as the
     * first send, and an intent that still verifies against current customer and purchase authority.
     *
     * @return array{deliveryId: string, state: string, replayed: bool, attempt: int}
     */
    public function requeueOrderReady(string $noticeId, User $actor, bool $uncertainChecked = false): array
    {
        $this->outside();
        $this->authorizeStaff($actor);
        $configuration = app(TransactionalMailPolicy::class)->configuration();
        if (! OrderRequest::uuid($noticeId)) {
            throw new NotificationException;
        }
        $this->verifyAuthority($noticeId);

        return DB::transaction(function () use ($noticeId, $actor, $configuration, $uncertainChecked): array {
            $staff = User::query()->lockForUpdate()->find($actor->getKey());
            if ($staff === null) {
                throw new AuthorizationException;
            }
            $this->authorizeStaff($staff, true);
            $notice = TransactionalNotice::where('public_id', $noticeId)->lockForUpdate()->first();
            if ($notice === null || ! hash_equals($notice->public_id, $noticeId) || $notice->notification_type !== 'test_order_ready') {
                throw new NotificationException;
            }
            $attempts = TransactionalMailDelivery::where('notice_id', $notice->id)->orderByDesc('id')->lockForUpdate()->get();
            $latest = $attempts->first();
            if ($latest === null) {
                // Nothing was ever queued for this intent: the normal first send applies, not a requeue.
                throw new NotificationException('no_delivery');
            }
            if ($attempts->contains(fn (TransactionalMailDelivery $row): bool => $row->state === 'accepted')) {
                throw new NotificationException('already_delivered');
            }
            if (in_array($latest->state, ['queued', 'submitting'], true)) {
                return ['deliveryId' => $latest->public_id, 'state' => $latest->state, 'replayed' => true, 'attempt' => $latest->attempt];
            }
            if ($latest->state === 'uncertain' && ! $uncertainChecked) {
                throw new NotificationException('uncertain_unchecked');
            }
            $delivery = TransactionalMailDelivery::create([
                'public_id' => (string) Str::uuid(), 'notice_id' => $notice->id, 'notice_public_id' => $notice->public_id,
                'template_version' => TransactionalMailPolicy::TEMPLATE, 'capability' => $configuration['capability'],
                'state' => 'queued', 'version' => 0, 'queued_at' => $this->now(), 'attempt' => $latest->attempt + 1,
            ]);
            AuditEvent::recordAttributed('notification.mail.requeued', $delivery, ['delivery_id' => $delivery->public_id,
                'notice_id' => $notice->public_id, 'previous_delivery_id' => $latest->public_id, 'previous_state' => $latest->state,
                'previous_reason' => $latest->reason, 'attempt' => $delivery->attempt, 'provider_log_checked' => $latest->state === 'uncertain',
            ], $staff->getKey());
            DeliverTransactionalMail::dispatch($delivery->public_id);

            return ['deliveryId' => $delivery->public_id, 'state' => 'queued', 'replayed' => false, 'attempt' => $delivery->attempt];
        }, 3);
    }

    private function authorizeStaff(User $user, bool $locked = false): void
    {
        $current = $locked ? $user : ($user->exists ? User::find($user->getKey()) : null);
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [$locked]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: $locked)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
    }

    /** Request the original wake-up only for an observed queued row; a racing claim makes a harmless duplicate job. */
    public function wakeQueued(string $deliveryId): bool
    {
        $this->outside();
        app(TransactionalMailPolicy::class)->configuration();
        if (! OrderRequest::uuid($deliveryId)) {
            throw new NotificationException;
        }
        $delivery = TransactionalMailDelivery::where('public_id', $deliveryId)->where('state', 'queued')->first();
        if ($delivery === null || ! hash_equals($delivery->public_id, $deliveryId)) {
            return false;
        }
        // No delivery mutation, recipient read or broker certainty. The unchanged job rechecks current authority.
        DeliverTransactionalMail::dispatch($delivery->public_id);

        return true;
    }

    /** Queue handler. Sends only from "queued", after claiming "submitting" in a committed transaction. */
    public function deliver(string $deliveryId): array
    {
        $this->outside();
        if (! OrderRequest::uuid($deliveryId)) {
            throw new NotificationException;
        }
        try {
            $configuration = app(TransactionalMailPolicy::class)->configuration();
        } catch (NotificationException) {
            return $this->transition($deliveryId, 'queued', 'failed', 'not_configured');
        }
        $claimed = DB::transaction(function () use ($deliveryId, $configuration): ?array {
            $delivery = TransactionalMailDelivery::where('public_id', $deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->state !== 'queued') {
                return null;
            }
            if (! hash_equals($delivery->capability, $configuration['capability'])) {
                $this->apply($delivery, 'failed', 'configuration_changed');

                return null;
            }
            $this->apply($delivery, 'submitting');

            return ['notice' => $delivery->notice_public_id, 'notice_id' => $delivery->notice_id];
        }, 3);
        if ($claimed === null) {
            return $this->status($deliveryId);
        }
        try {
            // Withdrawn customer access, changed purchase evidence or a disabled intent policy refuse before any transport.
            $this->verifyAuthority($claimed['notice']);
            $intent = $this->intent($claimed['notice_id'], $claimed['notice']);
        } catch (NotificationException $error) {
            return $this->transition($deliveryId, 'submitting', 'failed', $error->reason === 'intent_unreadable' ? 'intent_unreadable' : 'authority_withdrawn');
        }
        try {
            Mail::mailer($configuration['mailer'])->to($intent['email'])
                ->send(new OrderReadyMail($intent['order'], $configuration['sender'], $deliveryId));
        } catch (Throwable $error) {
            // A transport error after the attempt began cannot prove the provider did not accept the message.
            Log::warning('Transactional mail outcome is uncertain.', ['delivery_id' => $deliveryId, 'exception_class' => $error::class]);

            return $this->transition($deliveryId, 'submitting', 'uncertain', 'transport_uncertain');
        }

        return $this->transition($deliveryId, 'submitting', 'accepted', null, CanonicalJson::hash([
            'delivery_id' => $deliveryId, 'template' => TransactionalMailPolicy::TEMPLATE, 'order' => $intent['order'],
            'capability' => $configuration['capability'],
        ]));
    }

    public function status(string $deliveryId): array
    {
        $delivery = TransactionalMailDelivery::where('public_id', $deliveryId)->first();
        if ($delivery === null || ! hash_equals($delivery->public_id, $deliveryId)) {
            throw new NotificationException;
        }

        return ['deliveryId' => $delivery->public_id, 'state' => $delivery->state, 'reason' => $delivery->reason];
    }

    /**
     * Re-verifies the intent against current customer and purchase authority. The customer, purchase and delivery checks behind
     * it refuse with their own domain exceptions (email no longer verified, account became staff, a claim or purchase that no
     * longer verifies, activation evidence changed); those are per-intent authority refusals, reported as one. Anything else
     * (a database error, a defect) is not relabelled and propagates.
     */
    private function verifyAuthority(string $noticeId): void
    {
        try {
            app(TestTransactionalNotifications::class)->status($noticeId);
        } catch (CustomerAccessException|QuoteException|DeliveryException) {
            throw new NotificationException('authority_withdrawn');
        }
    }

    /** @return array{email: string, order: string} the verified recipient and order reference from the encrypted intent */
    private function intent(int $noticeId, string $noticePublicId): array
    {
        $raw = DB::table('transactional_notices')->where('id', $noticeId)->first(['public_id', 'capture_ciphertext', 'capture_hash']);
        try {
            if ($raw === null || ! hash_equals($raw->public_id, $noticePublicId) || strlen($raw->capture_ciphertext) > 16384
                || ! hash_equals($raw->capture_hash, hash('sha256', $raw->capture_ciphertext))) {
                throw new NotificationException('intent_unreadable');
            }
            $capture = json_decode(Crypt::decryptString($raw->capture_ciphertext), true, 8, JSON_THROW_ON_ERROR);
        } catch (NotificationException $error) {
            throw $error;
        } catch (Throwable) {
            throw new NotificationException('intent_unreadable');
        }
        $email = $capture['recipient']['email'] ?? null;
        $order = $capture['payload']['order_id'] ?? null;
        if (($capture['notification_id'] ?? null) !== $noticePublicId || ($capture['payload']['type'] ?? null) !== 'test_order_ready'
            || ($capture['payload']['template_version'] ?? null) !== TransactionalNotificationPolicy::TEMPLATE
            || ! is_string($email) || CustomerIdentityPolicy::email($email) !== $email || ! is_string($order) || ! OrderRequest::uuid($order)) {
            throw new NotificationException('intent_unreadable');
        }

        return ['email' => $email, 'order' => $order];
    }

    private function transition(string $deliveryId, string $from, string $state, ?string $reason, ?string $hash = null): array
    {
        DB::transaction(function () use ($deliveryId, $from, $state, $reason, $hash): void {
            $delivery = TransactionalMailDelivery::where('public_id', $deliveryId)->lockForUpdate()->first();
            if ($delivery !== null && $delivery->state === $from) {
                $this->apply($delivery, $state, $reason, $hash);
            }
        }, 3);

        return $this->status($deliveryId);
    }

    private function apply(TransactionalMailDelivery $delivery, string $state, ?string $reason = null, ?string $hash = null): void
    {
        $at = $this->now();
        $delivery->forceFill(['state' => $state, 'reason' => $reason, 'message_hash' => $hash, 'version' => $delivery->version + 1]
            + ($state === 'submitting' ? ['started_at' => $at] : ['finished_at' => $at]))->save();
        AuditEvent::recordAttributed('notification.mail.'.$state, $delivery, ['delivery_id' => $delivery->public_id]
            + ($reason === null ? [] : ['reason' => $reason]) + ($hash === null ? [] : ['message_hash' => $hash]), null);
    }

    private function now(): CarbonImmutable
    {
        return now()->toImmutable()->utc()->startOfSecond();
    }

    private function outside(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new NotificationException('outer_transaction');
            }
        }
    }
}
