<?php

namespace Tests\ReviewProbes\Free256\Addendum3;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Independent review addendum 3 probe (not part of the suite), SQLite: Codex P2 renderer-unsupported input. */
final class RendererInputProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_assent_accepts_names_the_renderer_refuses(): void
    {
        $this->freeSetup();
        $definition = $this->openDefinition();
        $grants = new ProductionFreeGrants;
        $log = [];
        foreach (['arabic' => 'مرحبا بالعالم', 'decomposed' => "Jose\u{0301} Synthetic"] as $label => $name) {
            $owner = $this->customer($label.'@example.test');
            $origin = $grants->accept($definition['id'], $this->assentInput($grants->review($definition['id'], $name, $owner['principal'], $owner['user'])), $owner['principal'], $owner['user']);
            try {
                (new ProductionFreeGrantDocuments)->render($origin['id']);
                $render = 'ok';
            } catch (ProductionFreeGrantException $error) {
                $render = $error->reason;
            }
            $seal = DB::table('production_free_origins')->where('id', $origin['id'])->value('seal');
            try {
                (new ProductionFreeGrantDownloads)->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
                $master = 'ok';
            } catch (ProductionFreeGrantException $error) {
                $master = $error->reason;
            }
            $log[] = $label.': accept=ok render='.$render.' master='.$master;
            $this->assertNotSame('ok', $render);
        }
        fwrite(STDERR, 'PROBE addendum3.input: '.implode('; ', $log)."\n");
    }
}
