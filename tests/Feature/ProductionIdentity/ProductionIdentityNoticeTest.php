<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\IdentityDatabase;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use App\Domain\Customers\ProductionIdentity\Notifications\DefinitelyNotSubmitted;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityAcceptance;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityMail;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

class ProductionIdentityNoticeTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public function test_definite_smtp_refusal_retries_only_after_due_time_then_accepts_once(): void
    {
        $this->requestIdentity();
        $id = DB::table('production_identity_notices')->value('id');
        $this->smtp('reject_rcpt', $id);
        $this->assertSame('definitely_not_submitted', DB::table('production_identity_outcomes')->value('status'));
        (new WorkIdentityNotice)->process($id);
        $this->assertSame(1, DB::table('production_identity_attempts')->count());
        $this->travel(31)->seconds();
        $this->smtp('accept', $id);
        $this->assertSame(['definitely_not_submitted', 'accepted'], DB::table('production_identity_outcomes')->orderBy('id')->pluck('status')->all());
        (new WorkIdentityNotice)->process($id);
        $this->assertSame(2, DB::table('production_identity_attempts')->count());
    }

    public function test_actual_data_received_but_lost_ack_is_unknown_and_never_retried(): void
    {
        $this->requestIdentity();
        $id = DB::table('production_identity_notices')->value('id');
        $mail = $this->smtp('lost_ack', $id);
        $this->assertStringContainsString('/customer/access#enroll.', $mail['data']);
        $this->assertSame('unknown', DB::table('production_identity_outcomes')->value('status'));
        $this->travel(150)->seconds();
        (new WorkIdentityNotice)->process($id);
        $this->assertSame(1, DB::table('production_identity_attempts')->count());
    }

    public function test_expired_claim_is_sealed_unknown_after_challenge_expiry_and_never_replaced(): void
    {
        $this->requestIdentity();
        $id = DB::table('production_identity_notices')->value('id');
        $transport = $this->transport();
        app()->instance(IdentityNoticeTransport::class, $transport);
        Event::listen(TransactionCommitted::class, function (): void {
            config(['production-customer-identity.notifications_enabled' => false]);
        });
        (new WorkIdentityNotice)->process($id); // terminal blocked; remove outcome to simulate worker crash using isolated test DDL
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER pi_outcomes_delete');
        $pdo->exec('DELETE FROM production_identity_outcomes');
        $guard = IdentitySchema::guards(DB::getDriverName())['pi_outcomes_delete'];
        $pdo->exec($guard['sql']);
        Event::forget(TransactionCommitted::class);
        config(['production-customer-identity.notifications_enabled' => true]);
        $this->travel(601)->seconds();
        (new WorkIdentityNotice)->process($id);
        $this->assertSame('unknown', DB::table('production_identity_outcomes')->value('status'));
        $this->assertSame('lease_expired', DB::table('production_identity_outcomes')->value('reason'));
        $this->assertSame(0, $transport->calls);
        $this->assertSame(1, DB::table('production_identity_attempts')->count());
    }

    #[DataProvider('terminalMutations')]
    public function test_commit_callback_withdrawals_are_closed_before_transport_io(string $mutation): void
    {
        $this->completeIdentity($this->requestIdentity());
        $this->requestIdentity('recover');
        $id = DB::table('production_identity_notices')->orderByDesc('id')->value('id');
        $transport = $this->transport();
        app()->instance(IdentityNoticeTransport::class, $transport);
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use ($mutation, &$fired): void {
            if ($fired) {
                return;
            } $fired = true;
            if ($mutation === 'role') {
                DB::table('users')->update(['is_admin' => 1]);
            } elseif ($mutation === 'disabled') {
                config(['production-customer-identity.notifications_enabled' => false]);
            } elseif ($mutation === 'credential') {
                DB::table('users')->update(['password' => 'changed-after-claim']);
            } else {
                $attempt = DB::table('production_identity_attempts')->orderByDesc('id')->first();
                (new IdentityDatabase)->insert('production_identity_outcomes', ['public_id' => (string) Str::uuid(), 'attempt_id' => $attempt->id,
                    'status' => 'unknown', 'reason' => 'transport_uncertain', 'receipt_hash' => str_repeat('0', 64), 'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'next_attempt_at' => now()->utc()->format('Y-m-d H:i:s')]);
            }
        });
        (new WorkIdentityNotice)->process($id);
        $this->assertTrue($fired);
        $this->assertSame(0, $transport->calls);
        $this->assertSame(1, DB::table('production_identity_attempts')->count());
        $this->assertContains(DB::table('production_identity_outcomes')->value('status'), ['blocked', 'unknown']);
    }

    public static function terminalMutations(): array
    {
        return [['role'], ['disabled'], ['credential'], ['outcome']];
    }

    public function test_automatic_retries_stop_after_three_definite_failures_and_scanner_remains_bounded(): void
    {
        $this->requestIdentity();
        $id = DB::table('production_identity_notices')->value('id');
        $transport = $this->transport(true);
        app()->instance(IdentityNoticeTransport::class, $transport);
        (new WorkIdentityNotice)->process($id);
        $this->travel(31)->seconds();
        (new WorkIdentityNotice)->process($id);
        $this->travel(61)->seconds();
        (new WorkIdentityNotice)->process($id);
        $this->travel(91)->seconds();
        (new WorkIdentityNotice)->process($id);
        $this->assertSame(3, $transport->calls);
        $this->assertSame(3, DB::table('production_identity_attempts')->count());
        $this->artisan('customers:process-identity-notices', ['--limit' => 1])->assertExitCode(0);
        $this->artisan('customers:process-identity-notices', ['--limit' => 101])->assertExitCode(2);
        $this->assertSame(3, $transport->calls);
    }

    private function transport(bool $definite = false): IdentityNoticeTransport
    {
        return new class($definite) implements IdentityNoticeTransport
        {
            public int $calls = 0;

            public function __construct(private bool $definite) {}

            public function provenance(): string
            {
                return 'synthetic_rehearsal';
            }

            public function capabilityVersion(): string
            {
                return LoopbackSmtp::CAPABILITY;
            }

            public function submit(IdentityMail $mail): IdentityAcceptance
            {
                $this->calls++;
                if ($this->definite) {
                    throw new DefinitelyNotSubmitted;
                }

                return new IdentityAcceptance(str_repeat('a', 64));
            }
        };
    }
}
