<?php

namespace Tests\Unit;

use App\Domain\Delivery\DeliveryByteRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RFC 9110 single byte-range evaluation for private delivery; anything the server may ignore is a full selection. */
class DeliveryByteRangeTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SCOPE = '0e8b1f3c-6b2a-4c9d-8f7e-1a2b3c4d5e6f';

    private const KEY = 'base64:c3ludGhldGljLWtleS1mb3ItZXRhZy10ZXN0cy0wMQ==';

    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function selections(): array
    {
        $etag = DeliveryByteRange::etag(self::SHA, self::SCOPE, self::KEY);

        return [
            'closed' => ['bytes=10-19', null, ['partial', 10, 19]],
            'closed clipped to the end' => ['bytes=90-500', null, ['partial', 90, 99]],
            'open' => ['bytes=40-', null, ['partial', 40, 99]],
            'suffix' => ['bytes=-25', null, ['partial', 75, 99]],
            'single last byte' => ['bytes=99-99', null, ['partial', 99, 99]],
            'unit is case-insensitive' => ['BYTES=1-2', null, ['partial', 1, 2]],
            'leading zeros' => ['bytes=007-008', null, ['partial', 7, 8]],
            'matching strong if-range' => ['bytes=5-', $etag, ['partial', 5, 99]],
            'whole file closed' => ['bytes=0-99', null, ['full', 0, 99]],
            'whole file open' => ['bytes=0-', null, ['full', 0, 99]],
            'suffix longer than the file' => ['bytes=-500', null, ['full', 0, 99]],
            'huge suffix saturates' => ['bytes=-99999999999999999999999', null, ['full', 0, 99]],
            'first past end' => ['bytes=100-', null, ['unsatisfiable', 0, 99]],
            'first far past end' => ['bytes=99999999999999999999999-', null, ['unsatisfiable', 0, 99]],
            'zero suffix' => ['bytes=-0', null, ['unsatisfiable', 0, 99]],
            'no range' => [null, null, ['full', 0, 99]],
            'if-range without range is ignored' => [null, $etag, ['full', 0, 99]],
            'last before first is invalid' => ['bytes=20-10', null, ['full', 0, 99]],
            'multi range is ignored' => ['bytes=0-1,5-6', null, ['full', 0, 99]],
            'multi range with an unsatisfiable member is ignored' => ['bytes=0-1,500-600', null, ['full', 0, 99]],
            'trailing list comma is ignored' => ['bytes=0-1,', null, ['full', 0, 99]],
            'unknown unit' => ['items=0-1', null, ['full', 0, 99]],
            'missing unit' => ['0-1', null, ['full', 0, 99]],
            'empty spec' => ['bytes=-', null, ['full', 0, 99]],
            'negative' => ['bytes=-1-2', null, ['full', 0, 99]],
            'non digits' => ['bytes=a-b', null, ['full', 0, 99]],
            'space inside' => ['bytes= 1-2', null, ['full', 0, 99]],
            'empty header' => ['', null, ['full', 0, 99]],
            'oversized header' => ['bytes=1-2'.str_repeat(' ', 200), null, ['full', 0, 99]],
            'etag of other bytes' => ['bytes=5-', DeliveryByteRange::etag(self::OTHER, self::SCOPE, self::KEY), ['full', 0, 99]],
            'raw content hash is not the etag' => ['bytes=5-', '"'.self::SHA.'"', ['full', 0, 99]],
            'weak etag never matches' => ['bytes=5-', 'W/'.$etag, ['full', 0, 99]],
            'unquoted hash never matches' => ['bytes=5-', self::SHA, ['full', 0, 99]],
            'http date never matches' => ['bytes=5-', 'Sun, 08 Nov 2026 10:00:00 GMT', ['full', 0, 99]],
            'failed if-range ignores an unsatisfiable range' => ['bytes=500-', DeliveryByteRange::etag(self::OTHER, self::SCOPE, self::KEY), ['full', 0, 99]],
        ];
    }

    #[DataProvider('selections')]
    public function test_single_range_selection_follows_rfc_9110(?string $range, ?string $ifRange, array $expected): void
    {
        $selection = DeliveryByteRange::evaluate($range, $ifRange, 100, self::SHA, DeliveryByteRange::etag(self::SHA, self::SCOPE, self::KEY));
        $this->assertSame($expected, [$selection->outcome, $selection->start, $selection->end]);
        $this->assertSame($expected[0] === 'partial', $selection->partial());
        $this->assertSame($expected[0] === 'unsatisfiable', $selection->unsatisfiable());
        $this->assertSame(100, $selection->sizeBytes);
        $this->assertSame(self::SHA, $selection->sha256);
    }

    public function test_one_byte_file_has_no_partial_selection(): void
    {
        $this->assertSame('full', DeliveryByteRange::evaluate('bytes=0-0', null, 1, self::SHA)->outcome);
        $this->assertSame('full', DeliveryByteRange::evaluate('bytes=-1', null, 1, self::SHA)->outcome);
        $this->assertSame('unsatisfiable', DeliveryByteRange::evaluate('bytes=1-', null, 1, self::SHA)->outcome);
    }

    public function test_length_and_strong_etag(): void
    {
        $this->assertSame(10, DeliveryByteRange::evaluate('bytes=10-19', null, 100, self::SHA)->length());
        $etag = DeliveryByteRange::etag(self::SHA, self::SCOPE, self::KEY);
        $key = hash_hmac('sha256', 'vasey-delivery-etag-key-v2', self::KEY, true);
        $this->assertSame('"'.hash_hmac('sha256', self::SCOPE."\0".self::SHA, $key).'"', $etag);
        $this->assertStringNotContainsString(self::SHA, $etag);
        // Identical files are not linkable across orders or deployments, and a missing validator never matches.
        $this->assertNotSame($etag, DeliveryByteRange::etag(self::SHA, 'another-order', self::KEY));
        $this->assertNotSame($etag, DeliveryByteRange::etag(self::SHA, self::SCOPE, 'another-key'));
        $this->assertSame('full', DeliveryByteRange::evaluate('bytes=5-', $etag, 100, self::SHA)->outcome);
        $this->assertSame('partial', DeliveryByteRange::evaluate('bytes=5-', $etag, 100, self::SHA, $etag)->outcome);
    }
}
