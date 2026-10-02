<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class RightsDeclarationWriterTest extends TestCase
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
        $track = Track::create(['title' => 'Synthetic current rights', 'slug' => 'synthetic-current-rights'])->refresh();
        $declaration = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-PRIVATE-REFERENCE',
            'sample_disclosure' => 'Synthetic private sample disclosure', 'status' => 'pending']);

        return compact('actor', 'track', 'declaration');
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'rights_declarations', 'audit_events']);
    }

    private function data(Track $track, array $changes = []): array
    {
        return array_replace(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-PRIVATE-REFERENCE',
            'sample_disclosure' => 'Synthetic private sample disclosure'], $changes);
    }

    private function invalid(callable $operation, string $field = 'rights'): void
    {
        try {
            $operation();
            $this->fail('An invalid rights writer request was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An unauthorized rights writer request was accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    private function withRequiredMfa(callable $operation): void
    {
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $operation();
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    private function assertAudit(string $verb, RightsDeclaration $record, User $actor, ?string $beforeHash, array $changed): void
    {
        $audit = AuditEvent::where('action', 'rights.declaration.'.$verb)->latest('id')->firstOrFail();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(RightsDeclaration::class, $audit->subject_type);
        $this->assertSame($record->id, $audit->subject_id);
        $this->assertSame(CanonicalJson::encode(['schema_version' => 1, 'track_id' => $record->track_id,
            'changed_fields' => $changed, 'before_hash' => $beforeHash, 'after_hash' => app(OfferSnapshot::class)->rightsHash($record)]),
            CanonicalJson::encode($audit->context));
        $context = json_encode($audit->context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($record->provenance_reference, $context);
        $this->assertStringNotContainsString($record->sample_disclosure, $context);
    }

    public function test_direct_verification_requires_current_required_mfa_enrollment(): void
    {
        ['actor' => $actor, 'declaration' => $declaration] = $this->pending();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->assertNull($actor->getAppAuthenticationSecret());
            $before = $this->evidence();
            try {
                app(VerifyRightsDeclaration::class)->handle($declaration, $actor);
                $this->fail('A direct verifier without currently required MFA enrollment verified private rights.');
            } catch (AuthorizationException) {
            }
            $this->assertSame($before, $this->evidence());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public static function formerGateWithdrawals(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null]];
    }

    #[DataProvider('formerGateWithdrawals')]
    public function test_direct_verifier_cannot_write_through_the_former_nontransactional_authority_gap(string $field, mixed $value): void
    {
        ['actor' => $actor, 'declaration' => $declaration] = $this->pending();
        $before = $this->evidence();
        $withdrawn = false;
        $inspect = true;
        $authorityReads = [];
        DB::listen(function ($query) use ($actor, $field, $value, &$withdrawn, &$inspect, &$authorityReads): void {
            if (! $inspect || ! preg_match('/^select .*\busers\b/i', str_replace(['"', '`'], '', $query->sql))) {
                return;
            }
            $authorityReads[] = DB::transactionLevel();
            if (! $withdrawn && DB::transactionLevel() === 0) {
                // A controlled same-process reproduction of the old pretransaction read gap, not a MySQL race.
                $withdrawn = true;
                DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            }
        });
        $denied = false;
        try {
            app(VerifyRightsDeclaration::class)->handle($declaration, $actor);
        } catch (AuthorizationException) {
            $denied = true;
        } finally {
            $inspect = false;
        }
        if ($withdrawn) {
            $this->assertTrue($denied, 'The former pretransaction Gate read authorized a write after persisted staff authority was withdrawn.');
            $this->assertSame($before, $this->evidence());
        } else {
            // Removing the nontransactional read removes that interception point. Real competing processes are tested on MySQL.
            $this->assertNotEmpty($authorityReads);
            $this->assertSame([1], array_values(array_unique($authorityReads)), 'All direct-verifier authority reads must occur inside its own transaction.');
            $this->assertFalse($denied);
            $this->assertSame('verified', $declaration->fresh()->status);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'rights.declaration.verified')->count());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_supported_create_edit_retarget_and_verify_keep_server_fields_and_minimized_atomic_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic first source', 'slug' => 'synthetic-first-source']);
        $target = Track::create(['title' => 'Synthetic target source', 'slug' => 'synthetic-target-source']);
        $tracksBefore = DB::table('tracks')->orderBy('id')->get()->toJson();
        $save = app(SaveRightsDeclaration::class);
        $created = $save->create($this->data($track), $actor);
        $this->assertSame('pending', $created->status);
        $this->assertNull($created->verified_by);
        $this->assertNull($created->verified_at);
        $this->assertAudit('created', $created, $actor, null, ['track_id', 'provenance_reference', 'sample_disclosure']);
        $before = $this->evidence();
        $review = $save->review($created, $actor);
        $this->assertSame($before, $this->evidence(), 'Review must not create an audit or write a row.');
        $this->assertSame(['schema_version', 'actor_id', 'intent', 'declaration_id', 'track_id', 'status', 'evidence_hash', 'display'], array_keys($review));
        $this->assertSame(1, $review['schema_version']);
        $this->assertSame($actor->id, $review['actor_id']);
        $this->assertSame('edit', $review['intent']);
        $this->assertSame($created->id, $review['declaration_id']);
        $this->assertSame($track->id, $review['track_id']);
        $this->assertSame('pending', $review['status']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $review['evidence_hash']);
        $this->assertSame(['track_title' => $track->title, 'provenance_reference' => $created->provenance_reference,
            'sample_disclosure' => $created->sample_disclosure], $review['display']);
        $oldHash = app(OfferSnapshot::class)->rightsHash($created);
        $changed = $save->updateReviewed($review, $this->data($track, ['provenance_reference' => 'SYNTHETIC-CORRECTED-REFERENCE']), $actor);
        $this->assertSame($created->id, $changed->id);
        $this->assertSame('pending', $changed->status);
        $this->assertNull($changed->verified_by);
        $this->assertAudit('updated', $changed, $actor, $oldHash, ['provenance_reference']);
        $noOpReview = $save->review($changed, $actor);
        $before = $this->evidence();
        $save->updateReviewed($noOpReview, $this->data($track, ['provenance_reference' => $changed->provenance_reference]), $actor);
        $this->assertSame($before, $this->evidence(), 'A current no-op must not save a timestamp or add an audit.');
        $oldHash = app(OfferSnapshot::class)->rightsHash($changed);
        $retargeted = $save->updateReviewed($save->review($changed, $actor),
            $this->data($target, ['provenance_reference' => $changed->provenance_reference, 'sample_disclosure' => 'Synthetic retargeted disclosure']), $actor);
        $this->assertSame($target->id, $retargeted->track_id);
        $this->assertAudit('updated', $retargeted, $actor, $oldHash, ['track_id', 'sample_disclosure']);
        $verify = app(VerifyRightsDeclaration::class);
        $verification = $verify->review($retargeted, $actor);
        $this->assertSame('verify', $verification['intent']);
        $oldHash = app(OfferSnapshot::class)->rightsHash($retargeted);
        $verified = $verify->verifyReviewed($verification, $actor);
        $this->assertSame('verified', $verified->status);
        $this->assertSame($actor->id, $verified->verified_by);
        $this->assertNotNull($verified->verified_at);
        $this->assertAudit('verified', $verified, $actor, $oldHash, ['status', 'verified_by', 'verified_at']);
        $this->assertSame($tracksBefore, DB::table('tracks')->orderBy('id')->get()->toJson());
        $this->assertSame(4, AuditEvent::where('action', 'like', 'rights.declaration.%')->count());
    }

    public static function extraWriteFields(): array
    {
        return ['id' => ['id', 777], 'verified status' => ['status', 'verified'], 'pending status' => ['status', 'pending'],
            'verifier' => ['verified_by', 1], 'verification time' => ['verified_at', '2026-10-02 12:00:00'],
            'creation time' => ['created_at', '2026-10-02 12:00:00'], 'update time' => ['updated_at', '2026-10-02 12:00:00'],
            'unknown field' => ['unreviewed', true]];
    }

    #[DataProvider('extraWriteFields')]
    public function test_create_and_reviewed_edit_refuse_every_noneditable_field_without_any_write(string $field, mixed $value): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $review = $save->review($declaration, $actor);
        $before = $this->evidence();
        $data = $this->data($track) + [$field => $value];
        $this->invalid(fn () => $save->create($data, $actor));
        $this->invalid(fn () => $save->updateReviewed($review, $data, $actor));
        $this->assertSame($before, $this->evidence());
    }

    public function test_required_fields_types_and_private_missing_targets_fail_without_partial_create_or_edit(): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $review = $save->review($declaration, $actor);
        $before = $this->evidence();
        foreach (['track_id' => [null, 0, -1, true, 1.5, 'unknown', '01', '+1', ' 1', '9223372036854775808'], 'provenance_reference' => [null, '', [], true],
            'sample_disclosure' => [null, '', [], true]] as $field => $values) {
            foreach ($values as $value) {
                $this->invalid(fn () => $save->create($this->data($track, [$field => $value]), $actor), $field);
                $this->invalid(fn () => $save->updateReviewed($review, $this->data($track, [$field => $value]), $actor), $field);
            }
            $missing = $this->data($track);
            unset($missing[$field]);
            $this->invalid(fn () => $save->create($missing, $actor));
            $this->invalid(fn () => $save->updateReviewed($review, $missing, $actor));
        }
        foreach ([fn () => $save->create($this->data($track, ['track_id' => 999999]), $actor),
            fn () => $save->updateReviewed($review, $this->data($track, ['track_id' => 999999]), $actor)] as $operation) {
            try {
                $operation();
                $this->fail('A nonexistent target track was accepted.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('track_id', $error->errors());
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_review_schema_actor_intent_record_track_and_exact_display_are_strict(): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $verify = app(VerifyRightsDeclaration::class);
        $edit = $save->review($declaration, $actor);
        $verification = $verify->review($declaration, $actor);
        $before = $this->evidence();
        $changes = ['schema_version' => 2, 'actor_id' => (string) $actor->id, 'intent' => 'create',
            'declaration_id' => 0, 'track_id' => (string) $track->id, 'status' => 'verified',
            'evidence_hash' => strtoupper($edit['evidence_hash']), 'display' => $edit['display'] + ['unreviewed' => true], 'extra' => true];
        foreach ($changes as $key => $value) {
            $this->invalid(fn () => $save->updateReviewed(array_replace($edit, [$key => $value]), $this->data($track), $actor));
            $this->invalid(fn () => $verify->verifyReviewed(array_replace($verification, [$key => $value]), $actor));
        }
        foreach (array_keys($edit) as $key) {
            $badEdit = $edit;
            $badVerification = $verification;
            unset($badEdit[$key], $badVerification[$key]);
            $this->invalid(fn () => $save->updateReviewed($badEdit, $this->data($track), $actor));
            $this->invalid(fn () => $verify->verifyReviewed($badVerification, $actor));
        }
        foreach (array_keys($edit['display']) as $key) {
            $badEdit = $edit;
            $badVerification = $verification;
            $badEdit['display'][$key] .= ' altered';
            $badVerification['display'][$key] .= ' altered';
            $this->invalid(fn () => $save->updateReviewed($badEdit, $this->data($track), $actor));
            $this->invalid(fn () => $verify->verifyReviewed($badVerification, $actor));
        }
        $this->invalid(fn () => $save->updateReviewed($verification, $this->data($track), $actor));
        $this->invalid(fn () => $verify->verifyReviewed($edit, $actor));
        $other = LicenseFixtures::admin();
        $this->denied(fn () => $save->updateReviewed($edit, $this->data($track), $other));
        $this->denied(fn () => $verify->verifyReviewed($verification, $other));
        $this->assertSame($before, $this->evidence());
    }

    public static function currentEvidenceChanges(): array
    {
        return ['provenance' => ['provenance_reference'], 'sample disclosure' => ['sample_disclosure'],
            'source association' => ['track_id'], 'displayed track title' => ['title']];
    }

    public function test_canonical_filament_track_ids_and_exact_utf8_text_fit_the_actual_byte_capacity_without_rewriting(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $maximum = str_repeat('é', 32767).'x';
        $this->assertSame(65535, strlen($maximum));
        $created = $save->create($this->data($track, ['track_id' => (string) $track->id,
            'provenance_reference' => $maximum, 'sample_disclosure' => "  Synthetic exact disclosure\n  "]), $actor);
        $this->assertSame($track->id, (int) $created->track_id);
        $this->assertSame($maximum, $created->provenance_reference);
        $this->assertSame("  Synthetic exact disclosure\n  ", $created->sample_disclosure);
        $before = $this->evidence();
        $review = $save->review($created, $actor);
        foreach (['provenance_reference', 'sample_disclosure'] as $field) {
            foreach ([str_repeat('é', 32768), "\xC3\x28"] as $invalid) {
                $this->invalid(fn () => $save->create($this->data($track, [$field => $invalid]), $actor), $field);
                $this->invalid(fn () => $save->updateReviewed($review, $this->data($track, [$field => $invalid]), $actor), $field);
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('currentEvidenceChanges')]
    public function test_edit_and_verification_refuse_a_review_when_exact_current_evidence_or_association_changed(string $field): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $verify = app(VerifyRightsDeclaration::class);
        $edit = $save->review($declaration, $actor);
        $verification = $verify->review($declaration, $actor);
        if ($field === 'title') {
            app(SaveTrackMetadata::class)->handle($track, ['title' => 'Synthetic changed track title', 'metadata_version' => $track->metadata_version], $actor);
        } else {
            $value = $field === 'track_id' ? Track::create(['title' => 'Synthetic retarget', 'slug' => 'synthetic-retarget'])->id : 'SYNTHETIC-CHANGED-CURRENT-EVIDENCE';
            $save->updateReviewed($save->review($declaration, $actor), $this->data($track, [$field => $value]), $actor);
        }
        $before = $this->evidence();
        $this->invalid(fn () => $save->updateReviewed($edit, $this->data($track), $actor));
        $this->invalid(fn () => $verify->verifyReviewed($verification, $actor));
        $this->assertSame($before, $this->evidence());
    }

    public function test_verified_rows_refuse_stale_edit_reverification_and_new_review_but_corrections_append_new_pending_evidence(): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $verify = app(VerifyRightsDeclaration::class);
        $edit = $save->review($declaration, $actor);
        $verification = $verify->review($declaration, $actor);
        $verified = $verify->verifyReviewed($verification, $actor);
        $original = $verified->getAttributes();
        $before = $this->evidence();
        foreach ([fn () => $save->review($declaration, $actor), fn () => $verify->review($declaration, $actor),
            fn () => $save->updateReviewed($edit, $this->data($track), $actor), fn () => $verify->verifyReviewed($verification, $actor),
            fn () => $verify->handle($declaration, $actor)] as $operation) {
            $this->invalid($operation);
        }
        $this->assertSame($before, $this->evidence());
        $successor = $save->create($this->data($track, ['provenance_reference' => 'SYNTHETIC-CORRECTION']), $actor);
        $this->assertGreaterThan($verified->id, $successor->id);
        $this->assertSame('pending', $successor->status);
        $this->assertSame($original, $verified->fresh()->getAttributes());
        $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.verified')->count());
    }

    public static function revocations(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
            'MFA enrollment' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_all_writer_entrypoints_recheck_current_authority_and_required_enrollment_before_private_reads(string $field, mixed $value): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $this->withRequiredMfa(function () use ($actor, $track, $declaration, $field, $value): void {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $save = app(SaveRightsDeclaration::class);
            $verify = app(VerifyRightsDeclaration::class);
            $edit = $save->review($declaration, $actor);
            $verification = $verify->review($declaration, $actor);
            $oldActor = $actor->fresh();
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $privateQueries = [];
            $inspect = true;
            DB::listen(function ($query) use (&$privateQueries, &$inspect): void {
                if ($inspect && preg_match('/\b(?:tracks|rights_declarations)\b/i', $query->sql)) {
                    $privateQueries[] = $query->sql;
                }
            });
            try {
                foreach ([fn () => $save->create($this->data($track), $oldActor), fn () => $save->review($declaration, $oldActor),
                    fn () => $save->updateReviewed($edit, $this->data($track), $oldActor), fn () => $verify->review($declaration, $oldActor),
                    fn () => $verify->verifyReviewed($verification, $oldActor), fn () => $verify->handle($declaration, $oldActor)] as $operation) {
                    $this->denied($operation);
                }
            } finally {
                $inspect = false;
            }
            $this->assertSame([], $privateQueries, 'Unauthorized commands must refuse before a private track or declaration query.');
            $this->assertSame($before, $this->evidence());
        });
    }

    public function test_unsaved_deleted_locally_elevated_and_forged_actor_models_cannot_write_or_receive_private_review(): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $verify = app(VerifyRightsDeclaration::class);
        $edit = $save->review($declaration, $actor);
        $verification = $verify->review($declaration, $actor);
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        $elevated = User::factory()->create();
        $elevated->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        $forged = new User;
        $forged->forceFill(['id' => $actor->id, 'is_admin' => true, 'email_verified_at' => now()]);
        $before = $this->evidence();
        foreach ([new User, $deleted, $elevated, $forged] as $invalidActor) {
            foreach ([fn () => $save->create($this->data($track), $invalidActor), fn () => $save->review($declaration, $invalidActor),
                fn () => $save->updateReviewed($edit, $this->data($track), $invalidActor), fn () => $verify->review($declaration, $invalidActor),
                fn () => $verify->verifyReviewed($verification, $invalidActor), fn () => $verify->handle($declaration, $invalidActor)] as $operation) {
                $this->denied($operation);
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_new_current_enrollment_authorizes_a_stale_unenrolled_actor_model_under_required_mfa(): void
    {
        ['actor' => $actor, 'declaration' => $declaration] = $this->pending();
        $this->withRequiredMfa(function () use ($actor, $declaration): void {
            $oldActor = $actor->fresh();
            $this->assertNull($oldActor->getAppAuthenticationSecret());
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $verified = app(VerifyRightsDeclaration::class)->handle($declaration, $oldActor);
            $this->assertSame('verified', $verified->status);
            $this->assertSame($actor->id, $verified->verified_by);
            $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.verified')->count());
        });
    }

    public function test_all_entrypoints_refuse_an_ambient_transaction_before_any_private_or_authority_query(): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $verify = app(VerifyRightsDeclaration::class);
        $edit = $save->review($declaration, $actor);
        $verification = $verify->review($declaration, $actor);
        $before = $this->evidence();
        $queries = [];
        $inspect = true;
        DB::listen(function ($query) use (&$queries, &$inspect): void {
            if ($inspect) {
                $queries[] = $query->sql;
            }
        });
        DB::beginTransaction();
        try {
            foreach ([fn () => $save->create($this->data($track), $actor), fn () => $save->review($declaration, $actor),
                fn () => $save->updateReviewed($edit, $this->data($track), $actor), fn () => $verify->review($declaration, $actor),
                fn () => $verify->verifyReviewed($verification, $actor), fn () => $verify->handle($declaration, $actor)] as $operation) {
                try {
                    $operation();
                    $this->fail('A rights writer inherited the caller transaction.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('standalone', $error->getMessage());
                }
                $this->assertSame(1, DB::transactionLevel());
            }
        } finally {
            $inspect = false;
            DB::rollBack();
        }
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->evidence());
        $verify->verifyReviewed($verification, $actor);
        $this->assertSame('verified', $declaration->fresh()->status);
    }

    public static function auditOperations(): array
    {
        return ['create' => ['create'], 'edit' => ['edit'], 'retarget' => ['retarget'], 'verify' => ['verify']];
    }

    #[DataProvider('auditOperations')]
    public function test_failed_audit_rolls_back_the_whole_supported_write_and_preserves_original_evidence(string $operation): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $target = Track::create(['title' => 'Synthetic audit target', 'slug' => 'synthetic-audit-target']);
        $save = app(SaveRightsDeclaration::class);
        $verify = app(VerifyRightsDeclaration::class);
        $edit = $save->review($declaration, $actor);
        $verification = $verify->review($declaration, $actor);
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic rights audit failure'));
        try {
            match ($operation) {
                'create' => $save->create($this->data($track), $actor),
                'edit' => $save->updateReviewed($edit, $this->data($track, ['sample_disclosure' => 'Synthetic changed disclosure']), $actor),
                'retarget' => $save->updateReviewed($edit, $this->data($target), $actor),
                'verify' => $verify->verifyReviewed($verification, $actor),
            };
            $this->fail('A rights mutation committed after its audit failed.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic rights audit failure', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_new_pending_and_verified_successors_preserve_paid_history_contract_bytes_and_original_verification(): void
    {
        DeliveryFixtures::configure();
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = DeliveryFixtures::activate(ActivationFixtures::issue(ContractFixtures::finalize(FinalizationFixtures::confirm(PaymentFixtures::started($gateway)))));
        $track = $fixture['track'];
        $actor = $fixture['actor'];
        $original = $track->rightsDeclarations()->latest('id')->firstOrFail();
        $originalRaw = $original->getAttributes();
        $tables = ['offers', 'offer_revisions', 'quotes', 'quote_lines', 'quote_pricings', 'orders', 'order_lines',
            'order_attempts', 'verified_payments', 'order_finalizations', 'license_grants', 'pending_entitlements', 'fulfillment_outbox',
            'contract_render_requests', 'contract_render_work', 'grant_contracts', 'test_fulfillment_activations', 'test_delivery_controls'];
        $history = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $files = Storage::disk('local')->allFiles();
        sort($files, SORT_STRING);
        $fileHashes = fn () => array_map(fn ($path) => [$path, hash_file('sha256', Storage::disk('local')->path($path))], $files);
        $oldAuditIds = AuditEvent::orderBy('id')->pluck('id')->all();
        $oldAudits = fn () => AuditEvent::whereIn('id', $oldAuditIds)->orderBy('id')->get()->toJson();
        $before = [$history(), $fileHashes(), $oldAudits()];
        $pending = app(SaveRightsDeclaration::class)->create($this->data($track, ['provenance_reference' => 'SYNTHETIC-NEW-CORRECTION']), $actor);
        $this->assertSame($pending->id, $track->rightsDeclarations()->latest('id')->firstOrFail()->id);
        $this->assertContains('The latest rights declaration must be verified.', app(PublicationReadiness::class)->draftBlockers($fixture['offer']));
        app(VerifyRightsDeclaration::class)->verifyReviewed(app(VerifyRightsDeclaration::class)->review($pending, $actor), $actor);
        $this->assertNotEmpty(app(PublicationReadiness::class)->offerBlockers($fixture['offer']), 'A changed latest verified declaration cannot silently rewrite a frozen published offer.');
        $this->assertSame($originalRaw, $original->fresh()->getAttributes());
        $this->assertSame($before, [$history(), $fileHashes(), $oldAudits()]);
        $this->assertSame(1, DB::table('license_grants')->count());
        $this->assertSame(1, DB::table('grant_contracts')->count());
        $this->assertSame(2, AuditEvent::where('action', 'like', 'rights.declaration.%')->count());
    }

    public function test_unrelated_sql_failures_propagate_and_roll_back_without_becoming_a_rights_validation_error(): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $save = app(SaveRightsDeclaration::class);
        $review = $save->review($declaration, $actor);
        $before = $this->evidence();
        $name = 'synthetic_rights_writer_sql_failure';
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TEMP TRIGGER {$name} BEFORE UPDATE ON rights_declarations WHEN OLD.id = {$declaration->id} BEGIN SELECT RAISE(ABORT, 'Synthetic unrelated rights writer SQL failure'); END"
            : "CREATE TRIGGER {$name} BEFORE UPDATE ON rights_declarations FOR EACH ROW BEGIN IF OLD.id = {$declaration->id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic unrelated rights writer SQL failure'; END IF; END");
        try {
            try {
                $save->updateReviewed($review, $this->data($track, ['sample_disclosure' => 'Synthetic attempted SQL failure change']), $actor);
                $this->fail('The synthetic SQL failure did not propagate.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Synthetic unrelated rights writer SQL failure', $error->getMessage());
            }
            $this->assertSame($before, $this->evidence());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::unprepared('DROP TRIGGER '.$name);
        }
    }

    public function test_review_proves_current_value_equality_and_does_not_claim_to_detect_a_restored_evidence_history(): void
    {
        ['actor' => $actor, 'track' => $track, 'declaration' => $declaration] = $this->pending();
        $this->freezeSecond();
        $save = app(SaveRightsDeclaration::class);
        $verify = app(VerifyRightsDeclaration::class);
        $original = $verify->review($declaration, $actor);
        $save->updateReviewed($save->review($declaration, $actor), $this->data($track, ['sample_disclosure' => 'Synthetic temporary different value']), $actor);
        $save->updateReviewed($save->review($declaration, $actor), $this->data($track), $actor);
        $this->assertSame($original, $verify->review($declaration, $actor));
        $this->assertSame(2, AuditEvent::where('action', 'rights.declaration.updated')->count());
        $verified = $verify->verifyReviewed($original, $actor);
        $this->assertSame('verified', $verified->status);
        $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.verified')->count());
    }
}
