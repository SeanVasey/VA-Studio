<?php

namespace Tests\Feature\ReviewProbes;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipRows;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use Pdo\Sqlite;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as B;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberCreditEventFixtures as F;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;
use Throwable;

/**
 * Independent reviewer probes for harness/membership-operative-1 at c689ffdc. Evidence only; not part of
 * the suite. Each test asserts the OBSERVED behaviour and prints a one-line observation so the JUnit and
 * text logs carry the result. Synthetic values only.
 */
class ReviewerOperativeProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static int $calls = 0;

    // ---- F1: SQLite callbacks after capture -------------------------------------------------------

    /** A variadic lower() is not a replacement of built-in lower(1), so the running pin does not block it. */
    public function test_f1_variadic_lower_registered_after_capture_is_refused_by_the_catalog(): void
    {
        $this->sqliteOnly();
        config(['production-memberships.enabled' => true]);
        try {
            DB::transaction(function () {
                $rows = new MembershipRows;
                /** @var Sqlite $pdo */
                $pdo = DB::connection()->getPdo();
                self::$calls = 0;
                $registered = $pdo->createFunction('lower', $this->withdrawing(), -1);
                $reason = $this->reason(fn () => $rows->assertCurrent());
                $this->observe('F1 variadic lower after capture: registered='.var_export($registered, true).' assertCurrent='.$reason.' calls='.self::$calls);
                $this->assertTrue($registered, 'SQLite admits lower(-1) beside built-in lower(1) even with the pin held');
                $this->assertSame('changed_primary_or_schema', $reason);
                $this->assertSame(0, self::$calls);
                $this->assertTrue(config('production-memberships.enabled'));
            });
        } finally {
            DB::purge();
        }
    }

    /** max(a, b) with nArg 2 beside the variadic built-in scalar max(-1). */
    public function test_f1_two_argument_max_registered_after_capture_is_refused_by_the_catalog(): void
    {
        $this->sqliteOnly();
        config(['production-memberships.enabled' => true]);
        try {
            DB::transaction(function () {
                $rows = new MembershipRows;
                /** @var Sqlite $pdo */
                $pdo = DB::connection()->getPdo();
                self::$calls = 0;
                $registered = $pdo->createFunction('max', $this->withdrawing(), 2);
                $reason = $this->reason(fn () => $rows->one('production_membership_plan_versions', 'id = ?', ['none']));
                $this->observe('F1 max(2) after capture: registered='.var_export($registered, true).' one()='.$reason.' calls='.self::$calls);
                $this->assertSame('changed_primary_or_schema', $reason);
                $this->assertSame(0, self::$calls);
            });
        } finally {
            DB::purge();
        }
    }

    /** The pin must still block a BINARY/NOCASE replacement after several savepoint cycles and reads. */
    public function test_f1_pin_still_blocks_collation_replacement_after_repeated_savepoint_cycles(): void
    {
        $this->sqliteOnly();
        config(['production-memberships.enabled' => true]);
        try {
            DB::transaction(function () {
                $rows = new MembershipRows;
                for ($i = 0; $i < 3; $i++) {
                    $rows->assertCurrent();
                }
                $rows->rows('production_membership_plan_versions', 'id = ?', ['none'], 2);
                /** @var Sqlite $pdo */
                $pdo = DB::connection()->getPdo();
                $binary = $pdo->createCollation('BINARY', static fn (string $a, string $b): int => strcmp($a, $b));
                $nocase = $pdo->createCollation('NOCASE', static fn (string $a, string $b): int => strcasecmp($a, $b));
                $lower = $pdo->createFunction('lower', static fn ($v) => $v, 1);
                $this->observe('F1 pin after cycles: BINARY='.var_export($binary, true).' NOCASE='.var_export($nocase, true).' lower(1)='.var_export($lower, true));
                $this->assertFalse($binary);
                $this->assertFalse($nocase);
                $this->assertFalse($lower);
                $rows->assertCurrent();
            });
        } finally {
            DB::purge();
        }
    }

    /** Residual: an application function that reuses an allowlisted extension name is admitted by the catalog check. */
    public function test_f1_allowlisted_extension_name_registered_by_the_application_is_admitted(): void
    {
        $this->sqliteOnly();
        config(['production-memberships.enabled' => true]);
        /** @var Sqlite $pdo */
        $pdo = DB::connection()->getPdo();
        self::$calls = 0;
        $pdo->createFunction('snippet', $this->withdrawing(), -1);
        $pdo->createFunction('highlight', $this->withdrawing(), -1);
        try {
            $reason = $this->reason(fn () => DB::transaction(fn () => (new MembershipRows)->assertCurrent()));
            $this->observe('F1 allowlisted extension names snippet/highlight registered by app: reader='.$reason.' calls='.self::$calls);
            $this->assertSame('admitted', $reason);
            $this->assertSame(0, self::$calls, 'Owned SQL never calls snippet()/highlight().');
        } finally {
            DB::purge();
        }
    }

    // ---- O3: environment read --------------------------------------------------------------------

    /**
     * Residual: a closure that is bound to the container scope, captures exactly `$value`, but returns
     * something else passes the shape check. The policy reads the captured value; Laravel resolves the body.
     */
    public function test_o3_forged_container_scoped_closure_diverges_from_the_resolved_environment(): void
    {
        $this->membershipRehearsal();
        B::configure();
        $value = 'testing';
        $forged = Closure::bind(function () use ($value) {
            ReviewerOperativeProbeTest::$calls++;

            return 'production';
        }, $this->app, Container::class);
        self::$calls = 0;
        $this->app->bind('env', $forged);
        $membership = (new MembershipPolicy)->current();
        $billing = (new BillingPolicy)->current();
        $calls = self::$calls;
        $resolved = $this->app->environment();
        $this->observe('O3 forged closure: policy env='.$membership['environment'].' billing env='.$billing['environment'].' calls during policy reads='.$calls.' app()->environment()='.$resolved);
        $this->assertSame(0, $calls, 'The reflection read never invokes the binding.');
        $this->assertSame('testing', $membership['environment']);
        $this->assertSame('testing', $billing['environment']);
        $this->assertSame('production', $resolved);
        // Restore before tearDown: the migration teardown confirms interactively in production (run 1 harness error).
        $this->app->detectEnvironment(fn () => 'testing');
    }

    /** An env instance keeps container precedence in MembershipPolicy (consistent with app()->environment()); BillingPolicy refuses it. */
    public function test_o3_env_instance_precedence(): void
    {
        $this->membershipRehearsal();
        B::configure();
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->instance('env', 'testing');
        $membership = (new MembershipPolicy)->current();
        $billing = $this->billingReason();
        $this->observe('O3 instance: membership env='.$membership['environment'].' app()->environment()='.$this->app->environment().' billing='.$billing);
        $this->assertSame('testing', $membership['environment']);
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('changed_policy', $billing);
        unset($this->app['env']);
        $this->app->detectEnvironment(fn () => 'testing');
    }

    /** A destroyed clone shares the pin statement; its destructor closes the pin of the live frame. */
    public function test_f1_destroyed_clone_releases_the_pin_of_the_live_frame(): void
    {
        $this->sqliteOnly();
        config(['production-memberships.enabled' => true]);
        try {
            DB::transaction(function () {
                $rows = new MembershipRows;
                $copy = clone $rows;
                unset($copy);
                /** @var Sqlite $pdo */
                $pdo = DB::connection()->getPdo();
                self::$calls = 0;
                $registered = $pdo->createCollation('BINARY', static function (string $a, string $b): int {
                    ReviewerOperativeProbeTest::$calls++;

                    return strcmp($a, $b);
                });
                $reason = $this->reason(fn () => $rows->assertCurrent());
                $this->observe('F1 clone+unset then BINARY replace: registered='.var_export($registered, true).' assertCurrent='.$reason.' collation calls during reader='.self::$calls);
                $this->assertTrue(true);
            });
        } finally {
            DB::purge();
        }
    }

    /** Forward check: the 258 policy is not part of the F1 driver refusal. */
    public function test_f1_member_grant_policy_verified_production_on_sqlite(): void
    {
        $this->sqliteOnly();
        config(['member-grants.enabled' => true, 'member-grants.provenance' => IdentityPolicy::PRODUCTION,
            'member-grants.approved_definition_hash' => str_repeat('a', 64), 'member-grants.approved_profile_hash' => str_repeat('b', 64),
            'member-grants.approved_original_terms_hash' => str_repeat('c', 64)]);
        try {
            (new \App\Domain\Grants\Member\MemberGrantPolicy)->current();
            $reason = 'admitted';
        } catch (\App\Domain\Grants\Member\MemberGrantException $error) {
            $reason = $error->reason;
        }
        $this->observe('F1 MemberGrantPolicy verified_production on sqlite: '.$reason);
        $this->assertSame('capability_absent', $reason, 'passes the provenance gate on SQLite; stops only at absent capabilities');
    }

    // ---- F2: activation/consume coupling ---------------------------------------------------------

    public function test_f2_reservation_hash_of_award_or_consume_seal_is_refused(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        [$award, $reserve] = F::reserve($graph['redemption']['id']);
        $consume = F::consume($reserve, $graph['origin']['id'], $receipt);
        $results = [];
        foreach (['award' => $award['seal'], 'consume' => $consume['seal']] as $label => $seal) {
            $results[$label] = $this->insertActivation(F::activation($graph['origin'], $receipt, $seal));
        }
        $results['reserve'] = $this->insertActivation(F::activation($graph['origin'], $receipt, $reserve['seal']));
        $this->observe('F2 reservation hash = award/consume/reserve seal: '.json_encode($results));
        $this->assertSame(['award' => 'refused', 'consume' => 'refused', 'reserve' => 'admitted'], $results);
    }

    /** A consume row claiming redemption R1 against a reserve of R2 (same period). */
    public function test_f2_consume_with_mismatched_reserve_redemption(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        $other = F::redemption($graph['period']);
        [, $reserveOther] = F::reserve($other['id']);
        $consume = 'admitted';
        try {
            F::consume($reserveOther, $graph['origin']['id'], $receipt, '2026-10-07 00:00:03', $graph['redemption']['id']);
        } catch (PDOException) {
            $consume = 'refused_by_257';
        }
        $activation = $this->insertActivation(F::activation($graph['origin'], $receipt, $reserveOther['seal']));
        $this->observe('F2 consume(R1) against reserve(R2): consume='.$consume.' activation='.$activation);
        $this->assertSame('refused', $activation);
    }

    /** Boundary: equal created_at is admitted by design (`<=`). */
    public function test_f2_equal_timestamp_is_admitted_and_one_second_earlier_activation_is_refused(): void
    {
        $graph = F::graph();
        $receipt = hash('sha256', 'synthetic readiness receipt');
        [, $reserve] = F::reserve($graph['redemption']['id']);
        F::consume($reserve, $graph['origin']['id'], $receipt, '2026-10-07 00:00:04');
        $earlier = $this->insertActivation([...F::activation($graph['origin'], $receipt, $reserve['seal']), 'created_at' => '2026-10-07 00:00:03']);
        $equal = $this->insertActivation(F::activation($graph['origin'], $receipt, $reserve['seal']));
        $this->observe('F2 timestamps: earlier='.$earlier.' equal='.$equal);
        $this->assertSame(['refused', 'admitted'], [$earlier, $equal]);
    }

    // ---- Billing ---------------------------------------------------------------------------------

    /** Sync queue: the hint commits, dispatch raises (no gateway bound), and a Stripe redelivery is a duplicate that schedules nothing. */
    public function test_billing_failed_dispatch_after_commit_is_not_rescheduled_by_redelivery(): void
    {
        B::configure();
        $binding = B::binding();
        $payload = $this->billingEvent('evt_SYNTHETICPROBE1', 'invoice.paid');
        $first = 'returned';
        try {
            (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, 'whsec_SYNTHETICREHEARSAL'));
        } catch (Throwable $error) {
            $first = 'threw '.(new \ReflectionClass($error))->getShortName();
        }
        $redelivery = (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, 'whsec_SYNTHETICREHEARSAL'));
        $this->observe('Billing sync dispatch: first='.$first.' events='.DB::table('production_membership_billing_events')->count()
            .' redelivery duplicate='.var_export($redelivery['duplicate'], true).' scheduled='.json_encode($redelivery['scheduled'])
            .' observations='.DB::table('production_membership_billing_observations')->count().' binding='.$binding['id']);
        $this->assertStringStartsWith('threw', $first);
        $this->assertTrue($redelivery['duplicate']);
        $this->assertNull($redelivery['scheduled']);
        $this->assertSame(0, DB::table('production_membership_billing_observations')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    /** A first retrieval under the wrong binding creates an immutable invoice identity that blocks the right binding. */
    public function test_billing_first_retrieval_under_wrong_binding_pins_the_invoice_identity(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(B::PERIOD_START + 3600));
        try {
            B::configure();
            $wrong = B::binding(['subscription_ref' => 'sub_WRONGSYNTHETIC']);
            $right = B::binding();
            $gateway = new RehearsalBillingGateway(B::graph());
            $first = (new BillingReconciliation($gateway))->retrieve($wrong['id'], B::INVOICE);
            $second = 'returned';
            try {
                (new BillingReconciliation(new RehearsalBillingGateway(B::graph())))->retrieve($right['id'], B::INVOICE);
            } catch (BillingException $error) {
                $second = $error->reason;
            }
            $this->observe('Billing wrong binding first: first outcome='.$first['outcome'].' reason='.(BillingValues::decrypt($first['payload_ciphertext'])['reason'] ?? 'null')
                .' then right binding='.$second.' invoices='.DB::table('production_membership_billing_invoices')->count());
            $this->assertSame('refused', $first['outcome']);
            $this->assertSame('conflicting_invoice', $second);
            $this->assertSame(0, DB::table('production_membership_credit_events')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** A valid signature with a body that claims paid never produces an observation or a credit event. */
    public function test_billing_signed_paid_event_alone_appends_no_observation(): void
    {
        B::configure();
        \Illuminate\Support\Facades\Queue::fake();
        B::binding();
        $payload = $this->billingEvent('evt_SYNTHETICPROBE2', 'invoice.paid');
        $result = (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, 'whsec_SYNTHETICREHEARSAL'));
        $this->observe('Billing signed paid event: disposition='.$result['event']['disposition'].' observations='
            .DB::table('production_membership_billing_observations')->count().' credit_events='.DB::table('production_membership_credit_events')->count());
        $this->assertSame(0, DB::table('production_membership_billing_observations')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    // ---- helpers -----------------------------------------------------------------------------------

    private function billingEvent(string $id, string $type): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'livemode' => false, 'account' => null, 'api_version' => '2026-08-26.dahlia',
            'created' => B::PERIOD_START + 60, 'data' => ['object' => ['id' => B::INVOICE, 'object' => 'invoice', 'status' => 'paid', 'amount_paid' => B::AMOUNT,
                'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => B::SUBSCRIPTION]]]]], JSON_THROW_ON_ERROR);
    }

    private function insertActivation(array $row): string
    {
        try {
            F::insert(MemberGrantSchema::TABLES[4], $row);

            return 'admitted';
        } catch (PDOException) {
            return 'refused';
        }
    }

    private function membershipRehearsal(): void
    {
        config(['production-memberships.enabled' => true, 'production-memberships.version' => MembershipPolicy::VERSION,
            'production-memberships.provenance' => IdentityPolicy::REHEARSAL, 'production-memberships.approved_policy_hash' => str_repeat('a', 64)]);
        foreach ([MembershipPaidInvoiceAuthority::class, MembershipPolicyFactsAuthority::class, MembershipEligibleLicenseAuthority::class, MemberGrantAuthority::class] as $capability) {
            $this->app->instance($capability, Mockery::mock($capability));
        }
    }

    private function billingReason(): string
    {
        try {
            (new BillingPolicy)->current();

            return 'admitted';
        } catch (BillingException $error) {
            return $error->reason;
        }
    }

    private function sqliteOnly(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite callback surface only.');
        }
    }

    private function reason(Closure $operation): string
    {
        try {
            $operation();

            return 'admitted';
        } catch (MembershipException $error) {
            return $error->reason;
        }
    }

    private function withdrawing(): Closure
    {
        return static function (mixed ...$arguments): mixed {
            self::$calls++;
            config(['production-memberships.enabled' => false]);

            return $arguments[0] ?? null;
        };
    }

    private function observe(string $line): void
    {
        fwrite(STDERR, 'OBSERVATION '.$line.PHP_EOL);
        $this->addToAssertionCount(0);
        Str::of($line);
    }
}
