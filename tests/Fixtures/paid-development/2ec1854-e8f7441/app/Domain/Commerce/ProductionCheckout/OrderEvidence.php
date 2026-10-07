<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** Authenticate the complete immutable new-domain graph; historical reads do not re-enable fresh purchases. */
final class OrderEvidence
{
    public static function review(Records $rows, array $record): array
    {
        $body = Evidence::open($record, 'production_checkout_review');
        CheckoutException::require($body['buyer']['origin_id'] === $record['buyer_origin_id'] && $body['candidate']['candidate_id'] === $record['candidate_id']
            && $body['request_hash'] === $record['request_hash'] && $body['request_hash'] === CanonicalJson::hash($body['request'])
            && $body['request']['key_digest'] === $record['request_key'] && $body['review_hash'] === self::reviewHash($body)
            && $body['payable'] === true && $body['assent']['accepted'] === false);
        $context = ExecutionContextV1::retained($body['machine'], $body['execution_context']);
        CheckoutException::require($body['expires_at'] === CarbonImmutable::parse($body['created_at'])->addSeconds($context->reviewLifetimeSeconds)->format('Y-m-d\TH:i:s\Z')
            && $body['request']['candidate_id'] === $record['candidate_id'] && $body['request']['basis_id'] === $body['basis_public_id']
            && $body['request']['buyer_origin_id'] === $record['buyer_origin_id'] && $body['buyer_declarations'] === $body['request']['buyer_declarations']);
        $context->requireBuyer($body['buyer']);
        $history = CurrentPolicy::historical($rows->current, $body['candidate'], $body['machine']);
        $basis = $rows->current->one(CheckoutSchema::TABLES['basis'], $record['basis_id']);
        $basisBody = Evidence::open($basis, 'production_checkout_exemption_basis');
        $authority = $rows->current->one(CheckoutSchema::TABLES['authority'], $basis['authority_id']);
        $authorityBody = Evidence::open($authority, 'production_checkout_exemption_authority');
        CheckoutException::require($body['basis_public_id'] === $basis['public_id'] && $body['basis_hash'] === $basis['payload_hash']
            && $basisBody['request_hash'] === CanonicalJson::hash($basisBody['request'])
            && $basisBody['request']['authority_id'] === $authority['public_id'] && $basisBody['request']['authority_hash'] === $authority['payload_hash']
            && $authorityBody['policy_hash'] === CanonicalJson::hash($authorityBody['policy']) && $authorityBody['owner_user_id'] === $authority['owner_user_id']
            && $basis['candidate_id'] === $record['candidate_id'] && $authority['candidate_id'] === $record['candidate_id']);
        Evidence::same($body['buyer'], $basisBody['request']['buyer']);
        Evidence::same($body['candidate'], $basisBody['request']['candidate']);
        Evidence::same($body['candidate'], $authorityBody['candidate']);
        ExemptionPolicyV1::validate($authorityBody['policy'], ['machine' => $body['machine'], 'context' => $context]);
        ExemptionPolicyV1::effective($authorityBody['policy'], CarbonImmutable::parse($body['created_at']));
        ExemptionPolicyV1::effective($basisBody['request']['attestation'], CarbonImmutable::parse($body['created_at']));
        CheckoutException::require($basisBody['request']['qualifier_id'] === $basis['created_by']
            && in_array($basis['created_by'], $authorityBody['policy']['qualifier_ids'], true)
            && $basisBody['request']['attestation']['qualified_exemption_confirmed'] === true
            && $basisBody['external_tax_fact_verified'] === false && $authorityBody['external_tax_fact_verified'] === false
            && $basisBody['authority_meaning'] === 'accepted_owner_delegated_qualified_exemption_attestation'
            && $authorityBody['authority_meaning'] === 'owner_approved_scoped_qualification_delegation');
        $selection = $body['selection'];
        Evidence::same($body['request']['items'], $selection['items']);
        CheckoutException::require($body['selection_hash'] === CanonicalJson::hash($selection)
            && $selection['selection_hash'] === $basis['selection_hash'] && $selection['selection_hash'] === $basisBody['request']['selection_hash']
            && $selection['selection_hash'] === CanonicalJson::hash(array_intersect_key($selection, array_flip(['items', 'graph', 'links', 'selection'])))
            && $selection['links'] === []);
        $interpreted = PreparationSelection::interpret($selection['graph'], $selection['items'], CarbonImmutable::parse($body['created_at']));
        Evidence::same($interpreted, $selection['selection']);
        $subtotal = PreparationSelection::subtotal(array_column($interpreted['lines'], 'price_minor'));
        Evidence::same($body['amounts'], ['currency' => 'USD', 'subtotal_minor' => $subtotal, 'tax_minor' => 0, 'total_minor' => $subtotal,
            'authority' => 'owner_delegated_qualified_exemption', 'basis_public_id' => $basis['public_id'], 'basis_hash' => $basis['payload_hash']]);
        CheckoutException::require($subtotal >= 50 && $subtotal <= 99999999 && $basisBody['tax_minor'] === 0 && $basisBody['total_minor'] === $subtotal);
        Evidence::same($body['seller'], $body['machine']['choices']['seller_identity']);
        Evidence::same($body['assent'], [...$body['machine']['choices']['assent'], 'accepted' => false]);

        return ['row' => $record, 'body' => $body, 'context' => $context, 'raw' => ['review' => $record, 'basis' => $basis, 'authority' => $authority, 'history' => $history]];
    }

