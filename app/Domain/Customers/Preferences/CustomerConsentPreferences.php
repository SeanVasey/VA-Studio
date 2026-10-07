<?php

namespace App\Domain\Customers\Preferences;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\Preferences\Models\ConsentEvent;
use App\Domain\Customers\Preferences\Models\ConsentPolicySnapshot;
use App\Domain\Customers\Preferences\Models\ConsentState;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** First-party choice capture; not imported/legal consent verification or a marketing sender. */
final class CustomerConsentPreferences
{
    private ConsentRuntime $runtime;

    public function __construct(?ConsentRuntime $runtime = null)
    {
        $this->runtime = $runtime ?? new LocalConsentRuntime;
    }

    public function read(CustomerPrincipal $principal, User $actor): array
    {
        return $this->run($principal, $actor, null);
    }

    public function change(CustomerPrincipal $principal, User $actor, array $command): array
    {
        $this->command($command);

        return $this->run($principal, $actor, $command);
    }

    private function run(CustomerPrincipal $principal, User $actor, ?array $command): array
    {
        return DB::transaction(function () use ($principal, $actor, $command): array {
            $proof = new ConsentEvidence;
            $access = app(CustomerAccess::class);
            $access->lock($principal, $principal->ownerKey, $actor);
            $user = User::whereKey($principal->userId)->firstOrFail();
            $email = $user->email;
            $recipient = CustomerIdentityPolicy::email($email);
            $policySource = app(ConsentPolicy::class);
            $grantsEnabled = $this->runtime->grantsEnabled();
            $configuration = config('customer-preferences');
            $configured = $policySource->configured();
            $state = ConsentState::where('customer_account_id', $principal->accountId)->where('purpose', ConsentPolicy::PURPOSE)->lockForUpdate()->first();
            $events = ConsentEvent::where('customer_account_id', $principal->accountId)->where('purpose', ConsentPolicy::PURPOSE)->orderByDesc('revision')->limit(2)->lockForUpdate()->get();
            $policies = [];
            foreach ($events as $event) {
                $this->event($event, $principal->accountId);
                if ($event->policy_id !== null && ! isset($policies[$event->policy_id])) {
                    $policy = ConsentPolicySnapshot::whereKey($event->policy_id)->lockForUpdate()->first();
                    $this->policy($policy);
                    $policies[$event->policy_id] = $policy;
                }
            }
            $revision = $state?->revision ?? 0;
            if (($state === null && $events->isNotEmpty()) || ($state !== null && ($events->isEmpty() || $revision < 1 || $revision > ConsentPolicy::MAX_REVISION
                || $state->event_id !== $events[0]->id || $events[0]->revision !== $revision || $state->customer_account_id !== $principal->accountId
                || ! $this->timestamp($state->created_at) || ! $this->timestamp($state->updated_at)
                || $state->created_at > $state->updated_at || $state->updated_at !== $events[0]->created_at))
                || ($revision === 1 && $events->count() !== 1) || ($revision > 1 && ($events->count() !== 2 || $events[1]->revision !== $revision - 1 || $events[0]->created_at < $events[1]->created_at))) {
                throw new ConsentException(503);
            }
            $configuredRow = $configured ? ConsentPolicySnapshot::where('purpose', ConsentPolicy::PURPOSE)->where('version', $configured['version'])->lockForUpdate()->first() : null;
            if ($configuredRow !== null) {
                $this->policy($configuredRow);
                $policies[$configuredRow->id] = $configuredRow;
            }
            $collision = $configuredRow !== null && ! $this->matches($configuredRow, $configured);
            if ($command !== null) {
                if ($command['version'] !== $revision || $revision >= ConsentPolicy::MAX_REVISION) {
                    throw new ConsentException(409);
                }
                $grant = $command['action'] === 'grant-consent';
                if ($grant && (! $grantsEnabled || $configured === null || $command['noticeVersion'] !== $configured['version'] || ! hash_equals($configured['notice_hash'], $command['noticeHash']))) {
                    throw new ConsentException;
                }
                if ($grant && $collision) {
                    throw new ConsentException(503);
                }
                $at = now()->utc()->format('Y-m-d H:i:s');
                if ($events->isNotEmpty() && $at < $events[0]->created_at) {
                    throw new ConsentException(503);
                }
                if ($grant && $configuredRow === null) {
                    $attributes = $configured + ['created_at' => $at];
                    $configuredRow = ConsentPolicySnapshot::create($attributes);
                    $this->expected($configuredRow, $attributes);
                    $policies[$configuredRow->id] = $configuredRow;
                }
                $id = (string) Str::uuid();
                $capture = CanonicalJson::encode(['schema' => 1, 'accountId' => $principal->accountId, 'purpose' => ConsentPolicy::PURPOSE,
                    'revision' => $revision + 1, 'eventId' => $id, 'email' => $recipient]);
                $eventAttributes = ['public_id' => $id, 'customer_account_id' => $principal->accountId, 'purpose' => ConsentPolicy::PURPOSE,
                    'revision' => ++$revision, 'status' => $grant ? 'granted' : 'withdrawn', 'policy_id' => $grant ? $configuredRow->id : ($events->first()?->policy_id),
                    'source' => 'first_party_customer', 'affirmative' => $grant ? 1 : 0, 'recipient_hmac' => $this->recipientHash($principal->accountId, $recipient),
                    'recipient_ciphertext' => Crypt::encryptString($capture), 'created_at' => $at];
                if (strlen($eventAttributes['recipient_ciphertext']) > 4096) {
                    throw new ConsentException(503);
                }
                $event = ConsentEvent::create($eventAttributes);
                $this->expected($event, $eventAttributes);
                $stateAttributes = ['customer_account_id' => $principal->accountId, 'purpose' => ConsentPolicy::PURPOSE,
                    'revision' => $revision, 'event_id' => $event->id, 'created_at' => $state?->created_at ?? $at, 'updated_at' => $at];
                $state ??= new ConsentState;
                $state->fill($stateAttributes)->save();
                $this->expected($state, $stateAttributes);
                $events = $events->prepend($event)->take(2);
            }
            $status = $events->first()?->status ?? 'unknown';
            // Changed notice/recipient never silently promotes previous captured evidence.
            if ($status === 'granted' && ($configured === null || $collision || ! $grantsEnabled
                || ! $this->matches($policies[$events[0]->policy_id], $configured)
                || ! hash_equals($events[0]->recipient_hmac, $this->recipientHash($principal->accountId, $recipient)))) {
                $status = 'unknown';
            }
            $projection = ['schema' => 1, 'purposes' => [['purpose' => ConsentPolicy::PURPOSE, 'version' => $revision, 'status' => $status,
                'notice' => $configured ? ['version' => $configured['version'], 'hash' => $configured['notice_hash'], 'text' => $configured['notice']] : null,
                'canGrant' => $grantsEnabled && $configured !== null && ! $collision]]];
            $expectedStates = $state ? [$state->getRawOriginal()] : [];
            $expectedEvents = $events->map(fn ($event) => $event->getRawOriginal())->all();
            $expectedPolicies = array_map(fn ($policy) => $policy->getRawOriginal(), array_values($policies));
            $range = $configured ? ['version' => $configured['version'], 'rows' => $configuredRow ? [$configuredRow->getRawOriginal()] : []] : null;
            $access->current($principal);
            // Resolve extensible/container policy callbacks before the final pure policy checks.
            $currentAccessPolicy = app(CustomerAccessPolicy::class);
            $currentGrantsEnabled = $this->runtime->grantsEnabled();
            if (config('customer-preferences') !== $configuration || $currentGrantsEnabled !== $grantsEnabled) {
                throw new ConsentException(503);
            }
            $currentAccessPolicy->requireEnabled();
            $proof->prove($principal, $email, $expectedStates, $expectedEvents, $expectedPolicies, $range);

            return $projection;
        });
    }

