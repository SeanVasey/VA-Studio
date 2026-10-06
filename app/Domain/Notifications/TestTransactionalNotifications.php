<?php

namespace App\Domain\Notifications;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Domain\Customers\PurchaseAccess;
use App\Domain\Delivery\ActivationPolicy;
use App\Domain\Delivery\DeliveryAccessEvidence;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Notifications\Models\TransactionalNotice;
use App\Domain\Notifications\Models\TransactionalNoticeAttempt;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/** Test-only durable notification work; producers, live mail and marketing remain disabled. */
final class TestTransactionalNotifications
{
    public function enqueueOrderReady(string $orderId, CustomerPrincipal $principal): array
    {
        $this->outside();
        if (! OrderRequest::uuid($orderId)) {
            throw new NotificationException;
        }

        return DB::transaction(function () use ($orderId, $principal): array {
            $context = $this->authority($principal, $orderId);
            $payload = $this->payload($context);
            $recipient = ['email' => $context['email']];
            $event = 'test_order_ready:'.$context['activation']->public_id.':'.$context['account']->public_id;
            $request = $this->hmac(['event_key' => $event, 'recipient' => $recipient, 'payload' => $payload,
                'policy_version' => TransactionalNotificationPolicy::VERSION]);
            $notice = TransactionalNotice::where('event_key', $event)->lockForUpdate()->first();
            if ($notice !== null) {
                $this->verifyNotice($notice, $context);
                if (! hash_equals($notice->event_key, $event) || ! hash_equals($notice->request_hmac, $request)) {
                    throw new NotificationException('conflict');
                }
                $attempts = $this->attemptRows($notice->id);
                $this->fence($context, $notice, $notice->getRawOriginal(), $attempts);

                return $this->enqueued($notice, replayed: true);
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            $id = (string) Str::uuid();
            $capture = CanonicalJson::encode(['schema_version' => 1, 'purpose' => 'test_transactional_notification_capture',
                'notification_id' => $id, 'recipient' => $recipient, 'payload' => $payload]);
            $ciphertext = Crypt::encryptString($capture);
            $columns = ['public_id' => $id, 'event_key' => $event, 'notification_type' => 'test_order_ready',
                'account_id' => $context['account']->id, 'user_id' => $context['user']->id,
                'access_version' => $principal->accessVersion, 'order_id' => $context['order']->id,
                'activation_id' => $context['activation']->id, 'claim_id' => $context['claim']?->id,
                'policy_version' => TransactionalNotificationPolicy::VERSION, 'canonicalization_version' => CanonicalJson::VERSION,
                'capture_ciphertext' => $ciphertext, 'capture_hash' => hash('sha256', $ciphertext),
                'recipient_hmac' => $this->hmac($recipient), 'payload_hash' => CanonicalJson::hash($payload),
                'request_hmac' => $request, 'created_at' => $at];
            $notice = TransactionalNotice::create($columns);
            $this->audit('intent_enqueued', $notice, ['payload_hash' => $notice->payload_hash], $principal->userId);
            $this->verifyNotice($notice, $context);
            $this->fence($context, $notice, $columns + ['id' => $notice->id], []);

            return $this->enqueued($notice, replayed: false);
        }, 5);
    }

    /** One claim at a time. Expiration is ambiguous and never authorizes a replacement lease. */
    public function claim(string $notificationId, bool $retryKnownFailure = false): ?NotificationLease
    {
        return $this->locked($notificationId, function (array $context, TransactionalNotice $notice) use ($retryKnownFailure): ?NotificationLease {
            $expectedAttempts = $this->attemptRows($notice->id);
            $prior = TransactionalNoticeAttempt::where('notice_id', $notice->id)->orderByDesc('number')->lockForUpdate()->first();
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($prior !== null) {
                $this->verifyAttempt($prior);
                if ($prior->state === 'leased' && $prior->lease_expires_at->lessThanOrEqualTo($at)) {
                    $changed = $this->transition($prior, 'uncertain', 'lease_expired', null, $at);
                    $expectedAttempts = $this->replaceAttempt($expectedAttempts, $prior->id, $changed);
                    $this->audit('capture_uncertain', $prior, ['reason' => 'lease_expired'], null);
                    $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

                    return null;
                }
                if ($prior->state !== 'failed' || ! $retryKnownFailure || $prior->number >= TransactionalNotificationPolicy::MAX_ATTEMPTS) {
                    $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

                    return null;
                }
                if ($at->lessThan($prior->finished_at)) {
                    throw new NotificationException;
                }
            }
            $token = bin2hex(random_bytes(32));
            $columns = ['public_id' => (string) Str::uuid(), 'notice_id' => $notice->id,
                'number' => ($prior?->number ?? 0) + 1, 'token_hash' => hash('sha256', $token),
                'started_at' => $at, 'lease_expires_at' => $at->addSeconds(TransactionalNotificationPolicy::LEASE_SECONDS),
                'state' => 'leased', 'reason' => null, 'receipt_hash' => null, 'finished_at' => null];
            $before = $expectedAttempts;
            $attempt = TransactionalNoticeAttempt::create($columns);
            $this->audit('lease_claimed', $attempt, ['attempt' => $attempt->number], null);
            $this->verifyAttempt($attempt);
            $capture = $this->verifyNotice($notice, $context);
            $this->fence($context, $notice, $notice->getRawOriginal(), [...$before, $columns + ['id' => $attempt->id]]);

            return new NotificationLease($notice->public_id, $attempt->public_id, $attempt->lease_expires_at,
                $token, $capture);
        });
    }

    public function dispatch(string $notificationId, bool $retryKnownFailure = false): array
    {
        $lease = $this->claim($notificationId, $retryKnownFailure);
        if ($lease === null) {
            return $this->status($notificationId);
        }
        try {
            $result = app(PrivateNotificationCapture::class)->store($lease->notificationId, $lease->capture());
        } catch (Throwable $error) {
            Log::warning('Private test notification capture could not be confirmed.', ['exception_class' => $error::class]);

            return $this->finish($lease, 'uncertain', 'capture_unknown');
        }
        if ($result === ['status' => 'not_accepted', 'reason' => 'private_storage_refused']) {
            return $this->finish($lease, 'failed', 'private_storage_refused');
        }
        if (($result['status'] ?? null) !== 'accepted' || ! is_string($result['receiptHash'] ?? null)
            || count($result) !== 2 || ! preg_match('/\A[a-f0-9]{64}\z/D', $result['receiptHash'])) {
            return $this->finish($lease, 'uncertain', 'capture_unknown');
        }

        return $this->complete($lease, $result['receiptHash']);
    }

    /** Caller-supplied success is insufficient: inspect the fixed original bytes before the DB transition. */
    public function complete(NotificationLease $lease, string $receiptHash): array
    {
        $this->outside();
        try {
            $positive = app(PrivateNotificationCapture::class)->inspect($lease->notificationId, $lease->capture());
        } catch (Throwable $error) {
            Log::warning('Private test notification capture could not be confirmed.', ['exception_class' => $error::class]);

            return $this->finish($lease, 'uncertain', 'capture_unknown');
        }
        if ($positive === null || ! hash_equals($positive, $receiptHash)) {
            return $this->finish($lease, 'uncertain', 'capture_unknown');
        }

        return $this->locked($lease->notificationId, function (array $context, TransactionalNotice $notice) use ($lease, $positive): array {
            $expectedAttempts = $this->attemptRows($notice->id);
            $attempt = $this->leaseAttempt($notice, $lease, $context);
            if ($attempt === null) {
                $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

                return $this->result($notice, 'stale');
            }
            if ($attempt->state !== 'leased') {
                $state = $attempt->state === 'accepted' && hash_equals($attempt->receipt_hash, $positive) ? 'accepted' : 'stale';
                $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

                return $this->result($notice, $state);
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            $expired = $attempt->lease_expires_at->lessThanOrEqualTo($at);
            $changed = $this->transition($attempt, $expired ? 'uncertain' : 'accepted', $expired ? 'lease_expired' : null,
                $expired ? null : $positive, $at);
            $expectedAttempts = $this->replaceAttempt($expectedAttempts, $attempt->id, $changed);
            $this->audit($expired ? 'capture_uncertain' : 'capture_accepted', $attempt,
                $expired ? ['reason' => 'lease_expired'] : ['receipt_hash' => $positive], null);
            $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

            return $this->result($notice, $attempt->state);
        });
    }

    /** Recovery reads; it never calls store, reserves another lease or rewrites an original file. */
    public function reconcile(string $notificationId): array
    {
        $capture = $this->locked($notificationId, function (array $context, TransactionalNotice $notice): string {
            $capture = $this->verifyNotice($notice, $context);
            $this->fence($context, $notice, $notice->getRawOriginal(), $this->attemptRows($notice->id));

            return $capture;
        });
        $positive = app(PrivateNotificationCapture::class)->inspect($notificationId, $capture);
        if ($positive === null) {
            return $this->status($notificationId);
        }

        return $this->locked($notificationId, function (array $context, TransactionalNotice $notice) use ($capture, $positive): array {
            $expectedAttempts = $this->attemptRows($notice->id);
            if (! hash_equals($capture, $this->verifyNotice($notice, $context))) {
                throw new NotificationException;
            }
            $attempt = TransactionalNoticeAttempt::where('notice_id', $notice->id)->orderByDesc('number')->lockForUpdate()->first();
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($attempt === null) {
                throw new NotificationException;
            }
            $this->verifyAttempt($attempt);
            if ($attempt->state === 'accepted') {
                if (! hash_equals($attempt->receipt_hash, $positive)) {
                    throw new NotificationException;
                }
                $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

                return $this->result($notice, 'accepted');
            }
            if ($attempt->state === 'leased' && $attempt->lease_expires_at->lessThanOrEqualTo($at)) {
                $changed = $this->transition($attempt, 'uncertain', 'lease_expired', null, $at);
                $expectedAttempts = $this->replaceAttempt($expectedAttempts, $attempt->id, $changed);
                $this->audit('capture_uncertain', $attempt, ['reason' => 'lease_expired'], null);
            }
            if ($attempt->state !== 'uncertain') {
                $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

                return $this->result($notice, $attempt->state);
            }
            $changed = $this->transition($attempt, 'accepted', 'capture_reconciled', $positive, $at);
            $expectedAttempts = $this->replaceAttempt($expectedAttempts, $attempt->id, $changed);
            $this->audit('capture_reconciled', $attempt, ['receipt_hash' => $positive], null);
            $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

            return $this->result($notice, 'accepted');
        });
    }

    /** Internal operational projection only; adding an HTTP/CLI entrypoint requires its own access review. */
    public function status(string $notificationId): array
    {
        $snapshot = $this->locked($notificationId, function (array $context, TransactionalNotice $notice): array {
            $attempts = $this->attemptRows($notice->id);
            $last = end($attempts);
            $state = $last === false ? 'pending' : $last['state'];
            if ($state === 'leased' && $last['lease_expires_at'] <= now()->utc()->format('Y-m-d H:i:s')) {
                $state = 'uncertain'; // Observation does not silently create a replacement claim.
            }
            $capture = $this->verifyNotice($notice, $context);
            $this->fence($context, $notice, $notice->getRawOriginal(), $attempts);

            return ['result' => $this->result($notice, $state), 'capture' => $capture,
                'receipt' => $state === 'accepted' ? $last['receipt_hash'] : null];
        });
        if ($snapshot['result']['state'] === 'accepted') {
            $positive = app(PrivateNotificationCapture::class)->inspect($notificationId, $snapshot['capture']);
            if ($positive === null || ! hash_equals($snapshot['receipt'], $positive)) {
                throw new NotificationException;
            }
        }

        return $snapshot['result'];
    }

    private function finish(NotificationLease $lease, string $state, string $reason): array
    {
        return $this->locked($lease->notificationId, function (array $context, TransactionalNotice $notice) use ($lease, $state, $reason): array {
            $expectedAttempts = $this->attemptRows($notice->id);
            $attempt = $this->leaseAttempt($notice, $lease, $context);
            if ($attempt === null || $attempt->state !== 'leased') {
                $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

                return $this->result($notice, 'stale');
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($attempt->lease_expires_at->lessThanOrEqualTo($at)) {
                $state = 'uncertain';
                $reason = 'lease_expired';
            }
            $changed = $this->transition($attempt, $state, $reason, null, $at);
            $expectedAttempts = $this->replaceAttempt($expectedAttempts, $attempt->id, $changed);
            $this->audit($state === 'failed' ? 'capture_not_accepted' : 'capture_uncertain', $attempt, ['reason' => $reason], null);
            $this->fence($context, $notice, $notice->getRawOriginal(), $expectedAttempts);

            return $this->result($notice, $state);
        });
    }

    private function locked(string $id, callable $callback): mixed
    {
        $this->outside();
        if (! OrderRequest::uuid($id)) {
            throw new NotificationException;
        }
        $hint = DB::table('transactional_notices')->where('public_id', $id)->first();
        if ($hint === null || ! hash_equals($hint->public_id, $id)) {
            throw new NotificationException;
        }

        return DB::transaction(function () use ($id, $hint, $callback): mixed {
            $user = User::whereKey($hint->user_id)->lockForUpdate()->first();
            $account = CustomerAccount::whereKey($hint->account_id)->lockForUpdate()->first();
            if ($user === null || $account === null || $account->access_version !== (int) $hint->access_version) {
                throw new NotificationException;
            }
            $principal = new CustomerPrincipal($account->id, $user->id, $account->owner_key,
                $account->access_version, app(CustomerAccess::class)->stamp($user));
            $order = Order::whereKey($hint->order_id)->lockForUpdate()->first();
            if ($order === null) {
                throw new NotificationException;
            }
            $context = $this->authority($principal, $order->public_id);
            $notice = TransactionalNotice::where('public_id', $id)->lockForUpdate()->first();
            if ($notice === null || $notice->id !== $hint->id || ! hash_equals($notice->public_id, $id)) {
                throw new NotificationException;
            }
            $this->verifyNotice($notice, $context);

            return $callback($context, $notice);
        }, 5);
    }

    private function authority(CustomerPrincipal $principal, string $orderId): array
    {
        $user = User::whereKey($principal->userId)->lockForUpdate()->firstOrFail();
        $account = CustomerAccount::whereKey($principal->accountId)->lockForUpdate()->firstOrFail();
        app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $user);
        $order = Order::where('public_id', $orderId)->lockForUpdate()->first();
        if ($order === null || ! hash_equals($order->public_id, $orderId)) {
            throw new NotificationException;
        }
        $claim = null;
        if (! hash_equals($order->owner_key, $principal->ownerKey)) {
            $claim = CustomerPurchaseClaim::where('order_id', $order->id)->where('account_id', $account->id)->lockForUpdate()->first();
            if ($claim === null) {
                throw new NotificationException;
            }
            app(CustomerPurchaseClaims::class)->verify($claim, $order, $principal);
        }
        app(PurchaseAccess::class)->assertOrder($order, $principal->ownerKey, $principal);
        $activation = TestFulfillmentActivation::where('order_id', $order->id)->lockForUpdate()->first();
        if ($activation === null || $activation->activated_at->isFuture()) {
            throw new NotificationException;
        }
        $activationAccount = app(ActivationPolicy::class)->account();
        $source = app(DeliveryAccessEvidence::class)->source($order, $activationAccount);
        app(ActivationPolicy::class)->current();
        if ($source['activation']->id !== $activation->id || ! OrderRequest::uuid($account->public_id)) {
            throw new NotificationException;
        }
        $email = CustomerIdentityPolicy::email($user->email);
        if ($email !== $user->email) {
            throw new NotificationException;
        }
        $proof = ['users' => $this->critical($user->getRawOriginal(), ['id', 'email', 'password', 'email_verified_at', 'is_admin']),
            'customer_accounts' => $this->critical($account->getRawOriginal(), ['id', 'public_id', 'user_id', 'owner_key', 'active', 'access_version']),
            'orders' => $this->critical($order->getRawOriginal(), ['id', 'public_id', 'owner_key', 'payload_hash']),
            'test_fulfillment_activations' => $this->critical($activation->getRawOriginal(), ['id', 'public_id', 'order_id', 'evidence_hash'])];
        if ($claim !== null) {
            $proof['customer_purchase_claims'] = $this->critical($claim->getRawOriginal(), ['id', 'public_id', 'order_id', 'account_id', 'evidence_hash']);
        }

        return compact('principal', 'user', 'account', 'order', 'claim', 'activation', 'activationAccount', 'email', 'proof');
    }

    private function verifyNotice(TransactionalNotice $notice, array $context): string
    {
        try {
            $capture = Crypt::decryptString($notice->capture_ciphertext);
            $payload = $this->payload($context);
            $recipient = ['email' => $context['email']];
            $event = 'test_order_ready:'.$context['activation']->public_id.':'.$context['account']->public_id;
            $expected = CanonicalJson::encode(['schema_version' => 1, 'purpose' => 'test_transactional_notification_capture',
                'notification_id' => $notice->public_id, 'recipient' => $recipient, 'payload' => $payload]);
            if (! OrderRequest::uuid($notice->public_id) || $notice->notification_type !== 'test_order_ready'
                || $notice->account_id !== $context['account']->id || $notice->user_id !== $context['user']->id
                || $notice->access_version !== $context['principal']->accessVersion || $notice->order_id !== $context['order']->id
                || $notice->activation_id !== $context['activation']->id || $notice->claim_id !== $context['claim']?->id
                || $notice->policy_version !== TransactionalNotificationPolicy::VERSION || $notice->canonicalization_version !== CanonicalJson::VERSION
                || strlen($notice->capture_ciphertext) > 16384 || strlen($capture) > TransactionalNotificationPolicy::MAX_CAPTURE_BYTES
                || ! hash_equals($notice->capture_hash, hash('sha256', $notice->capture_ciphertext))
                || ! hash_equals($notice->event_key, $event) || ! hash_equals($capture, $expected)
                || ! hash_equals($notice->recipient_hmac, $this->hmac($recipient)) || ! hash_equals($notice->payload_hash, CanonicalJson::hash($payload))
                || ! hash_equals($notice->request_hmac, $this->hmac(['event_key' => $event, 'recipient' => $recipient, 'payload' => $payload,
                    'policy_version' => TransactionalNotificationPolicy::VERSION]))
                || $notice->created_at->isFuture() || $notice->created_at->micro !== 0
                || $notice->created_at->lessThan($context['activation']->activated_at)) {
                throw new NotificationException;
            }

            return $capture;
        } catch (Throwable) {
            throw new NotificationException;
        }
    }

    private function leaseAttempt(TransactionalNotice $notice, NotificationLease $lease, array $context): ?TransactionalNoticeAttempt
    {
        if (! OrderRequest::uuid($lease->attemptId) || ! preg_match('/\A[a-f0-9]{64}\z/D', $lease->token())
            || ! hash_equals($lease->capture(), $this->verifyNotice($notice, $context))) {
            throw new NotificationException;
        }
        $attempt = TransactionalNoticeAttempt::where('notice_id', $notice->id)->orderByDesc('number')->lockForUpdate()->first();
        if ($attempt === null || ! hash_equals($attempt->public_id, $lease->attemptId)
            || ! hash_equals($attempt->token_hash, hash('sha256', $lease->token()))
            || ! $attempt->lease_expires_at->equalTo($lease->expiresAt)) {
            return null;
        }
        $this->verifyAttempt($attempt);

        return $attempt;
    }

    private function verifyAttempt(TransactionalNoticeAttempt $attempt): void
    {
        if (! OrderRequest::uuid($attempt->public_id) || ! preg_match('/\A[a-f0-9]{64}\z/D', $attempt->token_hash)
            || $attempt->number < 1 || $attempt->number > TransactionalNotificationPolicy::MAX_ATTEMPTS
            || $attempt->started_at->micro !== 0 || $attempt->started_at->isFuture()
            || ! $attempt->lease_expires_at->equalTo($attempt->started_at->addSeconds(TransactionalNotificationPolicy::LEASE_SECONDS))
            || ! in_array($attempt->state, ['leased', 'accepted', 'failed', 'uncertain'], true)
            || ($attempt->state === 'leased' && ($attempt->reason !== null || $attempt->receipt_hash !== null || $attempt->finished_at !== null))
            || ($attempt->state !== 'leased' && ($attempt->finished_at === null || $attempt->finished_at->lessThan($attempt->started_at)
                || $attempt->finished_at->isFuture() || $attempt->finished_at->micro !== 0))
            || ($attempt->state === 'accepted' && (! is_string($attempt->receipt_hash)
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $attempt->receipt_hash)
                || ! in_array($attempt->reason, [null, 'capture_reconciled'], true)
                || ($attempt->reason === null && $attempt->finished_at->greaterThanOrEqualTo($attempt->lease_expires_at))))
            || ($attempt->state === 'failed' && ($attempt->reason !== 'private_storage_refused' || $attempt->receipt_hash !== null
                || $attempt->finished_at->greaterThanOrEqualTo($attempt->lease_expires_at)))
            || ($attempt->state === 'uncertain' && ($attempt->receipt_hash !== null
                || ! in_array($attempt->reason, ['capture_unknown', 'lease_expired'], true)
                || ($attempt->reason === 'lease_expired' && $attempt->finished_at->lessThan($attempt->lease_expires_at))))) {
            throw new NotificationException;
        }
    }

    private function transition(TransactionalNoticeAttempt $attempt, string $state, ?string $reason, ?string $receipt, $at): array
    {
        if ($at->lessThan($attempt->started_at) || ($attempt->finished_at !== null && $at->lessThan($attempt->finished_at))) {
            throw new NotificationException;
        }
        $changes = ['state' => $state, 'reason' => $reason, 'receipt_hash' => $receipt, 'finished_at' => $at];
        $expected = array_replace($attempt->getRawOriginal(), $changes);
        $attempt->forceFill($changes)->save();

        return $expected;
    }

    /** Framework checks can invoke callbacks. The complete primary PDO proof must come after them. */
    private function fence(array $context, TransactionalNotice $notice, array $expectedNotice, array $attempts): void
    {
        $this->verifyNotice($notice, $context);
        app(DeliveryAccessEvidence::class)->source($context['order'], app(ActivationPolicy::class)->account());
        app(ActivationPolicy::class)->current();
        app(TransactionalNotificationPolicy::class)->requireEnabled();
        foreach ($context['proof'] as $table => $expected) {
            $actual = DB::table($table)->where('id', $expected['id'])->lockForUpdate()->first();
            if ($actual === null || $this->critical((array) $actual, array_keys($expected)) !== $expected) {
                throw new NotificationException;
            }
        }
        $raw = DB::table('transactional_notices')->where('id', $notice->id)->lockForUpdate()->first();
        if ($raw === null || $this->strings((array) $raw) !== $this->strings($expectedNotice)
            || $this->stringsRows($this->attemptRows($notice->id)) !== $this->stringsRows($attempts)) {
            throw new NotificationException;
        }
        // Recheck pure policy/capture data after the last framework QueryExecuted callback.
        if (! hash_equals($context['activationAccount'], app(ActivationPolicy::class)->account())) {
            throw new NotificationException;
        }
        app(ActivationPolicy::class)->current();
        app(TransactionalNotificationPolicy::class)->requireEnabled();
        $this->verifyNotice($notice, $context);

        $connection = DB::connection();
        $pdo = $connection->getPdo(); // Primary writer connection; never a replica or QueryExecuted dispatch.
        $locking = $connection->getDriverName() === 'mysql' ? ' FOR UPDATE' : '';
        foreach ($context['proof'] as $table => $expected) {
            $actual = $this->primaryRows($pdo, $table, 'id', (int) $expected['id'], $locking);
            if (count($actual) !== 1 || $this->critical($actual[0], array_keys($expected)) !== $expected) {
                throw new NotificationException;
            }
        }
        $actualNotice = $this->primaryRows($pdo, 'transactional_notices', 'id', $notice->id, $locking);
        $actualAttempts = $this->primaryRows($pdo, 'transactional_notice_attempts', 'notice_id', $notice->id, $locking);
        if (count($actualNotice) !== 1 || $this->strings($actualNotice[0]) !== $this->strings($expectedNotice)
            || $this->stringsRows($actualAttempts) !== $this->stringsRows($attempts)) {
            throw new NotificationException;
        }
        // Only pure return projections follow; no ORM/framework query runs after this complete proof.
    }

    private function primaryRows(PDO $pdo, string $table, string $column, int $id, string $locking): array
    {
        $allowed = ['users', 'customer_accounts', 'orders', 'test_fulfillment_activations',
            'customer_purchase_claims', 'transactional_notices', 'transactional_notice_attempts'];
        if (! in_array($table, $allowed, true) || ! in_array($column, ['id', 'notice_id'], true)) {
            throw new NotificationException;
        }
        $order = $table === 'transactional_notice_attempts' ? ' ORDER BY `number` ASC' : '';
        $statement = $pdo->prepare('SELECT * FROM `'.$table.'` WHERE `'.$column.'` = ?'.$order.$locking);
        $statement->bindValue(1, $id, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function attemptRows(int $noticeId): array
    {
        $rows = DB::table('transactional_notice_attempts')->where('notice_id', $noticeId)->orderBy('number')->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
        foreach ($rows as $position => $row) {
            $model = new TransactionalNoticeAttempt;
            $model->setRawAttributes($row, sync: true);
            $this->verifyAttempt($model);
            if ($model->notice_id !== $noticeId || $model->number !== $position + 1
                || ($position < count($rows) - 1 && $model->state !== 'failed')) {
                throw new NotificationException;
            }
        }

        return $rows;
    }

    private function replaceAttempt(array $rows, int $id, array $changed): array
    {
        return array_map(fn (array $row): array => (int) $row['id'] === $id ? $changed : $row, $rows);
    }

    private function critical(array $row, array $keys): array
    {
        return $this->strings(array_intersect_key($row, array_flip($keys)));
    }

    private function strings(array $row): array
    {
        $row = array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : ($value === null ? null : (string) $value), $row);
        ksort($row);

        return $row;
    }

    private function stringsRows(array $rows): array
    {
        return array_map(fn ($row) => $this->strings($row), $rows);
    }

    private function payload(array $context): array
    {
        return ['schema_version' => 1, 'type' => 'test_order_ready', 'account_id' => $context['account']->public_id,
            'order_id' => $context['order']->public_id, 'activation_id' => $context['activation']->public_id,
            'template_version' => TransactionalNotificationPolicy::TEMPLATE, 'test_only' => true];
    }

    private function hmac(array $value): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new NotificationException;
        }

        return hash_hmac('sha256', 'test-transactional-notification-v1'."\0".CanonicalJson::encode($value), $key);
    }

    private function outside(): void
    {
        TransactionalNotificationPolicy::outsideTransactions();
        app(TransactionalNotificationPolicy::class)->requireEnabled();
    }

    private function audit(string $action, $subject, array $context, ?int $actor): void
    {
        AuditEvent::recordAttributed('notification.test.'.$action, $subject, ['test_only' => true] + $context, $actor);
    }

    private function enqueued(TransactionalNotice $notice, bool $replayed): array
    {
        return ['notificationSchema' => 1, 'notificationId' => $notice->public_id, 'type' => 'test_order_ready',
            'testOnly' => true, 'replayed' => $replayed];
    }

    private function result(TransactionalNotice $notice, string $state): array
    {
        return ['notificationSchema' => 1, 'notificationId' => $notice->public_id, 'testOnly' => true, 'state' => $state];
    }
}