    public static function order(Records $rows, array $record): array
    {
        $body = Evidence::open($record, 'production_checkout_order');
        $review = self::review($rows, $rows->current->one(CheckoutSchema::TABLES['review'], $record['review_id']));
        CheckoutException::require($body['review_id'] === $review['row']['public_id'] && $body['review_hash'] === $review['body']['review_hash']
            && $body['review_payload_hash'] === $review['row']['payload_hash'] && $body['buyer'] === $review['body']['buyer']
            && $body['buyer']['origin_id'] === $record['buyer_origin_id'] && $body['request_hash'] === $record['request_hash']
            && $body['request_hash'] === CanonicalJson::hash($body['request']) && $body['request']['accepted'] === true
            && $body['request']['key_digest'] === $record['request_key'] && $body['request']['review_id'] === $body['review_id']
            && $body['request']['review_hash'] === $body['review_hash'] && $body['payable'] === true
            && $record['total_minor'] === $review['body']['amounts']['total_minor'] && $record['line_count'] === count($body['lines'])
            && $body['accepted_at'] === $body['created_at'] && $body['created_at'] < $review['body']['expires_at']);
        Evidence::same($body['buyer_declarations'], $review['body']['buyer_declarations']);
        Evidence::same($body['amounts'], $review['body']['amounts']);
        Evidence::same($body['assent'], [...$review['body']['machine']['choices']['assent'], 'accepted' => true,
            'review_hash' => $body['review_hash'], 'accepted_at' => $body['accepted_at']]);
        $lines = $rows->selector('line', 'order_id = ?', [$record['id']], 11);
        CheckoutException::require(count($lines) === $record['line_count']);
        foreach ($lines as $i => $line) {
            $original = Evidence::open($line, 'production_checkout_order_line');
            $descriptor = $body['lines'][$i];
            $selected = $review['body']['selection']['selection']['lines'][$i];
            CheckoutException::require($line['created_at'] === $record['created_at']
                && $original['order_public_id'] === $record['public_id'] && $original['order_payload_hash'] === $record['payload_hash']
                && $line['position'] === $i + 1 && $descriptor['position'] === $i + 1 && $line['public_id'] === $descriptor['public_id']
                && $line['track_id'] === $selected['track_id'] && $line['offer_revision_id'] === $selected['offer_revision_id']
                && $line['license_version_id'] === $selected['license_version_id'] && $line['amount_minor'] === $selected['price_minor']
                && $line['line_hash'] === CanonicalJson::hash($descriptor) && $original['line'] === $descriptor && $descriptor['selection'] === $selected);
        }
        $attempts = $rows->selector('attempt', 'order_id = ?', [$record['id']]);
        CheckoutException::require(count($attempts) === 1);
        $attempt = $attempts[0];
        $attemptBody = Evidence::open($attempt, 'production_checkout_attempt');
        $expires = CarbonImmutable::parse($body['created_at'])->addSeconds($review['context']->reservationSeconds)->format('Y-m-d\TH:i:s\Z');
        CheckoutException::require($attempt['created_at'] === $record['created_at']
            && $body['attempt_id'] === $attempt['public_id'] && $body['expires_at'] === $expires && $attempt['expires_at'] === $expires
            && $attemptBody['order_public_id'] === $record['public_id'] && $attemptBody['order_payload_hash'] === $record['payload_hash']
            && $attemptBody['expires_at'] === $expires);
        Evidence::same($attemptBody['inventory'], ['kind' => 'unscoped_nonexclusive',
            'selection_hash' => $review['body']['selection']['selection_hash'], 'pending_resources_retained' => true]);

        return ['row' => $record, 'body' => $body, 'review' => $review, 'lines' => $lines, 'attempt' => $attempt, 'attempt_body' => $attemptBody,
            'context' => $review['context'], 'raw' => [...$review['raw'], 'order' => $record, 'lines' => $lines, 'attempt' => $attempt]];
    }

    public static function reviewHash(array $body): string
    {
        unset($body['review_hash']);

        return CanonicalJson::hash($body);
    }

    public static function proveRetained(Records $rows, array $expected): void
    {
        foreach (['authority', 'basis', 'review', 'order', 'attempt'] as $kind) {
            if (isset($expected[$kind])) {
                Evidence::same($expected[$kind], $rows->current->one(CheckoutSchema::TABLES[$kind], $expected[$kind]['id']));
            }
        }
        if (isset($expected['lines'])) {
            Evidence::same($expected['lines'], $rows->selector('line', 'order_id = ?', [$expected['order']['id']], 11));
        }
        CurrentPolicy::proveHistorical($rows->current, $expected['history']);
    }
}
