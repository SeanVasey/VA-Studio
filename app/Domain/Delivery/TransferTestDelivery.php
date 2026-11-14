<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\RefundDispute\RefundDisputeAccess;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\PurchaseAccess;
use App\Domain\Delivery\Models\TestDeliveryAdmission;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * test-owner-delivery-v2 GET transfer (FP-077). Admission is exactly as strict as the v1 redemption (session-bound owner,
 * current v2 policy and account, control version, refund/dispute holds, authorization and redemption evidence), both before
 * the snapshot and under the order -> control -> authorization locks.
 *
 * - Not started: admitted only before `expires_at` (the 60-second start window). The first served response records the
 *   authorization's single redemption row, which marks the start of the transfer.
 * - Started: any satisfiable selection is admitted until `redeemed_at + resume_window_seconds` (frozen at issuance), never
 *   after, and never once coverage is complete or MAX_RESPONSES responses were admitted (admission rows, written here).
 * - Unsatisfiable: the same checks, no private file access, no start and no coverage; then `range_not_satisfiable`.
 * - Every admitted response copies and SHA-256-verifies the whole file before its first byte.
 *
 * Counting is not decided here: TestDeliveryCoverageLedger counts once when served bytes cover the file.
 */
final class TransferTestDelivery
{
    public function handle(string $orderPublicId, #[SensitiveParameter] string $ownerKey, string $authorizationPublicId,
        #[SensitiveParameter] ?string $sessionSecret, DeliveryByteRange $range, ?User $actor = null,
        ?CustomerPrincipal $principal = null): TestDeliveryTransfer
    {
        ActivationPolicy::outsideTransactions();
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }
        $ownerKey = app(PurchaseAccess::class)->deliveryOwner($orderPublicId, $ownerKey, $principal);
        $policies = app(TestAccessPolicy::class);
        $policy = $policies->current();
        $account = $policies->account();
        $read = app(ReadTestDeliveryAuthorization::class)->forSession($orderPublicId, $ownerKey, $authorizationPublicId, $sessionSecret);
        $order = $read['order'];
        $source = $read['source'];
        $target = $read['target'];
        $authorization = $read['authorization'];
        // The authorization is verified under its own v2 literal; it is served only while the current policy is that literal.
        if ($policy['version'] !== $authorization->policy_version) {
            throw new DeliveryException('unavailable');
        }
        if ($range->sizeBytes !== $target['file']['size_bytes'] || ! hash_equals($target['file']['sha256'], $range->sha256)) {
            throw new DeliveryException('changed');
        }
        $evidence = app(DeliveryAccessEvidence::class);
        $control = $evidence->control($source, TestDeliveryControl::where('order_id', $order->id)->first(), $authorization->control_version);
        $from = now()->toImmutable()->utc()->startOfSecond();
        $this->eligible($authorization, $control, $from, $read['redemption'], $read['transfer'], $read['coverage']);
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }
        $probe = $range->unsatisfiable();
        // The whole snapshot is copied and its SHA-256 read back before any byte, for full and partial selections alike.
        $prepared = $probe ? null : app(PrepareTestDeliveryStream::class)->handle($target['file']);
        try {
            if ($prepared && (! hash_equals($target['file']['sha256'], $prepared->sha256) || $target['file']['size_bytes'] !== $prepared->sizeBytes)) {
                throw new DeliveryException('target_unavailable');
            }
            $through = now()->toImmutable()->utc()->startOfSecond();
            if ($through->lessThan($from)) {
                throw new DeliveryException('retry');
            }
            $admission = DB::transaction(function () use ($order, $ownerKey, $source, $target, $authorization, $through, $read,
                $policies, $policy, $account, $evidence, $actor, $principal, $probe, $range): ?TestDeliveryAdmission {
                app(CustomerAccess::class)->lock($principal, $principal?->ownerKey ?? $ownerKey, $actor);
                // Same order as every other writer: order -> control -> authorization. No private bytes are read under these locks.
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked || ! hash_equals($locked->owner_key, $ownerKey)) {
                    throw new DeliveryException('not_found');
                }
                app(PurchaseAccess::class)->assertOrder($locked, $ownerKey, $principal);
                $control = TestDeliveryControl::where('order_id', $locked->id)->lockForUpdate()->first();
                $current = TestDeliveryAuthorization::whereKey($authorization->id)->lockForUpdate()->first();
                if (! $current || $current->order_id !== $locked->id || ! hash_equals($current->owner_key, $ownerKey)
                    || ! hash_equals($current->token_hash, $authorization->token_hash) || $current->policy_version !== TestAccessPolicy::V2) {
                    throw new DeliveryException('not_found');
                }
                if ($policies->account() !== $account || CanonicalJson::encode($policies->current()) !== CanonicalJson::encode($policy)) {
                    throw new DeliveryException('unavailable');
                }
                $fresh = $evidence->source($locked, $account);
                $freshTarget = $evidence->verify($current, $fresh);
                $transfer = $evidence->transfer($current);
                if ($fresh['snapshot_hash'] !== $source['snapshot_hash'] || $fresh['activation']->evidence_hash !== $source['activation']->evidence_hash
                    || CanonicalJson::encode($freshTarget) !== CanonicalJson::encode($target) || $transfer !== $read['transfer']) {
                    throw new DeliveryException('changed');
                }
                $control = $evidence->control($fresh, $control, $current->control_version);
                RefundDisputeAccess::assertClear($locked->id, (int) $current->license_grant_id);
                $redemption = TestDeliveryRedemption::where('test_delivery_authorization_id', $current->id)->lockForUpdate()->first();
                if ($redemption) {
                    $evidence->verifyRedemption($redemption, $current, $fresh, $freshTarget);
                }
                // Read under the authorization lock that every coverage writer takes first: the latest committed state.
                $coverage = app(TestDeliveryCoverageLedger::class)->state($current, $freshTarget['file']['size_bytes'], true);
                if ($coverage['admitted'] > 0 && ! $redemption) {
                    throw new DeliveryException('changed');
                }
                $at = now()->toImmutable()->utc()->startOfSecond();
                if ($at->lessThan($through)) {
                    throw new DeliveryException('retry');
                }
                $this->eligible($current, $control, $at, $redemption, $transfer, $coverage);
                $existing = $redemption;
                $action = null;
                if (! $redemption && ! $probe) {
                    $id = (string) Str::uuid();
                    [$ciphertext, $hash] = $evidence->encrypt($evidence->redemption($current, $fresh, $freshTarget, $id, $at));
                    $redemption = TestDeliveryRedemption::create(['public_id' => $id, 'test_delivery_authorization_id' => $current->id,
                        'control_version' => $current->control_version, 'content_hash' => $freshTarget['file']['sha256'],
                        'size_bytes' => $freshTarget['file']['size_bytes'], 'evidence_ciphertext' => $ciphertext,
                        'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION, 'redeemed_at' => $at]);
                    $action = 'commerce.delivery.redeemed'; // The transfer started; it counts only once coverage is complete.
                } elseif ($redemption && ! $probe) {
                    $action = 'commerce.delivery.resumed';
                }
                $admission = null;
                if (! $probe) {
                    // Counted before any byte, under the authorization lock: the response limit and the incomplete cap
                    // hold even if this worker dies mid-stream and never records coverage.
                    $admission = TestDeliveryAdmission::create(['test_delivery_authorization_id' => $current->id,
                        'test_delivery_redemption_id' => $redemption->id, 'sequence' => $coverage['admitted'] + 1,
                        'first_byte' => $range->start, 'last_byte' => $range->end, 'admitted_at' => $at]);
                }
                if ($action !== null) {
                    $auditContext = ['order_public_id' => $locked->public_id, 'authorization_public_id' => $current->public_id,
                        'redemption_public_id' => $redemption->public_id, 'policy_version' => TestAccessPolicy::V2, 'test_only' => true];
                    if ($principal) {
                        AuditEvent::recordAttributed($action, $redemption, $auditContext, $principal->userId);
                    } else {
                        AuditEvent::record($action, $redemption, $auditContext);
                    }
                }
                if ($redemption) {
                    $evidence->verifyRedemption($redemption, $current, $fresh, $freshTarget);
                }
                // Observers/auditing are part of the transaction: a window or policy change here rolls the start back.
                $commitAt = now()->toImmutable()->utc()->startOfSecond();
                if ($commitAt->lessThan($at)) {
                    throw new DeliveryException('retry');
                }
                // A start recorded here is still bound by the start window at commit, exactly like a v1 redemption.
                $this->eligible($current, $control, $commitAt, $existing, $transfer, $coverage);
                if ($policies->account() !== $account || CanonicalJson::encode($policies->current()) !== CanonicalJson::encode($policy)) {
                    throw new DeliveryException('unavailable');
                }
                app(CustomerAccess::class)->lock($principal, $principal?->ownerKey ?? $ownerKey, $actor);
                app(PurchaseAccess::class)->assertOrder($locked, $ownerKey, $principal);

                return $admission;
            }, 5);
            if ($prepared === null || $admission === null) {
                throw new DeliveryException('range_not_satisfiable');
            }

            return new TestDeliveryTransfer($prepared, $range, $authorization->id, $admission->id, $principal?->userId ?? $actor?->id);
        } catch (Throwable $error) {
            $prepared?->close();
            throw $error;
        }
    }

    /** Start window before the first byte; size-tiered resume window after it; nothing after completion. */
    public function eligible(TestDeliveryAuthorization $authorization, TestDeliveryControl $control, CarbonImmutable $at,
        ?TestDeliveryRedemption $redemption, array $transfer, array $coverage): void
    {
        if ($at->lessThan($authorization->issued_at) || $at->lessThan($control->updated_at)) {
            throw new DeliveryException('retry');
        }
        if (! $redemption) {
            if (! $at->lessThan($authorization->expires_at)) {
                throw new DeliveryException('expired');
            }

            return;
        }
        if (! $at->lessThan($redemption->redeemed_at->addSeconds($transfer['resume_window_seconds']))) {
            throw new DeliveryException('expired');
        }
        if ($coverage['completed']) {
            throw new DeliveryException('redeemed');
        }
        if ($coverage['admitted'] >= TestDeliveryCoverageLedger::MAX_RESPONSES) {
            throw new DeliveryException('resume_exhausted');
        }
    }
}
