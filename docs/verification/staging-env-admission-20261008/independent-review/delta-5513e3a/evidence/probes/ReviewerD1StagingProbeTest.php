<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Media\MalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Independent reviewer probes for B2's D1 staging variant (not part of the branch).
 * (1) Without the ClamAV-engine stand-in, staging must refuse the test-only scan evidence (not silently admit it).
 * (2) With the stand-in, quote creation and pricing also run under staging (B2's variant runs them under testing).
 */
class ReviewerD1StagingProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        OrderFixtures::configure();
    }

    private static function standIn(): MalwareScanner
    {
        return new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'reviewer-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        };
    }

    private static function outcome(callable $operation): string
    {
        try {
            $result = $operation();

            return 'completed'.(is_object($result) ? ':'.class_basename($result) : '');
        } catch (QuoteException $error) {
            return 'QuoteException:'.$error->errorCode.':'.$error->status;
        } catch (Throwable $error) {
            return class_basename($error).':'.$error->getMessage();
        }
    }

    public function test_probe_staging_refuses_test_only_scan_evidence_in_the_d1_chain(): void
    {
        $f = QuoteFixtures::selection();
        $this->app['env'] = 'staging';
        try {
            $rows = [
                'create quote (test-only scan) staging' => self::outcome(fn () => app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items'])),
                'link (test-only scan) staging' => self::outcome(function () use ($f) {
                    $scope = app(ManageRightsScope::class)->register('probe-scope', 'probe-reference', $f['actor']);

                    return app(ManageRightsScope::class)->link($scope->id, $f['revision']->id, 'probe-link', $f['actor']);
                }),
            ];
        } finally {
            $this->app['env'] = 'testing';
        }
        fwrite(STDERR, "\nPROBE D1 without stand-in under staging: ".json_encode($rows, JSON_PRETTY_PRINT)."\n");
        $this->assertStringStartsWith('QuoteException', $rows['create quote (test-only scan) staging']);
        $this->assertStringStartsWith('QuoteException', $rows['link (test-only scan) staging']);
    }

    public function test_probe_full_d1_chain_including_quote_and_pricing_under_staging(): void
    {
        $f = QuoteFixtures::selection(scanner: self::standIn());
        $this->app['env'] = 'staging';
        try {
            $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
            app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
            $unlinked = self::outcome(fn () => app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote)));
            $scope = app(ManageRightsScope::class)->register('probe-scope', 'probe-reference', $f['actor']);
            app(ManageRightsScope::class)->link($scope->id, $f['revision']->id, 'probe-link', $f['actor']);
            $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));
            $env = app()->environment();
        } finally {
            $this->app['env'] = 'testing';
        }
        fwrite(STDERR, "\nPROBE full D1 chain under staging: unlinked=".$unlinked.' order='.($order->exists ? 'prepared' : 'absent').' env='.$env."\n");
        $this->assertSame('QuoteException:INVENTORY_SCOPE_UNAVAILABLE:409', $unlinked);
        $this->assertTrue($order->exists);
        $this->assertSame('staging', $env);
    }
}
