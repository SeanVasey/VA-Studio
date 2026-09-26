<?php

namespace App\Domain\Commerce\Finalization;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\OrderLine;

/** Frozen buyer-contract input. Historical license renderer_version is review provenance, not a PDF profile. */
final class GrantRenderInput
{
    public function capture(Order $order, array $original, array $line, OrderLine $reference,
        OrderFinalization $finalization, string $grantId, array $policy): array
    {
        $binding = collect($original['attempt']['inventory']['snapshot']['bindings'])
            ->firstWhere('offer_revision_id', $reference->offer_revision_id);
        if (! $binding) { throw new FinalizationException('changed'); }

        return ['schema_version' => 1, 'purpose' => 'test_grant_render_input', 'test_only' => true,
            'grant_id' => $grantId, 'order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
            'order_line_id' => $reference->id, 'position' => $reference->position, 'attempt_id' => $original['attempt_id'],
            'verified_payment_id' => $finalization->verified_payment_id, 'finalization_id' => $finalization->public_id,
            'finalization_policy' => $policy, 'grant_effective_at' => $finalization->finalized_at->toIso8601ZuluString(),
            'confirmation_observed_at' => $finalization->confirmed_at->toIso8601ZuluString(),
            'buyer' => ['identity' => 'unverified_guest', 'legal_name' => $original['request']['buyer']['legalName'],
                'email' => $original['request']['buyer']['email']], 'seller' => $original['policy']['seller'],
            'assent' => ['policy_version' => $original['policy']['version'], 'version' => $original['policy']['assent']['version'],
                'text' => $original['policy']['assent']['text'], 'accepted' => true,
                'review_hash' => $original['review']['reviewHash'], 'accepted_at' => $original['created_at']],
            'selection' => $line['selection'], 'pricing' => $line['pricing'], 'disclosure' => $line['disclosure'],
            'inventory_binding' => $binding];
    }
}