    private function command(array $command): void
    {
        $grant = ($command['action'] ?? null) === 'grant-consent';
        $keys = $grant ? ['action', 'version', 'purpose', 'noticeVersion', 'noticeHash', 'affirmative'] : ['action', 'version', 'purpose'];
        if (! ConsentPolicy::keys($command, $keys) || ! in_array($command['action'], ['grant-consent', 'withdraw-consent'], true)
            || $command['purpose'] !== ConsentPolicy::PURPOSE || ! is_int($command['version']) || $command['version'] < 0 || $command['version'] > ConsentPolicy::MAX_REVISION
            || ($grant && ($command['affirmative'] !== true || ! ConsentPolicy::version($command['noticeVersion']) || ! is_string($command['noticeHash']) || preg_match('/\A[a-f0-9]{64}\z/D', $command['noticeHash']) !== 1))) {
            throw new ConsentException;
        }
    }

    private function expected(Model $model, array $attributes): void
    {
        $raw = $model->getRawOriginal();
        $intent = array_intersect_key($raw, $attributes);
        if (ConsentEvidence::normalized([$intent]) !== ConsentEvidence::normalized([$attributes])) {
            throw new ConsentException(503);
        }
    }

    private function matches(ConsentPolicySnapshot $policy, ?array $configured): bool
    {
        return $configured !== null && ConsentEvidence::normalized([array_intersect_key($policy->getRawOriginal(), $configured)]) === ConsentEvidence::normalized([$configured]);
    }

