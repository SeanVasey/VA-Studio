<?php

namespace Tests\Review;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RetainedDeadlineCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_original_capture_deadline_cannot_be_extended_during_consumption(): void
    {
        $fixture = QuoteFixtures::selection();
        $service = app(CurrentEligibleTrackSnapshot::class);
        $snapshot = $service->capture();
        $this->assertSame(['/tracks/'.$fixture['track']->slug], $snapshot->paths());
        $evidence = json_decode(Crypt::decryptString($snapshot->evidence()), true, 16, JSON_THROW_ON_ERROR);
        $deadline = CarbonImmutable::parse($evidence['expires_at']);
        $this->assertSame(now()->toImmutable()->addSeconds(CurrentEligibleTrackSnapshot::SECONDS)->toISOString(), $deadline->toISOString());
        $this->travelTo($deadline->subSecond());
        $armed = true;
        DB::listen(function ($query) use (&$armed, $deadline): void {
            if ($armed && preg_match('/from ["`]tracks["`].*limit 49/i', $query->sql)) {
                $armed = false;
                $this->travelTo($deadline);
            }
        });
        try {
            $service->currentPaths($snapshot);
            $this->fail('Retained evidence escaped after its original capture deadline elapsed during consumption.');
        } catch (LogicException) {
            $this->assertFalse($armed);
            $this->assertSame(0, DB::transactionLevel());
        }
    }
}
