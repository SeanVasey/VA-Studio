<?php

namespace App\Support\Money;

use InvalidArgumentException;

/** Exact proportional allocation with stable offer-revision tie breaking. */
final class AllocateDiscount
{
    public const ALGORITHM = 'largest_remainder_v1';

    public function handle(array $lines, mixed $discount): array
    {
        $discount = MinorUnits::amount($discount);
        if (! array_is_list($lines) || count($lines) < 1 || count($lines) > 10) {
            throw new InvalidArgumentException('Invalid discount selection.');
        }
        $ids = [];
        foreach ($lines as $line) {
            if (! is_array($line) || array_keys($line) !== ['offer_revision_id', 'base_minor'] ||
                MinorUnits::amount($line['offer_revision_id']) < 1 || isset($ids[$line['offer_revision_id']]) ||
                MinorUnits::amount($line['base_minor']) < 1) {
                throw new InvalidArgumentException('Invalid discount line.');
            }
            $ids[$line['offer_revision_id']] = true;
        }
        $basis = MinorUnits::sum(array_column($lines, 'base_minor'));
        if ($discount > $basis) {
            throw new InvalidArgumentException('A discount cannot exceed its eligible subtotal.');
        }
        $allocations = [];
        foreach ($lines as $line) {
            [$floor, $remainder] = $this->fraction($line['base_minor'], $discount, $basis);
            $allocations[] = $line + ['floor_minor' => $floor, 'remainder' => $remainder,
                'denominator' => $basis, 'extra_minor' => 0, 'discount_minor' => $floor];
        }
        // Return the trace in stable identity order, independently of input order.
        usort($allocations, static fn ($a, $b) => $a['offer_revision_id'] <=> $b['offer_revision_id']);
        $ranked = array_keys($allocations);
        usort($ranked, static fn ($a, $b) => ($allocations[$b]['remainder'] <=> $allocations[$a]['remainder']) ?:
            ($allocations[$a]['offer_revision_id'] <=> $allocations[$b]['offer_revision_id']));
        $remaining = $discount - MinorUnits::sum(array_column($allocations, 'floor_minor'));
        foreach (array_slice($ranked, 0, $remaining) as $index) {
            $allocations[$index]['extra_minor'] = 1;
            $allocations[$index]['discount_minor']++;
        }

        return ['algorithm' => self::ALGORITHM, 'eligible_subtotal_minor' => $basis,
            'discount_minor' => $discount, 'allocations' => $allocations];
    }

    /** a <= denominator and b <= MAX. Every intermediate is below 3*MAX, safely
     * inside a 64-bit PHP integer, even when a*b itself would overflow.
     */
    private function fraction(int $a, int $b, int $denominator): array
    {
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($b)) as $bit) {
            $remainder = 2 * $remainder + ($bit === '1' ? $a : 0);
            $quotient = 2 * $quotient + intdiv($remainder, $denominator);
            $remainder %= $denominator;
        }

        return [$quotient, $remainder];
    }
}