    private function policy(?ConsentPolicySnapshot $policy): void
    {
        if ($policy === null || $policy->purpose !== ConsentPolicy::PURPOSE || ! ConsentPolicy::version($policy->version)
            || ! ConsentPolicy::text($policy->notice, 2000, 8000) || ! ConsentPolicy::text($policy->review_reference, 200, 800)
            || ! $this->timestamp($policy->created_at) || ! is_string($policy->notice_hash) || ! is_string($policy->policy_hash)
            || ! hash_equals(hash('sha256', $policy->notice), $policy->notice_hash)
            || ! hash_equals(CanonicalJson::hash(['purpose' => $policy->purpose, 'version' => $policy->version, 'notice' => $policy->notice, 'review_reference' => $policy->review_reference]), $policy->policy_hash)) {
            throw new ConsentException(503);
        }
    }

    private function event(ConsentEvent $event, int $account): void
    {
        try {
            if (! is_string($event->recipient_ciphertext) || strlen($event->recipient_ciphertext) > 4096 || ! $this->timestamp($event->created_at)) {
                throw new ConsentException(503);
            }
            $plain = Crypt::decryptString($event->recipient_ciphertext);
            $recipient = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($recipient) || ! ConsentPolicy::keys($recipient, ['schema', 'accountId', 'purpose', 'revision', 'eventId', 'email'])
                || $plain !== CanonicalJson::encode($recipient) || $recipient['schema'] !== 1 || $recipient['accountId'] !== $account
                || $recipient['purpose'] !== ConsentPolicy::PURPOSE || $recipient['revision'] !== $event->revision || $recipient['eventId'] !== $event->public_id
                || ! is_string($recipient['email']) || CustomerIdentityPolicy::email($recipient['email']) !== $recipient['email']
                || ! hash_equals($this->recipientHash($account, $recipient['email']), $event->recipient_hmac)
                || $event->customer_account_id !== $account || $event->purpose !== ConsentPolicy::PURPOSE || $event->source !== 'first_party_customer'
                || $event->revision < 1 || $event->revision > ConsentPolicy::MAX_REVISION || ! Str::isUuid($event->public_id)
                || ! in_array($event->status, ['granted', 'withdrawn'], true) || $event->affirmative !== ($event->status === 'granted')
                || ($event->status === 'granted' && $event->policy_id === null)) {
                throw new ConsentException(503);
            }
        } catch (Throwable) {
            throw new ConsentException(503);
        }
    }

    private function recipientHash(int $account, string $email): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new ConsentException(503);
        }

        return hash_hmac('sha256', "customer-consent-recipient-v1\0".$account."\0".$email, $key);
    }

    private function timestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
            return false;
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $at !== false && $at->format('Y-m-d H:i:s') === $value;
    }
}
