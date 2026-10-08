<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Delivery\PreparedDeliveryStream;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantPolicy;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Support\FakeProductionFreeSources;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (6): a source (or snapshot) stream that returns '' while `feof()` is false must never spin. The deadline is
 * checked before every read, an empty non-EOF read is refused at once, and the spool slot and reservation are released.
 * The stalled stream ends itself after a read cap so that a regression fails instead of hanging the suite.
 */
final class ProductionFreeGrantStalledSourceTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private array $owner;

    private array $origin;

    private string $seal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $definition = $this->openDefinition();
        $this->owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $this->owner['principal'], $this->owner['user']);
        $this->origin = $grants->accept($definition['id'], $this->assentInput($review), $this->owner['principal'], $this->owner['user']);
        (new ProductionFreeGrantDocuments)->render($this->origin['id']);
        $this->seal = (new ProductionFreeGrantLibrary)->show($this->origin['id'], $this->owner['principal'], $this->owner['user'])['originSeal'];
        StalledStream::register();
        StalledStream::$reads = 0;
    }

    public function test_a_stalled_source_is_refused_at_once_and_releases_its_slot_and_reservation(): void
    {
        config(['production-free-grants.spool_slots' => 1]);
        $real = $this->sources;
        $this->app->instance(ProductionFreeGrantSources::class, $this->stalledSources());
        $started = hrtime(true);

        $this->refuses(fn () => $this->redeem(), 'artifact_unavailable');

        $this->assertLessThan(5_000_000_000, hrtime(true) - $started);
        $this->assertLessThanOrEqual(2, StalledStream::$reads, 'The stalled read must not be retried in a loop.');
        $spool = (new ProductionFreeGrantFiles)->spoolDirectory();
        $this->assertSame([], glob($spool.'/slot-*.snapshot'));
        $this->assertSame(str_repeat('0', 20), file_get_contents($spool.'/slot-0.reserve'));
        $this->assertSame(0, DB::table('production_free_redemptions')->count());

        // The single slot is free again for a healthy source.
        $this->app->instance(ProductionFreeGrantSources::class, $real);
        $this->assertSame($real->bytes['synthetic-master_wav'], $this->bytes($this->redeem()));
    }

    public function test_a_hung_blocking_source_is_cut_off_by_a_read_timeout_derived_from_the_remaining_time(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        $real = $this->sources;
        $this->app->instance(ProductionFreeGrantSources::class, new class($real, $pair[0]) implements ProductionFreeGrantSources
        {
            public function __construct(private readonly FakeProductionFreeSources $real, private $socket) {}

            public function prove(array $sourceManifest, array $assets): string
            {
                return $this->real->prove($sourceManifest, $assets);
            }

            public function open(array $asset)
            {
                return $this->socket;
            }
        });
        $policy = (new ProductionFreeGrantPolicy)->current();
        $before = ['policy' => $policy, 'target' => ['role' => 'master_wav', 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'source_id' => 'synthetic-master_wav']];
        $started = hrtime(true);

        // Nothing is ever written to the other end: an unbounded blocking read would hang here.
        $this->refuses(fn () => (new ReflectionMethod(ProductionFreeGrantDownloads::class, 'snapshot'))->invoke(new ProductionFreeGrantDownloads, $before, hrtime(true) + 1_500_000_000), 'artifact_unavailable');

        $this->assertLessThan(4_000_000_000, hrtime(true) - $started);
        fclose($pair[1]);
        $this->assertSame([], glob((new ProductionFreeGrantFiles)->spoolDirectory().'/slot-*.snapshot'));
    }

    public function test_the_deadline_is_checked_before_the_first_read(): void
    {
        $this->app->instance(ProductionFreeGrantSources::class, $this->stalledSources());
        $policy = (new ProductionFreeGrantPolicy)->current();
        $before = ['policy' => $policy, 'target' => ['role' => 'master_wav', 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'source_id' => 'synthetic-master_wav']];

        try {
            (new ReflectionMethod(ProductionFreeGrantDownloads::class, 'snapshot'))->invoke(new ProductionFreeGrantDownloads, $before, hrtime(true) - 1);
            $this->fail('An expired deadline must refuse.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('expired', $error->reason);
        }
        $this->assertSame(0, StalledStream::$reads);
        $this->assertSame([], glob((new ProductionFreeGrantFiles)->spoolDirectory().'/slot-*.snapshot'));
    }

    public function test_the_transfer_refuses_an_empty_read_before_eof_instead_of_spinning_to_the_deadline(): void
    {
        $stream = fopen('stalled://snapshot', 'rb');
        $transfer = new ProductionFreeGrantTransfer(new PreparedDeliveryStream($stream, hash('sha256', 'synthetic'), 9), 'x.bin', 'application/octet-stream', hrtime(true) + 400_000_000);
        $started = hrtime(true);

        $this->refuses(fn () => $transfer->writeTo(function (string $chunk): void {}), 'artifact_unavailable');

        $this->assertLessThan(300_000_000, hrtime(true) - $started);
        $this->assertLessThanOrEqual(2, StalledStream::$reads);
        $this->assertFalse(is_resource($stream));
    }

    private function stalledSources(): ProductionFreeGrantSources
    {
        $real = $this->sources;

        return new class($real) implements ProductionFreeGrantSources
        {
            public function __construct(private readonly FakeProductionFreeSources $real) {}

            public function prove(array $sourceManifest, array $assets): string
            {
                return $this->real->prove($sourceManifest, $assets);
            }

            public function open(array $asset)
            {
                return fopen('stalled://source', 'rb');
            }
        };
    }

    private function redeem(): ProductionFreeGrantTransfer
    {
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($this->origin['id'], ['originSeal' => $this->seal, 'role' => 'master_wav'], $this->owner['principal'], $this->owner['user']);

        return $downloads->redeem($authorization['id'], $authorization['token'], $this->owner['principal'], $this->owner['user']);
    }

    private function bytes(ProductionFreeGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
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

/** `stalled://` returns '' on every read and never reports EOF, until a safety cap that only a regression can reach. */
final class StalledStream
{
    public static int $reads = 0;

    public $context;

    public static function register(): void
    {
        if (! in_array('stalled', stream_get_wrappers(), true)) {
            stream_wrapper_register('stalled', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        self::$reads++;

        return '';
    }

    public function stream_eof(): bool
    {
        return self::$reads > 20000;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return true;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void {}
}
