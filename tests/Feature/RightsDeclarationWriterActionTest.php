<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Filament\Resources\RightsDeclarationResource\Pages\ManageRightsDeclarations;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
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

class RightsDeclarationWriterActionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function pending(): array
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $track = Track::create(['title' => 'Synthetic displayed source', 'slug' => 'synthetic-displayed-source']);
        $data = ['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-PRIVATE-UI-REFERENCE',
            'sample_disclosure' => "Synthetic sample disclosure\nSecond exact line"];
        $declaration = app(SaveRightsDeclaration::class)->create($data, $actor);
        $this->actingAs($actor);

        return compact('actor', 'track', 'declaration', 'data');
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'rights_declarations', 'audit_events']);
    }

    public static function intents(): array
    {
        return ['edit' => ['edit'], 'verify' => ['verify']];
    }

    private function assertConsumed(mixed $page): void
    {
        $page->assertSet('rightsReview', null)->assertSet('rightsReviewContext', null);
    }

    public function test_actual_create_edit_retarget_and_verify_actions_use_the_domain_and_consume_exact_mounted_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $source = Track::create(['title' => 'Synthetic create source', 'slug' => 'synthetic-create-source']);
        $target = Track::create(['title' => 'Synthetic selected target', 'slug' => 'synthetic-selected-target']);
        $this->actingAs($actor);
        $data = ['track_id' => (string) $source->id, 'provenance_reference' => 'SYNTHETIC-ACTUAL-UI-CREATE', 'sample_disclosure' => 'Synthetic actual UI evidence'];
        $page = Livewire::test(ManageRightsDeclarations::class)->mountAction('create')
            ->setActionData($data)->callMountedAction()->assertHasNoActionErrors();
        $this->assertConsumed($page);
        $created = RightsDeclaration::sole();
        $this->assertSame('pending', $created->status);
        $this->assertNull($created->verified_by);
        $this->assertNull($created->verified_at);
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction('edit', $created)
            ->assertTableActionDataSet(['track_id' => $source->id, 'provenance_reference' => $data['provenance_reference'], 'sample_disclosure' => $data['sample_disclosure']])
            ->assertSet('rightsReview.actor_id', $actor->id)->assertSet('rightsReview.declaration_id', $created->id)
            ->assertSet('rightsReview.track_id', $source->id)->assertSet('rightsReview.intent', 'edit');
        $baseline = $page->get('rightsReview');
        $edited = array_replace($data, ['track_id' => (string) $target->id, 'sample_disclosure' => 'Synthetic explicit retarget evidence']);
        $page->setTableActionData($edited)->assertSet('rightsReview', $baseline)
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertConsumed($page);
        $this->assertSame($target->id, $created->fresh()->track_id);
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction('verify', $created->fresh())
            ->assertSet('rightsReview.intent', 'verify')->assertSet('rightsReview.track_id', $target->id)
            ->assertSet('rightsReview.display.sample_disclosure', $edited['sample_disclosure']);
        $modal = $page->instance()->getMountedAction()->getModalContent()->render();
        foreach ([$target->title, $data['provenance_reference'], $edited['sample_disclosure']] as $text) {
            $this->assertStringContainsString(e($text), $modal);
        }
        $page->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Rights declaration verified');
        $this->assertConsumed($page);
        $this->assertSame('verified', $created->fresh()->status);
        $this->assertSame($actor->id, $created->fresh()->verified_by);
        $this->assertNotNull($created->fresh()->verified_at);
        $this->assertSame(['rights.declaration.created', 'rights.declaration.updated', 'rights.declaration.verified'], AuditEvent::orderBy('id')->pluck('action')->all());
    }

    #[DataProvider('intents')]
    public function test_modal_displays_the_exact_captured_private_evidence_as_escaped_text(string $intent): void
    {
        ['actor' => $actor, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $data = $declaration->only(['track_id', 'provenance_reference', 'sample_disclosure']);
        $data['provenance_reference'] = '<script>syntheticReference()</script>';
        $data['sample_disclosure'] = "<img src=x onerror=synthetic()>\nSynthetic exact second line";
        $save->updateReviewed($save->review($declaration, $actor), $data, $actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction($intent, $declaration->fresh());
        $modal = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString(e($data['provenance_reference']), $modal);
        $this->assertStringContainsString(e($data['sample_disclosure']), $modal);
        $this->assertStringNotContainsString($data['provenance_reference'], $modal);
        $this->assertStringNotContainsString('<img src=x', $modal);
        $this->assertSame($before, $this->evidence());
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach (['create', 'edit', 'verify'] as $intent) {
            foreach (['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
                'MFA enrollment' => ['app_authentication_secret', null]] as $label => [$field, $value]) {
                $cases[$intent.' '.$label] = [$intent, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function test_actual_submit_refuses_role_email_or_required_mfa_revoked_after_mount(string $intent, string $field, mixed $value): void
    {
        ['actor' => $actor, 'declaration' => $declaration, 'data' => $data] = $this->pending();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $page = Livewire::test(ManageRightsDeclarations::class);
            if ($intent === 'create') {
                $page->mountAction('create')->setActionData($data);
            } else {
                $page->mountTableAction($intent, $declaration)->assertSet('rightsReview.actor_id', $actor->id);
            }
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $page->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public static function competitors(): array
    {
        return ['edit after editor' => ['edit', 'edit'], 'verify after editor' => ['verify', 'edit'],
            'edit after verifier' => ['edit', 'verify'], 'verify after verifier' => ['verify', 'verify']];
    }

    #[DataProvider('competitors')]
    public function test_competing_editor_or_verifier_blocks_the_actual_mounted_submit_and_consumes_without_refreshing_review(string $intent, string $competitor): void
    {
        ['actor' => $actor, 'declaration' => $declaration, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction($intent, $declaration);
        $captured = $page->get('rightsReview');
        if ($competitor === 'edit') {
            app(SaveRightsDeclaration::class)->updateReviewed(app(SaveRightsDeclaration::class)->review($declaration, $actor),
                array_replace($data, ['sample_disclosure' => 'Synthetic other editor current disclosure']), $actor);
        } else {
            app(VerifyRightsDeclaration::class)->handle($declaration, $actor);
        }
        $before = $this->evidence();
        $page->assertSet('rightsReview', $captured)->callMountedTableAction();
        $this->assertConsumed($page);
        if ($intent === 'edit') {
            $page->assertHasTableActionErrors(['provenance_reference'])->assertNotified('Review required');
        } else {
            $page->assertSet('mountedActions', [])->assertNotified('Verification blocked');
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_invalid_form_consumes_before_schema_validation_and_a_corrected_retry_requires_reopening(): void
    {
        ['declaration' => $declaration, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction('edit', $declaration)
            ->setTableActionData(array_replace($data, ['provenance_reference' => '']))->callMountedTableAction()
            ->assertHasTableActionErrors(['provenance_reference']);
        $this->assertConsumed($page);
        $page->setTableActionData(array_replace($data, ['sample_disclosure' => 'Synthetic corrected retry']))
            ->callMountedTableAction()->assertHasTableActionErrors(['provenance_reference'])->assertNotified('Review required');
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $declaration)
            ->setTableActionData(array_replace($data, ['sample_disclosure' => 'Synthetic reopened correction']))
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Synthetic reopened correction', $declaration->fresh()->sample_disclosure);
    }

    public function test_domain_text_capacity_errors_render_under_the_actual_mounted_schema_field_and_consume_review(): void
    {
        ['declaration' => $declaration, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        foreach (['provenance_reference', 'sample_disclosure'] as $field) {
            $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction('edit', $declaration)
                ->setTableActionData(array_replace($data, [$field => str_repeat('é', 32768)]))
                ->callMountedTableAction()->assertHasTableActionErrors([$field])->assertNotified('Review required');
            $this->assertConsumed($page);
            $this->assertSame($before, $this->evidence());
        }
    }

    #[DataProvider('intents')]
    public function test_another_authorized_actor_cannot_submit_the_first_actors_mounted_review(string $intent): void
    {
        ['declaration' => $declaration] = $this->pending();
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction($intent, $declaration);
        $other = LicenseFixtures::admin();
        $other->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($other);
        $before = $this->evidence();
        $page->callMountedTableAction();
        $this->assertConsumed($page);
        if ($intent === 'edit') {
            $page->assertHasTableActionErrors(['provenance_reference']);
        } else {
            $page->assertSet('mountedActions', [])->assertNotified('Verification blocked');
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_locked_review_properties_reject_client_tampering_and_direct_public_callbacks_cannot_create_a_review_or_write(): void
    {
        ['declaration' => $declaration, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        foreach (['rightsReview.actor_id' => 999, 'rightsReview.track_id' => 999,
            'rightsReview.display.provenance_reference' => 'SYNTHETIC-FORGED', 'rightsReviewContext.table.page' => '2'] as $property => $value) {
            try {
                Livewire::test(ManageRightsDeclarations::class)->mountTableAction('edit', $declaration)->set($property, $value);
                $this->fail('A client changed locked rights review evidence.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
        $component = Livewire::test(ManageRightsDeclarations::class)->mountTableAction('edit', $declaration)->instance();
        $action = $component->getMountedAction();
        foreach ([fn () => $component->captureRightsReview($declaration, 'edit'),
            fn () => $component->updateRightsDeclaration($declaration, $data, $action),
            fn () => $component->createRightsDeclaration($data, $action),
            fn () => $component->verifyRightsDeclaration($declaration, $action)] as $operation) {
            try {
                $operation();
                $this->fail('A public UI helper bypassed actual action mounting or submission.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_cancel_action_record_arguments_search_sort_and_page_changes_clear_the_mounted_review(): void
    {
        ['declaration' => $declaration] = $this->pending();
        $other = RightsDeclaration::create(['track_id' => $declaration->track_id, 'provenance_reference' => 'SYNTHETIC-OTHER-RECORD',
            'sample_disclosure' => 'Synthetic other record', 'status' => 'pending']);
        $mount = fn () => Livewire::test(ManageRightsDeclarations::class)->mountTableAction('edit', $declaration);
        $before = $this->evidence();
        $this->assertConsumed($mount()->unmountAction());
        $this->assertConsumed($mount()->mountAction('create'));
        foreach (['mountedActions.0.context.recordKey' => (string) $other->id, 'mountedActions.0.name' => 'verify',
            'mountedActions.0.arguments.unreviewed' => true, 'tableSearch' => 'changed', 'tableSort' => 'status:desc'] as $property => $value) {
            $page = $mount()->set($property, $value);
            $this->assertConsumed($page);
            if (str_starts_with($property, 'mountedActions.')) {
                $page->callMountedAction();
                $this->assertSame($before, $this->evidence());
            }
        }
        $page = $mount();
        $this->assertConsumed($page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName()));
        $this->assertSame($before, $this->evidence());
    }

    public function test_a_selected_target_deleted_after_mount_cannot_retarget_or_regenerate_the_source_review(): void
    {
        ['declaration' => $declaration, 'data' => $data] = $this->pending();
        $target = Track::create(['title' => 'Synthetic disappearing target', 'slug' => 'synthetic-disappearing-target']);
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction('edit', $declaration)
            ->setTableActionData(array_replace($data, ['track_id' => $target->id]));
        $target->delete();
        $before = $this->evidence();
        $page->callMountedTableAction();
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
        $this->assertSame($data['track_id'], $declaration->fresh()->track_id);
    }

    #[DataProvider('intents')]
    public function test_uncertain_audit_failure_consumes_review_leaves_every_row_unchanged_and_logs_only_exception_class(string $intent): void
    {
        ['declaration' => $declaration, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageRightsDeclarations::class)->mountTableAction($intent, $declaration);
        if ($intent === 'edit') {
            $page->setTableActionData(array_replace($data, ['sample_disclosure' => 'Synthetic attempted audit failure change']));
        }
        $before = $this->evidence();
        Log::spy();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic private failure '.$data['provenance_reference']));
        $page->callMountedTableAction()->assertNotified('The save result could not be confirmed.')->assertSet('mountedActions', []);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
        Log::shouldHaveReceived('warning')->once()->with('Rights declaration UI could not confirm the result.', ['exception_class' => RuntimeException::class]);
        $this->assertStringNotContainsString($data['provenance_reference'], json_encode(session('filament.notifications'), JSON_THROW_ON_ERROR));
        $page->callMountedAction();
        $this->assertSame($before, $this->evidence());
    }
}
