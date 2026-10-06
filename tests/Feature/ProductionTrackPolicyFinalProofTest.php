<?php

namespace Tests\Feature;

use App\Domain\Commerce\Policy\PrepareProductionTrackPolicy;
use App\Domain\Commerce\Policy\SaveProductionTrackPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

class ProductionTrackPolicyFinalProofTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_query_executed_role_withdrawal_cannot_escape_the_final_primary_proof(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $actor = LicenseFixtures::admin();
        $review = app(PrepareProductionTrackPolicy::class)->review(null, ProductionTrackPolicyFixtures::authored(), $actor);
        $armed = true;
        $fired = false;
        DB::listen(function ($query) use ($actor, &$armed, &$fired): void {
            if (! $armed || ! preg_match('/\Aselect\b/i', $query->sql) || ! preg_match('/from ["`]users["`]/', $query->sql)) {
                return;
            }
            $methods = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
            if (! in_array('finish', $methods, true) || in_array('authority', $methods, true)) {
                return;
            }
            $armed = false;
            $statement = DB::connection()->getPdo()->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
            $statement->execute([0, $actor->id]);
            $fired = true;
        });
        try {
            $saved = app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
            $after = $this->rows();
            echo json_encode(['production_policy_query_callback_canary' => [
                'callback_fired' => $fired, 'returned_revision' => $saved->revision,
                'actor_is_admin' => (bool) $after['users'][0]['is_admin'],
                'drafts' => count($after['production_track_policy_drafts']), 'versions' => count($after['production_track_policy_versions']),
                'audits' => count($after['audit_events']),
            ]], JSON_THROW_ON_ERROR)."\n";
            $this->assertFalse($fired, 'The final primary proof must expose no QueryExecuted callback.');
            $this->assertTrue((bool) $after['users'][0]['is_admin']);
            $this->assertSame(1, $saved->revision);
            $this->assertDatabaseCount('production_track_policy_drafts', 1);
            $this->assertDatabaseCount('production_track_policy_versions', 1);
            $this->assertDatabaseCount('audit_events', 1);
        } finally {
            $armed = false;
        }
    }

    public function test_last_authority_query_callback_is_checked_by_primary_pdo_and_rolls_back(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $actor = LicenseFixtures::admin();
        $capture = app(PrepareProductionTrackPolicy::class)->review(null, ProductionTrackPolicyFixtures::authored(), $actor);
        $before = $this->rows();
        $armed = true;
        $fired = false;
        DB::listen(function ($query) use ($actor, &$armed, &$fired): void {
            if (! $armed || ! preg_match('/\Aselect\b/i', $query->sql) || ! preg_match('/from ["`]users["`]/', $query->sql)) {
                return;
            }
            $methods = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
            if (! in_array('finish', $methods, true) || ! in_array('authority', $methods, true)
                || array_intersect(['find', 'authorize', 'satisfiedBy'], $methods) !== []) {
                return;
            }
            $armed = false;
            $statement = DB::connection()->getPdo()->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
            $statement->execute([0, $actor->id]);
            $fired = true;
        });
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($capture, $actor);
            $this->fail('Post-fetch authority withdrawal committed.');
        } catch (AuthorizationException) {
            $this->assertTrue($fired);
            $this->assertSame($before, $this->rows());
        } finally {
            $armed = false;
        }
    }

    private function rows(): array
    {
        $result = [];
        foreach (['users', 'production_track_policy_drafts', 'production_track_policy_versions', 'production_track_policy_source_reviews', 'audit_events'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }
}
