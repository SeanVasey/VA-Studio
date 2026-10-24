<?php

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\OperationalHealth;
use App\Domain\Reporting\ReportingException;
use App\Filament\Widgets\ContentPipelineWidget;
use App\Filament\Widgets\QueueHealthWidget;
use App\Filament\Widgets\UnrecordedEvidenceWidget;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Operational health reads only rows the app stores; anything unreadable or unrecorded is unavailable, never zero. */
class OperationalHealthTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
    }

    private function metrics(string $section): array
    {
        return collect(app(OperationalHealth::class)->section($section, $this->admin)['metrics'])->keyBy('key')->all();
    }

    public function test_queue_receipts_inquiries_and_media_metrics_come_from_stored_rows_and_empty_tables_read_as_ok_zero(): void
    {
        config(['queue.default' => 'database']);
        foreach (['queue', 'content'] as $section) {
            foreach ($this->metrics($section) as $key => $metric) {
                $this->assertSame('ok', $metric['state'], $key);
                $this->assertSame(0, $metric['value'], $key);
            }
        }
        $now = now()->utc()->toImmutable();
        Schema::disableForeignKeyConstraints();
        $job = fn (?int $reserved, int $available) => ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => $available];
        DB::table('jobs')->insert([$job(null, $now->subSeconds(900)->timestamp), $job(null, $now->subSeconds(30)->timestamp),
            $job(null, $now->addHour()->timestamp), $job($now->subSeconds(5)->timestamp, $now->subSeconds(60)->timestamp)]);
        DB::table('failed_jobs')->insert([
            ['uuid' => 'a0000000-0000-4000-8000-000000000001', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'SYNTHETIC', 'failed_at' => $now->subHours(2)->format('Y-m-d H:i:s')],
            ['uuid' => 'a0000000-0000-4000-8000-000000000002', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'SYNTHETIC', 'failed_at' => $now->subDays(3)->format('Y-m-d H:i:s')],
        ]);
        $work = fn (string $state, int $receipt, string $at) => ['stripe_webhook_receipt_id' => $receipt, 'state' => $state, 'attempts' => 0, 'created_at' => $at, 'updated_at' => $at];
        DB::table('stripe_receipt_work')->insert([$work('pending', 1, $now->subHour()->format('Y-m-d H:i:s')), $work('retry', 2, $now->format('Y-m-d H:i:s')),
            $work('quarantined', 3, $now->format('Y-m-d H:i:s')), $work('processed', 4, $now->format('Y-m-d H:i:s'))]);
        $queue = $this->metrics('queue');
        $this->assertSame([2, 'attention'], [$queue['queue_ready']['value'], $queue['queue_ready']['state']]);
        $this->assertSame(900, $queue['queue_oldest']['value']);
        $this->assertSame(1, $queue['queue_reserved']['value']);
        $this->assertStringContainsString('1 more are scheduled', $queue['queue_ready']['detail']);
        $this->assertSame([2, 'attention'], [$queue['failed_jobs']['value'], $queue['failed_jobs']['state']]);
        $this->assertSame(1, $queue['failed_jobs_recent']['value']);
        $this->assertSame([2, 'attention'], [$queue['stripe_receipts_waiting']['value'], $queue['stripe_receipts_waiting']['state']]);
        $this->assertSame(1, $queue['stripe_receipts_quarantined']['value']);

        $inquiry = fn (string $state, int $n, string $at) => ['public_id' => '00000000-0000-4000-8000-00000000000'.$n, 'owner_hash' => str_repeat('a', 64), 'request_key' => str_repeat((string) $n, 64),
            'payload_hash' => str_repeat('b', 64), 'payload' => '{}', 'privacy_notice' => 'SYNTHETIC', 'privacy_notice_hash' => str_repeat('c', 64),
            'retention_policy_reference' => 'SYNTHETIC', 'operator_user_id' => $this->admin->id, 'site_release_id' => 1, 'site_content_hash' => str_repeat('d', 64),
            'state' => 'new', 'version' => 0, 'created_at' => $at, 'updated_at' => $at];
        DB::table('customer_inquiries')->insert([$inquiry('new', 1, $now->subDays(3)->format('Y-m-d H:i:s')), $inquiry('new', 2, $now->format('Y-m-d H:i:s')), $inquiry('read', 3, $now->format('Y-m-d H:i:s'))]);
        DB::table('customer_inquiries')->where('request_key', str_repeat('3', 64))->update(['state' => 'read', 'version' => 1]);
        $run = fn (string $status, ?string $started, string $created) => ['source_asset_id' => random_int(1, 1000000), 'requested_by' => $this->admin->id, 'profile_version' => 'SYNTHETIC',
            'profile_fingerprint' => bin2hex(random_bytes(8)), 'input_sha256' => str_repeat('e', 64), 'profile' => '{}', 'status' => $status, 'started_at' => $started, 'created_at' => $created, 'updated_at' => $created];
        DB::table('media_processing_runs')->insert([$run('failed', null, $now->format('Y-m-d H:i:s')), $run('processing', $now->subHour()->format('Y-m-d H:i:s'), $now->subHour()->format('Y-m-d H:i:s')),
            $run('processing', $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')), $run('queued', null, $now->subMinutes(30)->format('Y-m-d H:i:s')), $run('completed', null, $now->format('Y-m-d H:i:s'))]);
        DB::table('media_assets')->insert([['track_id' => 1, 'role' => 'master_wav', 'disk' => 'local', 'storage_path' => 'synthetic/a', 'original_name' => 'a.wav', 'status' => 'quarantined',
            'created_at' => $now->subDays(2)->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]]);
        Schema::enableForeignKeyConstraints();
        $content = $this->metrics('content');
        $this->assertSame([2, 'attention'], [$content['inquiries_new']['value'], $content['inquiries_new']['state']]);
        $this->assertSame(1, $content['media_failed']['value']);
        $this->assertSame(1, $content['media_stuck']['value']);
        $this->assertSame([1, 'attention'], [$content['media_queued']['value'], $content['media_queued']['state']]);
        $this->assertSame([1, 'attention'], [$content['media_quarantined']['value'], $content['media_quarantined']['state']]);

        $component = Livewire::test(QueueHealthWidget::class)->assertSee('Jobs waiting')->assertSee('Needs attention');
        $component->assertSeeHtml('data-metric="failed_jobs" data-state="attention"');
        Livewire::test(ContentPipelineWidget::class)->assertSee('Inquiries not yet read');
    }

    public function test_unreadable_unrecorded_and_out_of_scope_sources_are_unavailable_not_zero_and_non_staff_are_refused(): void
    {
        config(['queue.default' => 'sync', 'queue.failed.driver' => 'file']);
        $queue = $this->metrics('queue');
        foreach (['queue_ready', 'queue_oldest', 'queue_reserved', 'failed_jobs'] as $key) {
            $this->assertSame([null, 'unavailable'], [$queue[$key]['value'], $queue[$key]['state']], $key);
        }
        $this->assertStringContainsString("'sync'", $queue['queue_ready']['detail']);
        // The receipt source still reads; a dropped table is unavailable rather than zero.
        $this->assertSame('ok', $queue['stripe_receipts_waiting']['state']);
        Schema::disableForeignKeyConstraints();
        Schema::drop('stripe_receipt_work');
        $this->assertSame('unavailable', $this->metrics('queue')['stripe_receipts_waiting']['state']);
        // No Stripe test account is configured: no test order is in scope.
        config(['payments.stripe.account_id' => null]);
        foreach ($this->metrics('commerce') as $key => $metric) {
            $this->assertSame([null, 'unavailable'], [$metric['value'], $metric['state']], $key);
            $this->assertStringContainsString('not configured', $metric['detail']);
        }
        // Outside the environments that admit test commerce there is nothing to read.
        config(['payments.stripe.account_id' => 'acct_SYNTHETIC1']);
        $this->app['env'] = 'production';
        foreach ($this->metrics('commerce') as $metric) {
            $this->assertStringContainsString('not admitted', $metric['detail']);
        }
        $this->app['env'] = 'testing';
        $evidence = $this->metrics('evidence');
        $this->assertSame(['scheduler_last_run', 'backup_restore', 'host_resources'], array_keys($evidence));
        foreach ($evidence as $metric) {
            $this->assertSame([null, 'unavailable'], [$metric['value'], $metric['state']]);
        }
        Livewire::test(UnrecordedEvidenceWidget::class)->assertSee('Scheduler last run')->assertSee('Unavailable');
        $this->expectException(ReportingException::class);
        try {
            app(OperationalHealth::class)->section('nope', $this->admin);
        } finally {
            foreach ([null, User::factory()->create()] as $actor) {
                try {
                    app(OperationalHealth::class)->section('queue', $actor);
                    $this->fail('A non-staff actor read health.');
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
