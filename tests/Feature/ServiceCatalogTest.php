<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Services\Catalog\ServiceCatalog;
use App\Domain\Services\Catalog\ServicePublications;
use App\Domain\Services\Models\ServiceDraft;
use App\Domain\Services\Models\ServicePublication;
use App\Domain\Services\Projects\ServiceProjectPolicy;
use App\Domain\Services\ServiceDrafts;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\ServicePublicationResource\Pages\ManageServicePublications;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PrivateProductDraftFixtures as Fixtures;
use Tests\TestCase;

/** BL-64 phase 1: default-off public service pages that lead to the ordinary contact inquiry and take no payment. */
class ServiceCatalogTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const HOST = 'https://audio.example.test';

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.url' => self::HOST, 'app.debug' => false]);
        app()->forgetInstance('encrypter');
        $this->withoutVite();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->operator = LicenseFixtures::admin();
        $this->operator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    }

    private function draft(string $title, array $changes = []): ServiceDraft
    {
        $command = app(ServiceDrafts::class);

        return $command->applyReviewed($command->review(null, Fixtures::payload('service', ['title' => $title] + $changes), $this->operator), $this->operator);
    }

    private function approve(ServiceDraft $draft, string $slug, ?int $minor = null, ?string $currency = null): ServicePublication
    {
        return app(ServicePublications::class)->approve(['service_draft_id' => $draft->id, 'slug' => $slug, 'price_minor' => $minor, 'currency' => $currency], $this->operator);
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function authored(string $text): array
    {
        return ['status' => 'authored', 'text' => $text, 'reason' => null];
    }

    public function test_default_off_every_service_path_is_a_plain_404_even_with_an_approved_service_and_nothing_links_to_it(): void
    {
        $this->assertFalse(config('services-projects.catalog_enabled'));
        $this->approve($this->draft('Synthetic mix review'), 'mix-review');
        $this->get(self::HOST.'/services')->assertNotFound();
        $this->get(self::HOST.'/services/mix-review')->assertNotFound();
        $this->get(self::HOST.'/services/unknown')->assertNotFound();
        $this->assertSame([], app(ServiceCatalog::class)->all());
        $this->assertNull(app(ServiceCatalog::class)->inquiryPrefill('mix-review'));
        foreach (['/', '/about', '/sitemap.xml'] as $path) {
            $this->get(self::HOST.$path)->assertDontSee('/services/mix-review', false)->assertDontSee('Request this service', false);
        }
    }

    public function test_the_private_project_journey_keeps_its_own_flag_and_path(): void
    {
        $before = [$this->get(self::HOST.'/services/projects')->status(), $this->get(self::HOST.'/services/projects/data')->status()];
        config(['services-projects.catalog_enabled' => true, 'services-projects.test_enabled' => false]);
        $this->assertSame($before, [$this->get(self::HOST.'/services/projects')->status(), $this->get(self::HOST.'/services/projects/data')->status()]);
        $this->assertNotContains(200, $before);
        $this->assertTrue(app(ServiceProjectPolicy::class)->catalogEnabled());
        $this->assertFalse(app(ServiceProjectPolicy::class)->enabled());
    }

    public function test_enabled_lists_only_approved_unwithdrawn_services_and_shows_only_staff_entered_values(): void
    {
        config(['services-projects.catalog_enabled' => true]);
        $priced = $this->approve($this->draft('Synthetic mix review', ['description' => 'Plain <b>text</b> only.',
            'scope' => $this->authored('Two stereo mixes.'), 'revisions' => $this->authored('One revision round.')]), 'mix-review', 25050, 'USD');
        $this->approve($this->draft('Synthetic quoted service'), 'quoted-service');
        $this->draft('Synthetic unapproved draft');
        $withdrawn = $this->approve($this->draft('Synthetic withdrawn service'), 'withdrawn-service', 100, 'USD');
        app(ServicePublications::class)->withdraw($withdrawn, $this->operator);

        $page = $this->get(self::HOST.'/services', $this->inertia())->assertOk();
        $services = collect($page->json('props.services'))->keyBy('slug');
        $this->assertSame(['mix-review', 'quoted-service'], $services->keys()->sort()->values()->all());
        $this->assertSame('USD 250.50', $services['mix-review']['price']);
        $this->assertSame('Quoted on request', $services['quoted-service']['price']);
        $this->assertSame('/contact?service=mix-review', $services['mix-review']['requestHref']);
        $page->assertDontSee('Synthetic unapproved draft', false)->assertDontSee('Synthetic withdrawn service', false);

        $detail = $this->get(self::HOST.'/services/mix-review', $this->inertia())->assertOk();
        $this->assertSame([['label' => 'Scope', 'text' => 'Two stereo mixes.'], ['label' => 'Revisions', 'text' => 'One revision round.']], $detail->json('props.service.sections'));
        $this->assertSame('Plain <b>text</b> only.', $detail->json('props.service.description'));
        $this->get(self::HOST.'/services/withdrawn-service')->assertNotFound();
        $this->get(self::HOST.'/services/Mix-Review')->assertNotFound();
        $this->assertNotNull($priced->id);
    }

    public function test_a_service_without_a_price_says_quoted_on_request_and_no_price_is_ever_derived(): void
    {
        config(['services-projects.catalog_enabled' => true]);
        $this->approve($this->draft('Synthetic deposit text', ['deposit' => $this->authored('A deposit may be requested.')]), 'deposit-text');
        $detail = $this->get(self::HOST.'/services/deposit-text', $this->inertia())->assertOk();
        $this->assertSame('Quoted on request', $detail->json('props.service.price'));
        $this->assertSame('Deposit', $detail->json('props.service.sections.0.label'));
        $this->assertSame('Quoted on request', ServiceCatalog::price(null, null));
        $this->assertSame('Quoted on request', ServiceCatalog::price(500, null));
        $this->assertSame('Quoted on request', ServiceCatalog::price(500, 'JPY'));
        $this->assertSame('USD 1,234.05', ServiceCatalog::price(123405, 'USD'));
    }

    public function test_a_newer_draft_version_is_not_published_until_staff_approve_it_again(): void
    {
        config(['services-projects.catalog_enabled' => true]);
        $draft = $this->draft('Synthetic first title');
        $this->approve($draft, 'pinned');
        $command = app(ServiceDrafts::class);
        $command->applyReviewed($command->review($draft->fresh(), Fixtures::payload('service', ['title' => 'Synthetic second title']), $this->operator), $this->operator);
        $this->assertSame('Synthetic first title', $this->get(self::HOST.'/services/pinned', $this->inertia())->json('props.service.title'));
        $this->approve($draft->fresh(), 'pinned');
        $this->assertSame('Synthetic second title', $this->get(self::HOST.'/services/pinned', $this->inertia())->json('props.service.title'));
        $this->assertSame(2, AuditEvent::where('action', 'services.publication.approved')->count());
    }

    public function test_a_pinned_version_that_fails_verification_is_never_shown(): void
    {
        config(['services-projects.catalog_enabled' => true]);
        $publication = $this->approve($this->draft('Synthetic tamper check'), 'tamper');
        DB::table('service_publications')->where('id', $publication->id)->update(['manifest_sha256' => str_repeat('a', 64)]);
        $this->get(self::HOST.'/services/tamper')->assertNotFound();
        $this->assertSame([], app(ServiceCatalog::class)->all());
        DB::table('service_publications')->where('id', $publication->id)->update(['manifest_sha256' => $publication->manifest_sha256]);
        $this->get(self::HOST.'/services/tamper')->assertOk();
        $this->assertThrows(fn () => DB::table('service_draft_versions')->where('draft_id', $publication->service_draft_id)->update(['manifest_sha256' => str_repeat('b', 64)]));
    }

    public function test_request_button_prefills_the_ordinary_contact_inquiry_which_stays_a_plain_inquiry_with_no_payment(): void
    {
        config(['services-projects.catalog_enabled' => true, 'inquiries.enabled' => true,
            'inquiries.privacy_notice' => 'SYNTHETIC TEST NOTICE: inquiry details are retained for a response.',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-RETENTION-TEST-ONLY', 'inquiries.operator_user_id' => $this->operator->id]);
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['contact'] = ['title' => 'Synthetic contact', 'description' => 'Synthetic inquiry fixture.', 'paragraphs' => ['Development records only.'], 'email' => 'operator@example.test'];
        $release = app(SiteContent::class)->create($content, 'Synthetic contact fixture', $this->operator);
        app(SiteContent::class)->publish($release->id, SitePublication::findOrFail(1)->revision, $this->operator);
        $this->approve($this->draft('Synthetic mix review'), 'mix-review', 25000, 'USD');

        $this->get(self::HOST.'/contact?service=mix-review', $this->inertia())->assertOk()
            ->assertJsonPath('props.contactServiceRequest.title', 'Synthetic mix review')
            ->assertJsonPath('props.contactServiceRequest.subject', 'Service request')
            ->assertJsonPath('props.contactServiceRequest.message', "I would like to request this service: Synthetic mix review\n\n");
        foreach (['/contact', '/contact?service=unknown', '/contact?service[]=mix-review', '/contact?service='.str_repeat('a', 500)] as $path) {
            $this->get(self::HOST.$path, $this->inertia())->assertOk()->assertJsonPath('props.contactServiceRequest', null);
        }

        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf]);
        $token = app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current())['noticeToken'];
        $body = ['name' => 'Synthetic Buyer', 'email' => 'synthetic-buyer@example.test', 'subject' => 'Service request',
            'message' => "I would like to request this service: Synthetic mix review\n\nSynthetic details.", 'website' => '',
            'requestKey' => (string) Str::uuid(), 'noticeToken' => $token];
        $this->call('POST', self::HOST.'/contact/inquiries', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ORIGIN' => self::HOST, 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'REMOTE_ADDR' => '198.51.100.7'],
            json_encode($body, JSON_THROW_ON_ERROR))->assertCreated();
        $this->assertSame(1, CustomerInquiry::count());
        foreach (['orders', 'payment_observations', 'verified_payments', 'service_projects', 'service_project_events', 'pending_entitlements'] as $table) {
            Schema::hasTable($table) && $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_approval_is_staff_only_validated_audited_and_idempotent(): void
    {
        $draft = $this->draft('Synthetic mix review');
        $service = app(ServicePublications::class);
        $customer = User::factory()->create();
        try {
            $service->approve(['service_draft_id' => $draft->id, 'slug' => 'x', 'price_minor' => null, 'currency' => null], $customer);
            $this->fail('A non-staff user must be refused.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('service_publications', 0);
        }
        $bad = [['slug' => 'Bad Slug'], ['slug' => 'projects'], ['slug' => str_repeat('a', 81)], ['slug' => 'ok', 'price_minor' => 500],
            ['slug' => 'ok', 'currency' => 'USD'], ['slug' => 'ok', 'price_minor' => 0, 'currency' => 'USD'], ['slug' => 'ok', 'price_minor' => -5, 'currency' => 'USD'],
            ['slug' => 'ok', 'price_minor' => '500', 'currency' => 'USD'], ['slug' => 'ok', 'price_minor' => 500, 'currency' => 'JPY'], ['slug' => 'ok', 'service_draft_id' => 9999]];
        foreach ($bad as $changes) {
            try {
                $service->approve($changes + ['service_draft_id' => $draft->id, 'price_minor' => null, 'currency' => null], $this->operator);
                $this->fail('Invalid input must be refused: '.json_encode($changes));
            } catch (ValidationException) {
                $this->assertDatabaseCount('service_publications', 0);
            }
        }
        $first = $this->approve($draft, 'mix-review', 500, 'USD');
        $audits = AuditEvent::where('action', 'services.publication.approved')->count();
        $again = $this->approve($draft, 'mix-review', 500, 'USD');
        $this->assertSame($first->id, $again->id);
        $this->assertSame($audits, AuditEvent::where('action', 'services.publication.approved')->count(), 'An unchanged approval writes nothing.');
        $other = $this->draft('Synthetic other');
        try {
            $this->approve($other, 'mix-review');
            $this->fail('A slug is unique.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('service_publications', 1);
        }
        $withdrawn = $service->withdraw($first, $this->operator);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->assertSame(1, AuditEvent::where('action', 'services.publication.withdrawn')->count());
        $this->assertNull($this->approve($draft, 'mix-review', 500, 'USD')->withdrawn_at, 'Approving again restores a withdrawn page.');
        $this->expectException(ValidationException::class);
        $publication = ServicePublication::sole();
        $publication->delete();
    }

    public function test_staff_screen_approves_withdraws_and_refuses_non_staff(): void
    {
        $draft = $this->draft('Synthetic mix review');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->operator);
        Livewire::test(ManageServicePublications::class)->mountAction('create')
            ->setActionData(['service_draft_id' => $draft->id, 'slug' => 'mix-review', 'price_minor' => '', 'currency' => ''])
            ->callMountedAction()->assertHasNoActionErrors();
        $publication = ServicePublication::sole();
        $this->assertNull($publication->price_minor);
        Livewire::test(ManageServicePublications::class)->mountAction('create')
            ->setActionData(['service_draft_id' => $draft->id, 'slug' => 'projects', 'price_minor' => '', 'currency' => ''])
            ->callMountedAction()->assertHasActionErrors(['slug']);
        Livewire::test(ManageServicePublications::class)->callTableAction('withdraw', $publication)->assertHasNoTableActionErrors();
        $this->assertNotNull($publication->fresh()->withdrawn_at);
        $this->actingAs(User::factory()->create());
        Livewire::test(ManageServicePublications::class)->assertForbidden();
    }
}
