<?php

namespace Tests\Feature;

use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipAdministration;
use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipCreditBucket;
use App\Domain\Memberships\Models\MembershipPlan;
use App\Filament\Resources\MembershipCreditResource;
use App\Filament\Resources\MembershipCreditResource\Pages\ListMembershipCredits;
use App\Filament\Resources\MembershipPlanResource;
use App\Filament\Resources\MembershipPlanResource\Pages\ManageMembershipPlans;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipAdministrationActionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function pending(): array
    {
        $f = F::bucket();
        $this->actingAs($f['operator']);

        return $f;
    }

    private function input(MembershipPlan $plan): mixed
    {
        return Livewire::test(ManageMembershipPlans::class)->mountTableAction('editPlan', $plan);
    }

    private function preview(MembershipPlan $plan, array $policy = ['allowance' => 7], string $title = 'NONCOMMERCIAL REVISED POLICY'): mixed
    {
        return $this->input($plan)->setActionData(MembershipPlanResource::inputDisplay(F::data($policy, $title)))
            ->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('reviewRevision');
    }

    private function assertConsumed(mixed $page): void
    {
        $page->assertSet('planReview', null)->assertSet('planReviewContext', null)->assertSet('planInputContext', null);
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'audit_events']);
    }

    public function test_explicit_creation_saves_only_a_private_plan_and_reviewed_revision_retains_original_credit_policy(): void
    {
        $f = $this->pending();
        $bucket = DB::table('membership_credit_buckets')->first();
        $page = Livewire::test(ManageMembershipPlans::class)->mountAction('createPlan')
            ->setActionData(MembershipPlanResource::inputDisplay(F::data(['validity_seconds' => null], 'NONCOMMERCIAL NEW PRIVATE POLICY')))
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Private test plan created');
        $this->assertConsumed($page);
        $this->assertDatabaseCount('membership_plans', 2);
        $this->assertDatabaseCount('membership_credit_buckets', 1);
        $before = $this->evidence();
        $page = $this->preview($f['plan'])->assertSee('Current captured policy')->assertSee('Proposed policy');
        $review = $page->get('planReview');
        $this->assertSame($f['operator']->id, $review['actor_id']);
        $this->assertSame($before, $this->evidence());
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Private plan revision saved');
        $this->assertConsumed($page);
        $this->assertSame(2, DB::table('membership_plan_versions')->where('membership_plan_id', $f['plan']->id)->count());
        $this->assertSame((array) $bucket, (array) DB::table('membership_credit_buckets')->first());
        $this->assertDatabaseCount('membership_credit_events', 1);
        $this->assertSame($f['operator']->id, auth()->id());
    }

    public function test_semantic_no_op_adds_no_version_or_audit(): void
    {
        $f = $this->pending();
        $before = $this->evidence();
        $page = $this->preview($f['plan'], [], F::data()['title'])->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified('Private plan already matches; no version added');
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_stale_revision_is_consumed_and_back_to_policy_retains_input_for_an_explicit_fresh_review(): void
    {
        $f = $this->pending();
        $page = $this->preview($f['plan']);
        $plans = app(MembershipPlans::class);
        $plans->applyReviewedRevision($plans->reviewRevision($f['plan'], F::data(['allowance' => 8]), $f['operator']), $f['operator']);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasActionErrors(['entered_plan'])->assertNotified('Review the current private plan to continue');
        $this->assertConsumed($page);
        $page->assertSet('enteredPlan.allowance', 7);
        $this->assertSame($before, $this->evidence());
        $page->callMountedAction()->assertHasActionErrors(['entered_plan']);
        $this->assertSame($before, $this->evidence());
        $page->mountAction('backToPlan')->assertSet('mountedActions.0.name', 'editPlan')->assertActionDataSet(['allowance' => 7])
            ->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('reviewRevision')->assertSee('Captured version 2.');
        $this->assertSame($before, $this->evidence());
    }

    public function test_invalid_input_is_retained_but_cannot_reuse_consumed_mount_after_correction(): void
    {
        $f = $this->pending();
        $before = $this->evidence();
        $page = $this->input($f['plan'])->setActionData(['title' => ''])->callMountedAction()->assertHasActionErrors(['title']);
        $this->assertConsumed($page);
        $page->assertSet('enteredPlan.title', '')->setActionData(['title' => 'Corrected without reopening'])->callMountedAction()->assertHasActionErrors(['title']);
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('editPlan', $f['plan'])->assertActionDataSet(['title' => 'Corrected without reopening'])
            ->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('reviewRevision');
    }

    public static function contextChanges(): array
    {
        return ['search' => ['tableSearch', 'changed'], 'columns' => ['tableColumnSearches', ['id' => '2']],
            'sort' => ['tableSort', 'id:asc'], 'page size' => ['tableRecordsPerPage', 10],
            'arguments' => ['mountedActions.0.arguments.unreviewed', true], 'context' => ['mountedActions.0.context.unreviewed', true],
            'policy text' => ['mountedActions.0.data.entered_plan', 'Unreviewed replacement'],
            'hidden field' => ['mountedActions.0.data.price', '50']];
    }

    #[DataProvider('contextChanges')]
    public function test_each_context_change_consumes_review_and_cannot_write(string $property, mixed $value): void
    {
        $f = $this->pending();
        $before = $this->evidence();
        $page = $this->preview($f['plan'])->set($property, $value);
        $this->assertConsumed($page);
        $page->callMountedAction()->assertHasActionErrors(['entered_plan']);
        $this->assertSame($before, $this->evidence());
    }

    public function test_cancel_pagination_and_actor_substitution_cannot_reuse_review(): void
    {
        $f = $this->pending();
        $page = $this->preview($f['plan']);
        $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName());
        $this->assertConsumed($page);
        $page->callMountedAction()->assertHasActionErrors(['entered_plan']);
        $page = $this->preview($f['plan']);
        $other = F::operator();
        $this->actingAs($other);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasActionErrors(['entered_plan']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
        $this->actingAs($f['operator']);
        $page = $this->preview($f['plan'])->unmountAction();
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_locked_capture_and_direct_helpers_cannot_bypass_the_mounted_lifecycle(): void
    {
        $f = $this->pending();
        foreach (['planReview.actor_id' => 999, 'planReview.replacement.policy.allowance' => 99,
            'planReviewContext.table.page' => '2', 'enteredPlan.allowance' => 99, 'editingPlanId' => 999] as $property => $value) {
            try {
                $this->preview($f['plan'])->set($property, $value);
                $this->fail('A client updated retained private policy evidence.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
        $component = $this->preview($f['plan'])->instance();
        $action = $component->getMountedAction();
        $before = $this->evidence();
        foreach ([fn () => $component->capturePlanInput($f['plan']), fn () => $component->reviewPlan(F::data(), $action),
            fn () => $component->applyPlan($action), fn () => $component->createPlan(F::data(), $action), fn () => $component->backToPlan()] as $call) {
            try {
                $call();
                $this->fail('A direct helper bypassed the mounted lifecycle.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        Livewire::test(ManageMembershipPlans::class)->call('mountAction', 'reviewRevision')->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public static function revocations(): array
    {
        return ['role' => ['is_admin', false], 'email' => ['email_verified_at', null], 'MFA' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_current_operator_authority_is_required_after_the_review_was_captured(string $field, mixed $value): void
    {
        $f = $this->pending();
        $page = $this->preview($f['plan']);
        DB::table('users')->where('id', $f['operator']->id)->update([$field => $value]);
        $before = $this->evidence();
        $page->callMountedAction()->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_uncertain_failure_retains_copyable_input_logs_only_exception_class_and_prevents_replay(): void
    {
        $f = $this->pending();
        $page = $this->preview($f['plan']);
        $before = $this->evidence();
        Log::spy();
        AuditEvent::creating(fn () => throw new RuntimeException('PRIVATE_SYNTHETIC_FAILURE_DETAIL'));
        $page->callMountedAction()->assertHasActionErrors(['entered_plan'])->assertNotified('The private plan save could not be confirmed');
        $this->assertConsumed($page);
        $page->assertSet('enteredPlan.allowance', 7);
        $this->assertSame($before, $this->evidence());
        Log::shouldHaveReceived('warning')->once()->with('Private membership authoring could not confirm the result.', ['exception_class' => RuntimeException::class]);
        $notifications = json_encode(session('filament.claimed_notifications') ?? session('filament.notifications'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE_SYNTHETIC_FAILURE_DETAIL', $notifications);
        $this->assertStringNotContainsString('Private plan revision saved', $notifications);
        $page->callMountedAction()->assertHasActionErrors(['entered_plan']);
        $this->assertSame($before, $this->evidence());
        $this->assertCopyableRecovery($page);
    }

    public function test_real_commit_with_a_lost_response_requires_inspection_and_fresh_review_before_any_retry(): void
    {
        $f = $this->pending();
        $page = $this->preview($f['plan']);
        $real = app(MembershipPlans::class);
        $this->app->instance(MembershipAdministration::class, app(MembershipAdministration::class));
        $this->app->instance(MembershipPlans::class, new class($real)
        {
            public function __construct(private MembershipPlans $real) {}

            public function reviewRevision($plan, $replacement, $actor): array
            {
                return $this->real->reviewRevision($plan, $replacement, $actor);
            }

            public function applyReviewedRevision($review, $actor): never
            {
                $this->real->applyReviewedRevision($review, $actor);
                throw new RuntimeException('PRIVATE_SYNTHETIC_COMMITTED_RESPONSE_LOSS');
            }
        });
        $page->callMountedAction()->assertHasActionErrors(['entered_plan'])->assertNotified('The private plan save could not be confirmed');
        $this->assertConsumed($page);
        $this->assertSame(2, DB::table('membership_plan_versions')->where('membership_plan_id', $f['plan']->id)->count());
        $this->assertCopyableRecovery($page);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasActionErrors(['entered_plan']);
        $this->assertSame($before, $this->evidence());
        $this->app->instance(MembershipPlans::class, $real);
        $page->mountAction('backToPlan')->assertSet('mountedActions.0.name', 'editPlan')->assertActionDataSet(['allowance' => 7])
            ->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('reviewRevision')->assertSee('Captured version 2.')
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Private plan already matches; no version added');
        $this->assertSame($before, $this->evidence());
    }

    private function assertCopyableRecovery(mixed $page): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML($page->getMountedActionModalHtml());
        $xpath = new \DOMXPath($document);
        $copy = $xpath->query('//textarea[@readonly]');
        $this->assertCount(1, $copy);
        $this->assertFalse($copy->item(0)->hasAttribute('disabled'));
        $submit = $xpath->query('//form[@*[name()="wire:submit.prevent"]="callMountedAction"]//button[@type="submit"]');
        $this->assertCount(1, $submit);
        $this->assertTrue($submit->item(0)->hasAttribute('disabled'));
    }

    public function test_credit_list_starts_empty_and_inspects_only_the_selected_account_without_writes(): void
    {
        $f = $this->pending();
        $other = CustomerFixtures::account();
        $second = app(CreditLedger::class)->grantSynthetic($f['version'], $other['account'], 'synthetic:second_account', $f['operator']);
        $first = MembershipCreditBucket::findOrFail($f['grant']['bucket_id']);
        $otherBucket = MembershipCreditBucket::findOrFail($second['bucket_id']);
        $before = $this->evidence();
        $page = Livewire::test(ListMembershipCredits::class)->assertCountTableRecords(0)
            ->filterTable('account', (string) $f['account']->id)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$otherBucket])
            ->mountTableAction('creditHistory', $first);
        $this->assertSame($f['operator']->id, auth()->id());
        $this->assertSame($before, $this->evidence());
        $html = $this->modalHtml($page);
        $this->assertStringContainsString('Synthetic bucket #'.$first->id, $html);
        $this->assertStringContainsString('Spendable synthetic_credit at this captured read: 3.', $html);
        foreach ([$f['account']->owner_key, $f['user']->email, 'resource_hash', 'request_hash', 'key_hash'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_rendered_review_escapes_authored_title_and_keeps_copy_and_submit_controls(): void
    {
        $f = $this->pending();
        $page = $this->preview($f['plan'], ['validity_seconds' => null], '</textarea><script>synthetic-policy</script>');
        $html = $this->modalHtml($page);
        $this->assertStringNotContainsString('<script>synthetic-policy</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;synthetic-policy&lt;/script&gt;', $html);
        $this->assertStringContainsString('repeat(auto-fit, minmax(min(100%, 16rem), 1fr))', $html);
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertCount(1, $xpath->query('//textarea[@readonly]'));
        $submit = $xpath->query('//form[@*[name()="wire:submit.prevent"]="callMountedAction"]//button[@type="submit"]');
        $this->assertCount(1, $submit);
        $this->assertFalse($submit->item(0)->hasAttribute('wire:loading.attr'));
        $this->assertFalse($submit->item(0)->hasAttribute('disabled'));
    }

    public function test_credit_inspection_cannot_be_delegated_by_a_direct_unmounted_call_or_changed_account_filter(): void
    {
        $f = $this->pending();
        $bucket = MembershipCreditBucket::findOrFail($f['grant']['bucket_id']);
        $component = Livewire::test(ListMembershipCredits::class)->instance();
        try {
            $component->inspectCreditHistory($bucket);
            $this->fail('An unmounted callback inspected delegated credit history.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $component = Livewire::test(ListMembershipCredits::class)->filterTable('account', (string) $f['account']->id)
            ->mountTableAction('creditHistory', $bucket)->instance();
        $component->tableFilters['account']['value'] = null;
        try {
            $component->inspectCreditHistory($bucket);
            $this->fail('Credit inspection escaped its explicit selected account scope.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_malformed_account_scope_is_empty_and_disabled_resources_refuse_both_pages(): void
    {
        $this->pending();
        foreach ([null, '', '01', 0, -1, 1.5, ['1'], '99999999999999999999999'] as $scope) {
            $this->assertSame(0, MembershipCreditResource::scopedQuery($scope)->count());
        }
        config(['memberships.test_mode_enabled' => false]);
        Livewire::test(ManageMembershipPlans::class)->assertForbidden();
        Livewire::test(ListMembershipCredits::class)->assertForbidden();
    }

    public function test_private_http_pages_require_an_operator_and_current_enrolled_mfa(): void
    {
        $f = $this->pending();
        $this->get(MembershipPlanResource::getUrl())->assertOk();
        $this->get(MembershipCreditResource::getUrl())->assertOk();
        DB::table('users')->where('id', $f['operator']->id)->update(['app_authentication_secret' => null]);
        $this->get(MembershipPlanResource::getUrl())->assertForbidden();
        $this->get(MembershipCreditResource::getUrl())->assertForbidden();
    }

    private function modalHtml(mixed $page): string
    {
        return $page->effects['partials']['action-modals.0'] ?? $page->effects['partials']['action-modals'] ?? $page->html();
    }
}
