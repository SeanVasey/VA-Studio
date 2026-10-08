<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use ReflectionClass;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Independent review probe (not part of the suite). Question 4: delivery entitlement, authorization and limits. */
final class DeliveryProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private ProductionFreeGrantDownloads $downloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->downloads = new ProductionFreeGrantDownloads;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_every_role_requires_exact_owner_short_lived_one_use_authorization(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        $other = $this->customer('other@example.test');
        $grants = new ProductionFreeGrants;
        $otherReview = $grants->review($origin['definitionId'], 'Other Buyer', $other['principal'], $other['user']);
        $otherOrigin = $grants->accept($origin['definitionId'], $this->assentInput($otherReview), $other['principal'], $other['user']);
        (new ProductionFreeGrantDocuments)->render($otherOrigin['id']);
        $expected = ['contract' => $this->originalBytes($origin['id']),
            'master_wav' => $this->sources->bytes['synthetic-master_wav'], 'download_mp3' => $this->sources->bytes['synthetic-download_mp3'],
            'stems_zip' => $this->sources->bytes['synthetic-stems_zip']];
        foreach (ProductionFreeGrantDownloads::ROLES as $role) {
            $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $other['principal'], $other['user']), 'not_found');
            $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $owner['principal'], $other['user']), 'identity_refused');
            $authorization = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $owner['principal'], $owner['user']);
            $this->assertSame(300, CarbonImmutable::parse($authorization['expiresAt'])->getTimestamp() - CarbonImmutable::parse(DB::table('production_free_authorizations')->where('id', $authorization['id'])->value('created_at'), 'UTC')->getTimestamp());
            $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $other['principal'], $other['user']), 'not_found');
            $this->refuses(fn () => $this->downloads->redeem($authorization['id'], substr($authorization['token'], 0, 42).'A', $owner['principal'], $owner['user']), 'token_refused');
            $bytes = $this->bytes($this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']));
            $this->assertSame($expected[$role], $bytes);
            $this->assertSame($authorization['sha256'], hash('sha256', $bytes));
            $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'already_redeemed');
        }
        // Expiry: minted now, presented after 301 s.
        $late = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(301));
        $this->refuses(fn () => $this->downloads->redeem($late['id'], $late['token'], $owner['principal'], $owner['user']), 'expired');
        CarbonImmutable::setTestNow();
        // Wrong claim: a raw authorization naming the other owner's origin under this owner's account is refused by SQL.
        $row = (array) DB::table('production_free_authorizations')->where('id', $late['id'])->first();
        $this->pdoRefuses(fn () => $this->rawInsert('production_free_authorizations', [...$row, 'id' => (string) Str::uuid(), 'token_hash' => hash('sha256', 'x'), 'origin_id' => $otherOrigin['id']]));
        // A contract authorization whose artifact hash is not the published original is refused by SQL.
        $this->pdoRefuses(fn () => $this->rawInsert('production_free_authorizations', [...$row, 'id' => (string) Str::uuid(), 'token_hash' => hash('sha256', 'y'), 'role' => 'contract', 'artifact_sha256' => str_repeat('e', 64)]));
        // A raw redemption after expiry is refused by SQL.
        $this->pdoRefuses(fn () => $this->rawInsert('production_free_redemptions', ['id' => (string) Str::uuid(), 'authorization_id' => $late['id'], 'origin_id' => $origin['id'],
            'account_id' => $row['account_id'], 'user_id' => $row['user_id'], 'role' => 'master_wav', 'artifact_sha256' => $row['artifact_sha256'], 'bytes' => 10,
            'payload_ciphertext' => 'x', 'seal' => str_repeat('a', 64), 'created_at' => $row['expires_at']]));
        $this->assertSame(4, DB::table('production_free_redemptions')->count());
        // The spool holds nothing named after a redemption; the transfer exposes no path.
        $this->assertSame([], array_values(array_diff(scandir($this->privateRoot.'/delivery/production-free-spool'), ['.', '..'])));
        $properties = array_map(fn ($p) => $p->getName(), (new ReflectionClass(ProductionFreeGrantTransfer::class))->getProperties());
        sort($properties);
        $this->assertSame(['deadline', 'filename', 'mimeType', 'stream'], $properties);
        fwrite(STDERR, "PROBE delivery.matrix: 4 roles: foreign owner/actor, wrong token, replay refused; exact bytes+hash delivered once each; TTL 300 s; expired refused; raw wrong-claim, wrong-contract-hash and post-expiry redemption refused by SQL; spool empty; transfer properties ".json_encode($properties)."\n");
    }

    public function test_a_failed_consumer_consumes_the_one_use_authorization_and_parallel_transfers_are_unbounded(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        $authorization = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'stems_zip'], $owner['principal'], $owner['user']);
        $transfer = $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        try {
            $transfer->writeTo(function (): void {
                throw new \RuntimeException('client went away');
            });
        } catch (\RuntimeException) {
        }
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'already_redeemed');
        // Hold several snapshots open at once: no slot lease or free-space reserve bounds them.
        $held = [];
        foreach (['contract', 'master_wav', 'download_mp3', 'stems_zip'] as $role) {
            $a = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $owner['principal'], $owner['user']);
            $held[] = $this->downloads->redeem($a['id'], $a['token'], $owner['principal'], $owner['user']);
        }
        $deleted = 0;
        foreach (glob('/proc/self/fd/*') ?: [] as $fd) {
            $target = @readlink($fd);
            if (is_string($target) && str_contains($target, 'production-free-spool') && str_ends_with($target, '(deleted)')) {
                $deleted++;
            }
        }
        foreach ($held as $t) {
            $t->close();
        }
        $this->assertSame(4, $deleted);
        fwrite(STDERR, "PROBE delivery.consumer: consumer failure after redemption leaves authorization consumed (already_redeemed); {$deleted} unlinked spool snapshots held open concurrently with no slot/disk-budget lease\n");
    }

    public function test_one_gib_limit_is_honoured_end_to_end(): void
    {
        $big = sys_get_temp_dir().'/va-free256-gib-'.bin2hex(random_bytes(4)).'.wav';
        $handle = fopen($big, 'x+b');
        ftruncate($handle, DeliveryAssetFiles::MAX_BYTES);
        fclose($handle);
        try {
            $sha = hash_file('sha256', $big);
            $sources = new class($big) implements ProductionFreeGrantSources
            {
                public function __construct(private string $path) {}

                public function prove(array $sourceManifest, array $assets): string
                {
                    return hash('sha256', json_encode([$sourceManifest, $assets]));
                }

                public function open(array $asset)
                {
                    return fopen($this->path, 'rb');
                }
            };
            $this->app->instance(ProductionFreeGrantSources::class, $sources);
            $definitions = new ProductionFreeGrantDefinitions;
            $input = $this->definitionInput(['assets' => [['role' => 'master_wav', 'sourceId' => 'big-master', 'sha256' => $sha, 'bytes' => DeliveryAssetFiles::MAX_BYTES + 1]]]);
            $this->refuses(fn () => $definitions->propose($input, $this->staff()), 'invalid_input');
            $opened = $this->openDefinition(overrides: ['assets' => [['role' => 'master_wav', 'sourceId' => 'big-master', 'sha256' => $sha, 'bytes' => DeliveryAssetFiles::MAX_BYTES]]]);
            $owner = $this->customer();
            $grants = new ProductionFreeGrants;
            $review = $grants->review($opened['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
            $origin = $grants->accept($opened['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
            (new ProductionFreeGrantDocuments)->render($origin['id']);
            $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
            $a = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
            $started = hrtime(true);
            $transfer = $this->downloads->redeem($a['id'], $a['token'], $owner['principal'], $owner['user']);
            $hash = hash_init('sha256');
            $count = 0;
            $transfer->writeTo(function (string $chunk) use (&$hash, &$count): void {
                hash_update($hash, $chunk);
                $count += strlen($chunk);
            });
            $seconds = round((hrtime(true) - $started) / 1e9, 1);
            $this->assertSame(DeliveryAssetFiles::MAX_BYTES, $count);
            $this->assertSame($sha, hash_final($hash));
            fwrite(STDERR, "PROBE delivery.gib: MAX+1 refused at propose (invalid_input); exactly 1073741824 bytes snapshot+streamed with sha {$sha} in {$seconds}s\n");
        } finally {
            @unlink($big);
        }
    }

    private function delivered(): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];

        return [$owner, $origin, $seal];
    }

    private function originalBytes(string $originId): string
    {
        $original = (array) DB::table('production_free_originals')->where('origin_id', $originId)->first();

        return file_get_contents($this->privateRoot.'/contracts/production-free-v1/'.$originId.'/'.$original['claim_id'].'/original.pdf');
    }

    private function bytes(ProductionFreeGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    private function rawInsert(string $table, array $row): void
    {
        $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
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

    private function pdoRefuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Guard must refuse');
        } catch (PDOException) {
            $this->assertTrue(true);
        }
    }
}
