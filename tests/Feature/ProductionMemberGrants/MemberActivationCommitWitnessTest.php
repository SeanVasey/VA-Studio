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
use PDO;
use PDOException;
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

    /** A real SQLite commit failure; no replacement PDO, mock commit, or thrown commit delegate. */
    public function test_sqlite_real_commit_constraint_failure_keeps_the_activation_pre_commit_refusal(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Deferred FK commit failure uses the actual SQLite PDO; native MySQL has no deferred FK equivalent.');
        }
        $fixture = $this->memberActivationFixture();
        [$issuer, $input] = $this->reviewInput($fixture);
        $before = $this->memberOriginalEffects();
        $auditCount = DB::table('audit_events')->count();
        $missingActor = (int) DB::table('users')->max('id') + 100000;
        $primary = DB::connection()->getPdo();
        $this->assertSame(1, (int) $primary->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame(0, (int) $primary->query('PRAGMA defer_foreign_keys')->fetchColumn());
        $injected = 0;
        $transactional = false;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($primary, $missingActor, &$injected, &$transactional): void {
            if ($injected !== 0 || ! self::inside(MemberOriginalSourceCapture::class, 'inspectActivation')) {
                return;
            }
            // Keep the exact admitted schema and foreign_keys=ON. This existing FK is checked at the physical
            // commit after the delegate returns and the original observer's full terminal proof has run.
            $primary->exec('PRAGMA defer_foreign_keys=ON');
            $statement = $primary->prepare('INSERT INTO audit_events (actor_id, action, subject_type, subject_id, context) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$missingActor, 'synthetic.commit.failure', 'synthetic', 1, '{}']);
            $injected++;
            $transactional = $primary->inTransaction();
        });
        try {
            $error = $this->refused(fn () => $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
                $input, $fixture['buyer']['principal'], $fixture['buyer']['user']), 'source_refused');
        } finally {
            // Snapshot product cleanup before any test-only recovery can hide a retained transaction.
            $depthAfterProduct = DB::transactionLevel();
            $transactionAfterProduct = $primary->inTransaction();
            $deferAfterProduct = (int) $primary->query('PRAGMA defer_foreign_keys')->fetchColumn();
            if ($transactionAfterProduct) {
                DB::connection()->rollBack();
            }
            $primary->exec('PRAGMA defer_foreign_keys=OFF');
        }
        $this->assertSame(0, $depthAfterProduct);
        $this->assertFalse($transactionAfterProduct, 'The original observer must roll back its still-active owned PDO after commit failure.');
        $this->assertSame(0, $deferAfterProduct);
        $this->assertSame(1, $injected, 'Fault setup must return successfully; an INSERT/delegate exception is not commit failure.');
        $this->assertTrue($transactional);
        $this->assertInstanceOf(PDOException::class, $error->getPrevious());
        $cause = $error->getPrevious();
        $this->assertSame('23000', (string) $cause->getCode());
        $physicalCommit = array_filter($cause->getTrace(), static fn (array $frame): bool =>
            in_array($frame['class'] ?? null, [PDO::class, 'Pdo\\Sqlite'], true) && ($frame['function'] ?? null) === 'commit');
        $this->assertNotEmpty($physicalCommit, 'The exception must originate in actual internal PDO::commit, not a callback.');
        $this->assertSame($auditCount, DB::table('audit_events')->count());
        $this->assertMemberOriginalEffectsUnchanged($before);
        $this->assertSame(0, (int) $primary->query('PRAGMA defer_foreign_keys')->fetchColumn());
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
