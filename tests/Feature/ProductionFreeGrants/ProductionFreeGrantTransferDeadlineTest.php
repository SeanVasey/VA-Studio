<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * A4-4: the authorization TTL is a valid-to-start deadline. The snapshot has its own bounded deadline and the client
 * stream a transfer deadline derived from the asset size and a configured minimum rate, so a slow client is not cut off
 * after the one-use token was consumed. The clock is injected; no test sleeps.
 */
final class ProductionFreeGrantTransferDeadlineTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private const MIB = 1048576;

    private array $owner;

    private array $origin;

    private string $seal;

    private int $offset = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->sources->bytes['synthetic-master_wav'] = str_repeat('W', 8 * self::MIB);
        // 8 MiB at 16 KiB/s plus the 30 s allowance is 542 s, longer than the 300 s authorization.
        config(['production-free-grants.transfer_min_bytes_per_second' => 16384]);
        $definition = $this->openDefinition();
        $this->owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $this->owner['principal'], $this->owner['user']);
        $this->origin = $grants->accept($definition['id'], $this->assentInput($review), $this->owner['principal'], $this->owner['user']);
        (new ProductionFreeGrantDocuments)->render($this->origin['id']);
        $this->seal = (new ProductionFreeGrantLibrary)->show($this->origin['id'], $this->owner['principal'], $this->owner['user'])['originSeal'];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_stream_that_outlasts_the_authorization_but_fits_the_derived_deadline_completes(): void
    {
        $received = 0;
        $this->transfer()->writeTo(function (string $chunk) use (&$received): void {
            $received += strlen($chunk);
            // 400 s elapse once: past the 300 s authorization, inside the 542 s derived deadline.
            $this->offset = 400_000_000_000;
        });
        $this->assertSame(8 * self::MIB, $received);
    }

    public function test_a_stream_that_exceeds_the_derived_deadline_is_refused_mid_stream(): void
    {
        $received = 0;
        $this->refuses(function () use (&$received): void {
            $this->transfer()->writeTo(function (string $chunk) use (&$received): void {
                $received += strlen($chunk);
                $this->offset += 300_000_000_000;
            });
        }, 'expired');
        $this->assertSame(2 * self::MIB, $received, 'Chunk 1 ends at 300 s and chunk 2 at 600 s; the check before chunk 3 is past 542 s.');
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
    }

    public function test_the_derived_deadline_is_capped_by_the_configured_maximum(): void
    {
        config(['production-free-grants.transfer_max_seconds' => 60]);
        $received = 0;
        $this->refuses(function () use (&$received): void {
            $this->transfer()->writeTo(function (string $chunk) use (&$received): void {
                $received += strlen($chunk);
                $this->offset += 61_000_000_000;
            });
        }, 'expired');
        $this->assertSame(self::MIB, $received);
    }

    public function test_redeeming_after_the_authorization_expired_is_still_refused(): void
    {
        $authorization = $this->authorize();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(301));
        $this->refuses(fn () => $this->downloads()->redeem($authorization['id'], $authorization['token'], $this->owner['principal'], $this->owner['user']), 'expired');
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
    }

    public function test_shipped_defaults_and_policy_bounds(): void
    {
        $shipped = require base_path('config/production-free-grants.php');
        $this->assertSame(300, $shipped['snapshot_seconds']);
        $this->assertSame(262144, $shipped['transfer_min_bytes_per_second']);
        $this->assertSame(30, $shipped['transfer_base_seconds']);
        $this->assertSame(7200, $shipped['transfer_max_seconds']);
        foreach ([['snapshot_seconds', 29], ['snapshot_seconds', 1801], ['transfer_min_bytes_per_second', 16383], ['transfer_base_seconds', -1],
            ['transfer_base_seconds', 601], ['transfer_max_seconds', 59], ['transfer_max_seconds', 14401], ['transfer_max_seconds', '7200']] as [$key, $value]) {
            config(['production-free-grants.'.$key => $value]);
            $this->refuses(fn () => $this->authorize(), 'changed_policy');
            config(['production-free-grants.'.$key => $shipped[$key]]);
        }
        config(['production-free-grants.transfer_min_bytes_per_second' => 16384]);
        $this->assertNotEmpty($this->authorize()['id']);
    }

    private function downloads(): ProductionFreeGrantDownloads
    {
        return new ProductionFreeGrantDownloads(fn (): int => hrtime(true) + $this->offset);
    }

    private function authorize(): array
    {
        return $this->downloads()->authorize($this->origin['id'], ['originSeal' => $this->seal, 'role' => 'master_wav'], $this->owner['principal'], $this->owner['user']);
    }

    private function transfer()
    {
        $authorization = $this->authorize();

        return $this->downloads()->redeem($authorization['id'], $authorization['token'], $this->owner['principal'], $this->owner['user']);
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
