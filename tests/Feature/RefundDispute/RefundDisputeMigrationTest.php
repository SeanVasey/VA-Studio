<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeEffect;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

class RefundDisputeMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = 'migrations/2026_10_24_264000_refund_dispute_lifecycle.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        R::configure();
        Queue::fake();
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
    }

    /** A populated graph: a reconciling hold (released), a staff suspension (active), allocations and effects. */
    private function populated(): array
    {
        [$payments, $financial] = R::bind($this->app);
        $f = DeliveryFixtures::ready($payments);
        $staff = LicenseFixtures::admin();
        $paid = $f['payment']->amount_minor;
        R::provider($financial, [], [['du_ONE', $paid, 'needs_response']]);
        R::observe($f['order']->public_id, $staff);
        R::provider($financial, [['re_ONE', $paid, 'succeeded']], [['du_ONE', $paid, 'won']]);
        R::observe($f['order']->public_id, $staff);
        app(\App\Domain\Commerce\RefundDispute\RefundDisputeActions::class)->suspendLine($f['order']->public_id, $staff, (string) Str::uuid(),
            R::sequence($f['order']->public_id, $staff), $f['grant']->public_id);

        return $f + ['staff' => $staff];
    }

    private function rejected(callable $statement, string $label): void
    {
        try {
            $statement();
            $this->fail("Guard accepted: {$label}");
        } catch (QueryException $error) {
            $this->assertStringContainsString('Invalid or immutable refund and dispute evidence', $error->getMessage(), $label);
        }
    }

    private function rows(): array
    {
        return array_combine(R::TABLES, array_map(fn (string $table): string => json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR), R::TABLES));
    }

    public function test_guards_reject_rewrites_deletes_and_invalid_inserts_while_permitting_the_single_release(): void
    {
        $f = $this->populated();
        foreach (array_fill_keys(R::TABLES, 0) as $table => $_) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
        }
        $before = $this->rows();
        $updates = ['refund_dispute_observations' => ['outcome' => 'attention'], 'refund_dispute_objects' => ['amount_minor' => 1],
            'refund_dispute_statuses' => ['status' => 'lost'], 'refund_line_allocations' => ['amount_minor' => 1],
            'refund_dispute_effects' => ['kind' => 'allocation_required']];
        foreach ($updates as $table => $change) {
            $this->rejected(fn () => DB::table($table)->update($change), "{$table} update");
            $this->rejected(fn () => DB::table($table)->delete(), "{$table} delete");
        }
        $this->rejected(fn () => DB::table('refund_dispute_work')->update(['sequence' => 0]), 'work sequence decrease');
        $this->rejected(fn () => DB::table('refund_dispute_work')->update(['order_id' => $f['order']->id + 1]), 'work order rewrite');
        $this->rejected(fn () => DB::table('refund_dispute_work')->delete(), 'work delete');
        $released = RefundDisputeHold::whereNotNull('released_at')->sole();
        $active = RefundDisputeHold::whereNull('released_at')->sole();
        $this->rejected(fn () => DB::table('refund_dispute_holds')->where('id', $released->id)->update(['released_at' => now()->addMinute()]), 'second release');
        $this->rejected(fn () => DB::table('refund_dispute_holds')->where('id', $active->id)->update(['reason' => 'dispute_staff_hold']), 'reason rewrite');
        $this->rejected(fn () => DB::table('refund_dispute_holds')->where('id', $active->id)->update(['released_at' => now()]), 'release without effect');
        $applied = RefundDisputeEffect::find($active->applied_effect_id);
        $this->rejected(fn () => DB::table('refund_dispute_holds')->where('id', $active->id)->update(['released_at' => now(), 'released_effect_id' => $applied->id]), 'release by an apply effect');
        $this->rejected(fn () => DB::table('refund_dispute_holds')->delete(), 'hold delete');
        $this->assertSame($before, $this->rows());

        $observation = RefundDisputeObservation::orderBy('id')->first();
        $base = collect($observation->getAttributes())->except(['id'])->merge(['evidence_ciphertext' => $observation->evidence_ciphertext,
            'evidence_hash' => $observation->evidence_hash])->all();
        foreach ([
            'currency' => ['currency' => 'EUR'], 'outcome' => ['outcome' => 'settled'],
            'over paid' => ['succeeded_refund_minor' => $observation->paid_minor, 'pending_refund_minor' => 1, 'refund_state' => 'full', 'dispute_state' => 'none', 'outcome' => 'observed'],
            'duplicate request' => [], 'unknown order' => ['order_id' => 999999],
        ] as $label => $change) {
            $row = array_replace($base, ['public_id' => (string) Str::uuid(), 'sequence' => 99], $label === 'duplicate request' ? [] : ['request_id' => (string) Str::uuid()], $change);
            $this->rejected(fn () => DB::table('refund_dispute_observations')->insert($row), "observation {$label}");
        }
        $effect = ['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id, 'sequence' => 9, 'effect_key' => 'synthetic', 'kind' => 'license_revoked',
            'policy_hash' => str_repeat('a', 64), 'context' => '{}', 'created_at' => now()];
        $this->rejected(fn () => DB::table('refund_dispute_effects')->insert($effect), 'unknown effect kind');
        $this->rejected(fn () => DB::table('refund_dispute_effects')->insert(['kind' => 'observation_attention', 'license_grant_id' => 999999] + $effect), 'foreign grant');
        $this->rejected(fn () => DB::table('refund_dispute_effects')->insert(['kind' => 'observation_attention', 'request_id' => (string) Str::uuid()] + $effect), 'request without actor');
        $object = DB::table('refund_dispute_objects')->where('kind', 'refund')->first();
        $allocation = ['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id, 'refund_dispute_object_id' => $object->id,
            'order_line_id' => 999999, 'amount_minor' => 1, 'currency' => 'USD', 'source' => 'staff', 'actor_id' => $f['staff']->id, 'sequence' => 9, 'created_at' => now()];
        $this->rejected(fn () => DB::table('refund_line_allocations')->insert($allocation), 'foreign line');
        $line = $f['order']->lines()->value('id');
        $this->rejected(fn () => DB::table('refund_line_allocations')->insert(['order_line_id' => $line, 'source' => 'automatic_full_refund'] + $allocation), 'automatic with actor');
        $this->rejected(fn () => DB::table('refund_line_allocations')->insert(['order_line_id' => $line, 'currency' => 'EUR'] + $allocation), 'allocation currency');
        $this->rejected(fn () => DB::table('refund_dispute_holds')->insert(['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id,
            'license_grant_id' => null, 'reason' => 'refund_suspension', 'applied_effect_id' => $applied->id, 'created_at' => now()]), 'suspension without grant');
        $this->assertSame($before, $this->rows());
    }

    public function test_populated_rollback_refuses_and_an_empty_roundtrip_restores_every_guard(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();
        foreach (R::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $migration->up();
        $f = $this->populated();
        $before = $this->rows();
        try {
            $migration->down();
            $this->fail('Populated refund/dispute rollback was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('retained', $error->getMessage());
        }
        $this->assertSame($before, $this->rows());
        $this->rejected(fn () => DB::table('refund_dispute_effects')->delete(), 'effects delete after roundtrip');
        $this->assertSame(1, User::whereKey($f['staff']->id)->count());
    }
}
