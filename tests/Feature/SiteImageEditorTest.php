<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\TestCase;

class SiteImageEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        $this->actingAs($this->actor);
    }

    /** The editor's own form for the current site, as New content draft opens it. */
    private function form(): array
    {
        $content = SiteContentSchema::defaults();
        $content['studio']['paragraphs'] = array_map(fn (string $text): array => ['text' => $text], $content['studio']['paragraphs']);

        return ['label' => 'Synthetic images draft', 'content' => $content];
    }

    /** @return array<string, SiteImage> */
    private function images(): array
    {
        $images = [];
        foreach (array_keys(F::SIZES) as $slot) {
            $images[$slot] = F::ready($slot, $this->actor);
        }

        return $images;
    }

    /** A field of the open action's form, as Filament builds it for this request. */
    private function field(Testable $component, string $name): Field
    {
        $page = $component->instance();
        $field = $page->getSchema($page->getMountedActionSchemaName())->getComponent('images.'.$name);
        $this->assertInstanceOf(Field::class, $field);

        return $field;
    }

    private function helper(Testable $component, string $name): string
    {
        return (string) $this->field($component, $name)->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString();
    }

    public function test_each_image_field_offers_only_ready_images_of_its_slot_with_escaped_names(): void
    {
        $images = $this->images();
        $named = F::ready('studio', $this->actor, F::jpeg(1440, 630, ['progressive' => true]), '<img src=x onerror=alert(1)>.jpg');
        F::quarantined('studio', F::jpeg(1440, 630), 1440, 630, $this->actor);
        $component = Livewire::test(ListSiteReleases::class)->mountAction('createDraft');

        foreach (['hero_desktop', 'hero_mobile', 'studio', 'share'] as $slot) {
            $expected = $slot === 'studio' ? [$named->id, $images['studio']->id] : [$images[$slot]->id];
            $this->assertSame($expected, array_keys($this->field($component, $slot)->getOptions()), $slot);
        }
        $html = $this->field($component, 'studio')->toHtml();
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;.jpg', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }

    public function test_saving_with_images_creates_a_version_three_release_and_without_them_version_two(): void
    {
        $images = $this->images();
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: $this->form() + ['images' => [
            'hero_desktop' => (string) $images['hero_desktop']->id, 'hero_mobile' => (string) $images['hero_mobile']->id, 'hero_alt' => 'Synthetic hero',
            'studio' => (string) $images['studio']->id, 'studio_alt' => 'Synthetic studio', 'share' => (string) $images['share']->id, 'share_alt' => 'Synthetic share',
        ]])->assertHasNoActionErrors();
        $release = SiteRelease::sole();
        $this->assertSame(3, $release->schema_version);
        // The manifest is added after the form's fields; canonical hashing ignores key order.
        $this->assertEquals([
            'hero' => ['desktop' => ['id' => $images['hero_desktop']->id, 'manifest' => $images['hero_desktop']->manifest_sha256],
                'mobile' => ['id' => $images['hero_mobile']->id, 'manifest' => $images['hero_mobile']->manifest_sha256], 'alt' => 'Synthetic hero'],
            'studio' => ['id' => $images['studio']->id, 'manifest' => $images['studio']->manifest_sha256, 'alt' => 'Synthetic studio'],
            'share' => ['id' => $images['share']->id, 'manifest' => $images['share']->manifest_sha256, 'alt' => 'Synthetic share'],
        ], $release->content['images']);

        // Editing it again starts from the same images; clearing them all saves an image-free version 2 release.
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('duplicateDraft', $release);
        // A native select holds its value as text; saving casts it back to an id.
        $this->assertEquals([
            'hero_desktop' => $images['hero_desktop']->id, 'hero_mobile' => $images['hero_mobile']->id, 'hero_alt' => 'Synthetic hero',
            'studio' => $images['studio']->id, 'studio_alt' => 'Synthetic studio', 'share' => $images['share']->id, 'share_alt' => 'Synthetic share',
        ], $component->get('mountedActions.0.data.images'));
        $component->fillForm(['label' => 'Synthetic built in again', 'images' => array_fill_keys(['hero_desktop', 'hero_mobile', 'studio', 'share'], null)])
            ->callMountedAction()->assertHasNoTableActionErrors();
        $copy = SiteRelease::whereKeyNot($release->id)->sole();
        $this->assertSame(2, $copy->schema_version);
        $this->assertArrayNotHasKey('images', $copy->content);
    }

    public function test_the_hero_needs_both_images_and_every_image_needs_a_description(): void
    {
        $images = $this->images();
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: $this->form() + ['images' => [
            'hero_desktop' => (string) $images['hero_desktop']->id, 'hero_alt' => 'Synthetic hero',
        ]])->assertHasActionErrors(['images.hero_mobile']);
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: $this->form() + ['images' => [
            'hero_desktop' => (string) $images['hero_desktop']->id, 'hero_mobile' => (string) $images['hero_mobile']->id, 'hero_alt' => '   ',
            'studio' => (string) $images['studio']->id, 'share' => (string) $images['share']->id, 'share_alt' => 'Synthetic share',
        ]])->assertHasActionErrors(['images.hero_alt', 'images.studio_alt']);
        // An image of another slot, as a forged request could send, is refused beside its field.
        Livewire::test(ListSiteReleases::class)->callAction('createDraft', data: $this->form() + ['images' => [
            'studio' => (string) $images['share']->id, 'studio_alt' => 'Synthetic studio',
        ]])->assertHasActionErrors(['images.studio']);
        $this->assertDatabaseCount('site_releases', 0);
    }

    public function test_a_bright_hero_warns_that_the_heading_may_be_hard_to_read(): void
    {
        $bright = F::ready('hero_desktop', $this->actor, F::jpeg(2400, 890, ['solid' => [235, 235, 235]]));
        $dark = F::ready('hero_desktop', $this->actor, F::jpeg(2400, 890, ['solid' => [18, 18, 22]]));
        $brightMobile = F::ready('hero_mobile', $this->actor, F::jpeg(960, 890, ['solid' => [235, 235, 235]]));
        $warning = 'This image is bright where the heading sits';

        $component = Livewire::test(ListSiteReleases::class)->mountAction('createDraft');
        $this->assertStringNotContainsString($warning, $this->helper($component, 'hero_desktop'));
        $component->fillForm(['images' => ['hero_desktop' => (string) $dark->id]]);
        $this->assertStringNotContainsString($warning, $this->helper($component, 'hero_desktop'));
        $component->fillForm(['images' => ['hero_desktop' => (string) $bright->id]]);
        $this->assertStringContainsString($warning, $this->helper($component, 'hero_desktop'));
        $component->fillForm(['images' => ['hero_mobile' => (string) $brightMobile->id]]);
        $this->assertStringContainsString($warning, $this->helper($component, 'hero_mobile'));
    }

    public function test_the_released_images_render_on_the_live_site_after_publishing_from_the_editor(): void
    {
        $images = $this->images();
        $release = app(SiteContent::class)->create(array_replace(SiteEditorialFixtures::content('SYNTHETIC EDITOR IMAGES'), [
            'schema_version' => 3, 'images' => ['hero' => null, 'studio' => ['id' => $images['studio']->id, 'alt' => 'Synthetic studio'], 'share' => null],
        ]), 'From the editor', $this->actor);
        Livewire::test(ListSiteReleases::class)->callTableAction('publish', $release)->assertHasNoTableActionErrors();
        $jpeg = $images['studio']->variants()->where('format', 'jpeg')->orderByDesc('width')->firstOrFail();
        $this->get('/site-images/'.$jpeg->sha256.'.jpg')->assertOk();
    }
}
