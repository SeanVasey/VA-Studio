<?php

namespace App\Domain\Commerce\Finalization;

use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** Full retained graph validation. No current catalog availability, file I/O or provider calls. */
final class ReadFinalization
{
    public function verify(OrderFinalization $finalization, array $original): array
    {
        $order = Order::findOrFail($finalization->order_id);
        $attempt = $order->attempt()->sole();
        $payment = VerifiedPayment::findOrFail($finalization->verified_payment_id);
        app(ReadPaymentState::class)->verify($payment, $order, $original);
        $evidence = app(FinalizationEvidence::class);
        $payload = $evidence->decrypt($finalization->evidence_ciphertext, $finalization->evidence_hash, $finalization->canonicalization_version);
        $policy = FinalizationPolicy::validate($payload['policy'] ?? []);
        $checks = $payload['checks'] ?? [];
        $this->validateChecks($checks, $original, $payment);
        $reason = $this->reason($payment, $attempt->expires_at, $checks);
        $expected = $evidence->capture($order, $original, $payment, $finalization->public_id,
            $policy, $finalization->finalized_at, $reason, $checks);
        if (! OrderRequest::uuid($finalization->public_id) || $finalization->mode !== 'test'
            || $finalization->order_attempt_id !== $attempt->id || $payment->order_attempt_id !== $attempt->id
            || $finalization->policy_version !== $policy['version'] || $finalization->reason !== $reason
            || $finalization->outcome !== $expected['outcome'] || ! $finalization->confirmed_at->equalTo($payment->confirmed_at)
            || ! $finalization->eligibility_cutoff->equalTo($attempt->expires_at)
            || $finalization->finalized_at->lessThan($payment->confirmed_at)
            || CanonicalJson::encode($payload) !== CanonicalJson::encode($expected)) { throw new FinalizationException('changed'); }
        $reservation = InventoryReservation::findOrFail($attempt->inventory_reservation_id);
        $use = $attempt->promotion_use_id === null ? null : PromotionUse::findOrFail($attempt->promotion_use_id);
        $evidence->resourceDisposition($finalization, $order, $attempt, $reservation, $use);

        $grants = LicenseGrant::where('order_finalization_id', $finalization->id)->get();
        $sales = ExclusiveSale::where('order_finalization_id', $finalization->id)->get();
        $outbox = FulfillmentOutbox::where('order_finalization_id', $finalization->id)->get();
        if ($reason !== null) {
            if ($grants->isNotEmpty() || $sales->isNotEmpty() || $outbox->count() !== 1) { throw new FinalizationException('changed'); }
            $this->verifyOutbox($outbox->sole(), $finalization, null, 'exception', 'order_paid_exception_v1', $finalization->evidence_hash);

            return $payload;
        }
        $references = $order->lines()->orderBy('position')->get();
        if ($grants->count() !== count($original['lines']) || $references->count() !== $grants->count()
            || $outbox->count() !== $grants->count()) { throw new FinalizationException('changed'); }
        $expectedSales = 0;
        foreach ($original['lines'] as $position => $line) {
            $reference = $references[$position];
            $matches = $grants->where('order_line_id', $reference->id);
            if ($matches->count() !== 1) { throw new FinalizationException('changed'); }
            $grant = $matches->sole();
            $input = app(GrantRenderInput::class)->capture($order, $original, $line, $reference, $finalization, $grant->public_id, $policy);
            $decoded = $evidence->decrypt($grant->render_input_ciphertext, $grant->render_input_hash, $grant->canonicalization_version);
            if (! OrderRequest::uuid($grant->public_id) || $grant->offer_revision_id !== $reference->offer_revision_id
                || $grant->license_version_id !== $line['selection']['offer_snapshot']['license']['id']
                || $grant->rights_scope_id !== $input['inventory_binding']['scope_id']
                || ! $grant->created_at->equalTo($finalization->finalized_at)
                || CanonicalJson::encode($decoded) !== CanonicalJson::encode($input)) { throw new FinalizationException('changed'); }
            $entitlements = PendingEntitlement::where('license_grant_id', $grant->id)->get();
            $manifest = $line['selection']['offer_snapshot']['assets'];
            if ($entitlements->count() !== count($manifest)) { throw new FinalizationException('changed'); }
            foreach ($manifest as $asset) {
                $matching = $entitlements->where('media_asset_id', $asset['id'])->where('role', $asset['role']);
                if ($matching->count() !== 1) { throw new FinalizationException('changed'); }
                $entitlement = $matching->sole();
                if ($entitlement->asset_hash !== $asset['sha256'] || $entitlement->size_bytes !== $asset['size_bytes']
                    || $entitlement->state !== 'pending' || ! $entitlement->created_at->equalTo($finalization->finalized_at)) {
                    throw new FinalizationException('changed');
                }
            }
            $events = $outbox->where('license_grant_id', $grant->id);
            if ($events->count() !== 1) { throw new FinalizationException('changed'); }
            $this->verifyOutbox($events->sole(), $finalization, $grant, 'grant:'.$position, 'render_test_contract_v1', $grant->render_input_hash);
            $grantSales = $sales->where('license_grant_id', $grant->id);
            if ($line['selection']['offer_snapshot']['commercial']['type'] === 'exclusive') {
                $expectedSales++;
                if ($grantSales->count() !== 1) { throw new FinalizationException('changed'); }
                $sale = $grantSales->sole();
                if ($sale->rights_scope_id !== $grant->rights_scope_id || $sale->order_line_id !== $reference->id
                    || ! $sale->created_at->equalTo($finalization->finalized_at)) { throw new FinalizationException('changed'); }
            } elseif ($grantSales->isNotEmpty()) { throw new FinalizationException('changed'); }
        }
        if ($sales->count() !== $expectedSales) { throw new FinalizationException('changed'); }

        return $payload;
    }

