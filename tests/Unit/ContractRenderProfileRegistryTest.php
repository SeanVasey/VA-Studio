<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\TcpdfContractRenderer;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ContractRendererFixtures;

class ContractRenderProfileRegistryTest extends TestCase
{
    private function retained(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/contracts/retained-render-profile-v1.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_retained_v1_metadata_hash_and_real_pdf_bytes_are_identical_to_before_registry(): void
    {
        $retained = $this->retained();
        $profile = ContractRenderProfileRegistry::metadata('test-buyer-pdf-v1');
        $this->assertSame($retained['profile'], $profile);
        $this->assertSame($retained['profile_hash'], ContractRenderProfile::hash($retained['profile']));
        $this->assertSame($retained['profile'], ContractRenderProfileRegistry::metadata('test-buyer-pdf-v1'));
        $this->assertSame(ContractIssuancePolicy::V2_CONTRACT, ContractIssuancePolicy::CONTRACT);
        $this->assertSame(ContractIssuancePolicy::V1_CONTRACT, ContractIssuancePolicy::validate($retained['profile']['issuance_policy']));
        if (InstalledVersions::getReference('tecnickcom/tc-lib-pdf') !== 'c383bd3ac09164c3fd1a6ac7da8f5460d19e47e3') {
            $this->expectException(ContractIssuanceException::class);
        }
        $rendered = (new TcpdfContractRenderer)->render(ContractRendererFixtures::input(), $retained['profile']);
        $this->assertSame($retained['pdf_sha256'], $rendered->sha256);
        $this->assertSame($retained['size_bytes'], $rendered->sizeBytes);
    }

    public static function untrustedVersions(): array
    {
        return [['test-buyer-pdf-v2'], ['../../test-v1'], ['test-buyer-pdf-v1/../v2'], [''], [null], [1], [false], [['test-buyer-pdf-v1']]];
    }

    #[DataProvider('untrustedVersions')]
    public function test_unknown_malformed_or_path_like_versions_cannot_select_retained_metadata(mixed $version): void
    {
        $profile = $this->retained()['profile'];
        $profile['version'] = $version;
        $this->expectException(ContractIssuanceException::class);
        ContractRenderProfile::validate($profile);
    }

    public static function changes(): array
    {
        return [['issuance_policy', 'profile', 'test-buyer-pdf-v2'], ['issuance_policy', 'version', 'test-contract-issuance-v2'],
            ['limits', 'max_attempts', 500], ['page', 'margin_mm', 0], ['assets', 'profile_version', 'test-buyer-pdf-v2']];
    }

    #[DataProvider('changes')]
    public function test_known_version_cannot_borrow_successor_policy_assets_or_relaxed_parameters(string $section, string $field, mixed $value): void
    {
        $profile = $this->retained()['profile'];
        $profile[$section][$field] = $value;
        $this->expectException(ContractIssuanceException::class);
        ContractRenderProfile::validate($profile);
    }

    public function test_a_present_unregistered_manifest_is_not_discovered_or_trusted(): void
    {
        $directory = sys_get_temp_dir().'/unregistered-contract-profile-'.bin2hex(random_bytes(12));
        mkdir($directory.'/resources/contracts/test-v2', 0700, true);
        try {
            $manifest = $this->retained()['profile']['assets'];
            $manifest['profile_version'] = 'test-buyer-pdf-v2';
            file_put_contents($directory.'/resources/contracts/test-v2/profile-assets.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->assertSame($this->retained()['profile'], ContractRenderProfile::validate($this->retained()['profile']));
            $this->expectException(ContractIssuanceException::class);
            ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2', $directory);
        } finally {
            unlink($directory.'/resources/contracts/test-v2/profile-assets.json');
            rmdir($directory.'/resources/contracts/test-v2');
            rmdir($directory.'/resources/contracts');
            rmdir($directory.'/resources');
            rmdir($directory);
        }
    }

    public function test_unknown_policy_version_is_rejected_even_when_its_other_values_copy_v1(): void
    {
        $policy = ContractIssuancePolicy::V1_CONTRACT;
        $policy['version'] = 'test-contract-issuance-v2';
        $this->expectException(ContractIssuanceException::class);
        ContractIssuancePolicy::validate($policy);
    }

    public function test_retained_metadata_remains_valid_when_render_runtime_is_absent(): void
    {
        $profile = $this->retained()['profile'];
        $this->assertSame($profile, ContractRenderProfile::validate($profile));
        $this->assertSame($this->retained()['profile_hash'], ContractRenderProfile::hash($profile));
        $this->expectException(ContractIssuanceException::class);
        ContractRenderProfile::verifyRuntime($profile, sys_get_temp_dir().'/absent-contract-renderer-'.bin2hex(random_bytes(12)));
    }
}
