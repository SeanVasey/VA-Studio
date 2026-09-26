<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractText;
use App\Domain\Contracts\TcpdfContractRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ContractRendererFixtures;

class TcpdfContractRendererTest extends TestCase
{
    public function test_real_profile_reproduces_unicode_multpage_contract_and_all_integrity_metadata(): void
    {
        $profile = ContractRenderProfile::current();
        $input = ContractRendererFixtures::input();
        $input['disclosure']['termsText'] = str_repeat("Synthetic FULL TERMS paragraph. Zoë Ελληνικά Кириллица.\n", 150).'FINAL TERMS SENTINEL';
        $renderer = new TcpdfContractRenderer;
        $first = $renderer->render($input, $profile);
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $second = $renderer->render(array_reverse($input, true), $profile);
            $this->assertSame('Pacific/Auckland', date_default_timezone_get());
        } finally {
            date_default_timezone_set($timezone);
        }
        $this->assertSame($first->pdfBytes, $second->pdfBytes);
        $this->assertSame(hash('sha256', $first->pdfBytes), $first->sha256);
        $this->assertSame(strlen($first->pdfBytes), $first->sizeBytes);
        $this->assertSame(ContractRenderProfile::hash($profile), $first->profileHash);
        $this->assertSame((new ContractText)->build($input)['text_digest'], $first->textDigest);
        $this->assertGreaterThan(1, $first->pageCount);
        $this->assertLessThanOrEqual(100, $first->pageCount);
        $this->assertStringStartsWith('%PDF-', $first->pdfBytes);
        $this->assertStringNotContainsString('/JavaScript', $first->pdfBytes);
        $this->assertStringNotContainsString('/EmbeddedFiles', $first->pdfBytes);
        $this->assertStringNotContainsString('/URI', $first->pdfBytes);
        $input['buyer']['legal_name'] = 'Different original buyer';
        $this->assertNotSame($first->sha256, $renderer->render($input, $profile)->sha256);
    }

    public function test_profile_limits_and_provenance_cannot_be_relaxed_by_a_stored_request(): void
    {
        $profile = ContractRenderProfile::current();
        $profile['limits']['output_bytes']++;
        $this->expectException(RuntimeException::class);
        ContractRenderProfile::validate($profile);
    }

    public function test_missing_runtime_assets_do_not_destroy_historical_profile_metadata_validation(): void
    {
        $profile = ContractRenderProfile::current();
        $this->assertSame($profile, ContractRenderProfile::validate($profile));
        $this->expectException(RuntimeException::class);
        ContractRenderProfile::verifyRuntime($profile, sys_get_temp_dir().'/vasey-absent-contract-profile');
    }

    public function test_a_common_script_codepoint_absent_from_the_actual_font_fails_closed(): void
    {
        $input = ContractRendererFixtures::input();
        $input['buyer']['legal_name'] = "Test \u{1FAE8}";
        $profile = ContractRenderProfile::current();
        $this->expectException(RuntimeException::class);
        (new TcpdfContractRenderer)->render($input, $profile);
    }
}
