<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberLicenseConfirmationInput;
use App\Domain\Grants\Member\MemberOriginalSourceCapture;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/**
 * C4e review F6: the activation owner's confirmation and activation writes classify their commit with
 * MembershipCommitWitness, like the three C4d writers. A witnessed commit followed by a throwing afterCommit callback
 * stays a committed refusal; an idle PDO before the owned commit call (here a foreign raw rollback) is a plain
 * pre-commit refusal, no longer misread as committed.
 */
class MemberActivationCommitWitnessTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberActivationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_throwing_after_commit_callback_on_a_witnessed_confirmation_is_a_committed_refusal(): void
    {
        $fixture = $this->memberActivationFixture();
        [$issuer, $input] = $this->reviewInput($fixture);
        $armed = true;
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$armed, &$calls): void {
            if (! $armed || ! self::inside(MemberOriginalSourceCapture::class, 'inspectActivation')) {
                return;
            }
            $armed = false;
            DB::afterCommit(function () use (&$calls): void {
                $calls++;

                throw new RuntimeException('synthetic afterCommit failure after the owned physical commit');
            });
        });
        $error = $this->refused(fn () => $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $input, $fixture['buyer']['principal'], $fixture['buyer']['user']), 'committed_activation_receipt_refused');
        $this->assertSame(1, $calls);
        $this->assertInstanceOf(RuntimeException::class, $error->getPrevious());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_the_witness_keeps_a_committed_confirmation_committed_when_a_callback_reopens_a_transaction(): void
    {
        $fixture = $this->memberActivationFixture();
        [$issuer, $input] = $this->reviewInput($fixture);
        $primary = DB::connection()->getPdo();
        $armed = true;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$armed, $primary): void {
            if (! $armed || ! self::inside(MemberOriginalSourceCapture::class, 'inspectActivation')) {
                return;
            }
            $armed = false;
            DB::afterCommit(static function () use ($primary): void {
                // After the owned physical commit a callback leaves a foreign transaction open, so an idle-PDO check
                // alone would now read "not committed". Only the witness still knows the effect is durable.
                $primary->beginTransaction();

                throw new RuntimeException('synthetic afterCommit failure with a reopened transaction');
            });
        });
        try {
            $this->refused(fn () => $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
                $input, $fixture['buyer']['principal'], $fixture['buyer']['user']), 'committed_activation_receipt_refused');
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
        }
        $this->assertFalse($armed);
    }

    public function test_idle_pdo_before_the_owned_commit_call_is_a_pre_commit_refusal(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        [$issuer, $input] = $this->reviewInput($fixture);
        $primary = DB::connection()->getPdo();
        $armed = true;
        $rolledBack = 0;
        $real = Crypt::getFacadeRoot();
        $observed = Mockery::mock();
        $observed->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, $primary, &$armed, &$rolledBack) {
            $plain = $real->decryptString($ciphertext);
            if ($armed && $primary->inTransaction() && self::inside(MemberOriginalSourceCapture::class, 'inspectActivation')) {
                // A foreign raw rollback inside the owned frame: the PDO is idle, nothing is committed.
                $armed = false;
                $primary->rollBack();
                $rolledBack++;
            }

            return $plain;
        });
        Crypt::swap($observed);
        try {
            $this->refused(fn () => $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
                $input, $fixture['buyer']['principal'], $fixture['buyer']['user']), 'source_refused');
        } finally {
            Crypt::swap($real);
        }
        $this->assertSame(1, $rolledBack);
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    private function reviewInput(array $fixture): array
    {
        $issuer = $fixture['operation']->authorizations();
        $display = $issuer->review($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']);

        return [$issuer, MemberLicenseConfirmationInput::from(['display_hash' => $display->hash(), 'affirmed' => true])];
    }

    private static function inside(string $class, string $method): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            if (($frame['class'] ?? null) === $class && ($frame['function'] ?? null) === $method) {
                return true;
            }
        }

        return false;
    }

    private function refused(callable $call, string $reason): MemberGrantException
    {
        try {
            $call();
            $this->fail('Expected the owned activation phase to refuse.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);

            return $error;
        }
    }
}
