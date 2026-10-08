<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Independent review probe (not part of the suite), native MySQL only. Two forked PHP processes, each with its own
 * MySQL session, race: assent at the origin cap, assent below the cap, one-use redemption and render claims.
 */
final class NativeConcurrencyProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_two_processes_race_at_the_cap_on_native_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Native MySQL and pcntl required.');
        }
        $this->freeSetup();
        $author = $this->staff();
        $reviewer = $this->staff();
        $customers = [];
        foreach (range(0, 3) as $k) {
            $customers[$k] = $this->customer('racer'.$k.'@example.test');
        }
        $grants = new ProductionFreeGrants;
        $log = [];
        $violations = 0;
        foreach (['cap1-free', 'cap1-held', 'cap1-free', 'cap1-held', 'cap1-free', 'cap1-held', 'cap2-free', 'cap2-held', 'cap2-free', 'cap2-held'] as $round => $scenario) {
            $cap = str_starts_with($scenario, 'cap1') ? 1 : 2;
            $definition = $this->openDefinition($author, $reviewer, ['maxOrigins' => $cap, 'title' => 'Race '.$round]);
            $pair = [$customers[($round * 2) % 4], $customers[($round * 2 + 1) % 4]];
            $inputs = [];
            foreach ($pair as $k => $c) {
                $inputs[$k] = $this->assentInput($grants->review($definition['id'], 'Racer '.$k, $c['principal'], $c['user']));
            }
            $outcomes = $this->race(fn (int $k) => $grants->accept($definition['id'], $inputs[$k], $pair[$k]['principal'], $pair[$k]['user'])['id'],
                str_ends_with($scenario, 'held') ? $definition['id'] : null);
            $count = (int) DB::table('production_free_origins')->where('definition_id', $definition['id'])->count();
            $violations += $count > $cap ? 1 : 0;
            $log[] = ['round' => $round, 'scenario' => $scenario, 'cap' => $cap, 'origins' => $count, 'outcomes' => $outcomes];
            $this->assertLessThanOrEqual($cap, $count, json_encode(end($log)));
        }
        // One-use redemption race on one authorization.
        $definition = $this->openDefinition($author, $reviewer, ['title' => 'Redemption race']);
        $owner = $customers[0];
        $origin = $grants->accept($definition['id'], $this->assentInput($grants->review($definition['id'], 'Owner', $owner['principal'], $owner['user'])), $owner['principal'], $owner['user']);
        // Render race on the same origin.
        $renderOutcomes = $this->race(fn (int $k) => (new ProductionFreeGrantDocuments)->render($origin['id'])['documentStatus'], null);
        $works = DB::table('production_free_document_work')->where('origin_id', $origin['id'])->orderBy('ordinal')->pluck('kind')->all();
        $originals = (int) DB::table('production_free_originals')->where('origin_id', $origin['id'])->count();
        $log[] = ['scenario' => 'render-race', 'outcomes' => $renderOutcomes, 'work' => $works, 'originals' => $originals];
        $this->assertSame(1, $originals);
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $downloads = new ProductionFreeGrantDownloads;
        $redemptionLog = [];
        foreach (['free', 'free', 'free'] as $attempt) {
            $authorization = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
            $outcomes = $this->race(function (int $k) use ($downloads, $authorization, $owner): string {
                $transfer = $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
                $bytes = 0;
                $transfer->writeTo(function (string $chunk) use (&$bytes): void {
                    $bytes += strlen($chunk);
                });

                return 'streamed:'.$bytes;
            }, null);
            $rows = (int) DB::table('production_free_redemptions')->where('authorization_id', $authorization['id'])->count();
            $redemptionLog[] = ['outcomes' => $outcomes, 'redemptions' => $rows];
            $this->assertSame(1, $rows);
        }
        $log[] = ['scenario' => 'redemption-race', 'attempts' => $redemptionLog];
        file_put_contents(getenv('VA_REVIEW_RACE_LOG') ?: sys_get_temp_dir().'/free256-race.json', json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fwrite(STDERR, 'PROBE race: cap violations='.$violations.' '.json_encode($log, JSON_UNESCAPED_SLASHES)."\n");
        $this->assertSame(0, $violations);
    }

    /** @return array<int, array<string, string>> */
    private function race(callable $work, ?string $holdDefinition): array
    {
        $directory = sys_get_temp_dir().'/free256-race-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        DB::disconnect();
        $config = config('database.connections.mysql');
        $holder = null;
        if ($holdDefinition !== null) {
            $holder = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'] ?? '');
            $holder->beginTransaction();
            $statement = $holder->prepare('SELECT id FROM production_free_definitions WHERE id = ? FOR UPDATE');
            $statement->execute([$holdDefinition]);
            $statement->fetchAll();
        }
        $start = microtime(true) + 1.5;
        $pids = [];
        foreach ([0, 1] as $k) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $result = [];
                try {
                    time_sleep_until($start);
                    $result = ['ok' => (string) $work($k)];
                } catch (ProductionFreeGrantException $error) {
                    $result = ['refused' => $error->reason];
                } catch (Throwable $error) {
                    $result = ['error' => $error::class.': '.substr(preg_replace('/\s+/', ' ', $error->getMessage()), 0, 240)];
                }
                file_put_contents($directory.'/'.$k.'.json', json_encode($result));
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        if ($holder !== null) {
            time_sleep_until($start + 3.0);
            $holder->commit();
            $holder = null;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $outcomes = [];
        foreach ([0, 1] as $k) {
            $file = $directory.'/'.$k.'.json';
            $outcomes[$k] = is_file($file) ? json_decode(file_get_contents($file), true) : ['error' => 'no result'];
            @unlink($file);
        }
        @rmdir($directory);

        return $outcomes;
    }
}
