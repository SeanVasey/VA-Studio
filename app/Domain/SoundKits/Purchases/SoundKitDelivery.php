<?php

namespace App\Domain\SoundKits\Purchases;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Delivery\TestDeliveryAutoEnablePolicy;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Purchases\Models\SoundKitContract;
use App\Domain\SoundKits\Purchases\Models\SoundKitDeliveryAuthorization;
use App\Domain\SoundKits\Purchases\Models\SoundKitDeliveryControl;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Test delivery of a purchased kit (BL-13), on the track test-delivery model: an operator control per order (absent means not
 * enabled), a short-lived (60 s) single-use authorization bound to the owner, the order, its grant and one deliverable, and a
 * re-hash before any byte is sent. The archive is the exact revision the grant names, read from the private disk only: it is
 * copied into an unlinked temporary file while being hashed, compared with the grant's purchase snapshot (SHA-256 and size), and
 * only that checked copy is streamed, so bytes changed after the check are never served. The contract is the recorded original,
 * re-hashed the same way. Nothing is ever served from a public path, and there is no range or resume support for kits yet.
 */
final class SoundKitDelivery
{
    public const KINDS = ['archive', 'contract'];

    /** Operator: enable or block delivery of one paid order. Optimistic: the expected version must be current (0 = never set). */
    public function control(string $orderPublicId, string $action, int $expectedVersion, string $reference): SoundKitDeliveryControl
    {
        app(SoundKitPurchasePolicy::class)->require();
        if (! Str::isUuid($orderPublicId) || ! in_array($action, ['enable', 'block'], true)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{2,127}\z/D', $reference) !== 1 || $expectedVersion < 0) {
            throw new SoundKitPurchaseException('INVALID_REQUEST', 422);
        }

        return DB::transaction(function () use ($orderPublicId, $action, $expectedVersion, $reference): SoundKitDeliveryControl {
            $order = SoundKitOrder::query()->where('public_id', $orderPublicId)->lockForUpdate()->first();
            if (! $order || ! SoundKitGrant::query()->where('sound_kit_order_id', $order->id)->exists()) {
                throw new SoundKitPurchaseException('NOT_FOUND', 404);
            }
            $control = SoundKitDeliveryControl::query()->where('sound_kit_order_id', $order->id)->lockForUpdate()->first();
            if (($control?->version ?? 0) !== $expectedVersion) {
                throw new SoundKitPurchaseException('IDEMPOTENCY_CONFLICT', 409);
            }
            $values = ['state' => $action === 'enable' ? 'enabled' : 'blocked', 'version' => $expectedVersion + 1,
                'reference_hash' => hash('sha256', $reference), 'updated_at' => now()->toImmutable()->utc()->startOfSecond()];
            if ($control) {
                $control->fill($values)->save();
            } else {
                $control = SoundKitDeliveryControl::create(['sound_kit_order_id' => $order->id] + $values);
            }
            AuditEvent::recordAttributed('sound_kit.test_delivery.'.$values['state'], $control, ['order_public_id' => $order->public_id,
                'version' => $values['version'], 'test_only' => true], null);

            return $control;
        }, 5);
    }

