<?php

namespace Tests\Feature;

use App\Domain\Memberships\CreditLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures;
use Tests\TestCase;

class MembershipGrantClockBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_award_deadline_and_current_spendability_share_the_exact_retained_creation_instant(): void
    {
        MembershipFixtures::configure();
        $f = MembershipFixtures::plan() + CustomerFixtures::account();
        $tick = 0;
        $base = CarbonImmutable::parse('2026-10-06 21:00:00', 'UTC');
        Carbon::setTestNow(function () use ($base, &$tick): CarbonImmutable {
            return $base->addSeconds($tick++);
        });
        try {
            $grant = app(CreditLedger::class)->grantSynthetic($f['version'], $f['account'], 'synthetic:grant_clock_boundary', $f['operator']);
            $bucket = DB::table('membership_credit_buckets')->find($grant['bucket_id']);
            $this->assertSame(CarbonImmutable::parse($bucket->created_at, 'UTC')->addSeconds(3600)->format('Y-m-d H:i:s'), $bucket->expires_at);
            $read = app(CreditLedger::class)->read($grant['bucket_id'], $f['principal'], $f['user']);
            $this->assertSame(3, $read['spendable_credits']);
            $this->assertSame(1, DB::table('membership_credit_buckets')->count());
            $this->assertSame(1, DB::table('membership_credit_events')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'membership.test_credit.grant')->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}
