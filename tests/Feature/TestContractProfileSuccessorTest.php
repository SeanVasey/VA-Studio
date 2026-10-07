<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Contracts\TcpdfContractRenderer;
use App\Domain\Contracts\TcpdfV2ContractRenderer;
use App\Domain\Contracts\VersionedContractRenderer;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContractRendererFixtures;
use Tests\TestCase;

/** Both pinned graphs: render only the available exact profile; never activate current issuance. */
class TestContractProfileSuccessorTest extends TestCase
{
    private function v1(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/contracts/retained-render-profile-v1.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    private function successorRuntime(): bool
    {
        return InstalledVersions::getReference('tecnickcom/tc-lib-pdf') === 'd417129fad37d49dc9fc0d1e229e9740e1c774a3'
            && InstalledVersions::getReference('tecnickcom/tc-lib-pdf-font') === 'a78b8e0ac9284d1594b6ba68488cf65c3e958f7b';
    }

    private function v2(): array
    {
        return ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2');
    }

    public function test_current_profile_and_policy_select_v2_and_retained_v1_metadata_stays_exact(): void
    {
        $this->assertSame('test-buyer-pdf-v2', ContractRenderProfile::VERSION);
        $this->assertSame(ContractIssuancePolicy::V2_CONTRACT, ContractIssuancePolicy::CONTRACT);
        $this->assertSame($this->v1()['profile'], ContractRenderProfile::validate($this->v1()['profile']));
        $this->assertSame($this->v1()['profile_hash'], ContractRenderProfile::hash($this->v1()['profile']));
        if ($this->successorRuntime()) {
            $this->assertSame($this->v2(), ContractRenderProfile::current());
        } else {
            $this->expectException(ContractIssuanceException::class);
            ContractRenderProfile::current();
        }
    }

    public function test_successor_graph_cannot_render_a_retained_v1_request_with_new_library_bytes(): void
    {
        if ($this->successorRuntime()) {
            $this->expectException(ContractIssuanceException::class);
            (new VersionedContractRenderer)->render(ContractRendererFixtures::input(), $this->v1()['profile']);
        } else {
            $result = (new VersionedContractRenderer)->render(ContractRendererFixtures::input(), $this->v1()['profile']);
            $this->assertSame($this->v1()['pdf_sha256'], $result->sha256);
        }
    }

    public function test_actual_successor_render_is_deterministic_and_distinct_from_original_v1(): void
    {
        $input = ContractRendererFixtures::input();
        $profile = $this->v2();
        if (! $this->successorRuntime()) {
            $this->expectException(ContractIssuanceException::class);
        }
        $a = (new VersionedContractRenderer)->render($input, $profile);
        $b = (new VersionedContractRenderer)->render($input, $profile);
        $this->assertSame($a->pdfBytes, $b->pdfBytes);
        $this->assertSame('790fd79695a52b8578305c0cbd2c44c9e776c723200db59536f6394653ed1daf', $a->sha256);
        $this->assertNotSame($this->v1()['pdf_sha256'], $a->sha256);
        $this->assertSame(751516, $a->sizeBytes);
        $this->assertSame(ContractRenderProfile::hash($profile), $a->profileHash);
        $this->assertSame(1, $a->pageCount);
        $this->assertStringContainsString('8.76.3', $a->pdfBytes);
    }

    public function test_real_scrubbed_child_dispatches_only_the_trusted_successor_implementation(): void
    {
        $this->fakePrivateMediaStorage();
        if (! $this->successorRuntime()) {
            $this->expectException(ContractIssuanceException::class);
        }
        $result = (new IsolatedContractRenderer)->render(ContractRendererFixtures::input(), $this->v2());
        $this->assertSame('790fd79695a52b8578305c0cbd2c44c9e776c723200db59536f6394653ed1daf', $result->sha256);
        $this->assertSame(751516, $result->sizeBytes);
        $this->assertSame(ContractRenderProfile::hash($this->v2()), $result->profileHash);
    }

    public function test_direct_v2_renderer_rejects_a_valid_v1_profile_before_runtime_or_rendering(): void
    {
        $this->expectException(ContractIssuanceException::class);
        (new TcpdfV2ContractRenderer)->render(ContractRendererFixtures::input(), $this->v1()['profile']);
    }

    public function test_direct_immutable_v1_renderer_rejects_a_valid_v2_profile_before_runtime_or_rendering(): void
    {
        $this->expectException(ContractIssuanceException::class);
        (new TcpdfContractRenderer)->render(ContractRendererFixtures::input(), $this->v2());
    }

    public static function tamperedProfiles(): array
    {
        return [['version', null, 'test-buyer-pdf-v3'], ['version', null, '../../test-v2'],
            ['issuance_policy', 'profile', 'test-buyer-pdf-v1'], ['issuance_policy', 'version', 'test-contract-issuance-v1'],
            ['assets', 'profile_version', 'test-buyer-pdf-v1'], ['limits', 'output_bytes', PHP_INT_MAX],
            ['renderer', null, 'arbitrary-class'], ['template', null, 'unknown-template']];
    }

    #[DataProvider('tamperedProfiles')]
    public function test_cross_version_tampered_or_untrusted_dispatch_is_rejected(string $section, ?string $field, mixed $value): void
    {
        $profile = $this->v2();
        if ($field === null) {
            $profile[$section] = $value;
        } else {
            $profile[$section][$field] = $value;
        }
        $this->expectException(ContractIssuanceException::class);
        (new VersionedContractRenderer)->render(ContractRendererFixtures::input(), $profile);
    }

    public function test_successor_policy_does_not_replace_or_borrow_v1_contract(): void
    {
        $this->assertSame(ContractIssuancePolicy::V2_CONTRACT, ContractIssuancePolicy::validate($this->v2()['issuance_policy']));
        $wrong = ContractIssuancePolicy::V2_CONTRACT;
        $wrong['profile'] = 'test-buyer-pdf-v1';
        $this->expectException(ContractIssuanceException::class);
        ContractIssuancePolicy::validate($wrong);
    }

    public function test_supplied_root_cannot_swap_the_trusted_successor_manifest(): void
    {
        $directory = sys_get_temp_dir().'/tampered-successor-manifest-'.bin2hex(random_bytes(12));
        mkdir($directory.'/resources/contracts/test-v2', 0700, true);
        try {
            $manifest = $this->v1()['profile']['assets'];
            $manifest['profile_version'] = 'test-buyer-pdf-v2';
            file_put_contents($directory.'/resources/contracts/test-v2/profile-assets.json', json_encode($manifest, JSON_THROW_ON_ERROR));
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
}
