<?php

namespace Tests\Feature;

use App\Domain\Catalog\FamilyRevisionSelection;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteSelection;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Domain\SoundKits\Products\SoundKitPurchaseBinding;
use App\Domain\SoundKits\Products\SoundKitStorefrontPolicy;
use App\Filament\Resources\SoundKitProductResource\Pages\ManageSoundKitProducts;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SoundKitMediaFixtures;
use Tests\Support\SoundKitProductFixtures;
use Tests\TestCase;

/**
 * BL-53 (FP-007): the sound-kit catalog, publication gate and default-off public pages. Every kit, title, license, price and
 * file below is synthetic test data; the license text is the same one-line synthetic fixture the other license tests use.
 * No purchase, entitlement or download exists for a kit, and the last tests pin that absence.
 */
class SoundKitStorefrontTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use SoundKitProductFixtures;

    private User $actor;

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
        // The approved cover is a separate public asset (SoundKitMediaTest pins its shape); here it must exist and name no private field.
        $this->assertSame(['alt', 'width', 'height', 'jpeg', 'webp'], array_keys($index[0]['cover']));
        unset($index[0]['cover']);
        $microseconds = array_sum(array_map(fn (array $member): int => $member['audio']['duration_microseconds'], $product->archiveRevision->manifest['members']));
        $this->assertSame([[
            'slug' => $product->slug, 'path' => '/sound-kits/'.$product->slug, 'title' => $product->title, 'memberCount' => 2,
            'totalDurationSeconds' => intdiv($microseconds, 1000000), 'priceMinor' => 2500, 'currency' => 'USD', 'purchasable' => false,
        ]], $index);
        $detail = $this->get('/sound-kits/'.$product->slug, $this->inertia())->assertOk()->json('props.kit');
        $this->assertSame(['url', 'durationSeconds', 'waveform'], array_keys($detail['demo']));
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
        $this->assertSame(['cover', 'demo', 'offer', 'rights'], collect(array_keys($errors))->sort()->values()->all());
        $this->assertFalse($product->refresh()->is_published);

        // No summary, no revision, no license, no price: each is its own blocker.
        $bare = $service->save(null, $this->form($this->kit()[0], null, null, ['summary' => null, 'price_minor' => null]), $this->actor);
        $blockers = $service->blockers($bare);
        foreach (['summary', 'revision_public_id', 'license_version_id', 'price_minor', 'rights', 'offer', 'cover', 'demo'] as $field) {
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
        $product = SoundKitMediaFixtures::approved($service->approveOffer($product, $product->revision, $this->actor), $this->actor);
        $this->assertTrue($service->publish($product, $product->revision, $this->actor)->is_published);
    }

    public function test_a_published_kit_pointed_at_a_beat_license_behind_the_writer_is_no_longer_listed(): void
    {
        // Defence in depth for the read-time kit-type check: if a row is ever repointed at a published beat license outside the
        // writer, every public read drops the kit, even though it stays marked published.
        $this->enable();
        $product = $this->product();
        $this->assertSame([$product->slug], $this->listedSlugs());
        $beat = $this->license('non-exclusive');
        DB::table('sound_kit_products')->where('id', $product->id)->update(['license_version_id' => $beat->id]);
        $product->refresh();
        $this->assertTrue((bool) $product->is_published);
        $this->assertArrayHasKey('license_version_id', app(SoundKitProducts::class)->blockers($product));
        $this->assertSame([], $this->listedSlugs());
        $this->get('/sound-kits/'.$product->slug)->assertNotFound();
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
        $product = SoundKitMediaFixtures::approved($service->approveOffer($product, $product->revision, $this->actor), $this->actor);
        $product = $service->publish($product, $product->revision, $this->actor);
        $this->assertArrayHasKey('is_published', $this->messages(fn () => $service->publish($product, $product->revision, $this->actor)));
        $product = $service->unpublish($product, $product->revision, $this->actor);
        $this->assertArrayHasKey('is_published', $this->messages(fn () => $service->unpublish($product, $product->revision, $this->actor)));
        $this->assertSame(['created', 'rights_cleared', 'offer_approved', 'cover_chosen', 'cover_approved', 'demo_uploaded', 'demo_approved', 'published', 'unpublished'],
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
        // Without an approved cover and demo the kit still cannot be published; the reasons are shown (SoundKitMediaTest drives their own actions).
        Livewire::test(ManageSoundKitProducts::class)->callTableAction('publish', $product->refresh())->assertNotified('Not done');
        SoundKitMediaFixtures::approved($product->refresh(), $this->actor);
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
        $this->assertArrayHasKey('sound_kit_demos', RetentionSchedule::NOT_CUSTOMER_DATA);
    }

    public function test_a_kit_never_enters_the_track_quote_and_is_unpayable_while_the_test_purchase_lane_is_off(): void
    {
        $product = $this->product();
        $this->enable();
        $counts = fn (): array => array_map(fn (string $table) => DB::table($table)->count(), ['quotes', 'quote_lines', 'orders', 'order_lines', 'license_grants', 'pending_entitlements', 'tracks', 'offers', 'sound_kit_orders', 'sound_kit_grants']);
        $before = $counts();

        // The track quote refuses a kit revision exactly as before: a kit is bought only through its own lane (BL-13), never in a mixed cart.
        $revision = $product->archiveRevision;
        try {
            app(QuoteSelection::class)->resolveFamily(FamilyRevisionSelection::fromArray(['family' => 'wav_sample_kit', 'revisionId' => $revision->public_id, 'manifestHash' => $revision->manifest_sha256]));
            $this->fail('A kit must not enter a payable track line.');
        } catch (QuoteException $error) {
            $this->assertSame('FAMILY_LICENSE_POLICY_UNSET', $error->errorCode);
        }
        $this->assertFalse(app(SoundKitStorefrontPolicy::class)->purchasable());

        // While the test purchase lane is off the pin is unpayable; it is deterministic, carries the exact terms and writes nothing.
        $binding = app(SoundKitPurchaseBinding::class);
        $first = $binding->capture($product);
        $this->assertSame($first, $binding->capture($product->fresh()));
        $this->assertFalse($first['snapshot']['payable']);
        $this->assertSame(['kit_test_checkout_off'], $first['snapshot']['commercial_blockers']);
        $this->assertSame(app(SoundKitProducts::class)->termsHash($product), $first['snapshot']['offer_terms_hash']);
        $this->assertSame([2500, 'USD', 2], [$first['snapshot']['price_minor'], $first['snapshot']['currency'], $first['snapshot']['member_count']]);
        $this->assertSame($counts(), $before);

        // It refuses a kit that is not listed.
        $service = app(SoundKitProducts::class);
        $down = $service->unpublish($product, $product->revision, $this->actor);
        $this->assertArrayHasKey('title', $this->messages(fn () => $binding->capture($down)));

        // The only purchase routes are the BL-13 test lane's (SoundKitCheckoutTest: each answers 404 while the lane is closed).
        $uris = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'sound-kit') && ! str_starts_with($route->uri(), 'admin'));
        $this->assertSame(['GET|HEAD sound-kit-orders/{order}', 'GET|HEAD sound-kit-orders/{order}/status', 'GET|HEAD sound-kits', 'GET|HEAD sound-kits/{slug}',
            'GET|HEAD sound-kits/{slug}/demo/{demo}', 'POST sound-kit-orders/{order}/checkout', 'POST sound-kit-orders/{order}/checkout/reconcile',
            'POST sound-kit-orders/{order}/delivery/authorizations', 'POST sound-kit-orders/{order}/delivery/download', 'POST sound-kits/{slug}/orders'],
            $uris->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->sort()->values()->all());
        $this->post('/sound-kits/'.$product->slug.'/orders', ['licenseeName' => 'Synthetic', 'idempotencyKey' => 'k-1'])->assertNotFound();
        foreach (['buy', 'checkout', 'download', 'cart', 'order'] as $verb) {
            $this->get('/sound-kits/'.$product->slug.'/'.$verb)->assertNotFound();
            $this->post('/sound-kits/'.$product->slug.'/'.$verb)->assertStatus(404);
        }
    }
}
