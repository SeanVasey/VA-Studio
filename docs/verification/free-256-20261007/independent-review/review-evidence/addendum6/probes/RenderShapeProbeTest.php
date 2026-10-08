<?php

namespace Tests\ReviewProbes\Free256\Addendum6;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderProfile;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review addendum 6 probe (not part of the suite): which small terms shapes make the pinned renderer fail,
 * and whether such a definition passes propose/approve/open/accept (preflight skipped) and then never renders.
 */
final class RenderShapeProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_small_shapes_and_the_end_to_end_consequence(): void
    {
        $this->freeSetup();
        $profile = ProductionFreeGrantRenderProfile::current('synthetic_rehearsal');
        $log = [];
        $shapes = [];
        foreach ([1, 2, 10] as $words) {
            foreach ([49, 50, 51, 52, 60] as $length) {
                $shapes["{$words} word(s) of {$length} @ joined by spaces"] = implode(' ', array_fill(0, $words, str_repeat('@', $length)));
            }
        }
        $shapes['300 words of 60 @ joined by spaces'] = implode(' ', array_fill(0, 300, str_repeat('@', 60)));
        $shapes['200 URL-like tokens of 95 chars joined by spaces'] = implode(' ', array_fill(0, 200, 'https://example.test/'.str_repeat('abcdefghij', 7).'abcd'));
        $shapes['200 URL-like tokens of 95 chars, one per line'] = implode("\n", array_fill(0, 200, 'https://example.test/'.str_repeat('abcdefghij', 7).'abcd'));
        $shapes['150 words of 51 @ joined by spaces'] = implode(' ', array_fill(0, 150, str_repeat('@', 51)));
        $shapes['200 words of 51 @ joined by spaces'] = implode(' ', array_fill(0, 200, str_repeat('@', 51)));
        foreach ([100, 300, 600, 900, 1100] as $words) {
            $shapes["{$words} words of 51 @ joined by spaces"] = implode(' ', array_fill(0, $words, str_repeat('@', 51)));
            $shapes["{$words} words of 20 a joined by spaces"] = implode(' ', array_fill(0, $words * 2, str_repeat('a', 20)));
        }
        $shapes['2 words of 51 W'] = str_repeat('W', 51).' '.str_repeat('W', 51);
        $shapes['2 words of 55 a'] = str_repeat('a', 55).' '.str_repeat('a', 55);
        $shapes['1 word of 200 a then a space and b'] = str_repeat('a', 200).' b';
        $first = null;
        foreach ($shapes as $label => $terms) {
            $origin = ['provenance' => 'synthetic_rehearsal', 'origin_id' => '00000000-0000-4000-8000-000000000000', 'accepted_at' => '2000-01-01T00:00:00Z',
                'declared_name' => 'Buyer Name', 'definition_id' => '00000000-0000-4000-8000-000000000001', 'definition_hash' => str_repeat('a', 64),
                'review_id' => '00000000-0000-4000-8000-000000000002', 'display_hash' => str_repeat('b', 64),
                'buyer_binding' => ['account_public_id' => 'probe'], 'definition' => ['title' => 'Title', 'terms_reference' => 'Reference',
                    'terms_text' => $terms, 'terms_hash' => hash('sha256', $terms), 'assent_text' => 'Assent', 'assets' => [
                        ['role' => 'master_wav', 'source_id' => 'probe', 'sha256' => str_repeat('c', 64), 'bytes' => 1, 'mime_type' => 'audio/wav', 'filename' => 'production-free-master_wav.wav']]]];
            try {
                $r = app(ProductionFreeGrantRendererProcess::class)->render(ProductionFreeGrantRenderInput::fromOrigin($origin), $profile);
                $log[] = $label.': pages='.$r->pageCount;
            } catch (ContractIssuanceException $error) {
                $log[] = $label.': REFUSED '.$error->reason;
                $first ??= $terms;
            }
        }
        // End to end with the first failing shape: is it admitted, assented and then unrenderable?
        if ($first !== null) {
            config(['production-free-grants.approved_terms_hashes' => [hash('sha256', self::SYNTHETIC_TERMS), hash('sha256', $first)]]);
            try {
                $definition = $this->openDefinition(overrides: ['termsText' => $first]);
                $owner = $this->customer();
                $grants = new ProductionFreeGrants;
                $origin = $grants->accept($definition['id'], $this->assentInput($grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user'])), $owner['principal'], $owner['user']);
                try {
                    (new ProductionFreeGrantDocuments)->render($origin['id']);
                    $render = 'ok';
                } catch (ProductionFreeGrantException $error) {
                    $render = $error->reason;
                }
                $log[] = 'END TO END first failing shape: propose/approve/open/accept ok; render='.$render;
            } catch (ProductionFreeGrantException $error) {
                $log[] = 'END TO END first failing shape: refused at admission ('.$error->reason.')';
            }
        }
        fwrite(STDERR, "PROBE addendum6.shapes:\n  ".implode("\n  ", $log)."\n");
        $this->assertNotEmpty($log);
    }
}
