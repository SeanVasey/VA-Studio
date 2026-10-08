<?php

namespace Tests\ReviewProbes\Free256\Addendum6;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderProfile;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review addendum 6 probe (not part of the suite). Renders, with the real pinned renderer, the worst text
 * shapes that stay UNDER the preflight skip estimate (newlines + 1 + bytes/60 < 1500), to measure the page margin.
 */
final class PreflightEstimateProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_worst_shapes_under_the_skip_estimate_stay_far_below_100_pages(): void
    {
        $this->freeSetup();
        $profile = ProductionFreeGrantRenderProfile::current('synthetic_rehearsal');
        $assent = str_repeat('@', 2000);
        $title = str_repeat('@', 160);
        $reference = str_repeat('@', 160);
        $shapes = [
            'unbroken @ (widest glyph per byte)' => str_repeat('@', 60000),
            'lines of 52 @ (one char past a full line)' => rtrim(str_repeat(str_repeat('@', 52)."\n", 740)),
            'short lines a\\n' => rtrim(str_repeat("a\n", 1300)),
            'spaces between words of 51 @' => rtrim(str_repeat(str_repeat('@', 51).' ', 1100)),
        ];
        $log = [];
        foreach ($shapes as $label => $terms) {
            $text = $title."\n".$reference."\n".$terms."\n".$assent;
            $estimate = substr_count($text, "\n") + 1 + intdiv(strlen($text), 60);
            $this->assertLessThan(1500, $estimate, $label);
            foreach (['W' => str_repeat('W', 120), '@' => str_repeat('@', 120)] as $nameLabel => $name) {
                $origin = ['provenance' => 'synthetic_rehearsal', 'origin_id' => '00000000-0000-4000-8000-000000000000', 'accepted_at' => '2000-01-01T00:00:00Z',
                    'declared_name' => $name, 'definition_id' => '00000000-0000-4000-8000-000000000001', 'definition_hash' => str_repeat('a', 64),
                    'review_id' => '00000000-0000-4000-8000-000000000002', 'display_hash' => str_repeat('b', 64),
                    'buyer_binding' => ['account_public_id' => str_repeat('p', 36)], 'definition' => ['title' => $title, 'terms_reference' => $reference,
                        'terms_text' => $terms, 'terms_hash' => hash('sha256', $terms), 'assent_text' => $assent, 'assets' => $this->definitionInput()['assets'] === [] ? [] : [
                            ['role' => 'master_wav', 'source_id' => str_repeat('s', 128), 'sha256' => str_repeat('c', 64), 'bytes' => 1073741824, 'mime_type' => 'audio/wav', 'filename' => 'production-free-master_wav.wav'],
                            ['role' => 'download_mp3', 'source_id' => str_repeat('s', 128), 'sha256' => str_repeat('d', 64), 'bytes' => 1073741824, 'mime_type' => 'audio/mpeg', 'filename' => 'production-free-download_mp3.mp3'],
                            ['role' => 'stems_zip', 'source_id' => str_repeat('s', 128), 'sha256' => str_repeat('e', 64), 'bytes' => 1073741824, 'mime_type' => 'application/zip', 'filename' => 'production-free-stems_zip.zip']]]];
                $started = hrtime(true);
                try {
                    $rendered = app(ProductionFreeGrantRendererProcess::class)->render(ProductionFreeGrantRenderInput::fromOrigin($origin), $profile);
                    $log[] = sprintf('%s, name %s: estimate=%d -> pages=%d bytes=%d (%.1fs)', $label, $nameLabel, $estimate, $rendered->pageCount, $rendered->sizeBytes, (hrtime(true) - $started) / 1e9);
                } catch (\App\Domain\Contracts\ContractIssuanceException $error) {
                    $log[] = sprintf('%s, name %s: estimate=%d -> RENDER REFUSED %s (%.1fs)', $label, $nameLabel, $estimate, $error->reason, (hrtime(true) - $started) / 1e9);
                }
            }
        }
        fwrite(STDERR, "PROBE addendum6.preflight:\n  ".implode("\n  ", $log)."\n");
        $this->assertCount(8, $log);
    }
}