    /**
     * The optional automatic enable ({@see TestDeliveryAutoEnablePolicy}, D-19 amendment 2026-11-12): under the order lock, an order
     * whose grant matches its purchase snapshot and whose contract original is recorded (the staff page's "Contract issued, delivery
     * not enabled") and that never had a control is enabled through {@see control()} from version 0, the same audited step staff take,
     * plus one `sound_kit.test_delivery.auto_enabled` event naming the policy, attributed to no actor. An existing control (enabled or
     * blocked by anyone) or an order not yet at that state returns null and changes nothing; a person's decision is final.
     */
    public function enableAutomatically(string $orderPublicId): ?SoundKitDeliveryControl
    {
        app(SoundKitPurchasePolicy::class)->require();
        $automatic = app(TestDeliveryAutoEnablePolicy::class);
        $automatic->current();
        if (! Str::isUuid($orderPublicId)) {
            throw new SoundKitPurchaseException('INVALID_REQUEST', 422);
        }

        return DB::transaction(function () use ($orderPublicId, $automatic): ?SoundKitDeliveryControl {
            $order = SoundKitOrder::query()->where('public_id', $orderPublicId)->lockForUpdate()->first();
            if (! $order) {
                throw new SoundKitPurchaseException('NOT_FOUND', 404);
            }
            $automatic->current();
            if (SoundKitDeliveryControl::query()->where('sound_kit_order_id', $order->id)->lockForUpdate()->exists()) {
                return null;
            }
            $grant = SoundKitGrant::query()->where('sound_kit_order_id', $order->id)->first();
            if (! $grant || $grant->snapshot_hash !== $order->snapshot_hash
                || ! SoundKitContract::query()->where('sound_kit_grant_id', $grant->id)->exists()) {
                return null;
            }
            $control = $this->control($order->public_id, 'enable', 0, TestDeliveryAutoEnablePolicy::REFERENCE);
            AuditEvent::recordAttributed('sound_kit.test_delivery.auto_enabled', $control, ['order_public_id' => $order->public_id,
                'version' => $control->version, 'policy_version' => TestDeliveryAutoEnablePolicy::VERSION, 'test_only' => true], null);

            return $control;
        }, 5);
    }

    /** Owner: one short-lived single-use authorization for one deliverable. The secret is returned once and stored only as a hash. */
    public function authorize(string $orderPublicId, #[SensitiveParameter] string $ownerKey, mixed $kind): array
    {
        $order = app(SoundKitOrders::class)->owned($orderPublicId, $ownerKey);
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            throw new SoundKitPurchaseException('INVALID_REQUEST', 422);
        }
        $secret = bin2hex(random_bytes(32));
        $authorization = DB::transaction(function () use ($order, $kind, $secret): SoundKitDeliveryAuthorization {
            $locked = SoundKitOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $grant = $this->deliverable($locked);
            $at = now()->toImmutable()->utc()->startOfSecond();
            $authorization = SoundKitDeliveryAuthorization::create(['public_id' => (string) Str::uuid(), 'sound_kit_order_id' => $locked->id,
                'sound_kit_grant_id' => $grant->id, 'kind' => $kind, 'token_hash' => hash('sha256', $secret),
                'created_at' => $at, 'expires_at' => $at->addSeconds(SoundKitPurchasePolicy::AUTHORIZATION_SECONDS)]);
            AuditEvent::recordAttributed('sound_kit.test_delivery.authorized', $authorization, ['order_public_id' => $locked->public_id,
                'kind' => $kind, 'test_only' => true], null);

            return $authorization;
        }, 5);

        return ['authorizationId' => $authorization->public_id, 'token' => $secret, 'kind' => $kind,
            'expiresAt' => $authorization->expires_at->utc()->toISOString()];
    }

