<?php

namespace Tests\Feature;

use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Filament\Resources\LicenseTemplateResource\Pages\ManageLicenseTemplates;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class LicenseTemplateAuthoringActionTest extends TestCase
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
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $data = ['name' => 'NONBINDING UI IDENTITY', 'slug' => 'synthetic-ui-template', 'type' => 'non-exclusive'];
        $template = app(SaveLicenseTemplate::class)->create($data, $actor);
        $this->actingAs($actor);

        return compact('actor', 'data', 'template');
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['license_templates', 'license_versions', 'license_review_evidence', 'offers', 'offer_revisions', 'audit_events']);
    }

    private function assertConsumed(mixed $page): void
    {
        $page->assertSet('templateReview', null)->assertSet('templateReviewContext', null);
    }

    public function test_actual_create_and_edit_actions_use_captured_fields_and_atomic_actor_audits(): void
    {
        ['actor' => $actor, 'data' => $data] = $this->pending();
        $data = array_replace($data, ['name' => '<Synthetic identity>', 'slug' => 'synthetic-created-template']);
        $page = Livewire::test(ManageLicenseTemplates::class)->mountAction('create')
            ->setActionData($data)->callMountedAction()->assertHasNoActionErrors()->assertNotified('Created');
        $this->assertConsumed($page);
        $created = LicenseTemplate::where('slug', $data['slug'])->sole();
        $page = Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $created)
            ->assertTableActionDataSet($data)->assertSet('templateReview.actor_id', $actor->id)
            ->assertSet('templateReview.template_id', $created->id)->assertSet('templateReview.display', $data);
        $captured = $page->get('templateReview');
        $changed = array_replace($data, ['name' => 'Changed UI identity', 'type' => 'free']);
        $page->setTableActionData($changed)->assertSet('templateReview', $captured)
            ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Saved');
        $this->assertConsumed($page);
        $this->assertSame($changed, $created->fresh()->only(SaveLicenseTemplate::FIELDS));
        $this->assertSame(['rights.license_template.created', 'rights.license_template.updated'],
            AuditEvent::where('subject_id', $created->id)->orderBy('id')->pluck('action')->all());
        $this->assertSame([$actor->id], AuditEvent::where('subject_id', $created->id)->pluck('actor_id')->unique()->all());
        $this->assertDatabaseCount('license_versions', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_create_validation_can_be_corrected_but_invalid_edit_consumes_review_until_reopened(): void
    {
        ['template' => $template, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageLicenseTemplates::class)->mountAction('create')
            ->setActionData($data)->callMountedAction()->assertHasActionErrors(['slug']);
        $page->setActionData(array_replace($data, ['slug' => 'synthetic-corrected-create']))
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertConsumed($page);
        $before = $this->evidence();
        $page = Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $template)
            ->setTableActionData(array_replace($data, ['name' => '']))->callMountedTableAction()->assertHasTableActionErrors(['name']);
        $this->assertConsumed($page);
        $page->setTableActionData(array_replace($data, ['name' => 'Changed after invalid form']))
            ->callMountedTableAction()->assertHasTableActionErrors(['name'])->assertNotified('Reopen template to continue');
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $template)
            ->setTableActionData(array_replace($data, ['name' => 'Changed after reopen']))
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Changed after reopen', $template->fresh()->name);
    }

    public function test_competing_edit_blocks_stale_actual_submit_without_refreshing_or_overwriting(): void
    {
        ['actor' => $actor, 'template' => $template, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $template);
        $review = $page->get('templateReview');
        $command = app(SaveLicenseTemplate::class);
        $command->updateReviewed($command->review($template, $actor), array_replace($data, ['name' => 'Other editor saved identity']), $actor);
        $before = $this->evidence();
        $page->assertSet('templateReview', $review)->setTableActionData(array_replace($data, ['name' => 'Stale local identity']))
            ->callMountedTableAction()->assertHasTableActionErrors(['name'])->assertNotified('Reopen template to continue');
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public static function frozenStates(): array
    {
        return ['legal review' => ['legal_review'], 'approved' => ['approved'], 'published' => ['published']];
    }

    #[DataProvider('frozenStates')]
    public function test_all_reviewed_templates_hide_edit_and_explain_successor_identity_without_mutating_evidence(string $status): void
    {
        ['actor' => $actor] = $this->pending();
        $version = match ($status) {
            'legal_review' => app(ReviewLicense::class)->submit(LicenseFixtures::draft($actor), $actor),
            'approved' => LicenseFixtures::approved($actor),
            'published' => LicenseFixtures::published($actor),
        };
        $before = $this->evidence();
        Livewire::test(ManageLicenseTemplates::class)->assertTableActionHidden('edit', $version->template)
            ->assertSee('Frozen after review')->assertSee('Create a new successor template for identity changes.');
        $this->assertSame($before, $this->evidence());
    }

    public function test_submission_after_mount_refuses_edit_with_visible_frozen_guidance_and_preserves_submission(): void
    {
        ['actor' => $actor] = $this->pending();
        $version = LicenseFixtures::draft($actor);
        $template = $version->template;
        $page = Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $template)
            ->setTableActionData(array_replace($template->only(SaveLicenseTemplate::FIELDS), ['name' => 'Too late identity']));
        app(ReviewLicense::class)->submit($version, $actor);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasTableActionErrors(['name'])->assertNotified(
            Notification::make()->danger()->title('Reopen template to continue')
                ->body('Close and reopen the current template before saving again. '.SaveLicenseTemplate::FROZEN_MESSAGE)->persistent()
        );
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach (['create', 'edit'] as $intent) {
            foreach (['role' => ['is_admin', false], 'email' => ['email_verified_at', null], 'MFA' => ['app_authentication_secret', null]] as $label => [$field, $value]) {
                $cases[$intent.' '.$label] = [$intent, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function test_actual_submit_refuses_current_role_email_and_required_mfa_withdrawal(string $intent, string $field, mixed $value): void
    {
        ['actor' => $actor, 'template' => $template, 'data' => $data] = $this->pending();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $page = Livewire::test(ManageLicenseTemplates::class);
            $intent === 'create' ? $page->mountAction('create')->setActionData(array_replace($data, ['slug' => 'synthetic-after-mount']))
                : $page->mountTableAction('edit', $template)->setTableActionData(array_replace($data, ['name' => 'After mount']));
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $page->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_another_authorized_actor_cannot_submit_the_first_actors_mounted_edit(): void
    {
        ['template' => $template] = $this->pending();
        $page = Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $template);
        $other = LicenseFixtures::admin();
        $other->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($other);
        $before = $this->evidence();
        $page->callMountedTableAction()->assertHasTableActionErrors(['name']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_locked_properties_and_direct_public_callbacks_cannot_forge_review_or_write(): void
    {
        ['template' => $template, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        foreach (['templateReview.actor_id' => 999, 'templateReview.template_id' => 999,
            'templateReview.display.name' => 'Forged identity', 'templateReviewContext.table.page' => '2'] as $property => $value) {
            try {
                Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $template)->set($property, $value);
                $this->fail('A client changed locked template review evidence.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
        $component = Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $template)->instance();
        $action = $component->getMountedAction();
        foreach ([fn () => $component->captureTemplateReview($template), fn () => $component->createTemplate($data, $action),
            fn () => $component->updateTemplate($template, $data, $action)] as $operation) {
            try {
                $operation();
                $this->fail('A public helper bypassed real mounting or submission.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_cancel_and_action_record_argument_search_sort_page_changes_consume_the_baseline(): void
    {
        ['actor' => $actor, 'template' => $template, 'data' => $data] = $this->pending();
        $other = app(SaveLicenseTemplate::class)->create(array_replace($data, ['slug' => 'synthetic-other-record']), $actor);
        $mount = fn () => Livewire::test(ManageLicenseTemplates::class)->mountTableAction('edit', $template);
        $before = $this->evidence();
        $this->assertConsumed($mount()->unmountAction());
        foreach (['mountedActions.0.context.recordKey' => (string) $other->id,
            'mountedActions.0.arguments.unreviewed' => true, 'mountedActions.0.data.published_at' => '2026-01-01', 'tableSearch' => 'changed', 'tableSort' => 'name:desc'] as $property => $value) {
            $page = $mount()->set($property, $value);
            $this->assertConsumed($page);
            if (str_starts_with($property, 'mountedActions.')) {
                $page->callMountedAction()->assertHasActionErrors(['name']);
            }
            $this->assertSame($before, $this->evidence());
        }
        $page = $mount();
        $this->assertConsumed($page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName()));
        $page = $mount()->callMountedAction(['unexpected' => true])->assertHasTableActionErrors(['name']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public static function mutations(): array
    {
        return ['create' => ['create'], 'edit' => ['edit']];
    }

    #[DataProvider('mutations')]
    public function test_uncertain_audit_failure_closes_form_without_success_and_logs_only_exception_class(string $intent): void
    {
        ['template' => $template, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageLicenseTemplates::class);
        $intent === 'create' ? $page->mountAction('create')->setActionData(array_replace($data, ['slug' => 'synthetic-uncertain-create']))
            : $page->mountTableAction('edit', $template)->setTableActionData(array_replace($data, ['name' => 'Unconfirmed identity']));
        $before = $this->evidence();
        Log::spy();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic private exception content'));
        $page->callMountedAction()->assertSet('mountedActions', []);
        // Filament claims the queue while building the Livewire response. Read that emitted payload before its test helper drains it.
        $sent = session('filament.claimed_notifications') ?? session('filament.notifications');
        $this->assertIsArray($sent);
        $this->assertCount(1, $sent);
        $page->assertNotified(Notification::make()->danger()->title('The save result could not be confirmed.')
            ->body('Reload templates and inspect the current saved identity before trying again.')->persistent());
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
        Log::shouldHaveReceived('warning')->once()->with('License template UI could not confirm the result.', ['exception_class' => RuntimeException::class]);
        $notifications = json_encode($sent, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Synthetic private exception content', $notifications);
        $this->assertStringNotContainsString('"title":"Saved"', $notifications);
        $this->assertStringNotContainsString('"title":"Created"', $notifications);
        $page->callMountedAction();
        $this->assertSame($before, $this->evidence());
    }
}
