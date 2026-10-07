<?php

namespace Tests\Review;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\ContractText;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Contracts\TcpdfV2ContractRenderer;
use App\Domain\Contracts\TcpdfContractRenderer;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\ContractRendererFixtures;
use Tests\TestCase;

class PdfSuccessorCorrectedReviewCanaryTest extends TestCase
{
    private function newGraph(): bool
    {
        return InstalledVersions::getReference('tecnickcom/tc-lib-pdf') === 'd417129fad37d49dc9fc0d1e229e9740e1c774a3';
    }

    public function test_new_renderer_itself_refuses_original_profile_before_rendering(): void
    {
        $v1 = ContractRenderProfileRegistry::metadata('test-buyer-pdf-v1');
        $this->expectException(ContractIssuanceException::class);
        (new TcpdfV2ContractRenderer)->render(ContractRendererFixtures::input(), $v1);
    }

    public function test_immutable_original_renderer_refuses_successor_profile_before_rendering(): void
    {
        $v2 = ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2');
        $this->expectException(ContractIssuanceException::class);
        (new TcpdfContractRenderer)->render(ContractRendererFixtures::input(), $v2);
    }

    public function test_current_policy_cannot_be_activated_with_successor_configuration(): void
    {
        config(['contracts.test_issuance_enabled' => true, 'contracts.test_issuance_policy' => json_encode(ContractIssuancePolicy::V2_CONTRACT, JSON_THROW_ON_ERROR),
            'payments.stripe.mode' => 'test', 'payments.stripe.account_id' => 'acct_SyntheticProfileReview']);
        $this->expectException(ContractIssuanceException::class);
        $this->expectExceptionMessage('Test contract issuance is unavailable.');
        (new ContractIssuancePolicy)->current();
    }

    public function test_runtime_rejects_modified_successor_font_after_proving_complete_copy(): void
    {
        $profile = ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2');
        if (! $this->newGraph()) {
            $this->expectException(ContractIssuanceException::class);
            ContractRenderProfile::verifyVersionRuntime($profile, 'test-buyer-pdf-v2');
            return;
        }
        $copy = sys_get_temp_dir().'/pdf-successor-review-'.bin2hex(random_bytes(12));
        $files = new Filesystem;
        try {
            foreach ($profile['assets']['implementation'] as $path => $sha256) {
                $files->ensureDirectoryExists(dirname($copy.'/'.$path), 0700);
                $this->assertTrue(copy(base_path($path), $copy.'/'.$path));
            }
            $directory = ContractRenderProfileRegistry::assetsDirectory($profile['version']);
            foreach ($profile['assets']['assets'] as $path => $identity) {
                $files->ensureDirectoryExists(dirname($copy.'/'.$directory.'/'.$path), 0700);
                $this->assertTrue(copy(base_path($directory.'/'.$path), $copy.'/'.$directory.'/'.$path));
            }
            ContractRenderProfile::verifyVersionRuntime($profile, 'test-buyer-pdf-v2', $copy);
            $this->addToAssertionCount(1);
            $font = $copy.'/'.$directory.'/fonts/generated/dejavusans.z';
            $bytes = file_get_contents($font);
            $bytes[0] = chr(ord($bytes[0]) ^ 1);
            $this->assertSame(strlen($bytes), file_put_contents($font, $bytes));
            $this->expectException(ContractIssuanceException::class);
            ContractRenderProfile::verifyVersionRuntime($profile, 'test-buyer-pdf-v2', $copy);
        } finally {
            $files->deleteDirectory($copy);
        }
    }

    public function test_real_available_child_preserves_all_literal_frozen_text_and_page_times(): void
    {
        $this->fakePrivateMediaStorage();
        $version = $this->newGraph() ? 'test-buyer-pdf-v2' : 'test-buyer-pdf-v1';
        $profile = ContractRenderProfileRegistry::metadata($version);
        $input = ContractRendererFixtures::input();
        $literal = '<img src="file:///etc/passwd"> <a href="https://example.invalid/review">literal</a>';
        $paragraph = 'FULL FROZEN TERMS Zoë Ελληνικά Кириллица; unverified synthetic input.';
        $input['disclosure']['termsText'] = "FIRST REVIEW SENTINEL\n".str_repeat($paragraph."\n", 150).$literal."\nLAST REVIEW SENTINEL";
        $expected = (new ContractText)->build($input);
        $a = (new IsolatedContractRenderer)->render($input, $profile);
        $b = (new IsolatedContractRenderer)->render(array_reverse($input, true), $profile);
        $this->assertSame($a->pdfBytes, $b->pdfBytes);
        $this->assertSame(ContractRenderProfile::hash($profile), $a->profileHash);
        $this->assertSame($expected['text_digest'], $a->textDigest);
        $this->assertSame(strlen($a->pdfBytes), $a->sizeBytes);
        $this->assertGreaterThan(1, $a->pageCount);
        foreach (['/JavaScript', '/EmbeddedFiles', '/URI'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $a->pdfBytes);
        }
        preg_match_all('/\/LastModified \(([^)]+)\)/', $a->pdfBytes, $pageTimes);
        $this->assertCount($a->pageCount, $pageTimes[1]);
        $this->assertSame(["D:20260101000000+00'00'"], array_values(array_unique($pageTimes[1])));
        $path = tempnam(sys_get_temp_dir(), 'pdf-successor-review-');
        try {
            $this->assertTrue(chmod($path, 0600));
            $this->assertSame($a->sizeBytes, file_put_contents($path, $a->pdfBytes));
            $extract = new Process(['/usr/bin/pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
            $extract->mustRun();
            $text = $extract->getOutput();
            $normalize = static fn (string $value): string => trim((string) preg_replace('/\s+/u', ' ', $value));
            $this->assertSame($normalize($expected['text']), $normalize($text));
            $this->assertSame(150, substr_count($normalize($text), $paragraph));
            $this->assertStringContainsString($literal, $normalize($text));
            $this->assertStringContainsString('FIRST REVIEW SENTINEL', $text);
            $this->assertStringContainsString('LAST REVIEW SENTINEL', $text);
        } finally {
            unlink($path);
        }
    }
}
