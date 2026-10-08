<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeProductionFreeSources;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Independent review probe (not part of the suite). Two processes over one file-backed SQLite database:
 * `prepare` creates a granted origin (one rendered, one not) with the shipped renderer; the reviewer then edits
 * renderer files on disk; `observe` runs in a NEW process (fresh class constants) and records what an existing
 * owner can still do. Run with VA_REVIEW_STATE=<json path> and DB_DATABASE=<sqlite file>.
 */
final class UpgradeProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_prepare(): void
    {
        $this->freeSetup();
        $definition = $this->openDefinition();
        $grants = new ProductionFreeGrants;
        $state = ['definition' => $definition['id']];
        foreach (['rendered' => 'upgrade-rendered@example.test', 'pending' => 'upgrade-pending@example.test'] as $kind => $email) {
            $owner = $this->customer($email);
            $origin = $grants->accept($definition['id'], $this->assentInput($grants->review($definition['id'], 'Upgrade Buyer', $owner['principal'], $owner['user'])), $owner['principal'], $owner['user']);
            if ($kind === 'rendered') {
                (new ProductionFreeGrantDocuments)->render($origin['id']);
            }
            $state[$kind] = ['origin' => $origin['id'], 'email' => $email];
        }
        file_put_contents((string) getenv('VA_REVIEW_STATE'), json_encode($state));
        $this->assertSame(2, DB::table('production_free_origins')->count());
    }

    public function test_observe(): void
    {
        $state = json_decode((string) file_get_contents((string) getenv('VA_REVIEW_STATE')), true);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('F', 32))]);
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
        $root = sys_get_temp_dir().'/va-free256-upgrade-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        config(['filesystems.disks.local.root' => realpath($root), 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private',
            'production-free-grants.enabled' => true, 'production-free-grants.provenance' => 'synthetic_rehearsal',
            'production-free-grants.approved_terms_hashes' => [hash('sha256', self::SYNTHETIC_TERMS)]]);
        $this->app->instance(ProductionFreeGrantSources::class, new FakeProductionFreeSources);
        $observed = [];
        foreach (['rendered', 'pending'] as $kind) {
            $verified = (new ProductionCustomerSessions)->authenticate($state[$kind]['email'], 'MailboxPassword123');
            $this->assertNotNull($verified);
            [$principal, $user] = [$verified['principal'], $verified['user']];
            $originId = $state[$kind]['origin'];
            $observed[$kind.'.library.show'] = $this->attempt(fn () => (new ProductionFreeGrantLibrary)->show($originId, $principal, $user)['documentStatus']);
            $observed[$kind.'.library.index'] = $this->attempt(fn () => (string) (new ProductionFreeGrantLibrary)->index($principal, $user)['total']);
            $seal = DB::table('production_free_origins')->where('id', $originId)->value('seal');
            $observed[$kind.'.authorize.master_wav'] = $this->attempt(fn () => (new ProductionFreeGrantDownloads)->authorize($originId, ['originSeal' => $seal, 'role' => 'master_wav'], $principal, $user)['role']);
            if ($kind === 'pending') {
                $observed[$kind.'.render'] = $this->attempt(fn () => (new ProductionFreeGrantDocuments)->render($originId)['documentStatus']);
                $observed[$kind.'.work'] = implode(',', DB::table('production_free_document_work')->where('origin_id', $originId)->orderBy('ordinal')->pluck('kind')->all());
            }
        }
        @rmdir($root.'/delivery/production-free-spool');
        @rmdir($root.'/delivery');
        foreach (array_reverse(glob($root.'/contracts/production-free-v1/*/*') ?: []) as $directory) {
            @rmdir($directory);
        }
        foreach (array_reverse(glob($root.'/contracts/production-free-v1/*') ?: []) as $directory) {
            @rmdir($directory);
        }
        @rmdir($root.'/contracts/production-free-v1');
        @rmdir($root.'/contracts');
        @rmdir($root);
        fwrite(STDERR, 'PROBE upgrade['.(getenv('VA_REVIEW_LABEL') ?: 'unlabelled').']: '.json_encode($observed, JSON_UNESCAPED_SLASHES)."\n");
        $this->assertNotEmpty($observed);
    }

    private function attempt(callable $operation): string
    {
        try {
            return 'ok:'.$operation();
        } catch (ProductionFreeGrantException $error) {
            return 'refused:'.$error->reason;
        } catch (Throwable $error) {
            return 'error:'.$error::class.':'.substr($error->getMessage(), 0, 80);
        }
    }
}
