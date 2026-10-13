<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Memberships\Production\CurrentEligibleLicenses;
use App\Domain\Memberships\Production\MembershipCatalogEvidence;
use App\Domain\Memberships\Production\MembershipCatalogSnapshot;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionIdentityFixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Real synthetic catalog producers; independent native connection, never host/provider evidence. */
class MembershipNativeCatalogCurrentnessTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Catalog allocation counters, opened definitions and temporary shadows require native MySQL.');
        }
        $this->fakePrivateMediaStorage();
        CarbonImmutable::setTestNow('2026-10-13 12:00:00 UTC');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('E', 32)),
            'production-memberships.enabled' => true, 'production-memberships.provenance' => IdentityPolicy::REHEARSAL,
            'production-memberships.approved_policy_hash' => hash('sha256', 'SYNTHETIC eligibility proof fixture policy'),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unrelated_auto_allocated_insert_preserves_original_proof_but_selected_row_change_refuses(): void
    {
        $fixture = $this->fixture();
        $other = $this->independentConnection();
        $snapshot = $this->snapshot($fixture);
        $pdo = $snapshot->pdo;
        $this->assertFalse($pdo->inTransaction());
        $before = $this->shown($pdo);
        $counterBefore = $this->counter($before);
        $plansBefore = $this->planRows($snapshot);
        $this->assertSame(array_column($snapshot->plans, 'rows'), $plansBefore);

        // Use the actual post-commit receipt window. The original locking footprint
        // is closed; the same snapshot and its original deadline are retained.
        $other->beginTransaction();
        try {
            $statement = $other->prepare('INSERT INTO tracks (title, slug, artist, status) VALUES (?, ?, ?, ?)');
            $statement->execute(['SYNTHETIC unrelated draft', 'synthetic-counter-'.bin2hex(random_bytes(12)), 'Synthetic only', 'draft']);
            $insertedId = (int) $other->lastInsertId();
            $other->commit();
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
        $this->assertFalse($other->inTransaction());
        $this->assertNotSame($fixture['track']->id, $insertedId);
        $this->assertSame(1, (int) $other->query('SELECT COUNT(*) FROM tracks WHERE id = '.$insertedId)->fetchColumn());
        $after = $this->shown($pdo);
        $counterAfter = $this->counter($after);
        $this->assertGreaterThan($counterBefore, $counterAfter);
        // Independent fixture control: an auto-id row, not ALTER or an explicit id,
        // advanced exactly the table option while the selected footprint stayed fixed.
        $this->assertSame(str_replace(' AUTO_INCREMENT='.$counterBefore.' ', ' ', $before),
            str_replace(' AUTO_INCREMENT='.$counterAfter.' ', ' ', $after));
        $this->assertSame($plansBefore, $this->planRows($snapshot));
        $this->receipt(['case' => __FUNCTION__, 'stage' => 'unrelated_insert_committed',
            'mysql_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
            'primary_connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
            'other_connection_id' => (int) $other->query('SELECT CONNECTION_ID()')->fetchColumn(),
            'inserted_id' => $insertedId, 'counter_before' => $counterBefore, 'counter_after' => $counterAfter,
            'raw_before' => $before, 'raw_after' => $after,
            'raw_before_sha256' => hash('sha256', $before), 'raw_after_sha256' => hash('sha256', $after),
            'selected_plans_unchanged' => true, 'phase' => 'original_snapshot_after_commit']);

        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, 'mysql'), $snapshot->deadlineNs);
        try {
            MembershipCatalogEvidence::proveBytes($snapshot);
            try {
                // Old source must fail this exact positive comparison, after real
                // insertion succeeded; the reason is retained before PHPUnit reports it.
                MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, 'mysql', false);
            } catch (MembershipException $error) {
                $this->receipt(['case' => __FUNCTION__, 'stage' => 'original_snapshot_reproof_refused', 'refusal' => $error->reason]);
                throw $error;
            }
            $this->assertSame($plansBefore, $this->planRows($snapshot));
            $frame->finish();
        } finally {
            $frame->close();
        }
        $this->assertFalse($pdo->inTransaction());
        $this->receipt(['case' => __FUNCTION__, 'stage' => 'original_committed_snapshot_retained',
            'original_deadline_ns' => $snapshot->deadlineNs]);

        // A new independently admitted current frame owns this negative. No original
        // proof token is reused across transactions or given a renewed deadline.
        DB::transaction(function () use ($fixture): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $authority->lock((string) $fixture['offer']->id, $fixture['revision']->id,
                $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            $current = $authority->snapshot($proof, $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            MembershipCatalogEvidence::assertCurrent($current, $rows->identity(), 'mysql');
            $rows->identity()->prepare('UPDATE tracks SET title = ? WHERE id = ?')
                ->execute(['SYNTHETIC selected row changed', $fixture['track']->id]);
            $this->refused(fn () => MembershipCatalogEvidence::assertCurrent($current, $rows->identity(), 'mysql'), 'changed_catalog');
            $this->receipt(['case' => __FUNCTION__, 'stage' => 'selected_change_refused_in_fresh_current_frame', 'refusal' => 'changed_catalog']);
        });
        $this->noMembershipEffects();
    }

    public function test_real_table_comment_change_is_not_erased_by_counter_projection_in_committed_read_only_frame(): void
    {
        $fixture = $this->fixture();
        $other = $this->independentConnection();
        $other->exec("ALTER TABLE tracks COMMENT='SYNTHETIC AUTO_INCREMENT=31'");
        try {
            $snapshot = $this->snapshot($fixture);
            $pdo = DB::connection()->getPdo();
            $this->assertFalse($pdo->inTransaction());
            $before = $this->shown($pdo);
            $plansBefore = $this->planRows($snapshot);
            // Original transaction is closed, so its metadata locks cannot turn this
            // definition negative into an ALTER timeout or an implicit transaction loss.
            $other->exec("ALTER TABLE tracks COMMENT='SYNTHETIC AUTO_INCREMENT=32'");
            $after = $this->shown($pdo);
            $this->assertNotSame($before, $after);
            $this->assertStringContainsString("COMMENT='SYNTHETIC AUTO_INCREMENT=32'", $after);
            $this->assertSame($plansBefore, $this->planRows($snapshot));
            $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, 'mysql'), $snapshot->deadlineNs);
            try {
                MembershipCatalogEvidence::proveBytes($snapshot);
                $this->refused(fn () => MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, 'mysql', false), 'changed_catalog_source');
                $this->receipt(['case' => __FUNCTION__, 'stage' => 'real_comment_change_refused',
                    'raw_before' => $before, 'raw_after' => $after, 'selected_plans_unchanged' => true,
                    'refusal' => 'changed_catalog_source']);
                $frame->finish();
            } finally {
                $frame->close();
            }
            $this->assertFalse($pdo->inTransaction());
        } finally {
            $other->exec("ALTER TABLE tracks COMMENT=''");
        }
        $this->noMembershipEffects();
    }

    public function test_identical_row_local_temporary_shadow_remains_refused_after_a_positive_original_proof(): void
    {
        $fixture = $this->fixture();
        DB::transaction(function () use ($fixture): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $authority->lock((string) $fixture['offer']->id, $fixture['revision']->id,
                $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            $snapshot = $authority->snapshot($proof, $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            $pdo = $rows->identity();
            MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, 'mysql');
            $durableRows = $pdo->query('SELECT * FROM tracks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $definition = $this->shown($pdo);
            // MySQL1066-safe existing fixture pattern; TEMPORARY cannot retain FKs.
            $lines = array_filter(explode("\n", $definition), static fn (string $line): bool => ! str_contains($line, ' FOREIGN KEY '));
            $definition = (string) preg_replace('/,(\n\))/', '$1', implode("\n", $lines));
            $pdo->exec((string) preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition));
            try {
                foreach ($durableRows as $row) {
                    $statement = $pdo->prepare('INSERT INTO tracks (`'.implode('`, `', array_keys($row)).'`) VALUES ('
                        .implode(', ', array_fill(0, count($row), '?')).')');
                    $statement->execute(array_values($row));
                }
                $this->assertSame($durableRows, $pdo->query('SELECT * FROM tracks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
                $this->assertStringStartsWith('CREATE TEMPORARY TABLE', $this->shown($pdo));
                $this->refused(fn () => MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, 'mysql'), 'catalog_shadow_or_engine');
                $this->receipt(['case' => __FUNCTION__, 'stage' => 'identical_row_temporary_shadow_refused',
                    'shadow_definition' => $this->shown($pdo), 'identical_row_count' => count($durableRows),
                    'identical_rows_sha256' => hash('sha256', serialize($durableRows)), 'refusal' => 'catalog_shadow_or_engine']);
            } finally {
                $pdo->exec('DROP TEMPORARY TABLE tracks');
            }
            MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, 'mysql');
        });
        $this->noMembershipEffects();
    }

    private function fixture(): array
    {
        return QuoteFixtures::selection() + ['buyer' => $this->enrollThroughLocalSmtp()];
    }

    private function snapshot(array $fixture): MembershipCatalogSnapshot
    {
        return DB::transaction(function () use ($fixture): MembershipCatalogSnapshot {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $authority->lock((string) $fixture['offer']->id, $fixture['revision']->id,
                $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);

            return $authority->snapshot($proof, $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
        });
    }

    private function independentConnection(): PDO
    {
        $db = DB::connection()->getConfig();
        $address = empty($db['unix_socket']) ? 'host='.$db['host'].';port='.$db['port'] : 'unix_socket='.$db['unix_socket'];
        $pdo = new PDO('mysql:'.$address.';dbname='.$db['database'].';charset=utf8mb4', $db['username'], $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false]);
        $pdo->exec('SET SESSION lock_wait_timeout=3, innodb_lock_wait_timeout=3');
        $this->assertNotSame(DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
            $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());

        return $pdo;
    }

    private function shown(PDO $pdo): string
    {
        return $pdo->query('SHOW CREATE TABLE tracks')->fetch(PDO::FETCH_NUM)[1];
    }

    private function counter(string $shown): int
    {
        $this->assertSame(1, preg_match('/\n\) ENGINE=InnoDB AUTO_INCREMENT=([0-9]+) /', $shown, $match));

        return (int) $match[1];
    }

    private function planRows(MembershipCatalogSnapshot $snapshot): array
    {
        return array_map(static function (array $plan) use ($snapshot): array {
            $statement = $snapshot->pdo->prepare($plan['sql']);
            $statement->execute($plan['parameters']);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }, $snapshot->plans);
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected catalog source refusal.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    private function noMembershipEffects(): void
    {
        foreach (['production_membership_paid_periods', 'production_membership_redemptions', 'production_membership_credit_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    private function receipt(array $facts): void
    {
        fwrite(STDOUT, "\nCATALOG_CURRENTNESS ".json_encode($facts, JSON_THROW_ON_ERROR)."\n");
    }
}
