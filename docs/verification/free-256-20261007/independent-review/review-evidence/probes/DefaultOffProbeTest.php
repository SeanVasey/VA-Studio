<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Independent review probe (not part of the suite). Question 6: every entry point refuses unless explicitly enabled. */
final class DefaultOffProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_migration_installs_the_owned_schema_even_while_disabled_but_writes_no_row(): void
    {
        // identitySetup() runs migrate:fresh with the shipped configuration (enabled=false, provenance=null).
        $this->identitySetup();
        $this->assertFalse(config('production-free-grants.enabled'));
        $this->assertNull(config('production-free-grants.provenance'));
        $tables = DB::connection()->getPdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'production_free_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $triggers = DB::connection()->getPdo()->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'production_free_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $rows = array_sum(array_map(fn (string $t): int => (int) DB::table($t)->count(), $tables));
        $this->assertCount(9, $tables);
        $this->assertCount(27, $triggers);
        $this->assertSame(0, $rows);
        $this->assertSame(1, (int) DB::table('migrations')->where('migration', '2026_10_07_256000_production_free_grants')->count());
        try {
            (new \App\Domain\Grants\ProductionFree\ProductionFreeGrantSchema)->down();
            $this->fail('down must refuse');
        } catch (\LogicException $error) {
            $downMessage = $error->getMessage();
        }
        fwrite(STDERR, 'PROBE default_off.migration: with enabled=false the migration installed '.count($tables).' tables + '.count($triggers).' triggers, '.$rows.' rows; down() refuses: '.$downMessage."\n");
    }

    public function test_every_entry_point_refuses_each_disabling_condition(): void
    {
        $this->freeSetup();
        $author = $this->staff();
        $reviewer = $this->staff();
        $definitions = new ProductionFreeGrantDefinitions;
        $opened = $this->openDefinition($author, $reviewer);
        $proposed = $definitions->propose($this->definitionInput(['title' => 'Second']), $author);
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($opened['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($opened['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $library = new ProductionFreeGrantLibrary;
        $seal = $library->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $owner['principal'], $owner['user']);
        $transfer = $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        $pending = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
        $entries = [
            'propose' => fn () => $definitions->propose($this->definitionInput(['title' => 'Third']), $author),
            'approve' => fn () => $definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer),
            'open' => fn () => $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0], $reviewer),
            'close' => fn () => $definitions->close($opened['id'], ['definitionHash' => $opened['definitionHash'], 'expectedOrdinal' => 1], $reviewer),
            'read' => fn () => $definitions->read($opened['id'], $reviewer),
            'review' => fn () => $grants->review($opened['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']),
            'accept' => fn () => $grants->accept($opened['id'], $this->assentInput($review, ['requestKey' => (string) Str::uuid()]), $owner['principal'], $owner['user']),
            'revoke' => fn () => $grants->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'probe'], $reviewer),
            'index' => fn () => $library->index($owner['principal'], $owner['user']),
            'show' => fn () => $library->show($origin['id'], $owner['principal'], $owner['user']),
            'render' => fn () => (new ProductionFreeGrantDocuments)->render($origin['id']),
            'recover' => fn () => (new ProductionFreeGrantDocuments)->recover($origin['id']),
            'authorize' => fn () => $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $owner['principal'], $owner['user']),
            'redeem' => fn () => $downloads->redeem($pending['id'], $pending['token'], $owner['principal'], $owner['user']),
        ];
        $sources = $this->app->make(ProductionFreeGrantSources::class);
        $conditions = [
            'disabled' => [fn () => config(['production-free-grants.enabled' => false]), fn () => config(['production-free-grants.enabled' => true])],
            'environment' => [fn () => $this->app['env'] = 'production', fn () => $this->app['env'] = 'testing'],
            'provenance' => [fn () => config(['production-free-grants.provenance' => 'verified_production']), fn () => config(['production-free-grants.provenance' => 'synthetic_rehearsal'])],
            'capability_absent' => [fn () => $this->app->offsetUnset(ProductionFreeGrantSources::class), fn () => $this->app->instance(ProductionFreeGrantSources::class, $sources)],
            'changed_policy' => [fn () => config(['production-free-grants.enabled' => 'true']), fn () => config(['production-free-grants.enabled' => true])],
        ];
        $matrix = [];
        foreach ($conditions as $reason => [$apply, $restore]) {
            $apply();
            try {
                foreach ($entries as $name => $entry) {
                    try {
                        $entry();
                        $matrix[$reason][$name] = 'ALLOWED';
                    } catch (ProductionFreeGrantException $error) {
                        $matrix[$reason][$name] = $error->reason;
                    }
                }
            } finally {
                $restore();
            }
        }
        foreach ($matrix as $reason => $row) {
            foreach ($row as $name => $observed) {
                $this->assertSame($reason, $observed, $reason.' '.$name);
            }
        }
        // Shipped config file literal values.
        $shipped = require base_path('config/production-free-grants.php');
        $this->assertFalse($shipped['enabled']);
        $this->assertNull($shipped['provenance']);
        $this->assertSame([], $shipped['approved_terms_hashes']);
        // A transfer already handed out keeps streaming after the module is disabled (no proof at stream time).
        config(['production-free-grants.enabled' => false]);
        $bytes = 0;
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes += strlen($chunk);
        });
        $this->assertGreaterThan(0, $bytes);
        fwrite(STDERR, 'PROBE default_off: '.count($entries).' entry points x '.count($conditions).' conditions all refused with the exact reason; shipped enabled=false provenance=null approved=[]; an already-redeemed transfer streamed '.$bytes." bytes after disable\n");
    }
}
