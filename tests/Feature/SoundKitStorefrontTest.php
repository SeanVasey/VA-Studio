<?php

namespace Tests\Feature;

use App\Domain\Catalog\FamilyRevisionSelection;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteSelection;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Domain\SoundKits\Products\SoundKitPurchaseBinding;
use App\Domain\SoundKits\Products\SoundKitStorefrontPolicy;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
use App\Filament\Resources\SoundKitProductResource\Pages\ManageSoundKitProducts;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

/**
 * BL-53 (FP-007): the sound-kit catalog, publication gate and default-off public pages. Every kit, title, license, price and
 * file below is synthetic test data; the license text is the same one-line synthetic fixture the other license tests use.
 * No purchase, entitlement or download exists for a kit, and the last tests pin that absence.
 */
class SoundKitStorefrontTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    private int $sequence = 0;

    private string $originalEnv = 'testing';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $this->app['env'];
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
        $this->actor = LicenseFixtures::admin();
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        // The disposable-database cleanup runs db:wipe, which asks for confirmation in a production environment.
        $this->app->instance('env', $this->originalEnv);
        parent::tearDown();
    }

    /** A private kit with a technically verified (ready) archive revision, built through the real intake and processor. */
    private function kit(bool $process = true): array
    {
        $this->sequence++;
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic private kit '.$this->sequence,
            'description' => 'No commercial license or price', 'provenance' => 'Synthetic test generator'], $this->actor);
        $upload = UploadedFile::fake()->createWithContent('samples.zip', StemsFixtures::zip([
            ['name' => 'Kick.wav'], ['name' => 'Bass.wav', 'bytes' => MediaFixtures::wav(0.35, 120)],
        ]));
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $this->actor);
        if ($process) {
            $revision = app(SoundKitProcessor::class)->handle($revision->id);
            $this->assertSame('ready', $revision->status, $revision->failure_code ?? '');
        }

        return [$draft, $revision];
    }

    /** A published, reviewed license version of the given template type. Synthetic and nonbinding. */
    private function license(string $type = 'sound-kit', bool $publish = true): LicenseVersion
    {
        $author = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING SYNTHETIC '.$type, 'slug' => 'synthetic-'.Str::uuid(), 'type' => $type]);
        $draft = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => '[SYNTHETIC] TEST LICENSE. Test evidence only.',
            'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic grant'], 'required_asset_roles' => ['master_wav']]], $author);
        if (! $publish) {
            return $draft;
        }
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($draft, $author);
        $approved = $review->approve($submitted, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC-TEST-ONLY',
            'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    private function form(SoundKitDraft $draft, ?SoundKitRevision $revision, ?LicenseVersion $license, array $over = []): array
    {
        return $over + ['sound_kit_draft_id' => $draft->id, 'title' => 'Synthetic Kit '.$draft->id, 'slug' => 'synthetic-kit-'.$draft->id,
            'summary' => 'A clearly synthetic public summary.', 'revision_public_id' => $revision?->public_id, 'license_version_id' => $license?->id, 'price_minor' => 2500];
    }

    /** A product taken as far as asked: 'saved', 'cleared', 'approved' or 'published'. */
    private function product(string $upTo = 'published', array $over = [], ?LicenseVersion $license = null): SoundKitProduct
    {
        [$draft, $revision] = $this->kit();
        $service = app(SoundKitProducts::class);
        $product = $service->save(null, $this->form($draft, $revision, $license ?? $this->license(), $over), $this->actor);
        foreach (['cleared', 'approved', 'published'] as $stage) {
            if ($upTo === 'saved') {
                break;
            }
            $product = match ($stage) {
                'cleared' => $service->clearRights($product, $product->revision, 'Synthetic clearance note', $this->actor),
                'approved' => $service->approveOffer($product, $product->revision, $this->actor),
                'published' => $service->publish($product, $product->revision, $this->actor),
            };
            if ($upTo === $stage) {
                break;
            }
        }

        return $product->refresh();
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function production(): void
    {
        $this->app->instance('env', 'production');
    }

    private function enable(): void
    {
        config(['sound-kits.storefront_enabled' => true]);
    }

    private function messages(callable $operation): array
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('The operation was accepted.');
    }

    /** Every array key anywhere in the payload, lower-cased. */
    private function keys(mixed $value): array
    {
        $keys = [];
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $keys[] = strtolower((string) $key);
                array_push($keys, ...$this->keys($child));
            }
        }

        return $keys;
    }

    private function listedSlugs(): array
    {
        return array_column($this->get('/sound-kits', $this->inertia())->assertOk()->json('props.kits'), 'slug');
    }

    public function test_the_flag_defaults_off_and_every_public_surface_is_absent_until_it_is_on(): void
    {
        $this->assertFalse(config('sound-kits.storefront_enabled'));
        $product = $this->product();
        $this->assertTrue(app(SoundKitProducts::class)->listed($product));
        $this->get('/sound-kits')->assertNotFound();
        $this->get('/sound-kits/'.$product->slug)->assertNotFound();
        $this->assertNotContains('/sound-kits', array_column(app(EditorialContent::class)->chrome(SiteContentSchema::defaults())['navigation'], 'href'));
        $this->production();
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('/sound-kits', false);
        // Only the exact boolean true enables it.
        foreach (['true', 1, '1', 'on'] as $value) {
            config(['sound-kits.storefront_enabled' => $value]);
            $this->assertFalse(app(SoundKitStorefrontPolicy::class)->enabled());
            $this->get('/sound-kits')->assertNotFound();
            $this->get('/sound-kits/'.$product->slug)->assertNotFound();
        }
    }

    public function test_a_published_kit_is_listed_with_only_staff_entered_and_counted_facts_when_the_flag_is_on(): void
    {
        $product = $this->product();
        $this->enable();

        $index = $this->get('/sound-kits', $this->inertia())->assertOk()->json('props.kits');
        $microseconds = array_sum(array_map(fn (array $member): int => $member['audio']['duration_microseconds'], $product->archiveRevision->manifest['members']));
        $this->assertSame([[
            'slug' => $product->slug, 'path' => '/sound-kits/'.$product->slug, 'title' => $product->title, 'memberCount' => 2,
            'totalDurationSeconds' => intdiv($microseconds, 1000000), 'priceMinor' => 2500, 'currency' => 'USD', 'purchasable' => false,
        ]], $index);
        $detail = $this->get('/sound-kits/'.$product->slug, $this->inertia())->assertOk()->json('props.kit');
        $this->assertSame('A clearly synthetic public summary.', $detail['summary']);
        $this->assertFalse($detail['purchasable']);
        $this->assertContains('/sound-kits', array_column($this->get('/', $this->inertia())->json('props.siteContent.navigation'), 'href'));

        // Nothing private or actionable reaches the page: no file names, hashes, storage paths, license text, grant or download target.
        $revision = $product->archiveRevision;
        $body = $this->get('/sound-kits/'.$product->slug, $this->inertia())->getContent().$this->get('/sound-kits', $this->inertia())->getContent();
        foreach ([$revision->archive_path, $revision->source_path, $revision->archive_sha256, $revision->manifest_sha256, $revision->public_id, 'Kick.wav', 'Bass.wav', 'TEST LICENSE'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
        foreach ($this->keys([$detail, $index]) as $key) {
            $this->assertDoesNotMatchRegularExpression('/hash|sha|download|checkout|cart|entitle|grant|license|members|archive|source|file|storage/', $key);
        }
        $metadata = $this->get('/sound-kits/'.$product->slug, $this->inertia())->json('props.metadata');
        $this->assertSame('https://audio.example.test/sound-kits/'.$product->slug, $metadata['canonicalUrl']);
        $this->assertSame('noindex, nofollow', $metadata['robots']);
    }

    public function test_the_sitemap_lists_visible_kits_only_in_production_and_only_while_the_pages_are_on(): void
    {
        $visible = $this->product(over: ['slug' => 'visible', 'title' => 'Visible']);
        $this->product('approved', ['slug' => 'hidden-draft']);
        $this->production();
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('sound-kits', false);
        $this->enable();
        $xml = simplexml_load_string($this->get('/site-pages-sitemap.xml')->assertOk()->getContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = array_map(fn ($loc) => (string) $loc, $xml->xpath('/s:urlset/s:url/s:loc'));
        $this->assertSame(['https://audio.example.test/', 'https://audio.example.test/sound-kits', 'https://audio.example.test/sound-kits/visible'], $urls);
        $this->assertSame('visible', $visible->slug);
    }

    public function test_unpublished_unknown_and_withdrawn_kits_are_404_and_absent_from_the_index(): void
    {
        $this->product('approved', ['slug' => 'still-a-draft']);
        $withdrawn = $this->product(over: ['slug' => 'withdrawn']);
        app(SoundKitProducts::class)->unpublish($withdrawn, $withdrawn->revision, $this->actor);
        $this->product(over: ['slug' => 'visible']);
        $this->enable();
        foreach (['still-a-draft', 'withdrawn', 'nonexistent', 'Bad_Slug'] as $slug) {
            $this->get('/sound-kits/'.$slug)->assertNotFound();
        }
        $this->assertSame(['visible'], $this->listedSlugs());
    }

    public function test_publication_refuses_every_missing_gate_with_a_field_error_and_publishes_nothing(): void
    {
        $service = app(SoundKitProducts::class);
        [$draft, $revision] = $this->kit();
        $license = $this->license();
        $product = $service->save(null, $this->form($draft, $revision, $license), $this->actor);
        // Nothing but the save: no clearance, no approval.
        $errors = $this->messages(fn () => $service->publish($product, $product->revision, $this->actor));
        $this->assertSame(['offer', 'rights'], collect(array_keys($errors))->sort()->values()->all());
        $this->assertFalse($product->refresh()->is_published);

        // No summary, no revision, no license, no price: each is its own blocker.
        $bare = $service->save(null, $this->form($this->kit()[0], null, null, ['summary' => null, 'price_minor' => null]), $this->actor);
        $blockers = $service->blockers($bare);
        foreach (['summary', 'revision_public_id', 'license_version_id', 'price_minor', 'rights', 'offer'] as $field) {
            $this->assertArrayHasKey($field, $blockers);
        }
        $this->assertFalse($service->listed($bare));
        $this->assertArrayHasKey('revision_public_id', $this->messages(fn () => $service->clearRights($bare, $bare->revision, 'x', $this->actor)));
        $this->assertArrayHasKey('license_version_id', $this->messages(fn () => $service->approveOffer($bare, $bare->revision, $this->actor)));
        $this->assertSame(0, SoundKitProduct::where('is_published', true)->count());
    }

    public function test_an_unscanned_unverified_or_foreign_archive_revision_can_never_be_chosen_or_published(): void
    {
        $service = app(SoundKitProducts::class);
        [$draft, $waiting] = $this->kit(false);
        $this->assertNotSame('ready', $waiting->status);
        $license = $this->license();
        // A revision that is not ready (no scan evidence yet) cannot be chosen.
        $this->assertArrayHasKey('revision_public_id', $this->messages(fn () => $service->save(null, $this->form($draft, $waiting, $license), $this->actor)));
        // A ready revision of ANOTHER kit cannot be chosen either.
        [, $foreign] = $this->kit();
        $this->assertArrayHasKey('revision_public_id', $this->messages(fn () => $service->save(null, $this->form($draft, $foreign, $license), $this->actor)));
        $this->assertSame(0, SoundKitProduct::count());

        // The retained scan evidence is database-protected from edits (trigger), so it cannot go stale behind the pin; the physical
        // bytes are re-verified at publication in the next test.
        $this->assertSame(0, SoundKitProduct::count());
    }

    public function test_a_replaced_or_deleted_private_archive_cannot_be_published_and_the_purchase_binding_refuses_it(): void
    {
        $service = app(SoundKitProducts::class);
        $replaced = $this->product('approved', ['slug' => 'replaced']);
        $path = Storage::disk('local')->path($replaced->archiveRevision->archive_path);
        chmod($path, 0600);
        file_put_contents($path, 'replaced archive');
        $this->assertArrayHasKey('revision_public_id', $this->messages(fn () => $service->publish($replaced, $replaced->revision, $this->actor)));

        $deleted = $this->product('approved', ['slug' => 'deleted']);
        $this->assertTrue(unlink(Storage::disk('local')->path($deleted->archiveRevision->archive_path)));
        $this->assertArrayHasKey('revision_public_id', $this->messages(fn () => $service->publish($deleted, $deleted->revision, $this->actor)));

        // A kit published while intact, then damaged: the binding for a purchase re-verifies the bytes and refuses.
        $intact = $this->product(over: ['slug' => 'intact']);
        $binding = app(SoundKitPurchaseBinding::class);
        $this->assertTrue($binding->capture($intact)['snapshot']['member_count'] === 2);
        unlink(Storage::disk('local')->path($intact->archiveRevision->archive_path));
        $this->assertArrayHasKey('revision_public_id', $this->messages(fn () => $binding->capture($intact)));
    }

    public function test_a_kit_needs_a_published_sound_kit_license_never_a_beat_license_or_an_unreviewed_draft(): void
    {
        $service = app(SoundKitProducts::class);
        [$draft, $revision] = $this->kit();
        // A beat license (any other type) refuses at save: the kit carries only a sound-kit license.
        foreach (['non-exclusive', 'exclusive', 'free'] as $type) {
            $beat = $this->license($type, publish: false);
            $this->assertArrayHasKey('license_version_id', $this->messages(fn () => $service->save(null, $this->form($draft, $revision, $beat), $this->actor)));
        }
        $this->assertSame(0, SoundKitProduct::count());

        // A kit-type license that is still a draft is accepted as a draft choice but blocks approval and publication.
        $unpublished = $this->license('sound-kit', publish: false);
        $product = $service->save(null, $this->form($draft, $revision, $unpublished), $this->actor);
        $this->assertArrayHasKey('license_version_id', $service->blockers($product));
        $product = $service->clearRights($product, $product->revision, 'Synthetic clearance note', $this->actor);
        $this->assertArrayHasKey('license_version_id', $this->messages(fn () => $service->approveOffer($product, $product->revision, $this->actor)));
        $this->assertArrayHasKey('license_version_id', $this->messages(fn () => $service->publish($product, $product->revision, $this->actor)));

        // Switching to a published kit license lets the same product through; the approval and clearance rules still apply.
        $product = $service->save($product, $this->form($draft, $revision, $this->license(), ['slug' => $product->slug, 'title' => $product->title]), $this->actor, $product->revision);
        $this->assertNotNull($product->rights_cleared_at, 'a changed license does not void rights for the same archive');
        $this->assertNull($product->offer_terms_hash);
        $product = $service->approveOffer($product, $product->revision, $this->actor);
        $this->assertTrue($service->publish($product, $product->revision, $this->actor)->is_published);
    }

    public function test_the_license_kind_of_a_published_kit_license_cannot_be_changed_behind_the_writer(): void
    {
        $product = $this->product();
        // The database refuses a template type change once a version is published, so the writer's kit-type check (repeated at every
        // read as a second line) cannot be bypassed by editing the template.
        $this->expectException(QueryException::class);
        DB::table('license_templates')->where('id', $product->licenseVersion->license_template_id)->update(['type' => 'non-exclusive']);
    }

    public function test_rights_clearance_and_offer_approval_are_bound_to_the_exact_terms(): void
    {
        $service = app(SoundKitProducts::class);
        $product = $this->product('published');
        $this->assertSame([], $service->blockers($product));

        // Revoking clearance hides a published kit at once, without unpublishing it.
        $this->enable();
        $this->assertSame([$product->slug], $this->listedSlugs());
        $revoked = $service->revokeRights($product, $product->revision, $this->actor);
        $this->assertTrue($revoked->is_published);
        $this->assertArrayHasKey('rights', $service->blockers($revoked));
        $this->assertSame([], $this->listedSlugs());
        $this->get('/sound-kits/'.$product->slug)->assertNotFound();
        $this->assertArrayHasKey('rights', $this->messages(fn () => $service->revokeRights($revoked, $revoked->revision, $this->actor)));

        // A published kit's terms cannot be changed without unpublishing: the edit would void the approval.
        $cleared = $service->clearRights($revoked, $revoked->revision, 'Synthetic clearance note', $this->actor);
        $this->assertTrue($service->listed($cleared));
        $errors = $this->messages(fn () => $service->save($cleared, $this->form($cleared->draft, $cleared->archiveRevision, $cleared->licenseVersion, ['price_minor' => 9999, 'slug' => $cleared->slug, 'title' => $cleared->title]), $this->actor, $cleared->revision));
        $this->assertArrayHasKey('offer', $errors);
        $this->assertSame(2500, $cleared->refresh()->price_minor);
        // Title and summary stay editable while published.
        $edited = $service->save($cleared, $this->form($cleared->draft, $cleared->archiveRevision, $cleared->licenseVersion, ['title' => 'Renamed', 'slug' => $cleared->slug]), $this->actor, $cleared->revision);
        $this->assertSame('Renamed', $edited->title);
        $this->assertTrue($service->listed($edited));

        // Unpublished, a price change voids the approval (and keeps the clearance); a changed archive would void both.
        $down = $service->unpublish($edited, $edited->revision, $this->actor);
        $repriced = $service->save($down, $this->form($down->draft, $down->archiveRevision, $down->licenseVersion, ['price_minor' => 3100, 'slug' => $down->slug, 'title' => $down->title]), $this->actor, $down->revision);
        $this->assertNull($repriced->offer_terms_hash);
        $this->assertNotNull($repriced->rights_cleared_at);
        $this->assertArrayHasKey('offer', $this->messages(fn () => $service->publish($repriced, $repriced->revision, $this->actor)));
        $this->assertTrue($service->publish($service->approveOffer($repriced, $repriced->revision, $this->actor), $repriced->revision + 1, $this->actor)->is_published);

        // A stale approval can never list a kit even if the voiding were skipped: the hash is compared at every read.
        $stale = SoundKitProduct::whereKey($product->id)->first();
        DB::table('sound_kit_products')->where('id', $stale->id)->update(['price_minor' => 1]);
        $this->assertArrayHasKey('offer', $service->blockers($stale->refresh()));
        $this->assertSame([], $this->listedSlugs());
    }

    public function test_the_writer_enforces_its_field_rules_and_records_one_audit_event_per_command(): void
    {
        $service = app(SoundKitProducts::class);
        [$draft, $revision] = $this->kit();
        $license = $this->license();
        $base = $this->form($draft, $revision, $license);
        foreach ([['slug', ['slug' => 'Bad Slug']], ['slug', ['slug' => 'a--b']], ['title', ['title' => '<b>x</b>']], ['title', ['title' => '']],
            ['summary', ['summary' => '<script>']], ['price_minor', ['price_minor' => 0]], ['price_minor', ['price_minor' => -5]], ['price_minor', ['price_minor' => 'abc']],
            ['price_minor', ['price_minor' => 2147483648]], ['price_minor', ['price_minor' => 12.5]], ['revision_public_id', ['revision_public_id' => 'not-a-uuid']],
            ['revision_public_id', ['revision_public_id' => (string) Str::uuid()]], ['sound_kit_draft_id', ['sound_kit_draft_id' => 999999]],
            ['license_version_id', ['license_version_id' => 999999]]] as [$field, $change]) {
            $this->assertArrayHasKey($field, $this->messages(fn () => $service->save(null, $change + $base, $this->actor)), json_encode($change));
        }
        $this->assertArrayHasKey('title', $this->messages(fn () => $service->save(null, ['title' => 'x'], $this->actor)));
        $this->assertSame(0, SoundKitProduct::count());

        $product = $service->save(null, $base, $this->actor);
        $this->assertSame(2500, $product->price_minor);
        $this->assertSame('USD', $product->currency);
        $this->assertSame($revision->manifest_sha256, $product->manifest_sha256);
        // One product per private kit, and unique web addresses.
        $this->assertArrayHasKey('sound_kit_draft_id', $this->messages(fn () => $service->save(null, ['slug' => 'other'] + $base, $this->actor)));
        [$second] = $this->kit();
        $this->assertArrayHasKey('slug', $this->messages(fn () => $service->save(null, $this->form($second, null, null, ['slug' => $base['slug']]), $this->actor)));
        // The private kit of a product cannot change; a no-op save is not a new revision or an audit event.
        $this->assertArrayHasKey('sound_kit_draft_id', $this->messages(fn () => $service->save($product, ['sound_kit_draft_id' => $second->id] + $base, $this->actor, $product->revision)));
        $this->assertSame($product->revision, $service->save($product, $base, $this->actor, $product->revision)->revision);
        $this->assertArrayHasKey('title', $this->messages(fn () => $service->save($product, ['title' => 'New'] + $base, $this->actor, $product->revision + 5)));
        $this->assertArrayHasKey('title', $this->messages(fn () => $service->publish($product, $product->revision - 1, $this->actor)));
        $this->assertArrayHasKey('rights_note', $this->messages(fn () => $service->clearRights($product, $product->revision, '<b>', $this->actor)));

        $product = $service->clearRights($product, $product->revision, 'Synthetic clearance note', $this->actor);
        $product = $service->approveOffer($product, $product->revision, $this->actor);
        $product = $service->publish($product, $product->revision, $this->actor);
        $this->assertArrayHasKey('is_published', $this->messages(fn () => $service->publish($product, $product->revision, $this->actor)));
        $product = $service->unpublish($product, $product->revision, $this->actor);
        $this->assertArrayHasKey('is_published', $this->messages(fn () => $service->unpublish($product, $product->revision, $this->actor)));
        $this->assertSame(['created', 'rights_cleared', 'offer_approved', 'published', 'unpublished'],
            DB::table('audit_events')->where('action', 'like', 'sound_kit_products.%')->orderBy('id')->pluck('action')->map(fn ($a) => substr($a, strlen('sound_kit_products.product.')))->all());
        // The clearance note itself is not copied into the audit record, only its hash.
        $this->assertStringNotContainsString('Synthetic clearance note', DB::table('audit_events')->where('action', 'like', 'sound_kit_products.%')->get()->toJson());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'sound_kit_products.%')->whereNull('actor_id')->count());
    }

    public function test_only_current_mfa_enrolled_catalog_staff_can_write(): void
    {
        $service = app(SoundKitProducts::class);
        [$draft, $revision] = $this->kit();
        $data = $this->form($draft, $revision, $this->license());
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $noMfa = LicenseFixtures::admin();
            foreach ([fn () => $service->save(null, $data, $noMfa)] as $write) {
                try {
                    $write();
                    $this->fail('An admin without MFA wrote a kit product.');
                } catch (AuthorizationException $exception) {
                    $this->assertSame('Admin multi-factor authentication is required.', $exception->getMessage());
                }
            }
            $this->assertSame(0, SoundKitProduct::count());
            $product = $service->save(null, $data, $this->actor);
            // Every other command re-reads the same authority.
            foreach ([fn () => $service->clearRights($product, $product->revision, 'x', $noMfa), fn () => $service->approveOffer($product, $product->revision, $noMfa),
                fn () => $service->publish($product, $product->revision, $noMfa), fn () => $service->unpublish($product, $product->revision, $noMfa),
                fn () => $service->revokeRights($product, $product->revision, $noMfa)] as $write) {
                try {
                    $write();
                    $this->fail('An admin without MFA changed a kit product.');
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->assertSame($product->revision, $product->fresh()->revision);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
        // A non-staff user, and an unsaved actor, are refused whatever MFA says.
        foreach ([User::factory()->create(), new User] as $outsider) {
            try {
                $service->save(null, $data + ['slug' => 'x'], $outsider);
                $this->fail('A non-admin wrote a kit product.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, SoundKitProduct::count());
    }

    public function test_the_filament_editor_runs_the_whole_lifecycle_through_the_service_and_refuses_outsiders(): void
    {
        $this->actingAs($this->actor);
        [$draft, $revision] = $this->kit();
        $license = $this->license();
        $form = ['sound_kit_draft_id' => $draft->id, 'title' => 'Synthetic Editor Kit', 'slug' => 'synthetic-editor', 'summary' => 'Synthetic summary.',
            'revision_public_id' => $revision->public_id, 'license_version_id' => $license->id, 'price_minor' => 1900];
        Livewire::test(ManageSoundKitProducts::class)->callAction('create', data: ['slug' => 'Bad Slug'] + $form)->assertHasActionErrors(['slug']);
        Livewire::test(ManageSoundKitProducts::class)->callAction('create', data: $form)->assertHasNoActionErrors();
        $product = SoundKitProduct::sole();
        $this->assertSame(1900, $product->price_minor);
        // Publishing an unready product is refused with the reasons shown, and publishes nothing.
        Livewire::test(ManageSoundKitProducts::class)->callTableAction('publish', $product)->assertNotified('Not done');
        $this->assertFalse($product->refresh()->is_published);
        Livewire::test(ManageSoundKitProducts::class)->callTableAction('clearRights', $product, data: ['revision' => $product->revision, 'rights_note' => 'Synthetic clearance note'])->assertNotified('Rights clearance recorded');
        Livewire::test(ManageSoundKitProducts::class)->callTableAction('approveOffer', $product->refresh())->assertNotified('Offer approved');
        Livewire::test(ManageSoundKitProducts::class)->callTableAction('publish', $product->refresh())->assertNotified('Kit published');
        $this->assertTrue($product->refresh()->is_published);
        Livewire::test(ManageSoundKitProducts::class)->callTableAction('unpublish', $product)->assertNotified('Kit unpublished');
        $this->assertFalse($product->refresh()->is_published);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'sound_kit_products.%')->whereNull('actor_id')->count());

        $this->actingAs(User::factory()->create());
        $this->get('/admin/sound-kit-products')->assertForbidden();
    }

    public function test_the_table_is_classified_for_retention(): void
    {
        $this->assertArrayHasKey('sound_kit_products', RetentionSchedule::NOT_CUSTOMER_DATA);
    }

    public function test_a_kit_still_cannot_be_quoted_or_bought_and_the_purchase_binding_is_only_an_unpayable_pin(): void
    {
        $product = $this->product();
        $this->enable();
        $counts = fn (): array => array_map(fn (string $table) => DB::table($table)->count(), ['quotes', 'quote_lines', 'orders', 'order_lines', 'license_grants', 'pending_entitlements', 'tracks', 'offers']);
        $before = $counts();

        // The existing checkout chain refuses a kit revision exactly as before.
        $revision = $product->archiveRevision;
        try {
            app(QuoteSelection::class)->resolveFamily(FamilyRevisionSelection::fromArray(['family' => 'wav_sample_kit', 'revisionId' => $revision->public_id, 'manifestHash' => $revision->manifest_sha256]));
            $this->fail('A kit must not enter a payable track line.');
        } catch (QuoteException $error) {
            $this->assertSame('FAMILY_LICENSE_POLICY_UNSET', $error->errorCode);
        }
        $this->assertFalse(app(SoundKitStorefrontPolicy::class)->purchasable());

        // The pin is deterministic, unpayable, carries the exact terms, and writes nothing (a replay cannot duplicate anything).
        $binding = app(SoundKitPurchaseBinding::class);
        $first = $binding->capture($product);
        $this->assertSame($first, $binding->capture($product->fresh()));
        $this->assertFalse($first['snapshot']['payable']);
        $this->assertSame(['kit_checkout_unbuilt'], $first['snapshot']['commercial_blockers']);
        $this->assertSame(app(SoundKitProducts::class)->termsHash($product), $first['snapshot']['offer_terms_hash']);
        $this->assertSame([2500, 'USD', 2], [$first['snapshot']['price_minor'], $first['snapshot']['currency'], $first['snapshot']['member_count']]);
        $this->assertSame($counts(), $before);

        // It refuses a kit that is not listed.
        $service = app(SoundKitProducts::class);
        $down = $service->unpublish($product, $product->revision, $this->actor);
        $this->assertArrayHasKey('title', $this->messages(fn () => $binding->capture($down)));

        // There is no route that sells, grants or serves a kit: only the two read-only pages exist.
        $uris = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'sound-kit') && ! str_starts_with($route->uri(), 'admin'));
        $this->assertSame(['GET|HEAD sound-kits', 'GET|HEAD sound-kits/{slug}'], $uris->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->sort()->values()->all());
        foreach (['buy', 'checkout', 'download', 'cart', 'order'] as $verb) {
            $this->get('/sound-kits/'.$product->slug.'/'.$verb)->assertNotFound();
            $this->post('/sound-kits/'.$product->slug.'/'.$verb)->assertStatus(404);
        }
    }
}
