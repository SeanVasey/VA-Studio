<?php

namespace Tests\Support;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\PriceQuote;

/** Synthetic identity and policy, exclusively for tests. Never used by setup or seeders. */
final class OrderFixtures
{
    public static function policy(): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_order_preparation', 'version' => 'SYNTHETIC-ORDER-1',
            'seller' => ['legal_name' => 'Synthetic Seller'],
            'assent' => ['version' => 'SYNTHETIC-ASSENT-1', 'text' => 'Synthetic test assent only.'],
            'buyer_identity' => 'unverified_guest'];
    }

    public static function configure(): void
    {
        ExclusiveSelectionFixtures::configure();
        PricingFixtures::configure(PricingFixtures::policy());
        PromotionFixtures::configure([]);
        config(['commerce.test_order_policy' => json_encode(self::policy(), JSON_THROW_ON_ERROR)]);
    }

    public static function buyer(): array
    {
        return ['legalName' => 'Synthetic Buyer Privacy Marker', 'email' => 'order-privacy-marker@example.invalid'];
    }

    public static function request(Quote $quote, string $owner = InventoryFixtures::OWNER): array
    {
        $review = app(ReviewOrder::class)->handle($quote->public_id, $owner);

        return ['quoteId' => $quote->public_id, 'reviewHash' => $review['reviewHash'], 'buyer' => self::buyer(), 'accepted' => true];
    }

    public static function priced(bool $exclusive = false, bool $promoted = false): array
    {
        $f = $exclusive ? ExclusiveSelectionFixtures::active() : InventoryFixtures::selection();
        $quote = $f['quote'] ?? ExclusiveSelectionFixtures::quote($f);
        $promotion = null;
        if ($promoted) {
            $promotion = PromotionFixtures::policy(['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [$f['revision']->id]]]);
            PromotionFixtures::configure([$promotion]);
        }
        $prices = app(PriceQuote::class);
        $pricing = $promoted ? $prices->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC') :
            $prices->create($quote->public_id, InventoryFixtures::OWNER);

        return $f + compact('quote', 'pricing', 'promotion');
    }
}
