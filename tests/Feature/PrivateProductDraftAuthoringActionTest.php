<?php

namespace Tests\Feature;

use App\Domain\Merch\MerchDraftManifest;
use App\Domain\ProductAuthoring\PrivateDraft;
use App\Domain\ProductAuthoring\PrivateDraftManifest;
use App\Domain\ProductAuthoring\ReviewedPrivateDrafts;
use App\Domain\Services\ServiceDraftManifest;
use App\Filament\Resources\MerchDraftResource;
use App\Filament\Resources\MerchDraftResource\Pages\ManageMerchDrafts;
use App\Filament\Resources\ServiceDraftResource;
use App\Filament\Resources\ServiceDraftResource\Pages\ManageServiceDrafts;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PrivateProductDraftFixtures as Fixtures;
use Tests\TestCase;

class PrivateProductDraftAuthoringActionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function families(): array
    {
        return Fixtures::families();
    }

    private function page(string $kind): string
    {
        return $kind === 'service' ? ManageServiceDrafts::class : ManageMerchDrafts::class;
    }

    private function pending(string $kind): array
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $command = app($class);
        $draft = $command->applyReviewed($command->review(null, Fixtures::payload($kind), $actor), $actor);
        $this->actingAs($actor);

        return [$actor, $command, $draft];
    }

    private function evidence(string $kind): array
    {
        return array_map(fn ($table): string => DB::table($table)->orderBy('id')->get()->toJson(),
            [$kind.'_drafts', $kind.'_draft_versions', 'audit_events']);
    }

    private function uiPayload(string $kind, array $changes = []): array
    {
        $data = Fixtures::payload($kind, $changes);
        if ($kind === 'service') {
            $data['brief_questions'] = array_map(fn (string $question): array => ['question' => $question], $data['brief_questions']);
        }

        return $data;
    }

    private function assertConfirmationFooter(string $html, bool $disabled): void
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $buttons = $xpath->query('//button[contains(normalize-space(.), "Save reviewed private version")]');
        $this->assertSame(1, $buttons->length);
        $this->assertSame($disabled, $buttons->item(0)->hasAttribute('disabled'));
        $fields = $xpath->query('//textarea[@readonly]');
        $boundCopyFields = [];
        foreach ($fields as $field) {
            if ($field->getAttribute('wire:model') === 'mountedActions.0.data.entered_summary') {
                $boundCopyFields[] = $field;
                $this->assertFalse($field->hasAttribute('disabled'));
                $this->assertSame('state', $field->getAttribute('x-model'));
            }
        }
        $this->assertCount(1, $boundCopyFields);
    }

    #[DataProvider('families')]
    public function test_real_private_create_compare_and_save_retains_exact_content_and_escapes_authored_markup(string $kind): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        [$class, $draftClass] = Fixtures::classes($kind);
        $page = Livewire::test($this->page($kind))->mountAction('createDraft')->setActionData($this->uiPayload($kind))
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount($kind.'_drafts', 0);
        $review = $page->get('draftReview');
        $this->assertNotNull($review);
        $this->assertSame('confirmDraft', $page->get('mountedActions.0.name'));
        $this->assertSame($actor->id, $review['actor_id']);
        $this->assertStringContainsString('&lt;script&gt;', $page->html());
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Private draft version saved')->assertSet('draftReview', null);
        $draft = $draftClass::sole();
        $this->assertSame($review['manifest'], app($class)->snapshot($draft->id, $actor)['manifest']);
        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('families')]
    public function test_actual_edit_review_and_history_preserve_original_contents_and_zero_write_noop(string $kind): void
    {
        [$actor, $command, $draft] = $this->pending($kind);
        $page = Livewire::test($this->page($kind))->mountTableAction('editDraft', $draft)->assertSet('openedDraftId', $draft->id)
            ->setTableActionData($this->uiPayload($kind, ['description' => 'Entered next private contents']))
            ->callMountedAction()->assertHasNoActionErrors();
        $review = $page->get('draftReview');
        $this->assertSame('Private authored <script> text is retained as plain text.', $review['before_manifest']['description']);
        $this->assertSame('Entered next private contents', $review['manifest']['description']);
        $page->callMountedAction()->assertHasNoActionErrors()->assertSet('draftReview', null);
        $snapshot = $command->snapshot($draft->id, $actor);
        $this->assertSame(2, $snapshot['version']);
        $before = $this->evidence($kind);
        $page->mountTableAction('editDraft', $draft->fresh())->callMountedAction()->assertHasNoActionErrors()
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Private contents already match');
        $this->assertSame($before, $this->evidence($kind));
        $page->mountTableAction('history', $draft->fresh())->assertTableActionDataSet([
            'history' => 'Version 2 — '.$snapshot['history'][0]['created_at']."\n".($kind === 'service'
                ? ServiceDraftResource::describe($snapshot['history'][0]['manifest'])
                : MerchDraftResource::describe($snapshot['history'][0]['manifest']))
                ."\n\nVersion 1 — ".$snapshot['history'][1]['created_at']."\n".($kind === 'service'
                    ? ServiceDraftResource::describe($snapshot['history'][1]['manifest'])
                    : MerchDraftResource::describe($snapshot['history'][1]['manifest'])),
        ]);
    }

    #[DataProvider('families')]
    public function test_stale_comparison_is_consumed_keeps_copyable_text_and_cannot_save_twice(string $kind): void
    {
        [$actor, $command, $draft] = $this->pending($kind);
        $page = Livewire::test($this->page($kind))->mountTableAction('editDraft', $draft)
            ->setTableActionData($this->uiPayload($kind, ['description' => 'My retained unsaved text']))->callMountedAction();
        $command->applyReviewed($command->review($draft, Fixtures::payload($kind, ['description' => 'Another current editor']), $actor), $actor);
        $before = $this->evidence($kind);
        $page->callMountedAction()->assertHasActionErrors(['entered_summary'])->assertSet('draftReview', null)
            ->assertNotified('Review current draft to continue');
        $this->assertStringContainsString('My retained unsaved text', $page->get('enteredSummary'));
        $this->assertConfirmationFooter($page->html(), true);
        $page->callMountedAction()->assertHasActionErrors(['entered_summary']);
        $this->assertSame($before, $this->evidence($kind));
    }

    #[DataProvider('families')]
    public function test_changed_readonly_comparison_or_added_submit_arguments_refuse_without_writes(string $kind): void
    {
        [, , $draft] = $this->pending($kind);
        $before = $this->evidence($kind);
        $page = Livewire::test($this->page($kind))->mountTableAction('editDraft', $draft)->callMountedAction();
        $page->setActionData(['entered_summary' => 'Forged summary'])->callMountedAction()
            ->assertHasActionErrors(['entered_summary'])->assertSet('draftReview', null);
        $this->assertSame($before, $this->evidence($kind));
        $page->unmountAction()->mountTableAction('editDraft', $draft)->callMountedAction()
            ->callMountedAction(['unexpected' => true])->assertHasActionErrors(['entered_summary'])->assertSet('draftReview', null);
        $this->assertSame($before, $this->evidence($kind));
    }

    #[DataProvider('families')]
    public function test_locked_capture_and_direct_confirmation_rpc_cannot_manufacture_a_review(string $kind): void
    {
        [, , $draft] = $this->pending($kind);
        $before = $this->evidence($kind);
        try {
            Livewire::test($this->page($kind))->mountTableAction('editDraft', $draft)->callMountedAction()->set('draftReview.signature', str_repeat('a', 64));
            $this->fail('Locked review mutation must fail.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertSame($before, $this->evidence($kind));
        }
        Livewire::test($this->page($kind))->call('mountAction', 'confirmDraft')->assertForbidden();
        $this->assertSame($before, $this->evidence($kind));
    }

    #[DataProvider('families')]
    public function test_real_add_delete_and_reorder_preserve_the_opened_draft_fence(string $kind): void
    {
        [, , $draft] = $this->pending($kind);
        $field = $kind === 'service' ? 'brief_questions' : 'variants';
        $page = Livewire::test($this->page($kind))->mountTableAction('editDraft', $draft);
        $hash = $page->get('openedStateHash');
        $context = $page->get('inputContext');
        $component = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath($field);
        $this->assertInstanceOf(Repeater::class, $component);
        $childContext = $component->getAction('add')->getContext();
        $initial = array_keys($page->get('mountedActions.0.data.'.$field));
        $page->call('mountAction', 'add', [], $childContext)->assertSet('openedStateHash', $hash)->assertSet('inputContext', $context);
        $rows = $page->get('mountedActions.0.data.'.$field);
        $this->assertCount(3, $rows);
        $added = array_values(array_diff(array_keys($rows), $initial))[0];
        $page->call('mountAction', 'delete', ['item' => $added], $childContext)->assertSet('openedStateHash', $hash)->assertSet('inputContext', $context);
        $page->call('mountAction', 'reorder', ['items' => array_reverse($initial)], $childContext)
            ->assertSet('openedStateHash', $hash)->assertSet('inputContext', $context);
        $this->assertSame(array_reverse($initial), array_keys($page->get('mountedActions.0.data.'.$field)));
        $page->callMountedAction()->assertHasNoActionErrors()->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount($kind.'_draft_versions', 2);
    }

    #[DataProvider('families')]
    public function test_committed_save_with_lost_response_keeps_copyable_text_requires_reopen_and_never_replays_effects(string $kind): void
    {
        [$actor, $real, $draft] = $this->pending($kind);
        [$class, $draftClass, $versionClass] = Fixtures::classes($kind);
        $page = Livewire::test($this->page($kind))->mountTableAction('editDraft', $draft)
            ->setTableActionData($this->uiPayload($kind, ['description' => 'Retained copy after lost response']))->callMountedAction();
        $this->assertConfirmationFooter($page->html(), false);
        $proxy = new class($real, $kind, $draftClass, $versionClass) extends ReviewedPrivateDrafts
        {
            public function __construct(private ReviewedPrivateDrafts $real, private string $kind, private string $draftType, private string $versionType) {}

            protected function draftClass(): string
            {
                return $this->draftType;
            }

            protected function versionClass(): string
            {
                return $this->versionType;
            }

            protected function format(): PrivateDraftManifest
            {
                return $this->kind === 'service' ? new ServiceDraftManifest : new MerchDraftManifest;
            }

            public function applyReviewed(array $review, User $actor): PrivateDraft
            {
                $this->real->applyReviewed($review, $actor);
                throw new RuntimeException('PRIVATE synthetic exception body must not reach the editor.');
            }

            public function snapshot(int $id, User $actor): array
            {
                return $this->real->snapshot($id, $actor);
            }
        };
        app()->instance($class, $proxy);
        $page->callMountedAction()->assertHasActionErrors(['entered_summary'])->assertSet('draftReview', null)
            ->assertNotified('The save result could not be confirmed.');
        $this->assertStringContainsString('Retained copy after lost response', $page->get('enteredSummary'));
        $this->assertStringNotContainsString('PRIVATE synthetic exception', $page->html());
        $this->assertTrue($page->instance()->getMountedAction()->isDisabled());
        $this->assertConfirmationFooter($page->html(), true);
        $this->assertSame($page->get('enteredSummary'), $page->get('mountedActions.0.data.entered_summary'));
        $before = $this->evidence($kind);
        $page->callMountedAction()->assertHasActionErrors(['entered_summary']);
        $this->assertConfirmationFooter($page->html(), true);
        $this->assertSame($before, $this->evidence($kind));
        $this->assertSame(2, $real->snapshot($draft->id, $actor)['version']);
        $page->unmountAction()->mountTableAction('editDraft', $draft->fresh())
            ->assertTableActionDataSet(['description' => 'Retained copy after lost response']);
    }
}
