<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\ReviewedOfferDraft;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\ReviewedOfferDraftFixtures;
use Tests\TestCase;

class ReviewedOfferDraftTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function evidence(): array
    {
        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'offers', 'offer_revisions', 'license_versions', 'license_review_evidence', 'users', 'audit_events']);
    }

    private function data(array $review, array $changes = []): array
    {
        return array_replace(Arr::only($review['display'], ReviewedOfferDraft::FIELDS), $changes);
    }

    private function refused(callable $operation, string $key = 'offer'): void
    {
        $before = $this->evidence();
        try {
            $operation();
            $this->fail('An invalid or stale reviewed offer edit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_two_full_forms_preserve_the_winning_price_and_require_explicit_reopen(): void
    {
        ['actor' => $first, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $second = LicenseFixtures::admin();
        $command = app(ReviewedOfferDraft::class);
        $older = $command->review($offer, $first);
        $newer = $command->review($offer, $second);
        $command->updateReviewed($newer, $this->data($newer, ['price_minor' => 6999]), $second);
        $this->refused(fn () => $command->updateReviewed($older, $this->data($older, ['currency' => 'EUR']), $first));
        $this->assertSame(6999, $offer->fresh()->price_minor);
        $this->assertSame('USD', $offer->fresh()->currency);
        $reopened = $command->review($offer, $first);
        $this->assertSame(6999, $reopened['display']['price_minor']);
        $saved = $command->updateReviewed($reopened, $this->data($reopened, ['currency' => 'EUR']), $first);
        $this->assertSame(6999, $saved->price_minor);
        $this->assertSame('EUR', $saved->currency);
    }

    public function test_review_and_semantic_no_op_are_write_free_and_changed_audit_contains_only_identity(): void
    {
        ['actor' => $actor, 'track' => $track, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $before = $this->evidence();
        $command = app(ReviewedOfferDraft::class);
        $review = $command->review($offer, $actor);
        $this->assertSame(['schema_version', 'intent', 'actor_id', 'track_id', 'offer_id', 'track_hash', 'offer_hash', 'track_audit_id', 'offer_audit_id', 'display', 'track'], array_keys($review));
        $this->assertSame(['id' => $track->id, 'title' => $track->title], $review['track']);
        $this->assertSame($track->id, $review['display']['track_id']);
        $saved = $command->updateReviewed($review, $this->data($review, ['price_minor' => '4999', 'license_version_id' => (string) $offer->license_version_id]), $actor);
        $this->assertSame($offer->getAttributes(), $saved->getAttributes());
        $this->assertSame($before, $this->evidence());
        $saved = $command->updateReviewed($review, $this->data($review, ['price_minor' => '6999']), $actor);
        $audit = AuditEvent::latest('id')->firstOrFail();
        $this->assertSame('catalog.offer.draft_saved', $audit->action);
        $this->assertSame($actor->id, $audit->actor_id);
        $keys = array_keys($audit->context);
        sort($keys);
        $this->assertSame(['after_hash', 'before_hash', 'canonicalization_version', 'changed_fields', 'current_revision_id', 'schema_version'], $keys);
        $this->assertSame(['price_minor'], $audit->context['changed_fields']);
        $this->assertSame(CanonicalJson::hash($review['display']), $audit->context['before_hash']);
        $this->assertSame(CanonicalJson::hash($command->review($saved, $actor)['display']), $audit->context['after_hash']);
        $this->assertSame(null, $saved->current_revision_id);
        $this->assertFalse($saved->is_active);
    }

    public static function bounds(): array
    {
        return ['zero remains a valid draft' => [0], 'maximum minor units' => [2147483647]];
    }

    #[DataProvider('bounds')]
    public function test_original_draft_bounds_currency_and_exists_only_policy_are_preserved(int $price): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        ['track' => $other] = ReviewedOfferDraftFixtures::draft();
        $assets = [MediaFixtures::source($other), MediaFixtures::source($other), MediaFixtures::source($other)];
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $saved = app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => $price,
            'currency' => 'CHF', 'deliverable_asset_ids' => array_map(fn ($asset) => (string) $asset->id, $assets)]), $actor);
        $this->assertSame($price, $saved->price_minor);
        $this->assertSame('CHF', $saved->currency);
        $this->assertSame(array_map(fn ($asset) => $asset->id, $assets), $saved->deliverable_asset_ids);
        $this->assertSame('draft', $saved->licenseVersion->status);
        $this->assertSame('quarantined', $assets[0]->status);
        $this->assertFalse($saved->is_active);
    }

    public static function invalid(): array
    {
        return ['float' => ['price_minor', 49.99, 'price_minor'], 'decimal string' => ['price_minor', '49.99', 'price_minor'],
            'negative' => ['price_minor', -1, 'price_minor'], 'overflow' => ['price_minor', 2147483648, 'price_minor'],
            'lower currency' => ['currency', 'usd', 'currency'], 'long currency' => ['currency', 'USDD', 'currency'],
            'missing license' => ['license_version_id', null, 'license_version_id'], 'unknown license' => ['license_version_id', 999999, 'license_version_id'],
            'missing price' => ['price_minor', null, 'price_minor'], 'missing currency' => ['currency', null, 'currency'],
            'missing assets' => ['deliverable_asset_ids', null, 'deliverable_asset_ids'], 'unknown asset' => ['deliverable_asset_ids', [999999], 'deliverable_asset_ids.0'],
            'duplicate assets' => ['deliverable_asset_ids', ['existing', 'existing'], 'deliverable_asset_ids.0'],
            'too many assets' => ['deliverable_asset_ids', [1, 2, 3, 4], 'deliverable_asset_ids']];
    }

    #[DataProvider('invalid')]
    public function test_invalid_full_form_fields_fail_without_any_domain_effect(string $field, mixed $value, string $error): void
    {
        ['actor' => $actor, 'track' => $track, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $data = $this->data($review, [$field => $value]);
        if ($value === null) {
            unset($data[$field]);
        } elseif ($value === ['existing', 'existing']) {
            $asset = MediaFixtures::source($track);
            $data[$field] = [$asset->id, (string) $asset->id];
        }
        $this->refused(fn () => app(ReviewedOfferDraft::class)->updateReviewed($review, $data, $actor), $error);
    }

    public function test_fixed_track_and_publication_or_identity_fields_cannot_be_injected(): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        foreach (['track_id' => $offer->track_id, 'current_revision_id' => 1, 'is_active' => true, 'id' => $offer->id] as $field => $value) {
            $this->refused(fn () => app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review) + [$field => $value], $actor));
        }
        $hint = clone $offer;
        $hint->track_id = 999999;
        $this->refused(fn () => app(ReviewedOfferDraft::class)->review($hint, $actor));
        $this->refused(fn () => app(ReviewedOfferDraft::class)->review(new Offer, $actor));
    }

    public static function shapes(): array
    {
        return ['unknown key' => ['unexpected', true], 'schema' => ['schema_version', 2], 'intent' => ['intent', 'publish_offer'],
            'string offer id' => ['offer_id', '1'], 'negative cursor' => ['offer_audit_id', -1], 'float cursor' => ['track_audit_id', 1.0],
            'malformed hash' => ['offer_hash', str_repeat('g', 64)], 'changed display' => ['display.price_minor', 1],
            'changed track context' => ['track.title', 'Forged title'], 'missing track' => ['track', null]];
    }

    #[DataProvider('shapes')]
    public function test_invalid_or_changed_review_shape_cannot_authorize_a_save(string $field, mixed $value): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $data = $this->data($review, ['price_minor' => 6999]);
        if ($value === null) {
            unset($review[$field]);
        } else {
            Arr::set($review, $field, $value);
        }
        $this->refused(fn () => app(ReviewedOfferDraft::class)->updateReviewed($review, $data, $actor));
    }

    public static function drift(): array
    {
        return ['legacy changed' => ['legacy'], 'legacy no-op still audited' => ['noop'], 'same-second ABA' => ['aba'], 'track metadata' => ['track']];
    }

    #[DataProvider('drift')]
    public function test_participating_writes_invalidate_the_original_review_even_after_same_second_restore(string $kind): void
    {
        $this->freezeTime();
        ['actor' => $actor, 'track' => $track, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $command = app(ReviewedOfferDraft::class);
        $review = $command->review($offer, $actor);
        if ($kind === 'track') {
            app(SaveTrackMetadata::class)->handle($track, ['title' => 'Changed synthetic recording', 'metadata_version' => $track->metadata_version], $actor);
        } else {
            app(SaveOfferDraft::class)->handle($offer, $kind === 'noop' ? [] : ['price_minor' => 5999], $actor);
            if ($kind === 'aba') {
                app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 4999], $actor);
            }
            if (in_array($kind, ['noop', 'aba'], true)) {
                $this->assertSame($review['offer_hash'], $command->review($offer, $actor)['offer_hash']);
            }
        }
        $this->refused(fn () => $command->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor));
    }

    public function test_publication_and_deactivation_drift_require_reopening_without_rewriting_revisions(): void
    {
        ['actor' => $actor, 'offer' => $offer, 'revision' => $original] = QuoteFixtures::selection();
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 5999], $actor);
        $command = app(ReviewedOfferDraft::class);
        $beforePublish = $command->review($offer, $actor);
        $successor = app(PublishOffer::class)->handle($offer, $actor);
        $this->refused(fn () => $command->updateReviewed($beforePublish, $this->data($beforePublish, ['price_minor' => 6999]), $actor));
        $beforeDeactivate = $command->review($offer, $actor);
        app(DeactivateOffer::class)->handle($offer, $actor);
        $this->refused(fn () => $command->updateReviewed($beforeDeactivate, $this->data($beforeDeactivate, ['price_minor' => 6999]), $actor));
        $this->assertSame(4999, $original->fresh()->price_minor);
        $this->assertSame(5999, $successor->fresh()->price_minor);
    }

    public static function actors(): array
    {
        return ['role' => ['role'], 'verified email' => ['email'], 'required MFA' => ['mfa'], 'deleted' => ['deleted'], 'unsaved' => ['unsaved'], 'locally elevated customer' => ['customer']];
    }

    #[DataProvider('actors')]
    public function test_both_entry_points_require_current_persisted_actor_authority(string $state): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $command = app(ReviewedOfferDraft::class);
        $review = $command->review($offer, $actor);
        if (in_array($state, ['role', 'email', 'mfa'], true)) {
            if ($state === 'mfa') {
                $panel = Filament::getPanel('admin');
                $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
            }
            DB::table('users')->where('id', $actor->id)->update(match ($state) {
                'role' => ['is_admin' => false], 'email' => ['email_verified_at' => null], default => ['app_authentication_secret' => null]
            });
        } elseif ($state === 'deleted') {
            $actor = LicenseFixtures::admin();
            $actor->delete();
        } elseif ($state === 'unsaved') {
            $actor = (new User)->forceFill(['id' => $actor->id, 'is_admin' => true, 'email_verified_at' => now()]);
        } else {
            $actor = User::factory()->create();
            $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        }
        foreach ([fn () => $command->review($offer, $actor), fn () => $command->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor)] as $operation) {
            $before = $this->evidence();
            try {
                $operation();
                $this->fail('Unavailable persisted authority edited an offer draft.');
            } catch (AuthorizationException) {
            }
            $this->assertSame($before, $this->evidence());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_a_review_cannot_be_used_by_a_different_valid_actor(): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $other = LicenseFixtures::admin();
        $before = $this->evidence();
        try {
            app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $other);
            $this->fail('A different actor submitted the captured offer review.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function authorityObservers(): array
    {
        $cases = [];
        foreach (['saved', 'audit'] as $stage) {
            foreach (['role', 'email', 'mfa'] as $state) {
                $cases[$stage.' / '.$state] = [$stage, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('authorityObservers')]
    public function test_authority_withdrawal_during_save_or_audit_rolls_back_all_effects(string $stage, string $state): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        if ($state === 'mfa') {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        }
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $withdraw = fn () => DB::table('users')->where('id', $actor->id)->update(match ($state) {
            'role' => ['is_admin' => false], 'email' => ['email_verified_at' => null], default => ['app_authentication_secret' => null]
        });
        if ($stage === 'saved') {
            Offer::saved(fn () => $withdraw());
        } else {
            AuditEvent::created(fn () => $withdraw());
        }
        $before = $this->evidence();
        try {
            app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor);
            $this->fail('A save retained authority withdrawn by transactional observer work.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function finalDrift(): array
    {
        return ['persisted offer' => ['offer'], 'persisted track' => ['track'], 'track audit identity' => ['track-audit'],
            'newer offer audit' => ['offer-audit'], 'changed own audit context' => ['context'], 'changed own audit action' => ['action']];
    }

    #[DataProvider('finalDrift')]
    public function test_final_persisted_row_track_and_exact_own_audit_are_verified_before_commit(string $kind): void
    {
        ['actor' => $actor, 'track' => $track, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        if (in_array($kind, ['context', 'action'], true)) {
            AuditEvent::creating(function (AuditEvent $audit) use ($kind): void {
                if ($kind === 'action') {
                    $audit->action = 'catalog.offer.deactivated';
                } else {
                    $audit->context = ['changed' => 'Synthetic observer mutation'];
                }
            });
        } else {
            AuditEvent::created(function () use ($kind, $actor, $track, $offer): void {
                if ($kind === 'offer' || $kind === 'track') {
                    DB::table($kind === 'offer' ? 'offers' : 'tracks')->where('id', $kind === 'offer' ? $offer->id : $track->id)
                        ->update($kind === 'offer' ? ['price_minor' => 7999] : ['title' => 'Synthetic observer mutation']);
                } else {
                    DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'catalog.offer.synthetic_observer',
                        'subject_type' => $kind === 'track-audit' ? Track::class : Offer::class,
                        'subject_id' => $kind === 'track-audit' ? $track->id : $offer->id, 'context' => '{}', 'created_at' => now()]);
                }
            });
        }
        $this->refused(fn () => app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor));
    }

    public function test_exact_returned_audit_identity_allows_unrelated_auto_increment_gaps(): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        AuditEvent::record('synthetic.unrelated', $actor, [], $actor->id);
        $saved = app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor);
        $this->assertSame(6999, $saved->price_minor);
        $this->assertGreaterThan($review['offer_audit_id'] + 1, AuditEvent::latest('id')->firstOrFail()->id);
    }

    public function test_draft_edits_preserve_the_active_revision_and_real_purchased_original_graph(): void
    {
        $this->travelTo(now()->startOfSecond());
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        app()->instance(StripeCheckoutGateway::class, $gateway);
        app()->instance(StripePaymentGateway::class, $gateway);
        app()->instance(ContractRenderer::class, ContractFixtures::renderer());
        $fixture = DeliveryFixtures::ready($gateway);
        $actor = $fixture['actor'];
        $offer = $fixture['offer'];
        $before = DeliveryFixtures::retained();
        $revision = $offer->fresh()->currentRevision;
        $original = $revision->getAttributes();
        $active = $offer->fresh()->is_active;
        $track = $offer->fresh()->track->getAttributes();
        $this->assertNotEmpty(DB::table('license_grants')->get());
        $this->assertNotEmpty(DB::table('grant_contracts')->get());
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $newLicense = LicenseFixtures::draft($actor);
        $saved = app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => 7999,
            'currency' => 'EUR', 'license_version_id' => $newLicense->id, 'deliverable_asset_ids' => []]), $actor);
        $this->assertSame($review['offer_id'], $saved->id);
        $this->assertSame($revision->id, $saved->current_revision_id);
        $this->assertSame($active, $saved->is_active);
        $this->assertSame($original, $revision->fresh()->getAttributes());
        $this->assertSame($track, $saved->track->getAttributes());
        $this->assertSame($before, DeliveryFixtures::retained());
    }

    public function test_strict_entry_points_own_their_transaction_while_legacy_partial_edits_and_audit_context_remain_unchanged(): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $command = app(ReviewedOfferDraft::class);
        $review = $command->review($offer, $actor);
        $before = $this->evidence();
        DB::beginTransaction();
        try {
            foreach ([fn () => $command->review($offer, $actor), fn () => $command->updateReviewed($review, $this->data($review), $actor)] as $operation) {
                try {
                    $operation();
                    $this->fail('A caller-owned transaction entered the strict reviewed command.');
                } catch (LogicException $exception) {
                    $this->assertSame('Reviewed offer draft editing requires a standalone transaction.', $exception->getMessage());
                }
                $this->assertSame(1, DB::transactionLevel());
            }
            app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 5999], $actor);
            $saved = app(SaveOfferDraft::class)->handle($offer, ['currency' => 'EUR'], $actor);
            $this->assertSame(5999, $saved->price_minor);
            $this->assertSame('EUR', $saved->currency);
            $this->assertSame(['current_revision_id' => null], AuditEvent::latest('id')->firstOrFail()->context);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_failed_audit_rolls_back_the_strict_save(): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic offer audit failure'));
        try {
            app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor);
            $this->fail('A failed audit allowed a reviewed draft save.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic offer audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_asset_list_order_is_retained_while_numeric_string_ids_are_semantic_no_ops(): void
    {
        ['actor' => $actor, 'track' => $track, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $ids = [MediaFixtures::source($track)->id, MediaFixtures::source($track)->id];
        app(SaveOfferDraft::class)->handle($offer, ['deliverable_asset_ids' => $ids], $actor);
        $command = app(ReviewedOfferDraft::class);
        $review = $command->review($offer, $actor);
        $before = $this->evidence();
        $command->updateReviewed($review, $this->data($review, ['deliverable_asset_ids' => array_map('strval', $ids)]), $actor);
        $this->assertSame($before, $this->evidence());
        $saved = $command->updateReviewed($review, $this->data($review, ['deliverable_asset_ids' => array_reverse($ids)]), $actor);
        $this->assertSame(array_reverse($ids), $saved->deliverable_asset_ids);
        $this->assertSame(['deliverable_asset_ids'], AuditEvent::latest('id')->firstOrFail()->context['changed_fields']);
    }

    public static function noOpDrift(): array
    {
        return ['row' => ['offer'], 'track' => ['track'], 'authority' => ['role']];
    }

    #[DataProvider('noOpDrift')]
    public function test_no_op_rechecks_current_rows_and_authority_after_validation_reads(string $kind): void
    {
        ['actor' => $actor, 'track' => $track, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        $before = $this->evidence();
        $active = true;
        $interleaved = false;
        DB::listen(function ($query) use ($kind, $actor, $track, $offer, &$active, &$interleaved): void {
            if (! $active || $interleaved || ! preg_match('/\bfrom\s+["`]?license_versions["`]?\b/i', $query->sql)) {
                return;
            }
            $interleaved = true;
            match ($kind) {
                'offer' => DB::table('offers')->where('id', $offer->id)->update(['price_minor' => 5999]),
                'track' => DB::table('tracks')->where('id', $track->id)->update(['title' => 'Synthetic intervening title']),
                default => DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]),
            };
        });
        try {
            app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review), $actor);
            $this->fail('A no-op ignored transactional interference.');
        } catch (AuthorizationException|ValidationException) {
        } finally {
            $active = false;
        }
        $this->assertTrue($interleaved);
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function savingSubstitutions(): array
    {
        return ['draft field' => ['price_minor', 1], 'activity' => ['is_active', false]];
    }

    #[DataProvider('savingSubstitutions')]
    public function test_saving_observers_cannot_substitute_input_or_change_public_activity(string $field, mixed $value): void
    {
        ['actor' => $actor, 'offer' => $offer] = QuoteFixtures::selection();
        $review = app(ReviewedOfferDraft::class)->review($offer, $actor);
        Offer::saving(fn (Offer $saving) => $saving->setAttribute($field, $value));
        $this->refused(fn () => app(ReviewedOfferDraft::class)->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor));
    }

    public function test_resource_fences_precede_the_first_snapshot_and_validation_reads(): void
    {
        ['actor' => $actor, 'offer' => $offer] = ReviewedOfferDraftFixtures::draft();
        $active = true;
        $trace = [];
        DB::listen(function ($query) use (&$active, &$trace): void {
            if (! $active || ! preg_match('/\Aselect\b/i', $query->sql)) {
                return;
            }
            foreach (['users', 'tracks', 'offers', 'audit_events', 'license_versions', 'media_assets'] as $table) {
                if (preg_match('/\bfrom\s+["`]?'.$table.'["`]?(?:\s|$)/i', $query->sql)) {
                    $trace[] = ['table' => $table, 'sql' => $query->sql, 'level' => DB::transactionLevel()];
                }
            }
        });
        try {
            $command = app(ReviewedOfferDraft::class);
            $review = $command->review($offer, $actor);
            $command->updateReviewed($review, $this->data($review, ['price_minor' => 6999]), $actor);
        } finally {
            $active = false;
        }
        $tables = array_column($trace, 'table');
        $this->assertSame('users', $tables[0]);
        $trackIndex = array_search('tracks', $tables, true);
        $this->assertGreaterThan(0, $trackIndex);
        $this->assertSame('offers', $tables[$trackIndex + 1]);
        $this->assertSame('audit_events', $tables[$trackIndex + 2]);
        foreach ($trace as $read) {
            $this->assertGreaterThanOrEqual(1, $read['level']);
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (array_slice($trace, 0, $trackIndex + 2) as $read) {
                $this->assertStringContainsString('for update', $read['sql']);
            }
        }
        $this->assertSame(0, DB::transactionLevel());
    }
}