    public function reason(VerifiedPayment $payment, CarbonImmutable $cutoff, array $checks): ?string
    {
        if (! $payment->confirmed_at->lessThan($cutoff)) { return 'late_confirmation'; }
        if (collect($checks['scope_controls'])->contains('blocked', true)) { return 'inventory_blocked'; }
        if (! $checks['inventory_available']) { return 'inventory_unavailable'; }
        if (collect($checks['rights_controls'])->contains('available', false)) { return 'rights_unavailable'; }
        if (! $checks['assets_available']) { return 'asset_unavailable'; }

        return null;
    }

    private function validateChecks(array $checks, array $original, VerifiedPayment $payment): void
    {
        $late = ! $payment->confirmed_at->lessThan($original['attempt']['expires_at']);
        if (array_diff(array_keys($checks), ['scope_controls', 'inventory_available', 'assets_available', 'rights_controls']) !== []
            || count($checks) !== 4 || ! is_bool($checks['inventory_available'] ?? null)
            || ! array_key_exists('assets_available', $checks)
            || ($late ? $checks['assets_available'] !== null : ! is_bool($checks['assets_available']))
            || ! is_array($checks['scope_controls'] ?? null) || ! is_array($checks['rights_controls'] ?? null)) {
            throw new FinalizationException('changed');
        }
        $bindings = $original['attempt']['inventory']['snapshot']['bindings'];
        if (count($checks['scope_controls']) !== count($bindings) || count($checks['rights_controls']) !== count($original['lines'])) {
            throw new FinalizationException('changed');
        }
        foreach ($bindings as $index => $binding) {
            $control = $checks['scope_controls'][$index] ?? [];
            if (count($control) !== 4 || ($control['scope_id'] ?? null) !== $binding['scope_id']
                || ($control['scope_public_id'] ?? null) !== $binding['scope_public_id']
                || ! is_int($control['control_version'] ?? null) || $control['control_version'] < 0
                || ! is_bool($control['blocked'] ?? null)) { throw new FinalizationException('changed'); }
        }
        foreach ($original['lines'] as $index => $line) {
            $control = $checks['rights_controls'][$index] ?? [];
            if (count($control) !== 4 || ($control['track_id'] ?? null) !== $line['selection']['offer_snapshot']['product']['id']
                || ($control['retained_declaration_id'] ?? null) !== $line['selection']['offer_snapshot']['rights']['id']
                || ! array_key_exists('observed_declaration_id', $control)
                || ($control['observed_declaration_id'] !== null && (! is_int($control['observed_declaration_id']) || $control['observed_declaration_id'] < 1))
                || ! is_bool($control['available'] ?? null)
                || ($control['available'] && $control['retained_declaration_id'] !== $control['observed_declaration_id'])) {
                throw new FinalizationException('changed');
            }
        }
    }

    private function verifyOutbox($event, OrderFinalization $finalization, ?LicenseGrant $grant, string $key, string $kind, string $hash): void
    {
        $expected = ['schema_version' => 1, 'finalization_id' => $finalization->public_id,
            'grant_id' => $grant?->public_id, 'evidence_hash' => $hash];
        if (! OrderRequest::uuid($event->public_id) || $event->license_grant_id !== $grant?->id
            || $event->effect_key !== $key || $event->kind !== $kind || $event->state !== 'pending'
            || ! $event->created_at->equalTo($finalization->finalized_at)
            || CanonicalJson::encode($event->payload) !== CanonicalJson::encode($expected)) { throw new FinalizationException('changed'); }
    }
}
