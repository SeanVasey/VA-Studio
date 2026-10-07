<?php

namespace Tests\Review;

use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReadProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\ProductionTrackCapabilitiesGuardsTest;

/** Reviewer-owned canaries. These supplement the unchanged author cases. */
class NativeFixtureReviewCanaryTest extends ProductionTrackCapabilitiesGuardsTest
{
    private function fixture(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(ProductionTrackCapabilitiesGuardsTest::class, $method))->invoke($this, ...$arguments);
    }

    private function nativeMetadata(): array
    {
        return [
            'migrations' => DB::table('migrations')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'guards' => array_map(fn ($row): array => (array) $row, DB::select('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')),
        ];
    }

    public function test_review_sqlite_fixture_isolation_preserves_and_restores_primary(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $original = DB::getDefaultConnection();
        $primary = DB::connection();
        $before = $this->nativeMetadata();
        $id = DB::table('audit_events')->insertGetId(['actor_id' => null, 'action' => 'synthetic.review.primary.marker', 'subject_type' => 'synthetic.review', 'subject_id' => null, 'context' => '{}', 'created_at' => now()->utc()->format('Y-m-d H:i:s')]);
        $this->fixture('useSqliteAdversaryFixture');
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertNotSame($primary->getPdo(), DB::connection()->getPdo());
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame('synthetic.review.primary.marker', $primary->table('audit_events')->where('id', $id)->value('action'));
        $this->beforeApplicationDestroyed(function () use ($original, $primary, $before, $id): void {
            $this->assertSame($original, DB::getDefaultConnection());
            $this->assertSame('mysql', DB::getDriverName());
            $this->assertSame($primary->getPdo(), DB::connection()->getPdo());
            $this->assertSame($before, $this->nativeMetadata());
            $this->assertSame('synthetic.review.primary.marker', DB::table('audit_events')->where('id', $id)->value('action'));
            $this->assertSame(0, DB::transactionLevel());
        });
    }

    public static function reviewCallbackKinds(): array
    {
        return ['adapter' => [false], 'audit created' => [true]];
    }

    #[DataProvider('reviewCallbackKinds')]
    public function test_review_native_callback_rollback_keeps_metadata_and_primary(bool $auditCallback): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        [$candidate, $source, $author, , $machine] = $this->fixture('prepared', ! $auditCallback);
        $pdo = DB::connection()->getPdo();
        $before = $this->fixture('rows');
        $metadata = $this->nativeMetadata();
        $this->assertNotEmpty($metadata['guards']);
        $auditId = DB::table('audit_events')->where('subject_type', ProductionTrackCapabilities::class)->where('action', 'commerce.production_capability.candidate_saved')->sole()->id;
        $fired = false;
        $edit = function () use ($auditId, &$fired): void {
            $this->assertSame(1, DB::table('audit_events')->where('id', $auditId)->update(['action' => 'synthetic.review.audit.damage']));
            $this->assertSame(1, DB::transactionLevel());
            $fired = true;
        };
        try {
            if ($auditCallback) {
                $machine['version'] = 'synthetic-review-machine-v2';
                $capture = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
                AuditEvent::created(function (AuditEvent $event) use ($edit): void {
                    if ($event->subject_type === ProductionTrackCapabilities::class) {
                        $edit();
                    }
                });
                app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
            } else {
                app(ReadProductionTrackCapabilities::class)->withLockedForAdapter($candidate, PreparationContextV1::forMachine($machine), $author, $edit);
            }
            $this->fail('Native ordinary DML evidence damage was admitted.');
        } catch (ValidationException) {
            $this->assertTrue($fired);
            $this->assertSame($before, $this->fixture('rows'));
            $this->assertSame($metadata, $this->nativeMetadata());
            $this->assertSame($pdo, DB::connection()->getPdo());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            AuditEvent::flushEventListeners();
            AuditEvent::clearBootedModels();
        }
    }
}
