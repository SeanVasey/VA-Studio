<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SiteContentHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
    }

    private function release(string $marker, ?User $actor = null): SiteRelease
    {
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = $marker.' HERO';
        $content['studio']['lead'] = $marker.' STUDIO';
        $content['footer']['description'] = $marker.' FOOTER';
        $content['navigation'] = [['label' => $marker.' CATALOG', 'href' => '/#catalog']];
        $content['seo'] = ['title' => $marker.' TITLE', 'description' => $marker.' DESCRIPTION'];

        return app(SiteContent::class)->create($content, $marker.' INTERNAL LABEL', $actor ?? LicenseFixtures::admin());
    }

    private function previewUrl(SiteRelease $release): string
    {
        return route('filament.admin.site-releases.preview', $release);
    }

    private function assertPrivatePreview(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_private_drafts_never_appear_in_public_content_even_with_preview_query_parameters(): void
    {
        $release = $this->release('UNPUBLISHED SYNTHETIC');
        foreach (['/', '/?release='.$release->id, '/?preview='.$release->id, '/?site_release_id='.$release->id, '/api/catalog'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('UNPUBLISHED SYNTHETIC', false)
                ->assertDontSee($release->content_hash, false)->assertDontSee($release->label, false);
        }
        $this->get('/', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.siteContent.hero.title', 'SOUND')
            ->assertJsonPath('props.metadata.title', 'VASEY.AUDIO — Sound with intent');
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }

    public function test_preview_uses_exact_draft_without_publication_or_commerce_and_has_private_response_headers(): void
    {
        $actor = LicenseFixtures::admin();
        $active = $this->release('PUBLIC SYNTHETIC', $actor);
        app(SiteContent::class)->publish($active->id, 0, $actor);
        $draft = $this->release('PRIVATE SYNTHETIC', $actor);
        $audits = AuditEvent::count();
        $this->actingAs($actor);
        foreach ([[], ['X-Inertia' => 'true']] as $headers) {
            $response = $this->get($this->previewUrl($draft), $headers)->assertOk();
            $this->assertPrivatePreview($response);
            $response->assertDontSee($draft->content_hash, false)->assertDontSee($draft->label, false);
            if ($headers !== []) {
                $this->assertEquals($draft->content, $response->json('props.siteContent'));
                $response->assertJsonPath('props.sitePreview', true)
                    ->assertJsonPath('props.commerceEnabled', false)
                    ->assertJsonPath('props.testOrderPreparationEnabled', false)
                    ->assertJsonPath('props.testCheckoutEnabled', false)
                    ->assertJsonPath('props.metadata.robots', 'noindex, nofollow');
            }
        }
        $public = $this->get('/', ['X-Inertia' => 'true'])->assertOk();
        $this->assertEquals($active->content, $public->json('props.siteContent'));
        $public->assertDontSee('PRIVATE SYNTHETIC', false);
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 2);
        $this->assertSame($audits, AuditEvent::count());
    }

    public function test_preview_and_editor_reject_guests_customers_and_unverified_staff(): void
    {
        $draft = $this->release('PRIVATE ACCESS');
        $urls = [$this->previewUrl($draft), SiteReleaseResource::getUrl()];
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/admin/login')->assertDontSee('PRIVATE ACCESS', false);
        }
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        foreach ([User::factory()->create(), $unverified] as $user) {
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden()->assertDontSee('PRIVATE ACCESS', false);
            }
        }
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
    }

    public static function revokedPrivileges(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null]];
    }

    #[DataProvider('revokedPrivileges')]
    public function test_preview_rechecks_persisted_privileges_for_a_stale_authenticated_user(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->release('REVOKED PRIVATE', $actor);
        $this->actingAs($actor);
        User::whereKey($actor->id)->update([$field => $value]);
        // The in-memory authenticated instance still has the old staff and verification values.
        $this->assertTrue($actor->is_admin);
        $this->assertNotNull($actor->email_verified_at);
        $this->get($this->previewUrl($draft))->assertForbidden()->assertDontSee('REVOKED PRIVATE', false);
        $this->get(SiteReleaseResource::getUrl())->assertForbidden()->assertDontSee('REVOKED PRIVATE', false);
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }

    public function test_preview_and_editor_obey_the_required_panel_mfa_enrollment_gate(): void
    {
        $draft = $this->release('MFA PRIVATE');
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        Route::get('/admin/synthetic-site-mfa', fn () => 'Synthetic setup destination')
            ->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs(LicenseFixtures::admin());
        foreach ([$this->previewUrl($draft), SiteReleaseResource::getUrl()] as $url) {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($url));
            $route->middleware(Dashboard::getRouteMiddleware($panel));
            $this->get($url)->assertRedirect('/admin/synthetic-site-mfa')->assertDontSee('MFA PRIVATE', false);
        }
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }

    public function test_publication_and_rollback_switch_whole_public_snapshots_while_track_metadata_stays_track_specific(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $first = $this->release('FIRST PUBLIC', $actor);
        $second = $this->release('SECOND PUBLIC', $actor);
        $selection = QuoteFixtures::selection();
        $trackUrl = '/tracks/'.$selection['track']->slug;
        $originalTrackMetadata = $this->get($trackUrl, ['X-Inertia' => 'true'])->assertOk()->json('props.metadata');
        foreach ([['publish', $first], ['publish', $second], ['rollback', $first]] as $index => [$operation, $release]) {
            $site->{$operation}($release->id, $index, $actor);
            $home = $this->get('/', ['X-Inertia' => 'true'])->assertOk();
            $this->assertEquals($release->content, $home->json('props.siteContent'));
            $home->assertJsonPath('props.metadata.title', $release->content['seo']['title'])
                ->assertJsonPath('props.metadata.description', $release->content['seo']['description'])
                ->assertJsonPath('props.metadata.canonicalUrl', 'https://audio.example.test/');
            $this->get('/')->assertOk()->assertSee('<title data-inertia="title">'.$release->content['seo']['title'].'</title>', false);
            $track = $this->get($trackUrl, ['X-Inertia' => 'true'])->assertOk();
            $this->assertEquals($release->content, $track->json('props.siteContent'));
            $this->assertSame($originalTrackMetadata, $track->json('props.metadata'));
            $this->assertSame($selection['revision']->id, $selection['offer']->fresh()->current_revision_id);
        }
        $this->assertDatabaseCount('site_publication_revisions', 4);
    }

    public function test_editor_creates_and_duplicates_private_drafts_then_explicitly_publishes_and_restores(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = 'SYNTHETIC EDITOR ONE';
        $formContent = $content;
        $formContent['studio']['paragraphs'] = array_map(fn (string $text) => ['text' => $text], $content['studio']['paragraphs']);
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: ['label' => 'Synthetic editor one', 'content' => $formContent])
            ->assertHasNoActionErrors();
        $first = SiteRelease::sole();
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertEquals($content, $first->content);
        $content['hero']['title'] = 'SYNTHETIC EDITOR TWO';
        $formContent['hero']['title'] = $content['hero']['title'];
        Livewire::test(ListSiteReleases::class)->callTableAction('duplicateDraft', $first, data: ['label' => 'Synthetic editor two', 'content' => $formContent])
            ->assertHasNoTableActionErrors();
        $second = SiteRelease::whereKeyNot($first->id)->sole();
        $this->assertSame('SYNTHETIC EDITOR ONE', $first->fresh()->content['hero']['title']);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        foreach ([['publish', $first], ['publish', $second], ['rollback', $first]] as [$operation, $release]) {
            Livewire::test(ListSiteReleases::class)->callTableAction($operation, $release)->assertHasNoTableActionErrors();
            $this->assertSame($release->id, SitePublication::findOrFail(1)->active_release_id);
        }
        $this->assertDatabaseCount('site_releases', 3);
        $this->assertDatabaseCount('site_publication_revisions', 4);
    }

    public static function publicationActions(): array
    {
        return [['publish'], ['rollback']];
    }

    #[DataProvider('publicationActions')]
    public function test_open_confirmation_retains_its_revision_and_cannot_overwrite_another_publication(string $operation): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $target = $this->release('STALE TARGET', $actor);
        $previous = $this->release('CURRENT BEFORE', $actor);
        $winner = $this->release('CONCURRENT WINNER', $other);
        if ($operation === 'rollback') {
            $site->publish($target->id, 0, $actor);
            $site->publish($previous->id, 1, $actor);
        }
        $expected = SitePublication::findOrFail(1)->revision;
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction($operation, $target)
            ->assertSet('expectedPublicationRevision', $expected);
        $site->publish($winner->id, $expected, $other);
        $audits = AuditEvent::count();
        $component->callMountedAction();
        $this->assertSame($expected + 1, SitePublication::findOrFail(1)->revision);
        $this->assertSame($winner->id, SitePublication::findOrFail(1)->active_release_id);
        $this->assertSame($audits, AuditEvent::count());
        $this->assertDatabaseCount('site_publication_revisions', $expected + 2);
    }

    #[DataProvider('revokedPrivileges')]
    public function test_mounted_editor_action_rechecks_fresh_privileges_before_writing(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->release('MOUNTED PRIVATE', $actor);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('publish', $draft);
        User::whereKey($actor->id)->update([$field => $value]);
        $component->callMountedAction()->assertForbidden();
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
    }

    public function test_reactive_editor_actions_recheck_mfa_when_policy_becomes_required(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->release('MFA REACTIVE', $actor);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('publish', $draft);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $component->callMountedAction()->assertForbidden();
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }
}
