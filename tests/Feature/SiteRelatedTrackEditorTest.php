<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteImageFixtures;
use Tests\Support\SiteRelatedTrackFixtures as F;
use Tests\TestCase;

class SiteRelatedTrackEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
    }

    private function form(array $ids): array
    {
        $content = F::content();
        $content['studio']['paragraphs'] = array_map(fn (string $text): array => ['text' => $text], $content['studio']['paragraphs']);
        foreach (['about', 'contact'] as $section) {
            $content[$section]['paragraphs'] = array_map(fn (string $text): array => ['text' => $text], $content[$section]['paragraphs']);
        }
        foreach ($content['blog']['entries'] as &$entry) {
            $entry['paragraphs'] = array_map(fn (string $text): array => ['text' => $text], $entry['paragraphs']);
        }
        unset($entry);
        $content['blog']['entries'][0]['related_track_ids'] = array_map(fn (mixed $id): array => ['track' => $id], $ids);
        unset($content['images']);

        return ['label' => 'Synthetic related draft', 'content' => $content, 'enabled' => array_fill_keys(['about', 'contact', 'blog', 'videos'], true)];
    }

    public function test_editor_retains_deliberate_order_native_ids_and_required_empty_fields(): void
    {
        $first = QuoteFixtures::selection();
        $second = QuoteFixtures::selection();
        $this->actingAs($first['actor']);
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: $this->form([(string) $second['track']->id, (string) $first['track']->id]))->assertHasNoActionErrors();
        $release = SiteRelease::sole();
        $this->assertSame(4, $release->schema_version);
        $this->assertSame([$second['track']->id, $first['track']->id], $release->content['blog']['entries'][0]['related_track_ids']);
        $this->assertSame([], $release->content['blog']['entries'][1]['related_track_ids']);
        $this->assertSame(SiteContentSchema::NO_IMAGES, $release->content['images']);
    }

    public function test_a_withdrawn_copy_remains_editable_and_clear_restores_v3_without_losing_its_image(): void
    {
        $fixture = QuoteFixtures::selection();
        $this->actingAs($fixture['actor']);
        $image = SiteImageFixtures::ready('studio', $fixture['actor']);
        $content = F::content([$fixture['track']->id]);
        $content['images']['studio'] = ['id' => $image->id, 'alt' => 'Retained studio'];
        $release = app(SiteContent::class)->create($content, 'V4 original', $fixture['actor']);
        $hash = $release->content_hash;
        app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('duplicateDraft', $release);
        $component->fillForm(['label' => 'Withdrawn copy'])->callMountedAction()->assertHasNoTableActionErrors();
        $copy = SiteRelease::where('label', 'Withdrawn copy')->sole();
        $this->assertEquals($release->content, $copy->content);
        $this->assertSame($release->content_hash, $copy->content_hash);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('duplicateDraft', $copy);
        $entries = $component->get('mountedActions.0.data.content.blog.entries');
        $entries[array_key_first($entries)]['related_track_ids'] = [];
        $component->fillForm(['label' => 'Cleared relations', 'content.blog.entries' => $entries])->callMountedAction()->assertHasNoTableActionErrors();
        $cleared = SiteRelease::where('label', 'Cleared relations')->sole();
        $this->assertSame(3, $cleared->schema_version);
        $this->assertArrayNotHasKey('related_track_ids', $cleared->content['blog']['entries'][0]);
        $this->assertSame($copy->content['images'], $cleared->content['images']);
        $this->assertSame($hash, $release->fresh()->content_hash);
    }

    public function test_disabled_associations_do_not_require_v4_and_empty_enabled_associations_restore_v2(): void
    {
        $fixture = QuoteFixtures::selection();
        $this->actingAs($fixture['actor']);
        $form = $this->form([(string) $fixture['track']->id]);
        $form['enabled']['blog'] = false;
        $form['content']['navigation'] = array_values(array_filter($form['content']['navigation'], fn (array $link): bool => $link['href'] !== '/blog'));
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: $form)->assertHasNoActionErrors();
        $release = SiteRelease::sole();
        $this->assertSame(2, $release->schema_version);
        $this->assertNull($release->content['blog']);
        $this->assertArrayNotHasKey('images', $release->content);
        $this->assertArrayNotHasKey('related_track_ids', $release->content['videos']['entries'][0]);
    }

    public static function malformedSelections(): array
    {
        return ['boolean' => ['boolean'], 'float' => ['float'], 'leading zero' => ['leading'], 'exponent' => ['exponent'], 'overflow' => ['overflow'], 'array payload' => ['array'], 'null selection' => ['null']];
    }

    #[DataProvider('malformedSelections')]
    public function test_actual_livewire_submission_rejects_lossy_or_noncanonical_select_values(string $kind): void
    {
        $fixture = QuoteFixtures::selection();
        $this->actingAs($fixture['actor']);
        $id = $fixture['track']->id;
        $value = match ($kind) {
            'boolean' => true,
            'float' => (float) $id,
            'leading' => '0'.$id,
            'exponent' => $id.'e0',
            'overflow' => (string) PHP_INT_MAX.'0',
            'array' => ['id' => $id],
            'null' => null,
        };
        $component = Livewire::test(ListSiteReleases::class)->mountAction('createDraft')->fillForm($this->form([(string) $id]));
        $entries = $component->get('mountedActions.0.data.content.blog.entries');
        $entry = array_key_first($entries);
        $selection = array_key_first($entries[$entry]['related_track_ids']);
        // Updates and submission share one actual Livewire request. An intermediate snapshot would JSON-encode an
        // integral float as an integer before the assertion and would fail to exercise the hostile request type.
        $component->update(calls: [['method' => 'callMountedAction', 'params' => [[]], 'path' => '']], updates: [
            'mountedActions.0.data.content.blog.entries.'.$entry.'.related_track_ids.'.$selection.'.track' => $value,
        ])->assertHasActionErrors();
        $this->assertDatabaseCount('site_releases', 0);
    }
}
