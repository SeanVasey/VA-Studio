<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * F-3: the admin panel requires MFA only in production, and family 256 runs only in local/testing, so
 * `AdminMultiFactor::satisfiedBy` is a no-op wherever 256 can run. Staff authority for 256 therefore requires an
 * enrolled MFA provider unconditionally. These tests keep the shipped (non-production) panel rule.
 */
final class ProductionFreeGrantStaffMfaTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private ProductionFreeGrantDefinitions $definitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup(requireMfa: false);
        $this->definitions = new ProductionFreeGrantDefinitions;
    }

    public function test_the_shipped_panel_rule_does_not_require_mfa_here(): void
    {
        $this->assertFalse(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired());
        $this->assertTrue(AdminMultiFactor::satisfiedBy($this->staff(mfa: false)));
    }

    public function test_staff_without_enrolled_mfa_is_refused_for_every_staff_action(): void
    {
        $bare = $this->staff(mfa: false);
        $author = $this->staff();
        $reviewer = $this->staff();
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $bare), 'mfa_required');
        $this->assertSame(0, DB::table('production_free_definitions')->count());

        $proposed = $this->definitions->propose($this->definitionInput(), $author);
        $hash = ['definitionHash' => $proposed['definitionHash']];
        $this->refuses(fn () => $this->definitions->approve($proposed['id'], $hash, $bare), 'mfa_required');
        $this->assertSame(0, DB::table('production_free_reviews')->count());
        $this->definitions->approve($proposed['id'], $hash, $reviewer);

        $this->refuses(fn () => $this->definitions->open($proposed['id'], $hash + ['expectedOrdinal' => 0], $bare), 'mfa_required');
        $this->assertSame(0, DB::table('production_free_availability')->count());
        $this->definitions->open($proposed['id'], $hash + ['expectedOrdinal' => 0], $reviewer);

        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($proposed['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($proposed['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $this->refuses(fn () => $grants->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic revocation.'], $bare), 'mfa_required');
        $this->assertSame(0, DB::table('production_free_revocations')->count());

        $this->refuses(fn () => $this->definitions->close($proposed['id'], $hash + ['expectedOrdinal' => 1], $bare), 'mfa_required');
        $this->assertSame(1, DB::table('production_free_availability')->count());
        $this->refuses(fn () => $this->definitions->read($proposed['id'], $bare), 'mfa_required');

        // The same actions succeed for enrolled staff under the same shipped rule.
        $this->assertTrue($grants->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic revocation.'], $author)['revoked']);
        $this->assertFalse($this->definitions->close($proposed['id'], $hash + ['expectedOrdinal' => 1], $reviewer)['open']);
    }

    public function test_enrollment_removed_after_the_actor_was_loaded_is_refused(): void
    {
        $actor = $this->staff();
        DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $actor->fresh()), 'mfa_required');
        $this->assertSame(0, DB::table('production_free_definitions')->count());
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
