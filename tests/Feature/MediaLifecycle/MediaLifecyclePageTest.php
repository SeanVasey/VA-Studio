<?php

namespace Tests\Feature\MediaLifecycle;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\ReadStaffMediaInventory;
use App\Filament\Pages\MediaLifecyclePage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/** FP-034 tier 1: the read-only lifecycle page is staff- and MFA-gated like the media pages and shows no private value. */
final class MediaLifecyclePageTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->withoutVite();
    }

    private function requireMfa(bool $required): bool
    {
        $panel = Filament::getPanel('admin');
        $was = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);

        return $was;
    }

    public function test_staff_with_enrolled_mfa_see_counts_and_no_private_value(): void
    {
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $track = Track::create(['title' => 'SYNTHETIC_PRIVATE_TITLE', 'slug' => 'synthetic-private-title']);
        $asset = MediaFixtures::source($track, 'artwork');
        $asset->forceFill(['original_name' => 'SYNTHETIC_PRIVATE_NAME.png'])->save();
        $was = $this->requireMfa(true);
        try {
            $this->actingAs($admin);
            $page = Livewire::test(MediaLifecyclePage::class)
                ->assertSee('Media families by state')->assertSee('Unknown (no reference found, not proven)')
                ->assertSee('Upload sessions (not yet assets)')->assertSee('Read-only.');
            $html = $page->html();
            foreach (['SYNTHETIC_PRIVATE_TITLE', 'SYNTHETIC_PRIVATE_NAME', MediaAsset::findOrFail($asset->id)->storage_path, $asset->sha256, $admin->email] as $private) {
                $this->assertStringNotContainsString($private, $html);
            }
            $this->assertMatchesRegularExpression('/data-state="unknown"><th scope="row">[^<]+<\/th><td>1<\/td>/', $html);
        } finally {
            $this->requireMfa($was);
        }
    }

    public function test_customers_staff_without_required_mfa_and_revoked_staff_are_refused(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(MediaLifecyclePage::canAccess());
        Livewire::test(MediaLifecyclePage::class)->assertForbidden();

        $admin = LicenseFixtures::admin();
        $was = $this->requireMfa(true);
        try {
            $this->actingAs($admin);
            Livewire::test(MediaLifecyclePage::class)->assertForbidden();
            try {
                app(ReadStaffMediaInventory::class)->lifecycle($admin);
                $this->fail('The domain read must re-check MFA.');
            } catch (AuthorizationException) {
                // Expected: enrollment is required.
            }
            $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $page = Livewire::test(MediaLifecyclePage::class)->assertOk();
            // Removing staff authority takes effect on the next Livewire request of an already mounted page.
            User::findOrFail($admin->id)->forceFill(['is_admin' => false])->save();
            $this->actingAs(User::findOrFail($admin->id));
            $page->call('nextPage', 0)->assertForbidden();
        } finally {
            $this->requireMfa($was);
        }
    }

    public function test_the_proof_runs_only_on_the_throttled_action_and_renders_and_paging_read_its_cache(): void
    {
        $admin = LicenseFixtures::admin();
        $this->travelTo(now()->startOfSecond());
        $track = Track::create(['title' => 'SYNTHETIC page proof', 'slug' => 'synthetic-page-proof']);
        $source = MediaFixtures::source($track, 'artwork');
        $run = app(QueueMediaProcessing::class)->handle($source, $admin);
        $this->assertSame('completed', app(MediaProcessor::class)->handle($run->id)->status);
        $this->travel(31)->days();
        $this->actingAs($admin);
        $proofQueries = 0;
        DB::listen(function ($query) use (&$proofQueries): void {
            $proofQueries += str_contains($query->sql, "ESCAPE '!'") ? 1 : 0;
        });

        $page = Livewire::test(MediaLifecyclePage::class)->assertSee('No proof has run for this age');
        $page->call('nextPage', 0)->call('$refresh');
        $this->assertSame(0, $proofQueries, 'Rendering and paging must not run the content proof.');
        $this->assertMatchesRegularExpression('/data-state="unattached"><th scope="row">[^<]+<\/th><td>0<\/td>/', $page->html());

        $page->call('runProof');
        $this->assertGreaterThan(0, $proofQueries);
        $this->assertMatchesRegularExpression('/data-state="unattached"><th scope="row">[^<]+<\/th><td>1<\/td>/', $page->html());
        $this->assertStringContainsString('data-family="'.$source->id.'"', $page->html());

        $after = $proofQueries;
        $page->call('nextPage', 0)->call('$refresh');
        $page->call('runProof')->assertNotified('Proof not run');
        $this->assertSame($after, $proofQueries, 'Paging reads the cache and a second proof inside the cooldown is refused.');
        $this->assertStringContainsString('data-family="'.$source->id.'"', $page->html());

        $this->travel(MediaLifecyclePage::PROOF_COOLDOWN_SECONDS + 1)->seconds();
        $page->call('runProof');
        $this->assertGreaterThan($after, $proofQueries);
    }

    public function test_an_invalid_age_is_a_message_not_an_error(): void
    {
        $this->actingAs(LicenseFixtures::admin());
        foreach (['abc', '0', '3651', '-1', '1e3', ''] as $age) {
            Livewire::test(MediaLifecyclePage::class)->set('olderThanDays', $age)->assertOk()
                ->assertSee('Enter a whole number of days from 1 to 3650.');
        }
        Livewire::test(MediaLifecyclePage::class)->set('olderThanDays', 'abc')->call('runProof')->assertNotified('Proof not run');
    }
}
