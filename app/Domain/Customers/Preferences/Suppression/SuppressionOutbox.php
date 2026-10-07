<?php

namespace App\Domain\Customers\Preferences\Suppression;

use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\Preferences\ConsentEvidence;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\Preferences\Models\ConsentEvent;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionIntent;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionTarget;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class SuppressionOutbox
{
    /** Called inside the same locked transaction as the accepted withdrawal event/current revision. */
    public function withdrawal(ConsentEvent $event, string $recipient): void
    {
        try {
            $target = SuppressionTarget::where('customer_account_id', $event->customer_account_id)->where('purpose', ConsentPolicy::PURPOSE)->where('recipient_hmac', $event->recipient_hmac)->lockForUpdate()->first();
            if ($target === null) {
                $id = (string) Str::uuid();
                $plain = CanonicalJson::encode(['schema' => 1, 'targetId' => $id, 'accountId' => $event->customer_account_id, 'purpose' => ConsentPolicy::PURPOSE, 'email' => $recipient]);
                $attributes = ['public_id' => $id, 'customer_account_id' => $event->customer_account_id, 'purpose' => ConsentPolicy::PURPOSE,
                    'recipient_hmac' => $event->recipient_hmac, 'recipient_ciphertext' => Crypt::encryptString($plain), 'created_at' => $event->created_at];
                self::cipher($attributes['recipient_ciphertext']);
                $target = SuppressionTarget::create($attributes);
                self::expected($target, $attributes);
            }
            $attributes = ['public_id' => (string) Str::uuid(), 'consent_event_id' => $event->id, 'target_id' => $target->id, 'created_at' => $event->created_at];
            self::expected(SuppressionIntent::create($attributes), $attributes);
        } catch (Throwable) {
            throw new ConsentException(503);
        }
    }

    public function graph(CustomerPrincipal $principal, string $recipient, SuppressionEvidence $proof, array $policy): array
    {
        try {
            $hmac = self::recipientHash($principal->accountId, $recipient);
            $targets = $proof->capture('customer_suppression_targets', 'customer_account_id=? AND purpose=? AND recipient_hmac=?', [$principal->accountId, ConsentPolicy::PURPOSE, $hmac]);
            if ($targets === []) {
                return ['status' => 'not_requested', 'target' => null, 'attempt' => null, 'confirmation' => null];
            }
            if (count($targets) !== 1) {
                throw new ConsentException(503);
            }
            $target = $targets[0];
            $capture = self::plain($target['recipient_ciphertext']);
            if (! ConsentPolicy::keys($capture, ['schema', 'targetId', 'accountId', 'purpose', 'email']) || $capture !== ['accountId' => $principal->accountId, 'email' => $recipient,
                'purpose' => ConsentPolicy::PURPOSE, 'schema' => 1, 'targetId' => $target['public_id']]
                || (int) $target['customer_account_id'] !== $principal->accountId || $target['purpose'] !== ConsentPolicy::PURPOSE
                || ! Str::isUuid($target['public_id']) || ! self::timestamp($target['created_at']) || ! hash_equals($hmac, $target['recipient_hmac'])) {
                throw new ConsentException(503);
            }
            $intents = $proof->capture('customer_suppression_intents', 'target_id=?', [$target['id']], 1, 'id DESC');
            if (count($intents) !== 1 || ! Str::isUuid($intents[0]['public_id']) || ! self::timestamp($intents[0]['created_at'])) {
                throw new ConsentException(503);
            }
            $events = $proof->capture('customer_consent_events', 'id=?', [$intents[0]['consent_event_id']]);
            if (count($events) !== 1 || (int) $events[0]['customer_account_id'] !== $principal->accountId || $events[0]['purpose'] !== ConsentPolicy::PURPOSE
                || $events[0]['status'] !== 'withdrawn' || (int) $events[0]['affirmative'] !== 0 || ! hash_equals($hmac, $events[0]['recipient_hmac'])
                || $events[0]['created_at'] !== $intents[0]['created_at'] || $target['created_at'] > $events[0]['created_at']) {
                throw new ConsentException(503);
            }
            $this->event($events[0], $principal->accountId, $recipient);
            $attempts = $proof->capture('customer_suppression_attempts', 'target_id=?', [$target['id']]);
            if ($attempts === []) {
                return ['status' => 'pending', 'target' => $target, 'attempt' => null, 'confirmation' => null];
            }
            if (count($attempts) !== 1) {
                throw new ConsentException(503);
            }
            $attempt = $attempts[0];
            $request = $this->request($target, $attempt);
            $confirmations = $proof->capture('customer_suppression_confirmations', 'attempt_id=?', [$attempt['id']]);
            if ($confirmations !== []) {
                if (count($confirmations) !== 1) {
                    throw new ConsentException(503);
                }
                $confirmation = $confirmations[0];
                $plain = self::plain($confirmation['receipt_ciphertext']);
                if (! ConsentPolicy::keys($plain, ['schema', 'operationId', 'purpose', 'recipientHmac', 'bindingHash', 'requestHash', 'providerReceiptId', 'status'])
                    || $plain['schema'] !== 1 || $plain['purpose'] !== ConsentPolicy::PURPOSE || $plain['status'] !== 'suppressed'
                    || ! (new SuppressionReceipt($plain['operationId'], $plain['recipientHmac'], $plain['bindingHash'], $plain['requestHash'], $plain['providerReceiptId']))->confirms($request)
                    || ! hash_equals(CanonicalJson::hash($plain), $confirmation['receipt_hash']) || ! hash_equals($request->requestHash, $confirmation['request_hash'])
                    || ! self::timestamp($confirmation['created_at']) || $confirmation['created_at'] < $attempt['created_at']) {
                    throw new ConsentException(503);
                }
            }
            $confirmed = $confirmations !== [] && $policy['hash'] !== null && hash_equals($policy['hash'], $request->bindingHash);

            return ['status' => $confirmed ? 'confirmed' : 'unknown', 'target' => $target, 'attempt' => $attempt, 'confirmation' => $confirmations[0] ?? null];
        } catch (Throwable) {
            throw new ConsentException(503);
        }
    }

    private function event(array $event, int $account, string $recipient): void
    {
        $capture = self::plain($event['recipient_ciphertext']);
        if (! ConsentPolicy::keys($capture, ['schema', 'accountId', 'purpose', 'revision', 'eventId', 'email'])
            || $capture['schema'] !== 1 || $capture['accountId'] !== $account || $capture['purpose'] !== ConsentPolicy::PURPOSE
            || $capture['revision'] !== (int) $event['revision'] || $capture['eventId'] !== $event['public_id'] || $capture['email'] !== $recipient
            || $event['source'] !== 'first_party_customer' || (int) $event['revision'] < 1 || (int) $event['revision'] > ConsentPolicy::MAX_REVISION
            || ! Str::isUuid($event['public_id']) || ! self::timestamp($event['created_at'])) {
            throw new ConsentException(503);
        }
    }

    public function request(array $target, array $attempt): SuppressionRequest
    {
        $recipient = self::plain($target['recipient_ciphertext']);
        $binding = self::plain($attempt['binding_ciphertext']);
        if (! SuppressionPolicy::binding($binding) || ! Str::isUuid($attempt['public_id'])
            || ! self::timestamp($attempt['created_at']) || $attempt['created_at'] < $target['created_at']
            || (int) $attempt['account_access_version'] < 1 || (int) $attempt['account_access_version'] > 4294967294
            || ! hash_equals(CanonicalJson::hash($binding), $attempt['binding_hash'])) {
            throw new ConsentException(503);
        }
        $hash = self::requestHash($target, $attempt['public_id'], (int) $attempt['account_access_version'], $attempt['binding_hash']);
        if (! hash_equals($hash, $attempt['request_hash']) || CustomerIdentityPolicy::email($recipient['email']) !== $recipient['email']) {
            throw new ConsentException(503);
        }

        return new SuppressionRequest($attempt['public_id'], $recipient['email'], $target['recipient_hmac'], $binding, $attempt['binding_hash'], $hash);
    }

    public static function requestHash(array $target, string $operation, int $accessVersion, string $bindingHash): string
    {
        return CanonicalJson::hash(['schema' => 1, 'operationId' => $operation, 'targetId' => $target['public_id'], 'accountId' => (int) $target['customer_account_id'],
            'accessVersion' => $accessVersion, 'purpose' => ConsentPolicy::PURPOSE, 'recipientHmac' => $target['recipient_hmac'], 'bindingHash' => $bindingHash]);
    }

    public static function recipientHash(int $account, string $email): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new ConsentException(503);
        }

        return hash_hmac('sha256', "customer-consent-recipient-v1\0".$account."\0".$email, $key);
    }

    public static function expected(Model $model, array $attributes): void
    {
        if (ConsentEvidence::normalized([array_intersect_key($model->getRawOriginal(), $attributes)]) !== ConsentEvidence::normalized([$attributes])) {
            throw new ConsentException(503);
        }
    }

    public static function plain(mixed $cipher): array
    {
        self::cipher($cipher);
        $plain = Crypt::decryptString($cipher);
        $data = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($data) || CanonicalJson::encode($data) !== $plain) {
            throw new ConsentException(503);
        }

        return $data;
    }

    public static function cipher(mixed $cipher): void
    {
        if (! is_string($cipher) || strlen($cipher) < 1 || strlen($cipher) > 4096) {
            throw new ConsentException(503);
        }
    }

    public static function timestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
            return false;
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $at !== false && $at->format('Y-m-d H:i:s') === $value;
    }
}
