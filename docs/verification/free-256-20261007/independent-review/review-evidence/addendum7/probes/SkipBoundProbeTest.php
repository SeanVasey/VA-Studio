<?php

namespace Tests\ReviewProbes\Free256\Addendum7;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderable;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderProfile;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review addendum 7 probe (not part of the suite). Shapes at exactly the new skip bound
 * (mb_strlen(title\nreference\nterms\nassent) <= 2000), each confirmed to SKIP the preflight, then rendered with the real
 * pinned renderer and the heaviest fixed content (120 `@` name, 3 assets with 128-character source ids).
 */
final class SkipBoundProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_worst_shapes_at_the_skip_bound(): void
    {
        $this->freeSetup();
        $profile = ProductionFreeGrantRenderProfile::current('synthetic_rehearsal');
        $fill = fn (string $pattern, int $length): string => mb_substr(str_repeat($pattern, (int) ceil($length / mb_strlen($pattern))), 0, $length);
        // title, reference and assent are 1 character each; the joining newlines are 3 characters: terms get 1,994.
        $budget = 2000 - 3 - 3;
        $shapes = [
            'max newlines (a + 1,992 newlines + a)' => 'a'.str_repeat("\n", $budget - 2).'a',
            'alternating @ and newline' => rtrim($fill("@\n", $budget)),
            'alternating space and newline around @' => '@'.$fill(" \n", $budget - 2).'@',
            'tabs between @ (tab becomes 4 spaces)' => '@'.$fill("\t@", $budget - 1),
            'words of 51 @' => rtrim($fill(str_repeat('@', 51).' ', $budget)),
            'one unbroken @ token' => str_repeat('@', $budget),
            'widest multi-byte glyph U+2031, newline separated' => rtrim($fill("\u{2031}\n", $budget)),
            'CR LF pairs (normalized to one newline)' => 'a'.$fill("\r\n", $budget - 2).'a',
        ];
        $assets = [];
        foreach (['master_wav' => 'audio/wav', 'download_mp3' => 'audio/mpeg', 'stems_zip' => 'application/zip'] as $role => $mime) {
            $assets[] = ['role' => $role, 'source_id' => str_repeat('s', 128), 'sha256' => str_repeat('c', 64), 'bytes' => 1073741824,
                'mime_type' => $mime, 'filename' => 'production-free-'.$role.'.'.['master_wav' => 'wav', 'download_mp3' => 'mp3', 'stems_zip' => 'zip'][$role]];
        }
        $renderer = new class
        {
            public int $calls = 0;
        };
        $log = [];
        foreach ($shapes as $label => $terms) {
            $definition = ['title' => '@', 'terms_reference' => '@', 'terms_text' => $terms, 'assent_text' => '@', 'assets' => $assets];
            $characters = mb_strlen($definition['title']."\n".$definition['terms_reference']."\n".$terms."\n".$definition['assent_text']);
            // The skip decision: preflight with a renderer binding that counts calls.
            $calls = 0;
            $this->app->bind(ProductionFreeGrantRendererProcess::class, function () use (&$calls) {
                $calls++;

                return new ProductionFreeGrantRendererProcess;
            });
            ProductionFreeGrantRenderable::preflight($definition, $profile);
            $this->app->offsetUnset(ProductionFreeGrantRendererProcess::class);
            $origin = ['provenance' => 'synthetic_rehearsal', 'origin_id' => '00000000-0000-4000-8000-000000000000', 'accepted_at' => '2000-01-01T00:00:00Z',
                'declared_name' => str_repeat('@', 120), 'definition_id' => '00000000-0000-4000-8000-000000000001', 'definition_hash' => str_repeat('a', 64),
                'review_id' => '00000000-0000-4000-8000-000000000002', 'display_hash' => str_repeat('b', 64),
                'buyer_binding' => ['account_public_id' => str_repeat('p', 36)], 'definition' => [...$definition, 'terms_hash' => hash('sha256', $terms)]];
            try {
                $r = app(ProductionFreeGrantRendererProcess::class)->render(ProductionFreeGrantRenderInput::fromOrigin($origin), $profile);
                $result = 'pages='.$r->pageCount.' bytes='.$r->sizeBytes;
            } catch (ContractIssuanceException $error) {
                $result = 'RENDER REFUSED '.$error->reason;
            }
            $log[] = sprintf('%s: characters=%d preflight_renders=%d -> %s', $label, $characters, $calls, $result);
        }
        fwrite(STDERR, "PROBE addendum7.skip:\n  ".implode("\n  ", $log)."\n");
        $this->assertCount(count($shapes), $log);
    }
}
