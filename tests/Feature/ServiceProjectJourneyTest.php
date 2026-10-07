<?php

namespace Tests\Feature;

use App\Domain\Services\Projects\ServiceProjectException;
use App\Domain\Services\Projects\ServiceProjects;
use App\Domain\Services\ServiceDrafts;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PrivateProductDraftFixtures;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

class ServiceProjectJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    private function evidence(): array
    {
        return array_map(fn ($table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), ['service_projects', 'service_project_events', 'audit_events', 'orders', 'pending_entitlements']);
    }

    private function changed(callable $command, int $status = 409): void
    {
        try {
            $command();
            $this->fail('The changed or unavailable project must be refused.');
        } catch (ServiceProjectException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    public function test_actual_brief_quote_accept_revision_and_scope_review_preserve_frozen_evidence_without_payment_or_delivery(): void
    {
        Http::fake();
        Mail::fake();
        Queue::fake();
        $f = F::setup();
        $original = (array) DB::table('service_projects')->sole();
        $project = F::accept($f);
        $this->assertTrue($project['scopeFrozen']);
        $quoteRow = (array) DB::table('service_project_events')->where('operation', 'author_quote')->sole();
        $staff = fn ($action) => $f['journey']->staffCommand($project['id'], F::command($project, $action, ['milestoneId' => 'mix-review', 'reason' => 'Synthetic operator note']), $f['operator'])['project'];
        $project = $staff('begin_milestone');
        $project = $f['journey']->staffCommand($project['id'], F::command($project, 'ready_milestone', ['milestoneId' => 'mix-review', 'reason' => 'Scope ready for review']), $f['operator'])['project'];
        $project = $f['journey']->customerCommand($project['id'], F::command($project, 'request_revision', ['milestoneId' => 'mix-review', 'reason' => 'Synthetic revision note']), $f['customer']['principal'], $f['customer']['user'])['project'];
        $this->assertSame(1, $project['revisionsUsed']);
        $project = $f['journey']->staffCommand($project['id'], F::command($project, 'ready_milestone', ['milestoneId' => 'mix-review', 'reason' => 'Revised scope ready']), $f['operator'])['project'];
        $before = $this->evidence();
        $this->changed(fn () => $f['journey']->customerCommand($project['id'], F::command($project, 'request_revision', ['milestoneId' => 'mix-review', 'reason' => 'One too many']), $f['customer']['principal'], $f['customer']['user']));
        $this->assertSame($before, $this->evidence());
        $project = $f['journey']->customerCommand($project['id'], F::command($project, 'approve_milestone', ['milestoneId' => 'mix-review', 'reason' => 'Scope review approved']), $f['customer']['principal'], $f['customer']['user'])['project'];
        $this->assertSame('scope_reviewed', $project['status']);
        $this->assertSame('not_collected', $project['paymentState']);
        $this->assertFalse($project['deliveryAuthorized']);
        $this->assertSame($original, (array) DB::table('service_projects')->sole());
        $this->assertSame($quoteRow, (array) DB::table('service_project_events')->where('operation', 'author_quote')->sole());
        $this->assertSame(['author_quote', 'accept_quote', 'begin_milestone', 'ready_milestone', 'request_revision', 'ready_milestone', 'approve_milestone'], array_column($project['history'], 'action'));
        $this->assertStringNotContainsString('Synthetic buyer', $original['brief']);
        $this->assertStringNotContainsString('Synthetic authored', $quoteRow['payload']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('pending_entitlements', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_replay_is_exact_and_stale_or_cross_customer_commands_write_nothing(): void
    {
        $f = F::setup();
        $saved = $f['journey']->submitBrief($f['brief'], $f['customer']['principal'], $f['customer']['user']);
        $this->assertTrue($saved['replayed']);
        $this->assertSame($f['project']['id'], $saved['project']['id']);
        $body = F::command($f['project'], 'author_quote', ['quote' => F::quote()]);
        $project = $f['journey']->staffCommand($f['project']['id'], $body, $f['operator'])['project'];
        $before = $this->evidence();
        $this->assertTrue($f['journey']->staffCommand($project['id'], $body, $f['operator'])['replayed']);
        $changed = $body;
        $changed['quote']['scope'] = 'Changed retry body';
        $this->changed(fn () => $f['journey']->staffCommand($project['id'], $changed, $f['operator']));
        $this->changed(fn () => $f['journey']->staffCommand($project['id'], F::command($f['project'], 'author_quote', ['quote' => F::quote()]), $f['operator']));
        $other = CustomerFixtures::account();
        $before = $this->evidence();
        $this->changed(fn () => $f['journey']->customerShow($project['id'], $other['principal'], $other['user']), 404);
        $this->changed(fn () => $f['journey']->customerCommand($project['id'], F::command($project, 'accept_quote', ['quoteId' => $project['quoteId'], 'quoteHash' => $project['quoteHash']]), $other['principal'], $other['user']), 404);
        $this->assertSame($before, $this->evidence());
    }

    public function test_decline_revised_quote_and_exact_acceptance_keep_old_terms_and_reject_superseded_identity(): void
    {
        $f = F::setup();
        $first = F::author($f);
        $project = $f['journey']->customerCommand($first['id'], F::command($first, 'decline_quote', ['quoteId' => $first['quoteId'], 'quoteHash' => $first['quoteHash']]), $f['customer']['principal'], $f['customer']['user'])['project'];
        $project = $f['journey']->staffCommand($project['id'], F::command($project, 'author_quote', ['quote' => F::quote(['scope' => 'Second explicitly authored scope', 'totalMinor' => 23000])]), $f['operator'])['project'];
        $before = $this->evidence();
        $this->changed(fn () => $f['journey']->customerCommand($project['id'], F::command($project, 'accept_quote', ['quoteId' => $first['quoteId'], 'quoteHash' => $first['quoteHash']]), $f['customer']['principal'], $f['customer']['user']));
        $this->assertSame($before, $this->evidence());
        $project = $f['journey']->customerCommand($project['id'], F::command($project, 'accept_quote', ['quoteId' => $project['quoteId'], 'quoteHash' => $project['quoteHash']]), $f['customer']['principal'], $f['customer']['user'])['project'];
        $this->assertSame([17500, 23000], array_column($project['quotes'], 'totalMinor'));
        $before = $this->evidence();
        $this->changed(fn () => $f['journey']->staffCommand($project['id'], F::command($project, 'author_quote', ['quote' => F::quote()]), $f['operator']));
        $this->assertSame($before, $this->evidence());
    }

    public function test_retained_service_snapshot_survives_a_new_service_revision_and_new_brief_refuses_stale_definition(): void
    {
        $f = F::setup();
        $drafts = app(ServiceDrafts::class);
        $drafts->applyReviewed($drafts->review($f['draft'], PrivateProductDraftFixtures::payload('service', ['title' => 'Changed definition', 'brief_questions' => ['A different question']]), $f['operator']), $f['operator']);
        $this->assertSame('Synthetic private service', $f['journey']->customerShow($f['project']['id'], $f['customer']['principal'], $f['customer']['user'])['title']);
        $body = $f['brief'];
        $body['requestKey'] = (string) Str::uuid();
        $this->changed(fn () => $f['journey']->submitBrief($body, $f['customer']['principal'], $f['customer']['user']));
        $index = $f['journey']->customerIndex($f['customer']['principal'], $f['customer']['user']);
        $this->assertSame('Changed definition', $index['services'][0]['title']);
    }

    public function test_withdrawal_and_cancellation_are_recorded_without_refund_or_completed_authority(): void
    {
        $f = F::setup();
        $project = $f['journey']->customerCommand($f['project']['id'], F::command($f['project'], 'withdraw', ['reason' => 'Synthetic withdrawal']), $f['customer']['principal'], $f['customer']['user'])['project'];
        $this->assertSame('withdrawn', $project['status']);
        $this->changed(fn () => $f['journey']->staffCommand($project['id'], F::command($project, 'author_quote', ['quote' => F::quote()]), $f['operator']));
        $f2 = F::setup();
        $project = F::accept($f2);
        $project = $f2['journey']->customerCommand($project['id'], F::command($project, 'request_cancellation', ['reason' => 'Please review cancellation']), $f2['customer']['principal'], $f2['customer']['user'])['project'];
        $this->assertSame('cancellation_requested', $project['status']);
        $project = $f2['journey']->staffCommand($project['id'], F::command($project, 'cancel', ['reason' => 'Synthetic reviewed cancellation']), $f2['operator'])['project'];
        $this->assertSame('cancelled', $project['status']);
        $this->assertTrue($project['scopeFrozen']);
        $this->assertFalse($project['deliveryAuthorized']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_audit_callback_authority_withdrawal_rolls_back_the_append_and_keeps_prior_history(): void
    {
        $f = F::setup();
        $before = $this->evidence();
        AuditEvent::created(function (AuditEvent $event) use ($f): void {
            if ($event->action === 'service_project.author_quote') {
                DB::table('users')->where('id', $f['operator']->id)->update(['is_admin' => false]);
            }
        });
        try {
            $f['journey']->staffCommand($f['project']['id'], F::command($f['project'], 'author_quote', ['quote' => F::quote()]), $f['operator']);
            $this->fail('Current authority withdrawal must abort the append.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence());
        } finally {
            AuditEvent::flushEventListeners();
        }
    }

    public function test_customer_withdrawal_during_audit_and_disabled_or_production_policy_cannot_write(): void
    {
        $f = F::setup();
        $project = F::author($f);
        $before = $this->evidence();
        AuditEvent::created(function (AuditEvent $event) use ($f): void {
            if ($event->action === 'service_project.accept_quote') {
                DB::table('customer_accounts')->where('id', $f['customer']['account']->id)->update(['active' => false, 'access_version' => 2]);
            }
        });
        try {
            $f['journey']->customerCommand($project['id'], F::command($project, 'accept_quote', ['quoteId' => $project['quoteId'], 'quoteHash' => $project['quoteHash']]), $f['customer']['principal'], $f['customer']['user']);
            $this->fail('Withdrawn customer access must abort the append.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence());
        } finally {
            AuditEvent::flushEventListeners();
        }
        config(['services-projects.test_enabled' => false]);
        $this->changed(fn () => app(ServiceProjects::class)->staffShow($project['id'], $f['operator']), 404);
        config(['services-projects.test_enabled' => true]);
        $this->app['env'] = 'production';
        try {
            $this->changed(fn () => app(ServiceProjects::class)->staffShow($project['id'], $f['operator']), 404);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_terminal_framework_user_callback_cannot_return_a_private_staff_snapshot_after_role_withdrawal(): void
    {
        $f = F::setup();
        $project = F::author($f);
        $before = $this->evidence();
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads, $f): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, DB::connection()->getQueryGrammar()->wrapTable('users')) && ++$reads === 3) {
                DB::table('users')->where('id', $f['operator']->id)->update(['is_admin' => false]);
            }
        });
        try {
            $f['journey']->staffShow($project['id'], $f['operator']);
            $this->fail('No private snapshot may follow a terminal framework callback withdrawal.');
        } catch (AuthorizationException) {
            $this->assertGreaterThanOrEqual(3, $reads);
            $this->assertSame($before, $this->evidence());
        }
    }
}
