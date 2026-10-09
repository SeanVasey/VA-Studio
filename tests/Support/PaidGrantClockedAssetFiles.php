<?php

namespace Tests\Support;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;

use function App\Domain\Grants\Paid\hrtime as paidHrtime;

/**
 * Test-only DeliveryAssetFiles that applies the deadline paid code hands to the asset hash on the virtual paid-namespace
 * clock (PaidGrantMonotonicClock), as the real adapter would on a real slow clock. The real adapter reads the real clock
 * (`App\Domain\Delivery`), so without this a paid regression that passed an already lapsed budget to the asset hash would
 * still pass under the virtual clock (independent review A10-I2). A lapsed deadline is refused exactly as the adapter
 * refuses (`DeliveryException('asset_unavailable')`) and recorded; a missing deadline or one further away than the expected
 * bound is recorded as a violation. Otherwise the real verification runs unchanged.
 */
final class PaidGrantClockedAssetFiles extends DeliveryAssetFiles
{
    public int $verified = 0;

    public int $lapsed = 0;

    /** @var list<string> */
    public array $violations = [];

    public function __construct(private readonly int $maximumSeconds = 300) {}

    public function verify(array $entry, ?int $deadline = null): void
    {
        $this->verified++;
        $now = paidHrtime(true);
        if ($deadline === null) {
            $this->violations[] = 'no deadline';
        } elseif ($deadline > $now + $this->maximumSeconds * 1_000_000_000) {
            $this->violations[] = sprintf('deadline %.1f s away, past the %d s bound', ($deadline - $now) / 1e9, $this->maximumSeconds);
        } elseif ($deadline < $now) {
            $this->lapsed++;
            throw new DeliveryException('asset_unavailable');
        }
        parent::verify($entry, $deadline);
    }
}