    /**
     * Owner: redeem an authorization. Checks owner, order, expiry, single use, token and the current control; prepares a re-hashed
     * private copy of the exact purchased bytes; then consumes the authorization (only if still unused) and returns the copy.
     *
     * @return array{stream: resource, size: int, filename: string, mime: string, sha256: string}
     */
    public function redeem(string $orderPublicId, #[SensitiveParameter] string $ownerKey, mixed $authorizationId, #[SensitiveParameter] mixed $token): array
    {
        $order = app(SoundKitOrders::class)->owned($orderPublicId, $ownerKey);
        if (! is_string($authorizationId) || ! Str::isUuid($authorizationId) || ! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new SoundKitPurchaseException('DELIVERY_UNAVAILABLE', 403);
        }
        $authorization = SoundKitDeliveryAuthorization::query()->where('public_id', $authorizationId)->where('sound_kit_order_id', $order->id)->first();
        if (! $authorization || $authorization->redeemed_at !== null || $authorization->expires_at->lessThanOrEqualTo(now())
            || ! hash_equals($authorization->token_hash, hash('sha256', $token))) {
            throw new SoundKitPurchaseException('DELIVERY_UNAVAILABLE', 403);
        }
        $grant = $this->deliverable($order);
        if ($grant->id !== $authorization->sound_kit_grant_id) {
            throw new SoundKitPurchaseException('DELIVERY_UNAVAILABLE', 403);
        }
        $slug = $order->snapshot['product']['slug'];
        try {
            if ($authorization->kind === 'archive') {
                $revision = SoundKitRevision::query()->findOrFail($grant->sound_kit_revision_id);
                $prepared = $this->snapshot((string) $revision->archive_path, $order->snapshot['archive']['sha256'], $order->snapshot['archive']['size_bytes']);
                $prepared += ['filename' => $slug.'.zip', 'mime' => 'application/zip'];
            } else {
                $contract = SoundKitContract::query()->where('sound_kit_grant_id', $grant->id)->firstOrFail();
                $bytes = app(SoundKitContracts::class)->bytes($contract);
                $stream = tmpfile();
                if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes) || ! rewind($stream)) {
                    throw new \UnexpectedValueException;
                }
                $prepared = ['stream' => $stream, 'size' => strlen($bytes), 'sha256' => $contract->sha256,
                    'filename' => $slug.'-license.pdf', 'mime' => 'application/pdf'];
            }
        } catch (Throwable $error) {
            AuditEvent::recordAttributed('sound_kit.test_delivery.refused', $authorization, ['order_public_id' => $order->public_id,
                'kind' => $authorization->kind, 'reason' => $error instanceof ContractIssuanceException || $error instanceof MediaFailure ? 'bytes_changed' : 'unavailable',
                'test_only' => true], null);
            throw new SoundKitPurchaseException('DELIVERY_UNAVAILABLE', 409);
        }
        $consumed = DB::transaction(function () use ($authorization, $order): bool {
            SoundKitOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->deliverable($order);
            $updated = SoundKitDeliveryAuthorization::query()->whereKey($authorization->id)->whereNull('redeemed_at')
                ->where('expires_at', '>', now()->utc()->format('Y-m-d H:i:s'))
                ->update(['redeemed_at' => now()->toImmutable()->utc()->startOfSecond(), 'outcome' => 'redeemed']);
            if ($updated === 1) {
                AuditEvent::recordAttributed('sound_kit.test_delivery.redeemed', $authorization, ['order_public_id' => $order->public_id,
                    'kind' => $authorization->kind, 'test_only' => true], null);
            }

            return $updated === 1;
        }, 5);
        if (! $consumed) {
            fclose($prepared['stream']);
            throw new SoundKitPurchaseException('DELIVERY_UNAVAILABLE', 403);
        }

        return $prepared;
    }

    /** The order's grant, if it may be delivered now: a contract original is recorded and the operator control is `enabled`. */
    private function deliverable(SoundKitOrder $order): SoundKitGrant
    {
        $grant = SoundKitGrant::query()->where('sound_kit_order_id', $order->id)->first();
        $control = SoundKitDeliveryControl::query()->where('sound_kit_order_id', $order->id)->first();
        if (! $grant || $grant->snapshot_hash !== $order->snapshot_hash || $control?->state !== 'enabled'
            || ! SoundKitContract::query()->where('sound_kit_grant_id', $grant->id)->exists()) {
            throw new SoundKitPurchaseException('DELIVERY_UNAVAILABLE', 403);
        }

        return $grant;
    }

    /**
     * Copy the private archive into an unlinked temporary file while hashing the same descriptor, then compare with the purchase
     * snapshot. Only the checked copy is returned.
     *
     * @return array{stream: resource, size: int, sha256: string}
     */
    private function snapshot(string $relative, string $sha256, int $size): array
    {
        $path = app(PrivateMediaFiles::class)->resolve($relative);
        $input = @fopen($path, 'rb');
        $output = tmpfile();
        if ($input === false || $output === false) {
            throw new \UnexpectedValueException;
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) {
                    throw new \UnexpectedValueException;
                }
                $bytes += strlen($chunk);
                if ($bytes > $size || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
                    throw new \UnexpectedValueException;
                }
                hash_update($hash, $chunk);
            }
            if ($bytes !== $size || ! hash_equals($sha256, hash_final($hash)) || ! fflush($output) || ! rewind($output)) {
                throw new MediaFailure('source_changed', 'The purchased kit bytes do not match the purchase snapshot.');
            }
        } catch (Throwable $error) {
            fclose($output);
            throw $error;
        } finally {
            fclose($input);
        }

        return ['stream' => $output, 'size' => $size, 'sha256' => $sha256];
    }
}
