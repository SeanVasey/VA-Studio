<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\RightsDeclaration;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PublicTrackEmbedTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test']);
    }

    public function test_embed_is_script_free_minimal_escaped_and_independent_of_session_or_host(): void
    {
        $fixture = QuoteFixtures::selection();
        $title = 'Synthetic <script>private-code()</script> & "title"';
        $fixture['track']->update(['title' => $title, 'artist' => 'Synthetic <img src=x onerror=private()> artist']);
        Auth::partialMock()->shouldReceive('guard')->never();
        $response = $this->withServerVariables(['HTTP_HOST' => 'untrusted.example.test'])
            ->withUnencryptedCookie(config('session.cookie'), 'not-a-session')
            ->get($this->page($fixture).'?autoplay=1&url=https://untrusted.example.test');
        $response->assertOk();
        $this->assertHeaders($response);
        $this->assertFalse(app('request')->hasSession());
        $this->assertNotContains('web', Route::getRoutes()->getByName('embeds.show')->gatherMiddleware());
        $this->assertNotContains('web', Route::getRoutes()->getByName('embeds.preview')->gatherMiddleware());
        $html = $this->document($response->getContent());
        $this->assertSame($title, $html->evaluate('string(//h1)'));
        $this->assertSame('https://audio.example.test/tracks/'.$fixture['track']->slug, $html->evaluate('string(//a/@href)'));
        $this->assertSame('noopener noreferrer', $html->evaluate('string(//a/@rel)'));
        $this->assertSame('_blank', $html->evaluate('string(//a/@target)'));
        $this->assertSame($this->audio($fixture), $html->evaluate('string(//audio/@src)'));
        $this->assertSame('none', $html->evaluate('string(//audio/@preload)'));
        $this->assertCount(1, $html->query('//audio[@controls]'));
        $this->assertCount(0, $html->query('//script | //form | //iframe | //img | //audio[@autoplay] | //*[@onerror] | //meta[@name="csrf-token"]'));
        foreach (['licenseVersionId', 'licenseTiers', 'priceMinor', 'deliverableRoles', 'storage_path', 'media/revisions/',
            'TEST-ONLY-QUOTE-RIGHTS', 'untrusted.example.test', 'data-page=', 'orders/', 'admin/'] as $private) {
            $response->assertDontSee($private, false);
        }
    }

    public function test_preview_preserves_exact_bytes_head_and_single_range_responses_without_cookies(): void
    {
        $fixture = QuoteFixtures::selection();
        $asset = $fixture['media']['preview_tagged'];
        $bytes = Storage::disk('local')->get($asset->storage_path);
        $response = $this->get($this->audio($fixture))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertHeaders($response);
        $this->assertSame($bytes, $this->fileBytes($response));
        $this->assertSame($asset->sha256, hash('sha256', $bytes));
        $this->assertFalse(app('request')->hasSession());
        $partial = $this->get($this->audio($fixture), ['Range' => 'bytes=2-19'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-19/'.strlen($bytes))->assertHeader('Content-Length', '18');
        $this->assertHeaders($partial);
        $this->assertSame(substr($bytes, 2, 18), $this->fileBytes($partial));
        $head = $this->head($this->audio($fixture))->assertOk()->assertHeader('Content-Length', (string) strlen($bytes));
        $this->assertHeaders($head);
        $this->assertSame('', $this->fileBytes($head));
        $outside = $this->get($this->audio($fixture), ['Range' => 'bytes='.strlen($bytes).'-'.(strlen($bytes) + 9)])->assertStatus(416);
        $this->assertHeaders($outside);
        $this->assertSame('', $this->fileBytes($outside));
    }

    public function test_an_unsafe_configured_store_origin_is_not_emitted_as_a_link(): void
    {
        $fixture = QuoteFixtures::selection();
        foreach (['javascript:alert(1)', 'https://operator@example.test', 'https://example.test/?redirect=1', 'https://example.test/#fragment'] as $url) {
            config(['app.url' => $url]);
            $response = $this->get($this->page($fixture))->assertStatus(503);
            $this->assertHeaders($response);
            $this->assertSame('This preview is unavailable.', $response->getContent());
        }
    }

    public static function unavailableStates(): array
    {
        return [['withdrawn'], ['inactive_offer'], ['rights_hold'], ['missing_preview'], ['missing_artwork'], ['missing_master'], ['corrupt_preview']];
    }

    #[DataProvider('unavailableStates')]
    public function test_current_publication_and_files_are_rechecked_after_a_successful_load(string $state): void
    {
        $fixture = QuoteFixtures::selection();
        $this->get($this->page($fixture))->assertOk();
        $this->get($this->audio($fixture))->assertOk();
        if ($state === 'withdrawn') { app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']); }
        elseif ($state === 'inactive_offer') { app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']); }
        elseif ($state === 'rights_hold') {
            RightsDeclaration::create(['track_id' => $fixture['track']->id, 'provenance_reference' => 'PRIVATE-NEW-HOLD', 'sample_disclosure' => 'Private', 'status' => 'pending']);
        } else {
            $role = match ($state) { 'missing_artwork' => 'artwork', 'missing_master' => 'master_wav', default => 'preview_tagged' };
            $path = Storage::disk('local')->path($fixture['media'][$role]->storage_path);
            if ($state === 'corrupt_preview') {
                $bytes = file_get_contents($path);
                $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
                chmod($path, 0600); file_put_contents($path, $bytes); chmod($path, 0400);
                // Existing public integrity checks have a fixed 60-second cache window.
                $this->travel(61)->seconds();
            } else { unlink($path); }
        }
        $this->assertUnavailable($this->get($this->page($fixture)));
        $this->assertUnavailable($this->get($this->audio($fixture), ['Range' => 'bytes=0-9']));
        $head = $this->head($this->audio($fixture))->assertNotFound();
        $this->assertHeaders($head);
    }

    public function test_drafts_unknown_tracks_private_roles_and_cross_track_ids_have_only_generic_responses(): void
    {
        $fixture = QuoteFixtures::selection();
        $other = QuoteFixtures::selection();
        $draft = Track::create(['title' => 'PRIVATE draft', 'artist' => 'PRIVATE artist', 'slug' => 'private-draft']);
        foreach (['/embed/tracks/'.$draft->slug, '/embed/tracks/unknown', '/embed/tracks/BAD_SLUG',
            $this->page($fixture).'/preview/'.$fixture['media']['master_wav']->id,
            $this->page($fixture).'/preview/'.$fixture['media']['download_mp3']->id,
            $this->page($fixture).'/preview/'.$fixture['media']['artwork']->id,
            $this->page($fixture).'/preview/'.$other['media']['preview_tagged']->id,
            $this->page($fixture).'/preview/000'.$fixture['media']['preview_tagged']->id] as $url) {
            $this->assertUnavailable($this->get($url));
        }
        $this->assertHeaders($this->post($this->page($fixture))->assertStatus(405));
    }

    public function test_republication_uses_only_the_current_tagged_preview_and_rejects_an_old_embed_audio_url(): void
    {
        $fixture = QuoteFixtures::selection();
        $old = $this->audio($fixture);
        app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $source = MediaFixtures::source($fixture['track']->fresh(), 'master_wav', MediaFixtures::wav(1.2, 880));
        $run = app(QueueMediaProcessing::class)->handle($source, $fixture['actor']);
        app(MediaProcessor::class)->handle($run->id);
        $master = $run->outputs()->where('role', 'master_wav')->sole();
        $offer = app(SaveOfferDraft::class)->handle($fixture['offer'], ['deliverable_asset_ids' => [$master->id]], $fixture['actor']);
        app(PublishOffer::class)->handle($offer, $fixture['actor']);
        app(PublishTrack::class)->handle($fixture['track']->fresh(), $fixture['actor']);
        $new = $this->page($fixture).'/preview/'.$run->outputs()->where('role', 'preview_tagged')->sole()->id;
        $this->get($this->page($fixture))->assertOk()->assertSee($new, false);
        $this->get($new)->assertOk();
        $this->assertUnavailable($this->get($old));
    }

    public function test_sold_scope_removes_embed_and_preview_without_changing_retained_purchase_evidence(): void
    {
        ContractFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = ContractFixtures::paid($gateway);
        $this->assertSame(1, DB::table('exclusive_sales')->count());
        $this->assertSame('published', $fixture['track']->fresh()->status);
        $this->assertContains('The underlying rights scope has already been sold exclusively.', app(PublicationReadiness::class)->blockers($fixture['track']));
        $retained = ContractFixtures::retained();
        $preview = $fixture['track']->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->firstOrFail();
        $page = '/embed/tracks/'.$fixture['track']->slug;
        $this->assertUnavailable($this->get($page));
        $this->assertUnavailable($this->get($page.'/preview/'.$preview->id));
        $this->assertSame($retained, ContractFixtures::retained());
    }

    public function test_rate_limits_keep_generic_no_store_frame_policy(): void
    {
        for ($i = 0; $i < 120; $i++) { $this->get('/embed/tracks/unknown')->assertNotFound(); }
        $limited = $this->get('/embed/tracks/unknown')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertHeaders($limited);
        $this->assertSame('This preview is unavailable.', $limited->getContent());
    }

    public function test_debug_storage_failure_does_not_disclose_paths_or_database_details(): void
    {
        $fixture = QuoteFixtures::selection();
        config(['app.debug' => true]);
        Log::spy();
        $this->app->instance(VerifiedMedia::class, new class extends VerifiedMedia {
            public function path(MediaAsset $asset): ?string { throw new \RuntimeException('PRIVATE /srv/private/source DB_PASSWORD'); }
        });
        $response = $this->get($this->page($fixture))->assertStatus(503);
        $this->assertHeaders($response);
        $this->assertSame('This preview is unavailable.', $response->getContent());
        Log::shouldHaveReceived('error')->with('Public track preview failed.', ['exception_class' => \RuntimeException::class])->once();
    }

    private function assertHeaders(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Permissions-Policy', 'autoplay=(), camera=(), microphone=(), geolocation=()')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'self'; media-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors http: https:")
            ->assertHeaderMissing('X-Frame-Options')->assertHeaderMissing('Set-Cookie')->assertHeaderMissing('X-Inertia');
        $this->assertNotContains('Cookie', $response->headers->get('Vary') ? explode(', ', $response->headers->get('Vary')) : []);
    }

    private function assertUnavailable(TestResponse $response): void
    {
        $response->assertNotFound();
        $this->assertHeaders($response);
        $this->assertSame('This preview is unavailable.', $response->getContent());
    }

    private function page(array $fixture): string { return '/embed/tracks/'.$fixture['track']->slug; }
    private function audio(array $fixture): string { return $this->page($fixture).'/preview/'.$fixture['media']['preview_tagged']->id; }

    private function fileBytes(TestResponse $response): string
    {
        ob_start();
        try { $response->baseResponse->sendContent(); return ob_get_contents(); }
        finally { ob_end_clean(); }
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try { $document->loadHTML($html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }

        return new DOMXPath($document);
    }
}
