<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantDefinitions;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberGrantDocumentFixtures;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

/** InnoDB: the physical commit witness, observed from an independent connection, survives a throwing callback. */
class MembershipNativeCommitWitnessTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipReservationFixtures;

    private ?PDO $independent = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The InnoDB physical commit is observed from an independent native MySQL connection.');
        }
        $this->reservationSetup();
        DB::connection()->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    protected function tearDown(): void
    {
        $this->independent = null;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_native_throwing_after_commit_reservation_is_committed_on_innodb_and_replays(): void
    {
        $fixture = $this->reservationFixture();
        $calls = $this->throwAfterCommitOnce();
        try {
            $this->reserveFixture($fixture);
            $this->fail('Expected a committed receipt refusal.');
        } catch (MembershipException $error) {
            $this->assertSame('committed_receipt_refused', $error->reason);
        }
        $this->assertSame(1, $calls->value);
        $this->assertNoOpenInnoDbTransaction();
        // A separate session sees the durable effect: the commit is physical, not a cached PDO state.
        $redemptions = $this->independentRows('SELECT id FROM production_membership_redemptions');
        $this->assertCount(1, $redemptions);
        $this->assertSame(2, (int) $this->independentRows('SELECT COUNT(*) c FROM production_membership_credit_events')[0]['c']);
        $replayed = $this->reserveFixture($fixture);
        $this->assertSame($redemptions[0]['id'], $replayed['redemption_id']);
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_native_throwing_after_commit_award_is_committed_on_innodb_and_replays(): void
    {
        $fixture = $this->reservationFixture(award: false);
        $calls = $this->throwAfterCommitOnce();
        $award = fn () => (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        try {
            $award();
            $this->fail('Expected a committed receipt refusal.');
        } catch (MembershipException $error) {
            $this->assertSame('committed_receipt_refused', $error->reason);
        }
        $this->assertSame(1, $calls->value);
        $this->assertNoOpenInnoDbTransaction();
        $periods = $this->independentRows('SELECT id FROM production_membership_paid_periods');
        $this->assertCount(1, $periods);
        $this->assertSame($periods[0]['id'], $award()['period_id']);
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_native_throwing_after_commit_authoring_is_committed_on_innodb(): void
    {
        $author = $this->policyStaff();
        $plan = $this->createPolicy($author);
        config(['member-grants.authoring_enabled' => true, 'member-grants.provenance' => 'synthetic_rehearsal']);
        $profile = MemberGrantProfileDocument::from(MemberGrantDocumentFixtures::profile());
        $document = MemberGrantDocumentFixtures::definition();
        $document['plan_version_id'] = $plan['id'];
        $document['policy_hash'] = $plan['policy_hash'];
        $definition = MemberGrantDefinitionDocument::from($document);
        $calls = $this->throwAfterCommitOnce();
        try {
            (new MemberGrantDefinitions)->author($profile, $definition, $author);
            $this->fail('Expected a committed authoring receipt refusal.');
        } catch (MemberGrantException $error) {
            $this->assertSame('committed_receipt_refused', $error->reason);
        }
        $this->assertSame(1, $calls->value);
        $this->assertNoOpenInnoDbTransaction();
        $definitions = $this->independentRows('SELECT id FROM production_member_definitions');
        $this->assertCount(1, $definitions);
        $this->assertSame($definitions[0]['id'], (new MemberGrantDefinitions)->author($profile, $definition, $author)->definitionId);
        $this->assertSame(1, DB::table(MemberGrantRecords::DEFINITIONS)->count());
    }

    private function throwAfterCommitOnce(): object
    {
        $calls = new class
        {
            public int $value = 0;

            public bool $armed = true;
        };
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($calls): void {
            if (! $calls->armed) {
                return;
            }
            $calls->armed = false;
            DB::afterCommit(function () use ($calls): void {
                $calls->value++;
                throw new RuntimeException('Synthetic ordinary afterCommit failure.');
            });
        });

        return $calls;
    }

    private function assertNoOpenInnoDbTransaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $pdo = DB::connection()->getPdo();
        $this->assertFalse($pdo->inTransaction());
        $open = $pdo->query('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()')->fetchColumn();
        $this->assertSame(0, (int) $open);
    }

    private function independentRows(string $sql): array
    {
        if ($this->independent === null) {
            $config = DB::connection()->getConfig();
            $this->independent = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
                $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->assertNotSame(DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
                $this->independent->query('SELECT CONNECTION_ID()')->fetchColumn());
        }

        return $this->independent->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
