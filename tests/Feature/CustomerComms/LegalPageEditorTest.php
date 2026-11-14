<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** The site content editor saves legal pages as private v5 drafts; leaving them out keeps the older schema. */
class LegalPageEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function formDocument(array $document): array
    {
        $document['sections'] = array_map(fn (array $section): array => [
            'heading' => $section['heading'],
            'paragraphs' => array_map(fn (string $text): array => ['text' => $text], $section['paragraphs']),
        ], $document['sections']);

        return $document;
    }

    public function test_editor_saves_included_legal_pages_as_a_private_version_five_draft_and_copies_them_exactly(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $document = LegalPageFixtures::document('EDITOR LEGAL');
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: [
            'label' => 'Legal draft', 'enabled_legal' => ['privacy' => true, 'terms' => false, 'licenses' => false],
            'content.legal.privacy' => $this->formDocument($document),
        ])->assertHasNoActionErrors();
        $release = SiteRelease::sole();
        $this->assertSame(5, $release->schema_version);
        $this->assertSame(['privacy' => $document, 'terms' => null, 'licenses' => null], $release->content['legal']);
        $this->assertSame(SiteContentSchema::NO_IMAGES, $release->content['images']);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->get('/legal/privacy')->assertNotFound();

        Livewire::test(ListSiteReleases::class)->callTableAction('duplicateDraft', $release, data: ['label' => 'Legal copy'])
            ->assertHasNoTableActionErrors();
        $copy = SiteRelease::whereKeyNot($release->id)->sole();
        $this->assertEquals($release->content, $copy->content);
    }

    public function test_leaving_every_legal_page_out_keeps_the_older_schema_without_a_legal_key(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $source = app(SiteContent::class)->create(LegalPageFixtures::content('SOURCE LEGAL', ['privacy', 'terms']), 'Source', $actor);
        Livewire::test(ListSiteReleases::class)->callTableAction('duplicateDraft', $source, data: [
            'label' => 'Without legal pages', 'enabled_legal' => ['privacy' => false, 'terms' => false, 'licenses' => false],
        ])->assertHasNoTableActionErrors();
        $copy = SiteRelease::whereKeyNot($source->id)->sole();
        $this->assertSame(2, $copy->schema_version);
        $this->assertArrayNotHasKey('legal', $copy->content);
        $this->assertArrayNotHasKey('images', $copy->content);
        $this->assertSame(5, $source->fresh()->schema_version);
    }

    public function test_editor_refuses_incomplete_legal_text_and_non_staff_cannot_save(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $document = LegalPageFixtures::document('INCOMPLETE LEGAL');
        $document['approval_reference'] = '';
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: [
            'label' => 'Missing approval', 'enabled_legal' => ['privacy' => true],
            'content.legal.privacy' => $this->formDocument($document),
        ])->assertHasActionErrors();
        $document = LegalPageFixtures::document('INCOMPLETE LEGAL');
        $document['sections'][0]['paragraphs'][0] = '<p>markup</p>';
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: [
            'label' => 'Markup', 'enabled_legal' => ['privacy' => true],
            'content.legal.privacy' => $this->formDocument($document),
        ])->assertHasActionErrors();
        $this->assertDatabaseCount('site_releases', 0);

        $this->actingAs(User::factory()->create());
        $this->get('/admin/site-releases')->assertForbidden();
        $this->assertDatabaseCount('site_releases', 0);
    }
}
