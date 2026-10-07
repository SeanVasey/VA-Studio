<?php

namespace Tests\Feature;

use App\Domain\Services\Models\ServiceDraftVersion;
use App\Domain\Services\ServiceDrafts;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PrivateProductDraftFixtures;
use Tests\Support\ServiceProjectFixtures;
use Tests\TestCase;

final class ServiceProjectReviewRegressionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_the_bounded_service_index_includes_new_definitions_after_fifty_and_the_latest_brief_can_be_submitted(): void
    {
        $fixture = ServiceProjectFixtures::setup();
        $drafts = app(ServiceDrafts::class);
        $lastVersion = null;
        for ($number = 1; $number <= 50; $number++) {
            $body = PrivateProductDraftFixtures::payload('service', ['title' => 'Synthetic new service '.$number]);
            $draft = $drafts->applyReviewed($drafts->review(null, $body, $fixture['operator']), $fixture['operator']);
            $lastVersion = ServiceDraftVersion::where('draft_id', $draft->id)->sole();
        }
        $before = DB::table('service_projects')->count();
        $index = $fixture['journey']->customerIndex($fixture['customer']['principal'], $fixture['customer']['user']);
        $this->assertCount(50, $index['services']);
        $this->assertSame($lastVersion->id, $index['services'][0]['versionId']);
        $this->assertSame('Synthetic new service 50', $index['services'][0]['title']);
        $this->assertNotContains($fixture['service']->id, array_column($index['services'], 'versionId'));
        $body = [...$fixture['brief'], 'requestKey' => (string) Str::uuid(), 'serviceVersionId' => $lastVersion->id,
            'serviceHash' => $lastVersion->manifest_sha256];
        $result = $fixture['journey']->submitBrief($body, $fixture['customer']['principal'], $fixture['customer']['user']);
        $this->assertSame('Synthetic new service 50', $result['project']['title']);
        $this->assertSame($before + 1, DB::table('service_projects')->count());
    }

    public function test_real_transactional_failure_logs_only_a_fixed_exception_class_and_rolls_back_the_brief(): void
    {
        $fixture = ServiceProjectFixtures::setup();
        $this->postJson('/account/sign-in', ['email' => $fixture['customer']['user']->email,
            'password' => CustomerFixtures::PASSWORD])->assertOk();
        $before = [];
        foreach (['service_projects', 'service_project_events', 'audit_events'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        AuditEvent::created(function ($event): void {
            if ($event->action === 'service_project.brief_submitted') {
                throw new RuntimeException('PRIVATE exception body secret@example.invalid');
            }
        });
        Log::spy();
        $this->postJson('/services/projects', [...$fixture['brief'], 'requestKey' => (string) Str::uuid(),
            'summary' => 'PRIVATE submitted customer brief'])->assertStatus(503)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('PRIVATE', false)->assertDontSee('secret@example.invalid', false);
        Log::shouldHaveReceived('error')->once()->with('Service project request failed.', ['exception_class' => RuntimeException::class]);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
    }
}
