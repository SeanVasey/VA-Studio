<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\ReviewedOfferDraft;
use App\Domain\Catalog\SaveOfferDraft;
use App\Filament\Resources\LicenseTemplateResource;
use App\Filament\Resources\OfferResource\Pages\ManageOffers;
use App\Support\Audit\AuditEvent;
use Filament\Actions\Testing\TestAction;
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
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class OfferDraftAuthoringActionTest extends TestCase
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
        // Real existing synthetic publication fixtures; no browser/scanner claim.
        $selection = QuoteFixtures::selection();
        $actor = $selection['actor'];
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $offer = $selection['offer']->fresh();
        $data = $offer->only(ReviewedOfferDraft::FIELDS);
        $this->actingAs($actor);

        return array_replace($selection, compact('offer', 'data'));
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['license_templates', 'license_versions', 'license_review_evidence', 'tracks', 'offers', 'offer_revisions', 'audit_events']);
    }

    private function frozen(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['license_templates', 'license_versions', 'license_review_evidence', 'offer_revisions']);
    }

    private function assertConsumed(mixed $page): void
    {
        $page->assertSet('offerReview', null)->assertSet('offerReviewContext', null);
    }

    private function assertRecovery(mixed $page, int $price): void
    {
        $this->assertConsumed($page);
        $page->assertTableActionDataSet(['price_minor' => $price])
            ->assertDispatched('form-validation-error', livewireId: $page->instance()->getId());
        $document = new \DOMDocument;
        @$document->loadHTML($page->effects['partials']['action-modals.0']);
        $xpath = new \DOMXPath($document);
        $modal = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-modal-window ")][@*[name() = "x-on:form-validation-error.window"]]');
        $this->assertCount(1, $modal);
        $this->assertSame(LicenseTemplateResource::authoringModalAttributes()['x-on:form-validation-error.window'],
            $modal->item(0)->getAttribute('x-on:form-validation-error.window'));
        $this->assertGreaterThanOrEqual(1, $xpath->query('.//*[@data-validation-error]', $modal->item(0))->length);
    }

    public function test_real_edit_mount_captures_current_draft_and_changes_only_draft_with_attributed_audit(): void
    {
        ['actor' => $actor, 'offer' => $offer, 'data' => $data] = $this->pending();
        $frozen = $this->frozen();
        $auditCount = AuditEvent::where('action', 'catalog.offer.draft_saved')->count();
        $page = Livewire::test(ManageOffers::class)->mountAction(TestAction::make('edit')->table($offer))
            ->assertActionMounted(TestAction::make('edit')->table($offer))
            ->assertTableActionDataSet($data)->assertSet('offerReview.actor_id', $actor->id)
            ->assertSet('offerReview.offer_id', $offer->id)->assertSet('offerReview.track_id', $offer->track_id);
        $review = $page->get('offerReview');
        $this->assertSame('Edit offer draft', $page->instance()->getMountedAction()->getModalHeading());
        $this->assertSame(['schema_version', 'intent', 'actor_id', 'track_id', 'offer_id', 'track_hash',
            'offer_hash', 'track_audit_id', 'offer_audit_id', 'display', 'track'], array_keys($review));
        $page->setTableActionData(array_replace($data, ['price_minor' => 6999]))->assertSet('offerReview', $review)
            ->callMountedAction()->assertHasNoTableActionErrors()->assertNotified('Saved');
        $this->assertConsumed($page);
        $saved = $offer->fresh();
        $this->assertSame(6999, $saved->price_minor);
        $this->assertSame($offer->current_revision_id, $saved->current_revision_id);
        $this->assertSame($offer->is_active, $saved->is_active);
        $this->assertSame($data['deliverable_asset_ids'], $saved->deliverable_asset_ids);
        $this->assertSame($frozen, $this->frozen());
        $this->assertSame($auditCount + 1, AuditEvent::where('action', 'catalog.offer.draft_saved')->count());
        $audit = AuditEvent::where('action', 'catalog.offer.draft_saved')->latest('id')->firstOrFail();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame((string) $offer->id, (string) $audit->subject_id);
    }

    public function test_invalid_form_preserves_inputs_and_consumes_review_before_validation(): void
    {
        ['offer' => $offer, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer)
            ->setTableActionData(array_replace($data, ['price_minor' => 0]))
            ->callMountedTableAction()->assertHasTableActionErrors(['price_minor']);
        $this->assertRecovery($page, 0);
        $page->setTableActionData(array_replace($data, ['price_minor' => 5999]))
            ->callMountedAction()->assertHasTableActionErrors(['price_minor'])->assertNotified('Reopen offer draft to continue');
        $this->assertRecovery($page, 5999);
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $offer)->assertTableActionDataSet($data)
            ->setTableActionData(array_replace($data, ['price_minor' => 5999]))
            ->callMountedAction()->assertHasNoTableActionErrors();
        $this->assertSame(5999, $offer->fresh()->price_minor);
    }

    public function test_competing_edit_preserves_winner_and_losing_inputs_until_explicit_reopen(): void
    {
        ['offer' => $offer, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer);
        $review = $page->get('offerReview');
        Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer)
            ->setTableActionData(array_replace($data, ['price_minor' => 6999]))
            ->callMountedAction()->assertHasNoTableActionErrors();
        $before = $this->evidence();
        $page->assertSet('offerReview', $review)->setTableActionData(array_replace($data, ['price_minor' => 5999]))
            ->callMountedAction()->assertHasTableActionErrors(['price_minor']);
        $this->assertRecovery($page, 5999);
        $page->callMountedAction()->assertHasTableActionErrors(['price_minor']);
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $offer)->assertTableActionDataSet(['price_minor' => 6999])
            ->setTableActionData(array_replace($data, ['price_minor' => 5999]))
            ->callMountedAction()->assertHasNoTableActionErrors();
        $this->assertSame(5999, $offer->fresh()->price_minor);
    }

    public function test_same_second_legacy_change_and_restore_invalidates_old_form(): void
    {
        ['actor' => $actor, 'offer' => $offer, 'data' => $data] = $this->pending();
        $this->freezeSecond();
        $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer);
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 6999], $actor);
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => $data['price_minor']], $actor);
        $before = $this->evidence();
        $page->setTableActionData(array_replace($data, ['price_minor' => 5999]))
            ->callMountedAction()->assertHasTableActionErrors(['price_minor']);
        $this->assertRecovery($page, 5999);
        $this->assertSame($before, $this->evidence());
    }

    public function test_deactivation_after_mount_preserves_entered_price_and_cannot_reactivate(): void
    {
        ['actor' => $actor, 'offer' => $offer, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer);
        app(DeactivateOffer::class)->handle($offer, $actor);
        $before = $this->evidence();
        $page->setTableActionData(array_replace($data, ['price_minor' => 5999]))
            ->callMountedAction()->assertHasTableActionErrors(['price_minor']);
        $this->assertRecovery($page, 5999);
        $this->assertSame($before, $this->evidence());
        $this->assertFalse($offer->fresh()->is_active);
    }

    public static function revocations(): array
    {
        return ['role' => ['is_admin', false], 'email' => ['email_verified_at', null], 'MFA' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_current_operator_authority_is_required_after_mount(string $field, mixed $value): void
    {
        ['actor' => $actor, 'offer' => $offer, 'data' => $data] = $this->pending();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer)->setTableActionData($data);
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $page->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_another_authorized_actor_cannot_submit_original_review(): void
    {
        ['offer' => $offer] = $this->pending();
        $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer);
        $other = LicenseFixtures::admin();
        $other->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($other);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasTableActionErrors(['price_minor']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_locked_review_and_direct_callbacks_cannot_forge_or_save(): void
    {
        ['offer' => $offer, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        foreach (['offerReview.actor_id' => 999, 'offerReview.offer_id' => 999, 'offerReview.track_hash' => str_repeat('a', 64),
            'offerReview.display.price_minor' => 1, 'offerReviewContext.table.page' => '2'] as $property => $value) {
            try {
                Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer)->set($property, $value);
                $this->fail('Client changed locked offer review evidence.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
        $component = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer)->instance();
        $action = $component->getMountedAction();
        foreach ([fn () => $component->captureOfferReview($offer), fn () => $component->updateOfferDraft($offer, $data, $action)] as $operation) {
            try {
                $operation();
                $this->fail('A direct callback bypassed real mounting/submission.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_record_action_table_context_and_unknown_data_updates_consume_review(): void
    {
        ['actor' => $actor, 'offer' => $offer, 'data' => $data] = $this->pending();
        $other = app(SaveOfferDraft::class)->handle(null, ['track_id' => $offer->track_id] + $data, $actor);
        $mount = fn () => Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer);
        $before = $this->evidence();
        $this->assertConsumed($mount()->unmountAction());
        foreach (['mountedActions.0.context.recordKey' => (string) $other->id, 'mountedActions.0.arguments.unreviewed' => true,
            'mountedActions.0.data.current_revision_id' => 999, 'mountedActions.0.data.track_id' => 999,
            'tableSearch' => 'changed', 'tableSort' => 'price_minor:asc'] as $property => $value) {
            $page = $mount()->set($property, $value);
            $this->assertConsumed($page);
            if (str_starts_with($property, 'mountedActions.')) {
                $page->callMountedAction()->assertHasActionErrors(['price_minor']);
            }
            $this->assertSame($before, $this->evidence());
        }
        $page = $mount();
        $this->assertConsumed($page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName()));
        $page = $mount()->callMountedAction(['unexpected' => true])->assertHasTableActionErrors(['price_minor']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_exception_after_real_commit_keeps_inputs_and_reopening_reads_one_durable_save(): void
    {
        ['offer' => $offer, 'data' => $data] = $this->pending();
        $real = app(ReviewedOfferDraft::class);
        $this->app->instance(ReviewedOfferDraft::class, new class($real)
        {
            public function __construct(private ReviewedOfferDraft $real) {}

            public function review($offer, $actor): array
            {
                return $this->real->review($offer, $actor);
            }

            public function updateReviewed(array $review, array $data, $actor): never
            {
                $this->real->updateReviewed($review, $data, $actor);
                throw new RuntimeException('Synthetic private exception after durable commit');
            }
        });
        $auditCount = AuditEvent::where('action', 'catalog.offer.draft_saved')->count();
        $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer)
            ->setTableActionData(array_replace($data, ['price_minor' => 6999]))
            ->callMountedAction()->assertHasTableActionErrors(['price_minor'])->assertNotified('The offer save could not be confirmed');
        $this->assertRecovery($page, 6999);
        $this->assertSame(6999, $offer->fresh()->price_minor);
        $this->assertSame($auditCount + 1, AuditEvent::where('action', 'catalog.offer.draft_saved')->count());
        $before = $this->evidence();
        $page->callMountedAction()->assertHasTableActionErrors(['price_minor'])->assertNotified('Reopen offer draft to continue');
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $offer)->assertTableActionDataSet(['price_minor' => 6999]);
        $this->assertSame($before, $this->evidence());
    }

    public function test_uncertain_rollback_keeps_inputs_without_disclosing_exception_or_claiming_success(): void
    {
        ['offer' => $offer, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageOffers::class)->mountTableAction('edit', $offer)
            ->setTableActionData(array_replace($data, ['price_minor' => 6999]));
        $before = $this->evidence();
        Log::spy();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic private offer failure detail'));
        $page->callMountedAction()->assertHasTableActionErrors(['price_minor']);
        $page->assertNotified('The offer save could not be confirmed');
        $this->assertRecovery($page, 6999);
        $this->assertSame($before, $this->evidence());
        Log::shouldHaveReceived('warning')->once()->with('Offer draft UI could not confirm the result.', ['exception_class' => RuntimeException::class]);
        $notifications = json_encode(session('filament.claimed_notifications') ?? session('filament.notifications'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Synthetic private offer failure detail', $notifications);
        $this->assertStringNotContainsString('"title":"Saved"', $notifications);
        $page->callMountedAction()->assertHasTableActionErrors(['price_minor'])->assertNotified('Reopen offer draft to continue');
        $this->assertSame($before, $this->evidence());
    }
}
