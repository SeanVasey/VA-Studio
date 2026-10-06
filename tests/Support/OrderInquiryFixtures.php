<?php

namespace Tests\Support;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\SiteBuilder\SiteContent;
use Illuminate\Support\Str;

final class OrderInquiryFixtures
{
    public static function configure(): array
    {
        $fixture = InquiryConversationFixtures::create();
        config(['inquiries.test_order_inquiries_enabled' => true]);
        OrderFixtures::configure();

        return $fixture;
    }

    public static function guest(string $owner = InventoryFixtures::OWNER): Order
    {
        $fixture = OrderFixtures::priced();
        if ($owner !== InventoryFixtures::OWNER) {
            $fixture['quote'] = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $fixture['items']);
            app(PriceQuote::class)->create($fixture['quote']->public_id, $owner);
        }

        return app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), OrderFixtures::request($fixture['quote'], $owner));
    }

    public static function body(): array
    {
        return ['name' => 'Synthetic support sender', 'email' => 'support-sender@example.test',
            'subject' => 'Synthetic order question', 'message' => "Synthetic order support message.\nOriginal private text.",
            'website' => '', 'requestKey' => (string) Str::uuid(),
            'noticeToken' => app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current())['noticeToken']];
    }
}
