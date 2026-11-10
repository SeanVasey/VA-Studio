<?php

namespace Tests\Unit;

use App\Domain\Commerce\Checkout\CheckoutPaymentMethods as Methods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** BL-68: the shared allowlist rule. Evidence must equal the immutable request's list as an unordered set. */
class CheckoutPaymentMethodsTest extends TestCase
{
    private static function request(mixed $types): array
    {
        return ['params' => ['payment_method_types' => $types]];
    }

    public function test_offered_request_accepts_exactly_card_and_link_in_any_order(): void
    {
        $request = self::request(Methods::OFFERED);
        $this->assertTrue(Methods::matchesRequest(['card', 'link'], $request));
        $this->assertTrue(Methods::matchesRequest(['link', 'card'], $request));
    }

    public static function refusedForOffered(): array
    {
        return [
            'superset' => [['card', 'link', 'klarna']],
            'superset_bank' => [['card', 'link', 'us_bank_account']],
            'subset_card' => [['card']],
            'subset_link' => [['link']],
            'duplicate' => [['card', 'card', 'link']],
            'duplicate_pair' => [['card', 'link', 'link']],
            'string' => ['card,link'],
            'null' => [null],
            'case_variant' => [['Card', 'link']],
            'associative' => [['a' => 'card', 'b' => 'link']],
            'non_string' => [['card', 1]],
            'empty' => [[]],
            'swapped_for_other' => [['card', 'klarna']],
        ];
    }

    #[DataProvider('refusedForOffered')]
    public function test_offered_request_refuses_anything_but_the_exact_set(mixed $observed): void
    {
        $this->assertFalse(Methods::matchesRequest($observed, self::request(Methods::OFFERED)));
    }

    public function test_legacy_request_accepts_card_only(): void
    {
        $this->assertTrue(Methods::matchesRequest(['card'], self::request(Methods::LEGACY)));
        $this->assertFalse(Methods::matchesRequest(['card', 'link'], self::request(Methods::LEGACY)));
        $this->assertFalse(Methods::matchesRequest(['link'], self::request(Methods::LEGACY)));
        $this->assertFalse(Methods::matchesRequest(['card', 'klarna'], self::request(Methods::LEGACY)));
    }

    public static function untrustedRequests(): array
    {
        return [[['link']], [['card', 'klarna']], [['card', 'link', 'klarna']], [['card', 'card']], [[]], [null], ['card'], [['Card']], [['a' => 'card']]];
    }

    #[DataProvider('untrustedRequests')]
    public function test_a_request_that_asked_for_anything_else_is_never_trusted(mixed $requested): void
    {
        $this->assertFalse(Methods::matchesRequest($requested, self::request($requested)));
        $this->assertFalse(Methods::matchesRequest(Methods::OFFERED, self::request($requested)));
    }

    public function test_request_without_params_is_never_trusted(): void
    {
        $this->assertFalse(Methods::matchesRequest(['card'], []));
    }

    public function test_for_stored_replays_only_the_strict_legacy_list(): void
    {
        $this->assertSame(['card'], Methods::forStored(['card']));
        $this->assertSame(Methods::OFFERED, Methods::forStored(['card', 'link']));
        foreach ([['link'], ['card', 'klarna'], ['card', 'card'], ['Card'], [], null, 'card', ['a' => 'card'], [1 => 'card']] as $stored) {
            $this->assertSame(Methods::OFFERED, Methods::forStored($stored));
        }
    }
}
