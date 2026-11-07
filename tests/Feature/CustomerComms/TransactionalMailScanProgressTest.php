<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Mail\TransactionalMailScanProgress;
use App\Domain\Notifications\Mail\TransactionalMailScanSchema as Schema;
use App\Domain\Notifications\Models\TransactionalMailDelivery;
use App\Domain\Notifications\NotificationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalMailFixtures as M;
use Tests\Support\TransactionalNotificationFixtures as N;
use Tests\TestCase;

class TransactionalMailScanProgressTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Mail::fake();
        Queue::fake();
        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob(storage_path('framework/testing/mail-preflight-*'), GLOB_ONLYDIR) ?: [] as $path) {
                (new Filesystem)->deleteDirectory($path);
            }
        });
    }

    public function test_more_than_the_scan_budget_of_permanent_refusals_cannot_starve_a_later_valid_intent_across_runs(): void
    {
        $refused = [];
        for ($i = 0; $i < 5; $i++) {
            $refused[] = N::ready(suffix: 'FAIR'.$i);
        }
        $valid = N::ready(suffix: 'FAIRVALID');
        M::configure();
        foreach ($refused as $f) {
            $f['user']->forceFill(['email_verified_at' => null])->save();
        }
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 1])
            ->expectsOutput('queued=0 skipped=4 reasons=authority_withdrawn=4 superseded=0')->assertSuccessful();
        $this->assertSame($refused[3]['notice']['notificationId'], DB::table('transactional_notices')
            ->where('id', DB::table(Schema::TABLE)->where('scan_key', 'new_intents')->value('after_id'))->value('public_id'));
        cache()->flush(); // Operational progress is not a cache entry or a retained command instance.
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 1])
            ->expectsOutput('queued=1 skipped=1 reasons=authority_withdrawn=1 superseded=0')->assertSuccessful();
        $this->assertDatabaseCount('transactional_mail_deliveries', 1);
        $this->assertDatabaseHas('transactional_mail_deliveries', ['notice_public_id' => $valid['notice']['notificationId']]);
        $refused[0]['user']->forceFill(['email_verified_at' => now()])->save();
        $this->artisan('vasey:transactional-mail-queue', ['--limit' => 1])
            ->expectsOutput('queued=1 skipped=0 superseded=0')->assertSuccessful();
        $this->assertDatabaseHas('transactional_mail_deliveries', ['notice_public_id' => $refused[0]['notice']['notificationId']]);
        Mail::assertNothingOutgoing();
    }

    public function test_crash_after_reservation_defers_work_to_wrap_and_high_water_excludes_newer_rows(): void
    {
        $first = N::ready(suffix: 'CRASHFIRST');
        M::configure();
        $adapter = app(TransactionalMailAdapter::class);
        $id = $adapter->queueOrderReady($first['notice']['notificationId'])['deliveryId'];
        $progress = new TransactionalMailScanProgress;
        $cycle = $progress->start('queued_wakeups');
        $lost = $progress->reserve('queued_wakeups', $cycle); // Simulated caller death: no work or acknowledgment.
        $this->assertSame($id, $lost['public_id']);
        $later = N::ready(suffix: 'CRASHLATER');
        M::configure();
        $next = $adapter->queueOrderReady($later['notice']['notificationId'])['deliveryId'];
        $fresh = new TransactionalMailScanProgress;
        $this->assertNull($fresh->reserve('queued_wakeups', $cycle));
        $this->assertSame($lost['id'], (int) DB::table(Schema::TABLE)->where('scan_key', 'queued_wakeups')->value('high_water_id'));
        $newCycle = $fresh->start('queued_wakeups');
        $this->assertSame($cycle + 1, $newCycle);
        $this->assertNull($progress->reserve('queued_wakeups', $cycle), 'An old run cannot silently join the new pass.');
        $this->assertSame($id, $fresh->reserve('queued_wakeups', $newCycle)['public_id']);
        $this->assertSame($next, $fresh->reserve('queued_wakeups', $newCycle)['public_id']);
        $this->assertNull($fresh->reserve('queued_wakeups', $newCycle));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        Mail::assertNothingOutgoing();
    }

    public function test_a_late_visible_lower_id_is_reconsidered_on_the_next_finite_pass(): void
    {
        $high = N::ready(suffix: 'LATEHIGH');
        $low = N::ready(suffix: 'LATELOW');
        M::configure();
        $ids = [$high['notice']['notificationId'] => 200, $low['notice']['notificationId'] => 100];
        // Genuine queued inserts/guards, with explicit fixture allocator gaps; no retained row is rewritten.
        TransactionalMailDelivery::creating(function (TransactionalMailDelivery $row) use ($ids): void {
            if (isset($ids[$row->notice_public_id])) {
                $row->id = $ids[$row->notice_public_id];
            }
        });
        $adapter = app(TransactionalMailAdapter::class);
        $highId = $adapter->queueOrderReady($high['notice']['notificationId'])['deliveryId'];
        $p = new TransactionalMailScanProgress;
        $cycle = $p->start('queued_wakeups');
        $this->assertSame($highId, $p->reserve('queued_wakeups', $cycle)['public_id']);
        $lowId = $adapter->queueOrderReady($low['notice']['notificationId'])['deliveryId'];
        $this->assertNull($p->reserve('queued_wakeups', $cycle));
        $this->assertSame($lowId, $p->reserve('queued_wakeups', $p->start('queued_wakeups'))['public_id']);
    }

    public function test_raw_owned_and_resolved_peer_frames_are_refused_without_rollback_or_progress(): void
    {
        $before = DB::table(Schema::TABLE)->orderBy('scan_key')->get()->toJson();
        $pdo = DB::getPdo();
        $pdo->beginTransaction();
        try {
            $this->refused(fn () => (new TransactionalMailScanProgress)->start('new_intents'));
            $this->assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
        $configuration = DB::connection()->getConfig();
        config(['database.connections.mail_cursor_peer' => $configuration]);
        $peer = DB::connection('mail_cursor_peer');
        $peer->beginTransaction();
        try {
            $this->refused(fn () => (new TransactionalMailScanProgress)->start('new_intents'));
            $this->assertSame(1, $peer->transactionLevel());
            $this->assertTrue($peer->getPdo()->inTransaction());
        } finally {
            $peer->rollBack();
            DB::purge('mail_cursor_peer');
        }
        $this->assertSame($before, DB::table(Schema::TABLE)->orderBy('scan_key')->get()->toJson());
    }

    public function test_schema_guards_refuse_foreign_progress_illegal_transitions_and_rollback_before_any_install_ddl(): void
    {
        $pdo = DB::getPdo();
        $before = DB::table(Schema::TABLE)->orderBy('scan_key')->get()->toJson();
        foreach (['UPDATE '.Schema::TABLE." SET cycle=2,version=1 WHERE scan_key='new_intents'",
            'UPDATE '.Schema::TABLE." SET after_id=1,version=1 WHERE scan_key='new_intents'",
            'DELETE FROM '.Schema::TABLE] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail('Invalid progress transition accepted.');
            } catch (\PDOException) {
                $this->assertSame($before, DB::table(Schema::TABLE)->orderBy('scan_key')->get()->toJson());
            }
        }
        $migration = require base_path('database/migrations/2026_11_03_268400_transactional_mail_scan_progress.php');
        try {
            $migration->up();
            $this->fail('Existing progress was adopted.');
        } catch (LogicException) {
            $this->assertSame($before, DB::table(Schema::TABLE)->orderBy('scan_key')->get()->toJson());
        }
        try {
            $migration->down();
            $this->fail('Retained progress was deleted.');
        } catch (LogicException) {
            $this->assertSame($before, DB::table(Schema::TABLE)->orderBy('scan_key')->get()->toJson());
        }
        $pdo->exec('DROP TABLE '.Schema::TABLE);
        $pdo->exec('CREATE VIEW '.Schema::TABLE.' AS SELECT 1 AS sentinel');
        try {
            try {
                $migration->up();
                $this->fail('Foreign progress view was adopted.');
            } catch (LogicException) {
                $this->assertSame(1, (int) $pdo->query('SELECT sentinel FROM '.Schema::TABLE)->fetchColumn());
            }
        } finally {
            // migrate:fresh drops tables but not views on MySQL; a leftover view would refuse every later migration.
            $pdo->exec('DROP VIEW IF EXISTS '.Schema::TABLE);
        }
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An uncertain/foreign frame issued a reservation.');
        } catch (NotificationException $error) {
            $this->assertSame('scan_progress_unavailable', $error->reason);
        }
    }
}
