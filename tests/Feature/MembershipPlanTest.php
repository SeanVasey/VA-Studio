<?php

namespace Tests\Feature;

use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipPlanVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipPlanTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->travelTo(now()->utc()->startOfSecond());
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'users', 'customer_accounts', 'audit_events']);
    }

    private function refused(callable $operation, string $exception = ValidationException::class): void
    {
        $before = $this->rows();
        try {
            $operation();
            $this->fail('Invalid membership work was accepted.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_private_plan_versions_require_explicit_review_and_keep_original_policy(): void
    {
        $f = F::plan();
        $command = app(MembershipPlans::class);
        $replacement = F::data(['allowance' => 7], 'EXPLICIT SECOND SYNTHETIC POLICY');
        $before = $this->rows();
        $review = $command->reviewRevision($f['plan'], $replacement, $f['operator']);
        $this->assertSame(MembershipPlans::REVIEW_KEYS, array_keys($review));
        $this->assertSame($before, $this->rows());
        $saved = $command->applyReviewedRevision($review, $f['operator']);
        $this->assertSame(2, $saved['number']);
        $this->assertSame(7, $saved['policy']['allowance']);
        $this->assertSame(3, $f['version']->fresh()->policy['allowance']);
        $this->assertSame($f['projection']['manifest_hash'], $f['version']->fresh()->manifest_hash);
        $this->assertSame(1, DB::table('membership_plans')->count());
        $this->assertSame(2, DB::table('membership_plan_versions')->count());
        $audit = AuditEvent::latest('id')->firstOrFail();
        $this->assertSame($f['operator']->id, $audit->actor_id);
        $this->assertSame('membership.test_plan.revised', $audit->action);
        $this->assertSame(CanonicalJson::hash($review), $audit->context['review_hash']);
        $this->assertStringNotContainsString($replacement['title'], json_encode($audit->context));
        $this->refused(fn () => $command->applyReviewedRevision($review, $f['operator']));
        $current = $command->reviewRevision($f['plan'], $replacement, $f['operator']);
        $before = $this->rows();
        $this->assertSame($saved, $command->applyReviewedRevision($current, $f['operator']));
        $this->assertSame($before, $this->rows());
    }

    public function test_semantic_key_order_no_op_and_two_operators_do_not_lose_the_winner(): void
    {
        $f = F::plan();
        $command = app(MembershipPlans::class);
        $data = F::data();
        $data['policy'] = array_reverse($data['policy'], true);
        $before = $this->rows();
        $review = $command->reviewRevision($f['plan'], $data, $f['operator']);
        $this->assertSame($f['projection'], $command->applyReviewedRevision($review, $f['operator']));
        $this->assertSame($before, $this->rows());
        $other = F::operator();
        $older = $command->reviewRevision($f['plan'], F::data(['allowance' => 4]), $f['operator']);
        $newer = $command->reviewRevision($f['plan'], F::data(['allowance' => 5]), $other);
        $command->applyReviewedRevision($newer, $other);
        $this->refused(fn () => $command->applyReviewedRevision($older, $f['operator']));
        $this->assertSame(5, MembershipPlanVersion::orderByDesc('id')->firstOrFail()->policy['allowance']);
    }

    public static function invalid(): array
    {
        return [
            'missing policy' => ['policy', null], 'empty title' => ['title', ''], 'padded title' => ['title', ' padded '],
            'long title' => ['title', str_repeat('x', 181)], 'control title' => ['title', "unsafe\n"],
            'unknown outer field' => ['active', true], 'missing allowance' => ['policy.allowance', null],
            'float allowance' => ['policy.allowance', 2.5], 'numeric string allowance' => ['policy.allowance', '3'],
            'zero allowance' => ['policy.allowance', 0], 'overflow allowance' => ['policy.allowance', 1000001],
            'wrong schema' => ['policy.schema_version', '1'], 'upper unit' => ['policy.unit', 'CREDIT'],
            'empty unit' => ['policy.unit', ''], 'too long unit' => ['policy.unit', str_repeat('x', 33)],
            'zero validity' => ['policy.validity_seconds', 0], 'float validity' => ['policy.validity_seconds', 1.5],
            'long validity' => ['policy.validity_seconds', 31536001], 'future rollover' => ['policy.rollover', 'carry'],
            'integer reversal flag' => ['policy.reversal_allowed', 1], 'unknown policy' => ['policy.price_minor', 100],
        ];
    }

    #[DataProvider('invalid')]
    public function test_no_implicit_or_unapproved_plan_policy_is_admitted(string $key, mixed $value): void
    {
        $operator = F::operator();
        $data = F::data();
        if ($value === null) {
            Arr::forget($data, $key);
        } else {
            Arr::set($data, $key, $value);
        }
        $this->refused(fn () => app(MembershipPlans::class)->createDraft($data, $operator));
    }

    public function test_nullable_expiry_and_false_reversal_are_explicitly_retained(): void
    {
        $f = F::plan(['validity_seconds' => null, 'reversal_allowed' => false]);
        $this->assertNull($f['projection']['policy']['validity_seconds']);
        $this->assertFalse($f['projection']['policy']['reversal_allowed']);
    }

    public function test_bounded_unicode_plan_titles_keep_their_exact_authored_text(): void
    {
        $actor = F::operator();
        $title = str_repeat('音', 180);
        $saved = app(MembershipPlans::class)->createDraft(F::data(title: $title), $actor);
        $this->assertSame($title, $saved['title']);
        $this->assertSame($title, MembershipPlanVersion::findOrFail($saved['version_id'])->title);
    }

    public static function reviewTamper(): array
    {
        return ['schema' => ['schema_version', 2], 'intent' => ['intent', 'publish'], 'actor' => ['actor_id', 999999],
            'plan' => ['plan_id', 999999], 'version' => ['version_id', 999999], 'hash' => ['version_hash', str_repeat('f', 64)],
            'history' => ['history_hash', str_repeat('e', 64)], 'audit' => ['audit_id', null], 'extra' => ['published', true]];
    }

    #[DataProvider('reviewTamper')]
    public function test_substituted_review_identity_is_refused(string $key, mixed $value): void
    {
        $f = F::plan();
        $review = app(MembershipPlans::class)->reviewRevision($f['plan'], F::data(['allowance' => 9]), $f['operator']);
        $review[$key] = $value;
        $this->refused(fn () => app(MembershipPlans::class)->applyReviewedRevision($review, $f['operator']));
    }

    public function test_audit_cursor_catches_append_only_aba_even_when_policy_returns_to_original(): void
    {
        $f = F::plan();
        $command = app(MembershipPlans::class);
        $old = $command->reviewRevision($f['plan'], F::data(['allowance' => 6]), $f['operator']);
        $command->applyReviewedRevision($old, $f['operator']);
        $back = $command->reviewRevision($f['plan'], F::data(), $f['operator']);
        $command->applyReviewedRevision($back, $f['operator']);
        $this->refused(fn () => $command->applyReviewedRevision($old, $f['operator']));
    }

    public static function authority(): array
    {
        return ['role' => ['role'], 'verified email' => ['email'], 'MFA' => ['mfa']];
    }

    #[DataProvider('authority')]
    public function test_current_staff_authority_is_required_before_review_apply_and_no_op(string $withdraw): void
    {
        $f = F::plan();
        $command = app(MembershipPlans::class);
        $review = $command->reviewRevision($f['plan'], F::data(), $f['operator']);
        DB::table('users')->where('id', $f['operator']->id)->update(match ($withdraw) {
            'role' => ['is_admin' => false], 'email' => ['email_verified_at' => null], default => ['app_authentication_secret' => null],
        });
        $this->refused(fn () => $command->reviewRevision($f['plan'], F::data(), $f['operator']), AuthorizationException::class);
        $this->refused(fn () => $command->applyReviewedRevision($review, $f['operator']), AuthorizationException::class);
        $this->refused(fn () => $command->createDraft(F::data(), $f['operator']), AuthorizationException::class);
    }

    #[DataProvider('authority')]
    public function test_audit_observer_withdrawal_rolls_back_the_whole_plan_revision(string $withdraw): void
    {
        $f = F::plan();
        $review = app(MembershipPlans::class)->reviewRevision($f['plan'], F::data(['allowance' => 8]), $f['operator']);
        AuditEvent::created(function () use ($f, $withdraw): void {
            DB::table('users')->where('id', $f['operator']->id)->update(match ($withdraw) {
                'role' => ['is_admin' => false], 'email' => ['email_verified_at' => null], default => ['app_authentication_secret' => null],
            });
        });
        $this->refused(fn () => app(MembershipPlans::class)->applyReviewedRevision($review, $f['operator']), AuthorizationException::class);
    }

    public function test_exact_own_audit_and_extra_version_sabotage_are_detected(): void
    {
        $f = F::plan();
        $review = app(MembershipPlans::class)->reviewRevision($f['plan'], F::data(['allowance' => 8]), $f['operator']);
        AuditEvent::creating(function (AuditEvent $audit): void {
            if ($audit->action === 'membership.test_plan.revised') {
                $audit->context = ['test_only' => true];
            }
        });
        $this->refused(fn () => app(MembershipPlans::class)->applyReviewedRevision($review, $f['operator']));
    }

    public function test_final_authority_retrieval_cannot_append_an_unreviewed_version_after_audit(): void
    {
        $f = F::plan();
        $review = app(MembershipPlans::class)->reviewRevision($f['plan'], F::data(['allowance' => 8]), $f['operator']);
        $armed = false;
        AuditEvent::created(function () use (&$armed): void {
            $armed = true;
        });
        User::retrieved(function (User $user) use (&$armed, $f): void {
            if ($armed && $user->id === $f['operator']->id) {
                $armed = false;
                $row = (array) DB::table('membership_plan_versions')->where('membership_plan_id', $f['plan']->id)->orderByDesc('number')->first();
                unset($row['id']);
                $row['number']++;
                DB::table('membership_plan_versions')->insert($row);
            }
        });
        $this->refused(fn () => app(MembershipPlans::class)->applyReviewedRevision($review, $f['operator']));
    }

    public function test_production_disabled_and_nested_transactions_refuse_before_any_effect(): void
    {
        $actor = F::operator();
        config(['memberships.test_mode_enabled' => false]);
        $this->refused(fn () => app(MembershipPlans::class)->createDraft(F::data(), $actor), AuthorizationException::class);
        config(['memberships.test_mode_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->refused(fn () => app(MembershipPlans::class)->createDraft(F::data(), $actor), AuthorizationException::class);
        $this->app->detectEnvironment(fn () => 'testing');
        DB::beginTransaction();
        try {
            $before = $this->rows();
            try {
                app(MembershipPlans::class)->createDraft(F::data(), $actor);
                $this->fail('Nested membership work was accepted.');
            } catch (LogicException) {
            }
            $this->assertSame($before, $this->rows());
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }
}
