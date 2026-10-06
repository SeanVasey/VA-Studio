<?php

namespace Tests\Feature;

use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class LicenseTemplateAuthoringTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function data(): array
    {
        return ['name' => 'NONBINDING SYNTHETIC IDENTITY', 'slug' => 'synthetic-template', 'type' => 'non-exclusive'];
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['license_templates', 'license_versions', 'license_review_evidence', 'offers', 'offer_revisions', 'audit_events']);
    }

    private function invoke(string $operation, User $actor, LicenseTemplate $template, array $review): mixed
    {
        $command = app(SaveLicenseTemplate::class);

        return match ($operation) {
            'create' => $command->create(array_replace($this->data(), ['slug' => 'synthetic-second']), $actor),
            'review' => $command->review($template, $actor),
            'edit' => $command->updateReviewed($review, array_replace($this->data(), ['name' => 'Changed identity']), $actor),
        };
    }

    public function test_identity_create_edit_and_no_op_use_exact_actor_and_minimized_atomic_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs(LicenseFixtures::admin());
        $command = app(SaveLicenseTemplate::class);
        $template = $command->create($this->data(), $actor);
        $audit = AuditEvent::sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(LicenseTemplate::class, $audit->subject_type);
        $this->assertSame($template->id, $audit->subject_id);
        $this->assertSame('rights.license_template.created', $audit->action);
        $this->assertSame(CanonicalJson::encode(['schema_version' => 1, 'changed_fields' => ['name', 'slug', 'type'],
            'canonicalization_version' => CanonicalJson::VERSION, 'before_hash' => null,
            'after_hash' => CanonicalJson::hash($this->data())]), CanonicalJson::encode($audit->context));
        $before = $this->evidence();
        $review = $command->review($template, $actor);
        $command->updateReviewed($review, $this->data(), $actor);
        $this->assertSame($before, $this->evidence());
        $after = array_replace($this->data(), ['name' => 'Changed identity', 'type' => 'exclusive']);
        $saved = $command->updateReviewed($review, $after, $actor);
        $this->assertSame($after, $saved->only(SaveLicenseTemplate::FIELDS));
        $updated = AuditEvent::latest('id')->firstOrFail();
        $this->assertSame('rights.license_template.updated', $updated->action);
        $this->assertSame(['name', 'type'], $updated->context['changed_fields']);
        $this->assertSame(CanonicalJson::hash($this->data()), $updated->context['before_hash']);
        $this->assertSame(CanonicalJson::hash($after), $updated->context['after_hash']);
        $this->assertStringNotContainsString('Changed identity', json_encode($updated->context));
        $this->assertDatabaseCount('license_versions', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    public static function invalidInputs(): array
    {
        return ['missing name' => [['name' => null]], 'blank name' => [['name' => '   ']],
            'long name' => [['name' => str_repeat('x', 256)]], 'bad slug' => [['slug' => 'BAD / url']],
            'trailing newline slug' => [['slug' => "x\ninjected"]], 'long slug' => [['slug' => str_repeat('x', 256)]],
            'unknown type' => [['type' => 'invented-policy']], 'terms' => [['authored_source' => 'Unexpected terms']],
            'publication' => [['published_at' => '2026-01-01']], 'identity' => [['id' => 999]],
            'price' => [['price_minor' => 100]], 'baseline in data' => [['row_hash' => str_repeat('0', 64)]]];
    }

    #[DataProvider('invalidInputs')]
    public function test_unknown_or_invalid_identity_input_cannot_create_or_edit(array $change): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(SaveLicenseTemplate::class);
        $template = $command->create($this->data(), $actor);
        $review = $command->review($template, $actor);
        $before = $this->evidence();
        foreach (['create', 'edit'] as $operation) {
            try {
                $data = array_replace($this->data(), $change);
                $operation === 'create' ? $command->create($data, $actor) : $command->updateReviewed($review, $data, $actor);
                $this->fail('Invalid template identity was accepted.');
            } catch (ValidationException) {
            }
            $this->assertSame($before, $this->evidence());
        }
    }

    public function test_duplicate_slug_and_forged_or_wrong_actor_baselines_preserve_all_evidence(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(SaveLicenseTemplate::class);
        $template = $command->create($this->data(), $actor);
        $other = $command->create(array_replace($this->data(), ['slug' => 'other-template']), $actor);
        $review = $command->review($template, $actor);
        $before = $this->evidence();
        foreach ([fn () => $command->create($this->data(), $actor),
            fn () => $command->updateReviewed($review, array_replace($this->data(), ['slug' => $other->slug]), $actor),
            fn () => $command->updateReviewed(array_replace($review, ['template_id' => $other->id]), $this->data(), $actor),
            fn () => $command->updateReviewed(array_replace($review, ['unexpected' => true]), $this->data(), $actor)] as $operation) {
            try {
                $operation();
                $this->fail('Duplicate or forged baseline was accepted.');
            } catch (ValidationException) {
            }
            $this->assertSame($before, $this->evidence());
        }
        try {
            $command->updateReviewed($review, $this->data(), LicenseFixtures::admin());
            $this->fail('Another operator reused a captured edit.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function actors(): array
    {
        $rows = [];
        foreach (['create', 'review', 'edit'] as $operation) {
            foreach (['role', 'email', 'deleted', 'unsaved', 'customer', 'mfa'] as $state) {
                $rows[$operation.' / '.$state] = [$operation, $state];
            }
        }

        return $rows;
    }

    #[DataProvider('actors')]
    public function test_current_persisted_authority_and_required_mfa_guard_every_command(string $operation, string $state): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(SaveLicenseTemplate::class);
        $template = $command->create($this->data(), $actor);
        $review = $command->review($template, $actor);
        if (in_array($state, ['role', 'email'], true)) {
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['email_verified_at' => null]);
        } elseif ($state === 'deleted') {
            $actor = LicenseFixtures::admin();
            $actor->delete();
        } elseif ($state === 'unsaved') {
            $actor = (new User)->forceFill(['id' => $actor->id, 'is_admin' => true, 'email_verified_at' => now()]);
        } elseif ($state === 'customer') {
            $actor = User::factory()->create();
            $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        } else {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
        }
        $before = $this->evidence();
        try {
            $this->invoke($operation, $actor, $template, $review);
            $this->fail('Unavailable current authority wrote template identity.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_same_second_aba_and_stale_edits_are_rejected_without_an_extra_audit(): void
    {
        $this->freezeTime();
        $actor = LicenseFixtures::admin();
        $command = app(SaveLicenseTemplate::class);
        $template = $command->create($this->data(), $actor);
        $original = $template->fresh()->getAttributes();
        $old = $command->review($template, $actor);
        $command->updateReviewed($old, array_replace($this->data(), ['name' => 'Temporary identity']), $actor);
        $command->updateReviewed($command->review($template, $actor), $this->data(), $actor);
        $this->assertSame($original, $template->fresh()->getAttributes());
        $this->assertGreaterThan($old['audit_id'], $command->review($template, $actor)['audit_id']);
        $before = $this->evidence();
        try {
            $command->updateReviewed($old, array_replace($this->data(), ['name' => 'Stale loser']), $actor);
            $this->fail('An A to B to A cycle reused an old baseline.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('changed since', $exception->errors()['name'][0]);
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function frozenStates(): array
    {
        return ['submitted' => ['legal_review'], 'approved' => ['approved'], 'published' => ['published']];
    }

    #[DataProvider('frozenStates')]
    public function test_every_reviewed_state_refuses_identity_changes_and_preserves_original_license_graph(string $status): void
    {
        $actor = LicenseFixtures::admin();
        $version = match ($status) {
            'legal_review' => app(ReviewLicense::class)->submit(LicenseFixtures::draft($actor), $actor),
            'approved' => LicenseFixtures::approved($actor),
            'published' => LicenseFixtures::published($actor),
        };
        $before = $this->evidence();
        try {
            app(SaveLicenseTemplate::class)->review($version->template, $actor);
            $this->fail('Frozen template offered a mutable baseline.');
        } catch (ValidationException $exception) {
            $this->assertSame(SaveLicenseTemplate::FROZEN_MESSAGE, $exception->errors()['name'][0]);
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function mutations(): array
    {
        return ['create' => ['create'], 'edit' => ['edit']];
    }

    #[DataProvider('mutations')]
    public function test_audit_failure_rolls_back_identity_mutation(string $operation): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(SaveLicenseTemplate::class);
        $template = $command->create($this->data(), $actor);
        $review = $command->review($template, $actor);
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            $this->invoke($operation, $actor, $template, $review);
            $this->fail('Unaudited identity mutation committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_baseline_capture_has_no_earlier_consistent_database_read_and_rejects_ambient_transactions(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(SaveLicenseTemplate::class);
        $template = $command->create($this->data(), $actor);
        $trace = [];
        $active = true;
        DB::listen(function (QueryExecuted $query) use (&$trace, &$active): void {
            if ($active && preg_match('/\Aselect\b/i', $query->sql)) {
                $trace[] = [$query->sql, DB::transactionLevel()];
            }
        });
        $command->review($template, $actor);
        $active = false;
        $this->assertStringContainsString('users', $trace[0][0]);
        $this->assertStringContainsString('audit_events', $trace[array_key_last($trace)][0]);
        foreach ($trace as [$sql, $level]) {
            $this->assertSame(1, $level);
            if (DB::getDriverName() === 'mysql' && ! str_contains($sql, 'audit_events')) {
                $this->assertStringContainsString('for update', $sql);
            }
        }
        DB::beginTransaction();
        try {
            $command->review($template, $actor);
            $this->fail('A caller snapshot was accepted.');
        } catch (LogicException) {
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }
}
