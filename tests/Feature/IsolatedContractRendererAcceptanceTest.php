<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractText;
use App\Domain\Contracts\IsolatedContractRenderer;
use Symfony\Component\Process\Process;
use Tests\Support\ContractRendererFixtures;
use Tests\TestCase;

/** Required real-process and independent-reader acceptance, with synthetic frozen data only. */
class IsolatedContractRendererAcceptanceTest extends TestCase
{
    public function test_two_isolated_children_preserve_every_frozen_section_in_the_same_valid_multipage_pdf(): void
    {
        $input = ContractRendererFixtures::input();
        $input['seller'] = ['legal_name' => 'Synthetic seller Ελληνικά Кириллица', 'policy_version' => 'synthetic-seller-v1'];
        $input['assent']['accepted_at'] = '2026-01-01T00:00:00Z';
        $input['assent']['review_hash'] = str_repeat('a', 64);
        $input['selection']['product'] = ['title' => 'Synthetic Zoë recording', 'revision' => 7];
        $input['selection']['license']['full_policy'] = 'ORIGINAL SYNTHETIC LICENSE SCOPE';
        $input['selection']['assets'] = [
            ['role' => 'master', 'revision' => 'synthetic-master-r1', 'sha256' => str_repeat('b', 64)],
            ['role' => 'stems', 'revision' => 'synthetic-stems-r2', 'sha256' => str_repeat('c', 64)],
        ];
        $input['pricing'] = ['currency' => 'GBP', 'subtotal_minor' => 1200, 'discount_minor' => 200, 'total_minor' => 1000];
        $paragraph = 'FULL FROZEN TERMS. Zoë Émile Ελληνικά Кириллица. Synthetic rights remain fixed.';
        $literal = '<img src="file:///etc/passwd"> <a href="https://example.invalid/">literal</a>';
        $input['disclosure']['termsText'] = "FIRST TERMS SENTINEL\n".str_repeat($paragraph."\n", 150)
            .$literal."\nFINAL TERMS SENTINEL";
        $profile = ContractRenderProfile::current();
        $expected = (new ContractText)->build($input);

        // Each fresh adapter invokes the real child script. No process factory or renderer fake is installed.
        $first = (new IsolatedContractRenderer)->render($input, $profile);
        usleep(1_100_000); // A different wall-clock second must not alter any page metadata.
        $second = (new IsolatedContractRenderer)->render(array_reverse($input, true), $profile);
        $this->assertSame($first->pdfBytes, $second->pdfBytes);
        $this->assertSame(hash('sha256', $first->pdfBytes), $first->sha256);
        $this->assertSame(strlen($first->pdfBytes), $first->sizeBytes);
        $this->assertSame($expected['text_digest'], $first->textDigest);
        $this->assertSame(ContractRenderProfile::hash($profile), $first->profileHash);
        $this->assertSame($first->textDigest, $second->textDigest);
        $this->assertGreaterThan(1, $first->pageCount);
        $this->assertLessThanOrEqual(100, $first->pageCount);
        preg_match_all('/\/LastModified \(([^)]+)\)/', $first->pdfBytes, $pageDates);
        $this->assertCount($first->pageCount, $pageDates[1]);
        $this->assertSame(["D:20260101000000+00'00'"], array_values(array_unique($pageDates[1])));
        foreach (['/JavaScript', '/EmbeddedFiles', '/URI'] as $activeContent) {
            $this->assertStringNotContainsString($activeContent, $first->pdfBytes);
        }

        $path = tempnam(sys_get_temp_dir(), 'vasey-contract-acceptance-');
        $this->assertIsString($path);
        try {
            $this->assertTrue(chmod($path, 0600));
            $this->assertSame($first->sizeBytes, file_put_contents($path, $first->pdfBytes));
            $check = new Process(['qpdf', '--check', $path], timeout: 30);
            $check->mustRun();
            $this->assertStringContainsString('No syntax or stream encoding errors found', $check->getOutput());
            $pages = new Process(['qpdf', '--show-npages', $path], timeout: 30);
            $pages->mustRun();
            $this->assertSame((string) $first->pageCount, trim($pages->getOutput()));
            $extract = new Process(['pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
            $extract->mustRun();
            $text = $extract->getOutput();
            // Ignore layout whitespace only; labels, scalar values, ordering and multiplicity must all survive.
            $normalize = static fn (string $value): string => trim((string) preg_replace('/\s+/u', ' ', $value));
            $this->assertSame($normalize($expected['text']), $normalize($text));
            $this->assertSame(150, substr_count($normalize($text), $paragraph));
            $this->assertStringContainsString($literal, $normalize($text));
            $this->assertStringContainsString('Zoë Émile Ελληνικά Кириллица', $normalize($text));
            $this->assertStringContainsString('FIRST TERMS SENTINEL', $text);
            $this->assertStringContainsString('FINAL TERMS SENTINEL', $text);
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }
}
