<?php

namespace Tests\ReviewProbes\Free256\Addendum4;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review addendum 4 probe (not part of the suite), SQLite. A real socket source that pauses mid-stream
 * (forked writer), renderer-unsupported input at every write site, and a terms text that passes admission but
 * exceeds the renderer's page limit.
 */
final class Addendum4ProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_socket_sources_with_pauses_and_slot_release(): void
    {
        $this->freeSetup();
        config(['production-free-grants.spool_slots' => 1]);
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $origin = $grants->accept($definition['id'], $this->assentInput($grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user'])), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $bytes = $this->sources->bytes['synthetic-master_wav'];
        $log = [];
        foreach (['gap 3 s' => [3], 'gap 7 s' => [7], 'trickle 1 byte/s for 8 s' => array_fill(0, 8, 1)] as $label => $pauses) {
            \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::now('UTC')->addSeconds(61));
            $this->app->instance(ProductionFreeGrantSources::class, new SocketSource($bytes, $pauses));
            $downloads = new ProductionFreeGrantDownloads;
            $authorization = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
            $started = hrtime(true);
            try {
                $transfer = $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
                $transfer->close();
                $outcome = 'ok';
            } catch (ProductionFreeGrantException $error) {
                $outcome = 'refused:'.$error->reason;
            }
            $seconds = round((hrtime(true) - $started) / 1e9, 1);
            pcntl_wait($status);
            $reserve = @file_get_contents($this->privateRoot.'/delivery/production-free-spool/slot-0.reserve');
            $residue = count(glob($this->privateRoot.'/delivery/production-free-spool/slot-*.snapshot') ?: []);
            $log[] = $label.': '.$outcome.' in '.$seconds.'s; slot-0.reserve='.var_export($reserve, true).' residue='.$residue;
        }
        \Carbon\CarbonImmutable::setTestNow();
        // After every outcome the single slot is free: a normal in-memory source redeems at once.
        $this->app->instance(ProductionFreeGrantSources::class, $this->sources);
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::now('UTC')->addSeconds(200));
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
        $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user'])->close();
        \Carbon\CarbonImmutable::setTestNow();
        $log[] = 'single slot reusable afterwards: ok';
        fwrite(STDERR, "PROBE addendum4.stall:\n  ".implode("\n  ", $log)."\n");
        $this->assertCount(4, $log);
    }

    public function test_unrenderable_text_is_refused_at_every_write_site_and_a_page_overflow_is_not(): void
    {
        $this->freeSetup();
        $grants = new ProductionFreeGrants;
        $definitions = new ProductionFreeGrantDefinitions;
        $log = [];
        $definition = $this->openDefinition();
        foreach (['arabic' => 'مرحبا بالعالم', 'decomposed' => "Jose\u{0301} Synthetic", 'no glyph U+02EF' => "Name \u{02EF}"] as $label => $name) {
            $owner = $this->customer(md5($label).'@example.test');
            $log[] = 'review '.$label.': '.$this->attempt(fn () => $grants->review($definition['id'], $name, $owner['principal'], $owner['user']));
            $good = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
            $log[] = 'accept '.$label.': '.$this->attempt(fn () => $grants->accept($definition['id'], $this->assentInput($good, ['declaredName' => $name]), $owner['principal'], $owner['user']));
        }
        foreach (['title' => 'مرحبا', 'termsReference' => "ref\u{0301}", 'assentText' => "I affirm \u{02EF}"] as $field => $value) {
            $log[] = 'propose '.$field.': '.$this->attempt(fn () => $definitions->propose($this->definitionInput([$field => $value]), $this->staff()));
        }
        $this->assertSame(0, DB::table('production_free_origins')->count());
        // Page overflow: 32,768 short lines fit the 65,536-character terms limit and the repertoire, but not 100 pages.
        $terms = rtrim(str_repeat("a\n", 32768));
        config(['production-free-grants.approved_terms_hashes' => [hash('sha256', self::SYNTHETIC_TERMS), hash('sha256', $terms)]]);
        $long = $this->attempt(fn () => $this->openDefinition(overrides: ['termsText' => $terms, 'title' => 'Long terms']));
        $log[] = 'propose+approve+open with 32,768-line terms: '.$long;
        if (str_starts_with($long, 'ok')) {
            $opened = DB::table('production_free_definitions')->orderByDesc('created_at')->value('id');
            $owner = $this->customer('long@example.test');
            $origin = $grants->accept($opened, $this->assentInput($grants->review($opened, 'Declared Synthetic Buyer', $owner['principal'], $owner['user'])), $owner['principal'], $owner['user']);
            $log[] = 'accept: ok; render: '.$this->attempt(fn () => (new ProductionFreeGrantDocuments)->render($origin['id']));
        }
        fwrite(STDERR, "PROBE addendum4.text:\n  ".implode("\n  ", $log)."\n");
        $this->assertNotEmpty($log);
    }

    private function attempt(callable $operation): string
    {
        try {
            $operation();

            return 'ok';
        } catch (ProductionFreeGrantException $error) {
            return 'refused:'.$error->reason;
        }
    }
}

/** A real unix socket source; a forked writer sends the bytes with the given pauses (seconds) between parts. */
final class SocketSource implements ProductionFreeGrantSources
{
    public function __construct(private string $bytes, private array $pauses) {}

    public function prove(array $sourceManifest, array $assets): string
    {
        return hash('sha256', json_encode([$sourceManifest, $assets], JSON_THROW_ON_ERROR));
    }

    public function open(array $asset)
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            // The writer must never return into the test runner: a write to a socket the reader already refused and
            // closed raises a warning (EPIPE) that the framework turns into an exception.
            try {
                fclose($reader);
                $parts = str_split($this->bytes, (int) ceil(strlen($this->bytes) / (count($this->pauses) + 1)));
                foreach ($parts as $index => $part) {
                    @fwrite($writer, $part);
                    @fflush($writer);
                    if (isset($this->pauses[$index])) {
                        sleep($this->pauses[$index]);
                    }
                }
                @fclose($writer);
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }
        fclose($writer);

        return $reader;
    }
}
