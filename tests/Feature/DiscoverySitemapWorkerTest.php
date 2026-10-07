<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use App\Domain\Catalog\DiscoverySitemap\CandidateBuildRequest;
use App\Domain\Catalog\DiscoverySitemap\CandidateWindow;
use App\Domain\Catalog\DiscoverySitemap\DiscoverySitemapConsumption;
use App\Domain\Catalog\DiscoverySitemap\SitemapConfiguration;
use App\Domain\Catalog\DiscoverySitemap\SitemapException;
use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Http\Controllers\DiscoveryTrackSitemapController;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class DiscoverySitemapWorkerTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.url' => 'https://synthetic.example', 'discovery-sitemap.enabled' => true]);
        $this->travelTo(now()->startOfSecond());
    }

    private function generation(): array
    {
        $store = app(SitemapStore::class);
        $request = $store->newRequest();
        $start = $store->start($request);

        return [$store, $request, $start['generation']];
    }

    private function published(): array
    {
        [$store, $request, $id] = $this->generation();
        $this->assertSame('complete', $store->step($request, 1)['state']);
        $this->assertSame($id, $store->publish($request, 0));

        return [$store, $request, $id];
    }

    private function xml(SitemapStore $store, string $id, int $slot = 1): string
    {
        return app(CurrentEligibleTrackSnapshot::class)->consumeIdentities(DiscoverySitemapConsumption::prepare($store, $id, $slot));
    }

    private function refuses(callable $action): void
    {
        try {
            $action();
            $this->fail('Changed/partial authority escaped.');
        } catch (SitemapException|LogicException) {
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_empty_source_and_fixed_unused_chunks_are_truthful_and_index_is_bounded(): void
    {
        [$store, $request, $id] = $this->published();
        $this->assertSame($store->step($request, 1), $store->step($request, 1));
        $this->assertSame($id, $store->publish($request, 0));
        $this->assertSame($this->xml($store, $id), $this->xml($store, $id, 128));
        $this->assertStringNotContainsString('<url>', $this->xml($store, $id));
        $index = $store->currentIndexXml();
        $this->assertSame(129, substr_count($index, '<sitemap>'));
        $this->assertStringContainsString('/site-pages-sitemap.xml', $index);
        $this->assertStringContainsString('/128.xml', $index);
        $this->assertStringNotContainsString('windows', $index);
        $this->assertStringNotContainsString('epoch', $index);
        $this->assertStringNotContainsString('expires', $index);
        $this->assertStringNotContainsString('producer', $index);
        $this->assertLessThanOrEqual(SitemapConfiguration::INDEX_BYTES, strlen($index));
    }

    public function test_all_ineligible_candidates_still_advance_complete_cursor_without_private_titles(): void
    {
        $rows = [];
        for ($i = 0; $i < 49; $i++) {
            $rows[] = ['title' => 'PRIVATE-'.$i, 'slug' => 'fixture-'.$i, 'published_slug' => 'fixture-'.$i, 'status' => 'published'];
        }
        DB::table('tracks')->insert($rows);
        [$store, $request, $id] = $this->generation();
        $this->assertSame('generating', $store->step($request, 1)['state']);
        $this->refuses(fn () => $store->publish($request, 0));
        $this->refuses(fn () => $store->step($request, 3));
        $this->assertSame('complete', $store->step($request, 2)['state']);
        $this->assertSame(2, $store->status($request)['committed_windows']);
        $store->publish($request, 0);
        foreach ([1, 2, 3, 128] as $slot) {
            $xml = $this->xml($store, $id, $slot);
            $this->assertStringNotContainsString('<url>', $xml);
            $this->assertStringNotContainsString('PRIVATE', $xml);
            $this->assertStringNotContainsString('fixture-', $xml);
        }
        $this->assertSame(2, DB::table('discovery_sitemap_windows')->count());
    }

    public function test_partial_authenticated_candidate_factory_is_not_completeness_authority(): void
    {
        DB::table('tracks')->insert(['title' => 'Synthetic missing rights', 'slug' => 'fixture', 'published_slug' => 'fixture', 'status' => 'published']);
        [$store, $request, $id] = $this->generation();
        $epoch = (new DiscoveryEpoch)->current(DB::connection()->getPdo());
        $fake = CandidateWindow::captured(CandidateBuildRequest::forState($id, 1, 0, $epoch, SitemapConfiguration::hash()), [], false);
        $this->refuses(fn () => $store->append($request, $fake, str_repeat('0', 64)));
        $this->assertSame(0, DB::table('discovery_sitemap_windows')->count());
        $this->refuses(fn () => $store->publish($request, 0));
        $this->assertNull(DB::table('discovery_sitemap_current')->value('generation_id'));
    }

    public function test_exact_public_paths_share_eligibility_and_origin_never_comes_from_request_host(): void
    {
        $fixture = QuoteFixtures::selection();
        [$store, $request, $id] = $this->published();
        $xml = $this->xml($store, $id);
        $this->assertStringContainsString('<loc>https://synthetic.example/tracks/'.$fixture['track']->slug.'</loc>', $xml);
        $this->assertSame(1, substr_count($xml, '<url>'));
        $this->assertStringNotContainsString('storage', $xml);
        $this->assertStringNotContainsString('license', $xml);
        $this->assertStringNotContainsString('owner', $xml);
        Storage::disk('local')->delete($fixture['media']['preview_tagged']->storage_path);
        $this->assertStringNotContainsString('<url>', $this->xml($store, $id));
    }

    public function test_hydrated_property_missing_candidate_id_cannot_silently_publish_incomplete_xml(): void
    {
        QuoteFixtures::selection();
        [$store, , $id] = $this->published();
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 1);
        Track::retrieved(function (Track $track): void {
            unset($track->id);
        });
        $this->refuses(fn () => app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption));
    }

    public function test_current_epoch_configuration_and_generation_expiry_refuse_old_pointer(): void
    {
        $fixture = QuoteFixtures::selection();
        [$store, $request, $id] = $this->published();
        config(['app.url' => 'https://changed.example']);
        $this->refuses(fn () => $this->xml($store, $id));
        config(['app.url' => 'https://synthetic.example']);
        $this->travel(3600)->seconds();
        $this->refuses(fn () => $this->xml($store, $id));
        $this->refuses(fn () => $store->publish($request, 0));
        $this->travelBack();
        $fixture['track']->update(['status' => 'draft']);
        $this->refuses(fn () => $this->xml($store, $id));
        $this->refuses(fn () => $store->currentIndexXml());
    }

    public function test_clock_only_license_boundary_is_freshly_evaluated_and_cannot_slide_during_last_commit(): void
    {
        $fixture = QuoteFixtures::selection();
        $expiry = now()->addSeconds(5);
        $license = LicenseFixtures::published($fixture['actor'], content: ['effective_until' => $expiry]);
        $offer = app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $license->id], $fixture['actor']);
        app(PublishOffer::class)->handle($offer, $fixture['actor']);
        [$store, , $id] = $this->published();
        $this->assertSame(1, substr_count($this->xml($store, $id), '<url>'));
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 1);
        $commits = 0;
        Event::listen(TransactionCommitted::class, function () use (&$commits, $expiry): void {
            if (++$commits === 2) {
                $this->travelTo($expiry);
            }
        });
        $this->refuses(fn () => app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption));
        $this->assertSame(2, $commits);
        $this->assertStringNotContainsString('<url>', $this->xml($store, $id));
    }

    public function test_original_ten_second_deadline_is_not_renewed_by_final_commit(): void
    {
        QuoteFixtures::selection();
        [$store, , $id] = $this->published();
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 1);
        $commits = 0;
        Event::listen(TransactionCommitted::class, function () use (&$commits): void {
            if (++$commits === 2) {
                $this->travel(10)->seconds();
            }
        });
        $this->refuses(fn () => app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption));
        $this->assertSame(2, $commits);
    }

    public function test_actual_final_transaction_committed_epoch_withdrawal_refuses_prebuilt_xml(): void
    {
        $fixture = QuoteFixtures::selection();
        [$store, , $id] = $this->published();
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 1);
        $commits = 0;
        Event::listen(TransactionCommitted::class, function () use (&$commits, $fixture): void {
            if (++$commits === 2) {
                $fixture['track']->update(['status' => 'draft']);
            }
        });
        $this->refuses(fn () => app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption));
        $this->assertSame(2, $commits);
        $this->assertSame('draft', $fixture['track']->fresh()->status);
    }

    public function test_actual_final_transaction_committed_configuration_withdrawal_refuses_prebuilt_xml(): void
    {
        QuoteFixtures::selection();
        [$store, , $id] = $this->published();
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 1);
        $commits = 0;
        Event::listen(TransactionCommitted::class, function () use (&$commits): void {
            if (++$commits === 2) {
                config(['discovery-sitemap.enabled' => false]);
            }
        });
        $this->refuses(fn () => app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption));
        $this->assertSame(2, $commits);
    }

    public function test_actual_final_transaction_committed_pointer_replacement_refuses_prebuilt_xml(): void
    {
        QuoteFixtures::selection();
        [$store, , $id] = $this->published();
        [$second, $request, $next] = $this->generation();
        $second->step($request, 1);
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 1);
        $commits = 0;
        Event::listen(TransactionCommitted::class, function () use (&$commits, $second, $request): void {
            if (++$commits === 2) {
                $second->publish($request, 1);
            }
        });
        $this->refuses(fn () => app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption));
        $this->assertSame(3, $commits);
        $this->assertSame($next, DB::table('discovery_sitemap_current')->value('generation_id'));
    }

    public function test_unknown_commit_acknowledgement_replay_keeps_one_window_and_one_pointer_revision(): void
    {
        [$store, $request, $id] = $this->generation();
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                throw new LogicException('Synthetic unknown acknowledgement');
            }
        });
        $this->refuses(fn () => $store->step($request, 1));
        // First commit is the read/preparation boundary; no window has been acknowledged or committed yet.
        $this->assertSame(0, DB::table('discovery_sitemap_windows')->count());
        $this->assertSame('complete', $store->step($request, 1)['state']);
        $armed = true;
        $this->refuses(fn () => $store->publish($request, 0));
        $this->assertSame($id, DB::table('discovery_sitemap_current')->value('generation_id'));
        $this->assertSame($id, $store->publish($request, 0));
        $this->assertSame(1, DB::table('discovery_sitemap_windows')->count());
        $this->assertSame(1, (int) DB::table('discovery_sitemap_current')->value('revision'));
    }

    public function test_actual_window_commit_unknown_acknowledgement_and_start_replay_report_current_progress(): void
    {
        [$store, $request] = $this->generation();
        $commits = 0;
        Event::listen(TransactionCommitted::class, function () use (&$commits): void {
            if (++$commits === 4) {
                throw new LogicException('Synthetic actual window acknowledgement loss');
            }
        });
        $this->refuses(fn () => $store->step($request, 1));
        $this->assertSame(4, $commits);
        $this->assertSame(1, DB::table('discovery_sitemap_windows')->count());
        $this->assertSame('complete', $store->step($request, 1)['state']);
        $this->assertSame('complete', $store->start($request)['state']);
        $this->assertSame(1, $store->start($request)['committed_windows']);
        $this->assertSame(1, DB::table('discovery_sitemap_windows')->count());
    }

    public function test_direct_sql_pointer_certificate_corruption_refuses_all_public_xml(): void
    {
        [$store, , $id] = $this->published();
        DB::connection()->getPdo()->exec("UPDATE discovery_sitemap_current SET revision = revision + 1, certificate = 'SYNTHETIC-PARTIAL', seal = '".str_repeat('0', 64)."' WHERE id = 1");
        $this->refuses(fn () => $this->xml($store, $id));
        $this->refuses(fn () => $store->currentIndexXml());
        $this->assertSame('SYNTHETIC-PARTIAL', DB::table('discovery_sitemap_current')->value('certificate'));
    }

    public function test_exact_6144_candidates_complete_without_truncation(): void
    {
        $rows = [];
        for ($i = 0; $i < 6144; $i++) {
            $rows[] = ['title' => 'Synthetic incomplete '.$i, 'slug' => 'synthetic-'.$i, 'published_slug' => 'synthetic-'.$i, 'status' => 'published'];
            if (count($rows) === 100) {
                DB::table('tracks')->insert($rows);
                $rows = [];
            }
        }
        DB::table('tracks')->insert($rows);
        [$store, $request, $id] = $this->generation();
        for ($ordinal = 1; $ordinal <= 128; $ordinal++) {
            $result = $store->step($request, $ordinal);
        }
        $this->assertSame('complete', $result['state']);
        $this->assertSame(128, $result['committed_windows']);
        $this->assertSame($id, $store->publish($request, 0));
        $this->assertSame(6144, (int) DB::table('discovery_sitemap_windows')->where('ordinal', 128)->value('end_id'));
    }

    public function test_changed_pointer_revision_and_replaced_generation_refuse_old_consumption(): void
    {
        [$store, $request, $id] = $this->published();
        [$second, $nextRequest, $next] = $this->generation();
        $second->step($nextRequest, 1);
        $this->refuses(fn () => $second->publish($nextRequest, 0));
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 128);
        $second->publish($nextRequest, 1);
        $this->refuses(fn () => app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption));
        $this->refuses(fn () => $store->publish($request, 0));
        $this->assertSame($next, DB::table('discovery_sitemap_current')->value('generation_id'));
    }

    public function test_raw_commit_and_reopened_transaction_invalidates_same_consumption(): void
    {
        [$store, , $id] = $this->published();
        $consumption = DiscoverySitemapConsumption::prepare($store, $id, 1);
        DB::beginTransaction();
        $pdo = DB::connection()->getPdo();
        $this->assertSame([], $consumption->candidateIds($pdo));
        $pdo->commit();
        $pdo->beginTransaction();
        try {
            $consumption->assertCurrent($pdo);
            $this->fail('Raw commit/reopen retained authority.');
        } catch (SitemapException) {
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }

    public function test_unregistered_controller_refuses_actual_body_queries_bad_identifiers_and_nonproduction(): void
    {
        $this->app->instance('env', 'production');
        [$store, , $id] = $this->published();
        $controller = app(DiscoveryTrackSitemapController::class);
        $response = $controller(Request::create('https://attacker.example/path', 'GET'), $id, '1');
        $this->assertSame(200, $response->status());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        foreach ([['GET', [], ' ', $id, '1'], ['GET', ['x' => ''], '', $id, '1'], ['GET', [], '', $id, '01'], ['GET', [], '', $id, '129'], ['GET', [], '', strtoupper($id), '1'], ['POST', [], '', $id, '1'], ['GET', [], '', str_repeat('a', 32), '1']] as [$method, $query, $body, $generation, $slot]) {
            $request = Request::create('/synthetic', $method, $query, [], [], [], $body);
            $response = $controller($request, $generation, $slot);
            $this->assertSame(404, $response->status());
            $this->assertSame('', $response->getContent());
        }
        $this->app->instance('env', 'testing');
        $this->assertSame(404, $controller(Request::create('/synthetic'), $id, '1')->status());
    }

    public function test_real_private_command_keeps_capability_private_and_performs_one_bounded_operation(): void
    {
        $path = sys_get_temp_dir().'/dsm-command-'.bin2hex(random_bytes(10));
        try {
            $this->assertSame(0, Artisan::call('discovery-sitemap:build', ['--new-request' => $path]));
            $request = file_get_contents($path);
            $this->assertSame(0, fileperms($path) & 0077);
            $this->assertStringNotContainsString($request, Artisan::output());
            $this->assertSame(0, DB::table('discovery_sitemap_generations')->count());
            $this->assertSame(0, Artisan::call('discovery-sitemap:build', ['--request-file' => $path, '--start' => true]));
            $this->assertSame(1, Artisan::call('discovery-sitemap:build', ['--request-file' => $path, '--ordinal' => '01']));
            $this->assertSame(0, DB::table('discovery_sitemap_windows')->count());
            $this->assertSame(0, Artisan::call('discovery-sitemap:build', ['--request-file' => $path, '--ordinal' => '1']));
            $this->assertStringContainsString('complete', Artisan::output());
            $this->assertStringNotContainsString($request, Artisan::output());
            $this->assertSame(0, Artisan::call('discovery-sitemap:build', ['--request-file' => $path, '--publish-revision' => '0']));
            $this->assertSame(1, (int) DB::table('discovery_sitemap_current')->value('revision'));
            $this->assertSame(0, Artisan::call('discovery-sitemap:build', ['--request-file' => $path, '--status' => true]));
            $this->assertStringContainsString('complete', Artisan::output());
            $this->assertSame($request, file_get_contents($path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_6145_candidates_overflow_whole_generation_without_publication_or_truncation(): void
    {
        $rows = [];
        for ($i = 0; $i < 6145; $i++) {
            $rows[] = ['title' => 'Synthetic incomplete '.$i, 'slug' => 'synthetic-'.$i, 'published_slug' => 'synthetic-'.$i, 'status' => 'published'];
            if (count($rows) === 100) {
                DB::table('tracks')->insert($rows);
                $rows = [];
            }
        }
        DB::table('tracks')->insert($rows);
        [$store, $request] = $this->generation();
        for ($ordinal = 1; $ordinal <= 128; $ordinal++) {
            $result = $store->step($request, $ordinal);
        }
        $this->assertSame('overflow', $result['state']);
        $this->assertSame(128, $result['committed_windows']);
        $this->refuses(fn () => $store->publish($request, 0));
        $this->assertNull(DB::table('discovery_sitemap_current')->value('generation_id'));
        $this->assertSame(128, DB::table('discovery_sitemap_windows')->count());
    }
}
