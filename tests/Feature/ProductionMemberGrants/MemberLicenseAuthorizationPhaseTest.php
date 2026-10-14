<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberLicenseConfirmationInput;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\Support\MemberOriginalCommittedClock;
use Tests\TestCase;

require_once __DIR__.'/../../Support/MemberOriginalCommittedClock.php';

/** Real confirmation phases; reflection only observes registrations or narrows an actually issued cap. */
class MemberLicenseAuthorizationPhaseTest extends TestCase
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
        MemberOriginalCommittedClock::$onFinish = null;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_confirmation_rebuilds_the_display_and_rejects_a_different_hash_without_a_ticket(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $issuer = $fixture['operation']->authorizations();
        $error = $this->refused(fn () => $issuer->confirm($fixture['member_authored']->definitionId,
            $fixture['reservation']['redemption_id'], MemberLicenseConfirmationInput::from(['display_hash' => str_repeat('a', 64), 'affirmed' => true]),
            $fixture['buyer']['principal'], $fixture['buyer']['user']), 'source_refused');
        $this->assertInstanceOf(MemberGrantException::class, $error->getPrevious());
        $this->assertSame('changed_confirmation_display', $error->getPrevious()->reason);
        $this->assertCount(0, self::raw($issuer, 'tickets'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_current_facts_callback_cannot_withdraw_the_reservation_catalog_then_issue_confirmation(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        [$issuer, $input] = $this->reviewInput($fixture);
        $armed = false;
        $changed = 0;
        $primary = DB::connection()->getPdo();
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use (&$armed): void {
            $armed = true;
        });
        $real = Crypt::getFacadeRoot();
        $observed = Mockery::mock();
        $observed->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, $primary, $fixture, &$armed, &$changed) {
            $plain = $real->decryptString($ciphertext);
            if ($armed && self::inside(SealedMemberGrantFacts::class, 'currentCallbacks')) {
                $armed = false;
                $statement = $primary->prepare('UPDATE tracks SET status = ? WHERE id = ?');
                $statement->execute(['draft', $fixture['catalog']['track']->id]);
                $changed += $statement->rowCount();
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
        $this->assertSame(1, $changed);
        $this->assertSame('published', DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status'));
        $this->assertCount(0, self::raw($issuer, 'tickets'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_normal_committed_callback_withdrawal_cannot_issue_the_detached_confirmation(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        [$issuer, $input] = $this->reviewInput($fixture);
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, static function () use (&$calls): void {
            config(['member-grants.enabled' => false]);
            $calls++;
        });
        $this->refused(fn () => $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $input, $fixture['buyer']['principal'], $fixture['buyer']['user']), 'committed_activation_receipt_refused');
        $this->assertSame(1, $calls);
        $this->assertCount(0, self::raw($issuer, 'tickets'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_original_invoice_cap_is_checked_after_the_confirmation_shared_finish(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        [$issuer, $input] = $this->reviewInput($fixture);
        $reservations = app(MembershipReservationAuthority::class);
        $consumed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($reservations, &$consumed): void {
            foreach (self::raw($reservations, 'activationCaptures') as $entry) {
                $window = self::raw($entry['context'], 'invoiceFreshness');
                MemberOriginalCommittedClock::$onFinish = function () use ($window, &$consumed): void {
                    $this->assertGreaterThan(hrtime(true), $window->deadlineNs());
                    // Synthetic consumption of the real original receipt cap; no replacement or clock renewal.
                    (new ReflectionProperty($window, 'deadline'))->setValue($window, hrtime(true) - 1);
                    $consumed++;
                };
            }
        });
        $this->refused(fn () => $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $input, $fixture['buyer']['principal'], $fixture['buyer']['user']), 'committed_activation_receipt_refused');
        $this->assertSame(1, $consumed);
        $this->assertCount(0, self::raw($issuer, 'tickets'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_genuine_ticket_is_consumed_once_even_when_later_live_source_admission_fails(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $ticket = $this->confirmMemberActivation($fixture);
        $issuer = $fixture['operation']->authorizations();
        $this->assertCount(1, self::raw($issuer, 'tickets'));
        DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->update(['status' => 'draft']);
        $this->refused(fn () => $this->activateMemberFixture($fixture, $ticket), 'source_refused');
        $this->assertCount(0, self::raw($issuer, 'tickets'));
        $this->refused(fn () => $this->activateMemberFixture($fixture, $ticket), 'foreign_authorization');
        $this->assertSame([], $this->memberActivationFiles($fixture['reservation']['redemption_id']));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_confirmation_retires_live_capture_and_keeps_the_same_original_budget_for_the_ticket(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        [$issuer, $input] = $this->reviewInput($fixture);
        $reservations = app(MembershipReservationAuthority::class);
        $saved = [];
        $budget = null;
        $sources = self::raw($fixture['operation'], 'sources');
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($sources, $reservations, &$saved, &$budget): void {
            foreach (self::raw($sources, 'workflows') as $original => $_) {
                $budget = $original;
            }
            foreach (self::raw($reservations, 'activationCaptures') as $capture => $entry) {
                $saved = [$capture, $entry['rows']];
            }
        });
        $ticket = $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $input, $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertInstanceOf(MemberOriginalBudget::class, $budget);
        $this->assertSame($budget, self::raw($issuer, 'tickets')[$ticket]['budget']);
        $this->assertCount(0, self::raw($reservations, 'activationCaptures'));
        try {
            $reservations->activationIntent($saved[0], $saved[1]);
            $this->fail('Retired capture was admitted.');
        } catch (MembershipException $error) {
            $this->assertSame('foreign_member_capture', $error->reason);
        }
        $saved = [];
        // The actual ticket retains the exact original object, so narrowing it refuses before detached work.
        $budget->shortenTo(hrtime(true) - 1);
        $this->refused(fn () => $this->activateMemberFixture($fixture, $ticket), 'workflow_deadline');
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    private function reviewInput(array $fixture): array
    {
        $issuer = $fixture['operation']->authorizations();
        $display = $issuer->review($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']);

        return [$issuer, MemberLicenseConfirmationInput::from(['display_hash' => $display->hash(), 'affirmed' => true])];
    }

    private static function raw(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    private static function inside(string $class, string $method): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50) as $frame) {
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
            $this->fail('Expected the real owned phase to refuse.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);

            return $error;
        }
    }
}
