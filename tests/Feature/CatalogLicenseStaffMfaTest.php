<?php

namespace Tests\Feature;

use App\Domain\Catalog\ActivateExclusiveOffer;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExclusiveOfferFixtures;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Direct domain calls with visibly synthetic records; required enrollment is not a new HTTP session assertion. */
final class CatalogLicenseStaffMfaTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->required(false);
    }

    private function required(bool $required): void
    {
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
    }

    public static function writers(): iterable
    {
        foreach (['metadata create', 'metadata update', 'offer create', 'offer update', 'offer deactivate', 'offer publish',
            'license create', 'license update', 'license submit', 'license approve', 'license publish',
            'exclusive prepare', 'exclusive activate'] as $writer) {
            yield $writer => [$writer];
        }
    }

    public static function refusedWriters(): iterable
    {
        foreach (self::writers() as $writer => $args) {
            foreach (['unenrolled', 'withdrawn'] as $state) {
                yield $writer.' / '.$state => [$writer, $state];
            }
        }
    }

    private function fixture(string $writer): array
    {
        if (str_starts_with($writer, 'exclusive')) {
            ExclusiveSelectionFixtures::configure();

            return $writer === 'exclusive prepare' ? ExclusiveOfferFixtures::draft() : ExclusiveSelectionFixtures::prepared();
        }
        if (! str_starts_with($writer, 'license')) {
            $fixture = QuoteFixtures::selection();
            if ($writer === 'offer publish') {
                app(SaveOfferDraft::class)->handle($fixture['offer'], ['price_minor' => 5100], $fixture['actor']);
            }

            return $fixture;
        }
        $actor = LicenseFixtures::admin();
        $version = match ($writer) {
            'license approve' => app(ReviewLicense::class)->submit(LicenseFixtures::draft(), LicenseFixtures::admin()),
            'license publish' => LicenseFixtures::approved($actor),
            default => LicenseFixtures::draft($actor),
        };

        return compact('actor', 'version');
    }

    private function invoke(string $writer, array $fixture, User $actor): mixed
    {
        $version = $fixture['version'] ?? null;
        $content = $version === null ? [] : ['authored_source' => 'NONBINDING SYNTHETIC MFA SOURCE', 'structured_terms' => $version->structured_terms];

        return match ($writer) {
            'metadata create' => app(SaveTrackMetadata::class)->handle(null,
                ['title' => 'Synthetic MFA title', 'slug' => 'synthetic-mfa-title', 'artist' => 'Test only'], $actor),
            'metadata update' => app(SaveTrackMetadata::class)->handle($fixture['track'],
                ['title' => 'Synthetic MFA update', 'metadata_version' => $fixture['track']->metadata_version], $actor),
            'offer create' => app(SaveOfferDraft::class)->handle(null,
                ['track_id' => $fixture['track']->id, 'license_version_id' => $fixture['offer']->license_version_id,
                    'price_minor' => 5100, 'currency' => 'USD', 'deliverable_asset_ids' => $fixture['offer']->deliverable_asset_ids], $actor),
            'offer update' => app(SaveOfferDraft::class)->handle($fixture['offer'], ['price_minor' => 5100], $actor),
            'offer deactivate' => app(DeactivateOffer::class)->handle($fixture['offer'], $actor),
            'offer publish' => app(PublishOffer::class)->handle($fixture['offer'], $actor),
            'license create' => app(CreateLicenseDraft::class)->handle($version->template, $content, $actor, $version),
            'license update' => app(UpdateLicenseDraft::class)->handle($version, $content, $actor),
            'license submit' => app(ReviewLicense::class)->submit($version, $actor),
            'license approve' => app(ReviewLicense::class)->approve($version, $actor,
                ['approval_reference' => 'SYNTHETIC-MFA-ONLY', 'review_hash' => $version->submission_hash, 'summary_consistency_confirmed' => true]),
            'license publish' => app(PublishLicense::class)->handle($version, $actor),
            'exclusive prepare' => app(PrepareExclusiveOffer::class)->handle($fixture['offer'], $fixture['scope']->id, 'SYNTHETIC-MFA-ONLY', $actor),
            'exclusive activate' => app(ActivateExclusiveOffer::class)->handle($fixture['offer'], $fixture['revision']->id, $actor),
        };
    }

    private function evidence(): array
    {
        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'offers', 'offer_revisions', 'license_templates', 'license_versions', 'license_review_evidence',
                'rights_declarations', 'rights_scope_offers', 'exclusive_activations', 'audit_events']);
    }

    #[DataProvider('refusedWriters')]
    public function test_required_panel_rule_refuses_unenrolled_or_withdrawn_staff_without_any_domain_effect(string $writer, string $state): void
    {
        $fixture = $this->fixture($writer);
        $actor = $fixture['actor'];
        $actor->saveAppAuthenticationSecret($state === 'withdrawn' ? 'JBSWY3DPEHPK3PXP' : null);
        if ($state === 'withdrawn') {
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            $this->assertNotNull($actor->getAppAuthenticationSecret(), 'The caller retains an enrolled model.');
        }
        $this->assertNull(User::findOrFail($actor->id)->getAppAuthenticationSecret());
        $before = $this->evidence();
        $this->required(true);
        try {
            $this->invoke($writer, $fixture, $actor);
            $this->fail('The direct domain writer admitted staff who do not satisfy the required panel rule.');
        } catch (AuthorizationException $error) {
            $this->assertSame(StaffWriteAuthority::REFUSAL, $error->getMessage());
        } finally {
            $this->required(false);
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writers')]
    public function test_required_panel_rule_admits_the_current_enrolled_staff_row(string $writer): void
    {
        $fixture = $this->fixture($writer);
        $actor = $fixture['actor'];
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $before = DB::table('audit_events')->count();
        $this->required(true);
        try {
            $result = $this->invoke($writer, $fixture, $actor);
        } finally {
            $this->required(false);
        }
        $this->assertTrue($result->exists);
        $this->assertGreaterThan($before, DB::table('audit_events')->count());
        $this->assertSame($actor->id, DB::table('audit_events')->orderByDesc('id')->value('actor_id'));
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writers')]
    public function test_optional_testing_panel_rule_preserves_existing_unenrolled_staff_behavior(string $writer): void
    {
        $fixture = $this->fixture($writer);
        $actor = $fixture['actor'];
        $actor->saveAppAuthenticationSecret(null);
        $this->assertFalse(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired());
        $result = $this->invoke($writer, $fixture, $actor);
        $this->assertTrue($result->exists);
        $this->assertSame(0, DB::transactionLevel());
    }
}
