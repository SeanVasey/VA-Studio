<?php

namespace Tests\Feature;

use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewedLicenseDraft;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class ReviewedLicenseDraftTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['license_templates', 'license_versions', 'license_review_evidence', 'offers', 'offer_revisions', 'audit_events']);
    }

    private function data(array $review, array $changes = []): array
    {
        return array_replace(Arr::only($review['display'], ReviewedLicenseDraft::FIELDS), $changes);
    }

    private function refused(callable $operation, string $key = 'license'): void
    {
        $before = $this->evidence();
        try {
            $operation();
            $this->fail('An invalid or stale draft edit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_two_open_editors_keep_the_winning_draft_and_require_explicit_reopen(): void
    {
        $first = LicenseFixtures::admin();
        $second = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($first);
        $command = app(ReviewedLicenseDraft::class);
        $older = $command->review($draft, $first);
        $newer = $command->review($draft, $second);
        $command->updateReviewed($newer, $this->data($newer, ['authored_source' => 'NEWER SAVED SYNTHETIC DRAFT']), $second);
        $this->refused(fn () => $command->updateReviewed($older, $this->data($older, ['authored_source' => 'STALE SYNTHETIC FORM']), $first));
        $this->assertSame('NEWER SAVED SYNTHETIC DRAFT', $draft->fresh()->authored_source);
        $reopened = $command->review($draft, $first);
        $this->assertSame('NEWER SAVED SYNTHETIC DRAFT', $reopened['display']['authored_source']);
        $saved = $command->updateReviewed($reopened, $this->data($reopened, ['authored_source' => 'REVIEWED NEW SYNTHETIC DRAFT']), $first);
        $this->assertSame('REVIEWED NEW SYNTHETIC DRAFT', $saved->authored_source);
        $this->assertSame([$first->id, $second->id], $saved->content_author_ids);
        $this->assertSame($first->id, $saved->author_id);
    }

    public static function schemas(): array
    {
        return ['original' => [1], 'typed' => [2], 'scoped' => [3], 'economic' => [4]];
    }

    #[DataProvider('schemas')]
    public function test_review_and_no_op_preserve_each_supported_schema_and_use_minimized_atomic_edit_audits(int $schema): void
    {
        $actor = LicenseFixtures::admin();
        $fixture = match ($schema) {
            2 => TypedLicenseFixtures::class, 3 => ScopedLicenseFixtures::class, 4 => EconomicLicenseFixtures::class, default => null
        };
        $draft = $fixture === null ? LicenseFixtures::draft($actor) : LicenseFixtures::draft($actor, $fixture::terms(), ['authored_source' => $fixture::source()]);
        $command = app(ReviewedLicenseDraft::class);
        $editor = LicenseFixtures::admin();
        $before = $this->evidence();
        $review = $command->review($draft, $editor);
        $this->assertSame($schema, $review['display']['structured_terms']['schema_version']);
        $this->assertSame($draft->template->only(SaveLicenseTemplate::FIELDS), $review['template']);
        $reordered = $this->data($review, ['structured_terms' => array_reverse($review['display']['structured_terms'], true)]);
        $command->updateReviewed($review, $reordered, $editor);
        $this->assertSame($before, $this->evidence());
        $this->assertSame([$actor->id], $draft->fresh()->content_author_ids);
        $review = $command->review($draft, $actor);
        $source = "SYNTHETIC PRIVATE AMENDMENT\n".$review['display']['authored_source'];
        $saved = $command->updateReviewed($review, $this->data($review, ['authored_source' => $source,
            'effective_from' => '2030-01-02T03:04:05+02:00', 'effective_until' => '2030-02-03T04:05:06+02:00']), $actor);
        $this->assertSame($source, $saved->authored_source);
        $this->assertSame('2030-01-02 01:04:05', $command->review($saved, $actor)['display']['effective_from']);
        $this->assertSame($schema, $saved->structured_terms['schema_version']);
        $audit = AuditEvent::latest('id')->firstOrFail();
        $this->assertSame('rights.license.draft_updated', $audit->action);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(['authored_source', 'effective_from', 'effective_until'], $audit->context['changed_fields']);
        $this->assertSame(CanonicalJson::hash($review['display']), $audit->context['before_hash']);
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE AMENDMENT', json_encode($audit->context));
        $submitted = app(ReviewLicense::class)->submit($saved, $actor);
        $this->refused(fn () => app(ReviewLicense::class)->approve($submitted, $actor, ['approval_reference' => 'SYNTHETIC', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]));
    }

    public static function drift(): array
    {
        return ['legacy content' => ['legacy'], 'legacy no-op' => ['noop'], 'legacy same-second ABA' => ['aba'], 'template identity' => ['template'], 'template same-second ABA' => ['template-aba']];
    }

    #[DataProvider('drift')]
    public function test_participating_legacy_and_template_writes_invalidate_even_same_second_aba(string $kind): void
    {
        $this->freezeTime();
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($actor);
        $command = app(ReviewedLicenseDraft::class);
        $review = $command->review($draft, $actor);
        if (str_starts_with($kind, 'template')) {
            $templates = app(SaveLicenseTemplate::class);
            $template = $draft->template;
            $original = $template->only(SaveLicenseTemplate::FIELDS);
            $templates->updateReviewed($templates->review($template, $actor), array_replace($original, ['name' => 'Temporary synthetic name']), $actor);
            if ($kind === 'template-aba') {
                $templates->updateReviewed($templates->review($template, $actor), $original, $actor);
                $this->assertSame($review['template_hash'], $command->review($draft, $actor)['template_hash']);
            }
        } else {
            $legacy = app(UpdateLicenseDraft::class);
            $legacy->handle($draft, $this->data($review, $kind === 'noop' ? [] : ['authored_source' => 'Temporary synthetic text']), $actor);
            if ($kind === 'aba') {
                $legacy->handle($draft, $this->data($review), $actor);
                $this->assertSame($review['version_hash'], $command->review($draft, $actor)['version_hash']);
            }
        }
        $this->refused(fn () => $command->updateReviewed($review, $this->data($review), $actor));
    }

    public static function frozen(): array
    {
        return ['submitted' => ['legal_review'], 'approved' => ['approved'], 'published' => ['published']];
    }

    #[DataProvider('frozen')]
    public function test_lifecycle_drift_refuses_capture_and_save_without_changing_retained_evidence(string $status): void
    {
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($actor);
        $command = app(ReviewedLicenseDraft::class);
        $review = $command->review($draft, $actor);
        $submitted = app(ReviewLicense::class)->submit($draft, $actor);
        if ($status !== 'legal_review') {
            $submitted = app(ReviewLicense::class)->approve($submitted, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);
        }
        if ($status === 'published') {
            app(PublishLicense::class)->handle($submitted, $actor);
        }
        $this->refused(fn () => $command->review($draft, $actor));
        $this->refused(fn () => $command->updateReviewed($review, $this->data($review), $actor));
    }

    public function test_forged_baselines_unknown_fields_bad_content_and_reparented_hints_fail_closed(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($actor);
        $other = LicenseFixtures::draft($actor);
        $command = app(ReviewedLicenseDraft::class);
        $review = $command->review($draft, $actor);
        foreach ([['version_id' => $other->id], ['template_id' => $other->license_template_id], ['version_id' => (string) $draft->id],
            ['template_hash' => $review['template_hash']."\n"], ['version_audit_id' => 0], ['display' => []], ['template' => ['name' => "\xFF"]],
            ['display' => ['structured_terms' => ['bad' => 1.5]]], ['schema_version' => 2], ['unexpected' => true]] as $change) {
            $this->refused(fn () => $command->updateReviewed(array_replace($review, $change), $this->data($review), $actor));
        }
        $this->refused(fn () => $command->updateReviewed(Arr::except($review, ['intent']), $this->data($review), $actor));
        $this->refused(fn () => $command->updateReviewed($review, $this->data($review, ['price_minor' => 100]), $actor));
        $this->refused(fn () => $command->updateReviewed($review, $this->data($review, ['authored_source' => '']), $actor), 'authored_source');
        $this->refused(fn () => $command->updateReviewed($review, $this->data($review, ['effective_from' => '2030-02-01', 'effective_until' => '2030-01-01']), $actor), 'effective_until');
        $draft->license_template_id = $other->license_template_id;
        $this->refused(fn () => $command->review($draft, $actor));
        $draft->exists = false;
        $this->refused(fn () => $command->review($draft, $actor));
        try {
            $command->updateReviewed($review, $this->data($review), LicenseFixtures::admin());
            $this->fail('A different actor reused the captured review.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public static function actors(): array
    {
        $cases = [];
        foreach (['review', 'save'] as $operation) {
            foreach (['role', 'email', 'mfa', 'unsaved', 'customer'] as $state) {
                $cases[$operation.' / '.$state] = [$operation, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('actors')]
    public function test_current_actor_and_required_mfa_guard_capture_and_save(string $operation, string $state): void
    {
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($actor);
        $command = app(ReviewedLicenseDraft::class);
        $review = $command->review($draft, $actor);
        if ($state === 'role' || $state === 'email') {
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['email_verified_at' => null]);
        } elseif ($state === 'mfa') {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
        } elseif ($state === 'unsaved') {
            $actor->exists = false;
        } else {
            $actor = User::factory()->create();
            $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        }
        $before = $this->evidence();
        try {
            $operation === 'review' ? $command->review($draft, $actor) : $command->updateReviewed($review, $this->data($review, ['authored_source' => 'Denied edit']), $actor);
            $this->fail('Unavailable authority reached the draft.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public static function lateAuthority(): array
    {
        return ['capture role' => ['review', 'role'], 'capture MFA' => ['review', 'mfa'], 'audit role' => ['save', 'role'], 'audit MFA' => ['save', 'mfa']];
    }

    #[DataProvider('lateAuthority')]
    public function test_post_projection_or_post_audit_withdrawal_rolls_back_before_return(string $operation, string $state): void
    {
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($actor);
        $command = app(ReviewedLicenseDraft::class);
        $review = $command->review($draft, $actor);
        if ($state === 'mfa') {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        }
        $before = $this->evidence();
        $withdraw = fn () => DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['app_authentication_secret' => null]);
        $operation === 'review' ? LicenseVersion::retrieved($withdraw) : AuditEvent::created($withdraw);
        try {
            $operation === 'review' ? $command->review($draft, $actor) : $command->updateReviewed($review, $this->data($review, ['authored_source' => 'Late denied edit']), $actor);
            $this->fail('Late withdrawal returned private content or committed an edit.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence());
            $this->assertTrue(User::findOrFail($actor->id)->is_admin);
            if ($state === 'mfa') {
                $this->assertNotNull(User::findOrFail($actor->id)->app_authentication_secret);
            }
        }
    }

    public function test_audit_failure_rolls_back_content_authorship_and_reopening_reads_committed_state(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft();
        $command = app(ReviewedLicenseDraft::class);
        $review = $command->review($draft, $actor);
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic audit outage'));
        try {
            $command->updateReviewed($review, $this->data($review, ['authored_source' => 'Uncommitted text']), $actor);
            $this->fail('Failed audit committed an edit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit outage', $exception->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame($review, $command->review($draft, $actor));
    }

    public function test_review_fences_precede_snapshot_reads_and_new_entry_points_refuse_caller_transactions(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($actor);
        $command = app(ReviewedLicenseDraft::class);
        $trace = [];
        $active = true;
        DB::listen(function (QueryExecuted $query) use (&$trace, &$active): void {
            if ($active && preg_match('/\Aselect\b/i', $query->sql)) {
                $trace[] = [$query->sql, DB::transactionLevel()];
            }
        });
        $review = $command->review($draft, $actor);
        $active = false;
        $firstAudit = array_find_key($trace, fn ($entry) => str_contains($entry[0], 'audit_events'));
        $this->assertStringContainsString('license_templates', $trace[$firstAudit - 2][0]);
        $this->assertStringContainsString('license_versions', $trace[$firstAudit - 1][0]);
        foreach ($trace as [$sql, $level]) {
            $this->assertSame(1, $level);
            if (DB::getDriverName() === 'mysql' && ! str_contains($sql, 'audit_events')) {
                $this->assertStringContainsString('for update', $sql);
            }
        }
        $before = $this->evidence();
        DB::beginTransaction();
        try {
            foreach ([fn () => $command->review($draft, $actor), fn () => $command->updateReviewed($review, $this->data($review), $actor)] as $operation) {
                try {
                    $operation();
                    $this->fail('Caller-owned snapshot was reused.');
                } catch (LogicException) {
                    $this->assertSame(1, DB::transactionLevel());
                }
            }
            app(UpdateLicenseDraft::class)->handle($draft, $this->data($review, ['authored_source' => 'Caller-owned legacy edit']), $actor);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
    }
}
