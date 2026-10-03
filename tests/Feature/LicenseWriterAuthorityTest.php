<?php

namespace Tests\Feature;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Domain\Rights\VerifiedLicense;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class LicenseWriterAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_current_publication_eligibility_refuses_unprotected_or_unpublished_licenses(): void
    {
        $license = LicenseFixtures::published();
        $verifier = app(VerifiedLicense::class);
        $this->assertFalse($verifier->availableForPublication($license));
        $this->assertTrue($verifier->available($license));
        $approved = LicenseFixtures::approved();
        DB::transaction(function () use ($verifier, $license, $approved): void {
            $this->assertTrue($verifier->availableForPublication($license));
            $this->assertFalse($verifier->availableForPublication($approved));
        });
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function writers(): array
    {
        return array_combine(['create', 'update', 'submit', 'approve', 'publish'], array_map(fn ($writer) => [$writer], ['create', 'update', 'submit', 'approve', 'publish']));
    }

    private function fixture(string $writer): array
    {
        $actor = LicenseFixtures::admin();
        $version = match ($writer) {
            'approve' => app(ReviewLicense::class)->submit(LicenseFixtures::draft(), LicenseFixtures::admin()),
            'publish' => LicenseFixtures::approved($actor),
            default => LicenseFixtures::draft($actor),
        };

        return [$actor, $version];
    }

    private function invoke(string $writer, User $actor, LicenseVersion $version): LicenseVersion
    {
        $content = ['authored_source' => 'Changed synthetic source', 'structured_terms' => $version->structured_terms];

        return match ($writer) {
            'create' => app(CreateLicenseDraft::class)->handle($version->template, $content, $actor, $version),
            'update' => app(UpdateLicenseDraft::class)->handle($version, $content, $actor),
            'submit' => app(ReviewLicense::class)->submit($version, $actor),
            'approve' => app(ReviewLicense::class)->approve($version, $actor, ['approval_reference' => 'SYNTHETIC-TEST-ONLY', 'review_hash' => $version->submission_hash, 'summary_consistency_confirmed' => true]),
            'publish' => app(PublishLicense::class)->handle($version, $actor),
        };
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['license_templates', 'license_versions', 'license_review_evidence', 'audit_events']);
    }

    #[DataProvider('writers')]
    public function test_authority_reads_are_in_the_mutation_transaction_before_any_license_resource(string $writer): void
    {
        [$actor, $version] = $this->fixture($writer);
        // Resolve template outside the observed mutation; callers may retain models.
        $version->load('template');
        $trace = [];
        $active = true;
        DB::listen(function (QueryExecuted $query) use (&$trace, &$active): void {
            if ($active && preg_match('/\Aselect\b/i', $query->sql)) {
                foreach (['users', 'license_templates', 'license_versions', 'license_review_evidence'] as $table) {
                    if (preg_match('/\bfrom\s+["`]?'.$table.'["`]?(?:\s|$)/i', $query->sql)) {
                        $trace[] = ['table' => $table, 'level' => DB::transactionLevel()];
                    }
                }
            }
        });
        try {
            $result = $this->invoke($writer, $actor, $version);
        } finally {
            $active = false;
        }
        $this->assertSame('users', $trace[0]['table']);
        $this->assertNotEmpty(array_filter($trace, fn ($entry) => $entry['table'] === 'users'));
        $this->assertSame([], array_values(array_filter($trace, fn ($entry) => $entry['level'] < 1)));
        $this->assertSame($actor->id, AuditEvent::latest('id')->firstOrFail()->actor_id);
        $this->assertSame($result->id, AuditEvent::latest('id')->firstOrFail()->subject_id);
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function unavailableActors(): array
    {
        $cases = [];
        foreach (array_keys(self::writers()) as $writer) {
            foreach (['role', 'email', 'deleted', 'unsaved', 'customer'] as $state) {
                $cases[$writer.' / '.$state] = [$writer, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('unavailableActors')]
    public function test_unavailable_persisted_authority_cannot_mutate_any_license_evidence(string $writer, string $state): void
    {
        [$actor, $version] = $this->fixture($writer);
        if ($state === 'role' || $state === 'email') {
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['email_verified_at' => null]);
        } elseif ($state === 'deleted') {
            $actor = LicenseFixtures::admin();
            User::findOrFail($actor->id)->delete();
        } elseif ($state === 'unsaved') {
            $actor = (new User)->forceFill(['id' => $actor->id, 'is_admin' => true, 'email_verified_at' => now()]);
        } else {
            $actor = User::factory()->create();
            $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        }
        $before = $this->evidence();
        try {
            $this->invoke($writer, $actor, $version);
            $this->fail('Unavailable current authority mutated licensing evidence.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writers')]
    public function test_nested_caller_transaction_remains_owned_by_the_caller_and_rolls_back_all_effects(string $writer): void
    {
        [$actor, $version] = $this->fixture($writer);
        $actor->forceFill(['is_admin' => false, 'email_verified_at' => null]);
        $before = $this->evidence();
        $audits = AuditEvent::count();
        DB::beginTransaction();
        try {
            $this->invoke($writer, $actor, $version);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($audits + 1, AuditEvent::count());
            $this->assertSame($actor->id, AuditEvent::latest('id')->firstOrFail()->actor_id);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writers')]
    public function test_audit_failure_rolls_back_license_content_lifecycle_and_review_evidence(string $writer): void
    {
        [$actor, $version] = $this->fixture($writer);
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic licensing audit failure'));
        try {
            $this->invoke($writer, $actor, $version);
            $this->fail('Failed audit allowed a license mutation to commit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic licensing audit failure', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }
}
