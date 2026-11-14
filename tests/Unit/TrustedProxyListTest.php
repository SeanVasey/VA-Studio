<?php

namespace Tests\Unit;

use App\Support\Http\TrustedProxyList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The reviewed proxy allowlist: explicit addresses and bounded ranges only; anything else invalidates the whole list. */
final class TrustedProxyListTest extends TestCase
{
    public function test_blank_and_unset_trust_no_proxy(): void
    {
        $this->assertSame([], TrustedProxyList::parse(null));
        $this->assertSame([], TrustedProxyList::parse(''));
    }

    public function test_explicit_addresses_and_bounded_ranges_are_admitted(): void
    {
        $this->assertSame(['10.0.0.5'], TrustedProxyList::parse('10.0.0.5'));
        $this->assertSame(['173.245.48.0/20', '2400:cb00::/32', '127.0.0.1', '::1'],
            TrustedProxyList::parse('173.245.48.0/20, 2400:cb00::/32 ,127.0.0.1,::1'));
        $this->assertSame(['10.0.0.0/8', '2001:db8::/16'], TrustedProxyList::parse('10.0.0.0/8,2001:db8::/16'));
        $this->assertSame(['10.0.0.5'], TrustedProxyList::parse('10.0.0.5,10.0.0.5'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function refused(): array
    {
        return [
            'wildcard' => ['*'], 'double wildcard' => ['**'], 'framework alias' => ['REMOTE_ADDR'],
            'wildcard among addresses' => ['10.0.0.5,*'], 'every IPv4 address' => ['0.0.0.0/0'], 'every IPv6 address' => ['::/0'],
            'IPv4 wider than /8' => ['10.0.0.0/7'], 'IPv6 wider than /16' => ['2001::/15'], 'IPv4 prefix over 32' => ['10.0.0.5/33'],
            'IPv6 prefix over 128' => ['::1/129'], 'leading zero prefix' => ['10.0.0.0/08'], 'hostname' => ['proxy.example.com'],
            'empty entry' => ['10.0.0.5,'], 'space only entry' => ['10.0.0.5, ,10.0.0.6'], 'malformed address' => ['10.0.0.256'],
            'trailing junk' => ['10.0.0.5 trusted'], 'array' => [['10.0.0.5']], 'boolean' => [true], 'integer' => [167772165],
            'too many entries' => [implode(',', array_map(fn (int $i): string => '10.0.0.'.$i, range(1, 33)))],
            'oversized' => [str_repeat('1', 4097)],
        ];
    }

    #[DataProvider('refused')]
    public function test_anything_else_invalidates_the_whole_list(mixed $value): void
    {
        $this->assertNull(TrustedProxyList::parse($value));
    }
}
