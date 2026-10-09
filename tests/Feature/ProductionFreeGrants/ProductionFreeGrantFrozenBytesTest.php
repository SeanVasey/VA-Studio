<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderProfile;
use Tests\TestCase;

/**
 * Family 256 composes main-resident substrate by reference, never by copy. Any change to these exact bytes
 * (fad3ab44 blobs) must be re-reviewed against 256 before this test is updated.
 */
final class ProductionFreeGrantFrozenBytesTest extends TestCase
{
    private const MAIN = [
        'app/Domain/Contracts/ContractRenderProfileRegistry.php' => 'd6718f8f0d962679da3b6429c0edcfba76db223df216c03c99d0710f03e272e7',
        'app/Domain/Contracts/ContractRenderProfile.php' => '8b51ac91c35e9809a6040d32519a166d4652a759e265b97c32d205c865e95d40',
        'app/Domain/Contracts/ContractRenderer.php' => '4ef17678b4c8fd48b255e3a72eb26a32fe7f0d8da7d6ffe29ef5f4de8d141c4a',
        'app/Domain/Contracts/RenderedContract.php' => 'b3fa57b8c8cc8e354597a5fc5f12b7942aa734e9d1bab9f0d2014358dd1d249f',
        'app/Domain/Contracts/ContractRenderWorkspace.php' => '61cd991e9389fcca6b04fc4d0e5ec2c2c0f75266979dfa64af5e305f754e61ad',
        'app/Domain/Contracts/ContractFiles.php' => '3ab16f6dd6e71221de3925101329ec53807fe927fa3688188999a531e5763f1c',
        'app/Domain/Contracts/ContractIo.php' => '245cce48509e9df21a8cbc3d4cac625c1a446044d27d03bee5203a1b735138c8',
        'app/Domain/Contracts/ContractIssuanceException.php' => '9df97742776ba51f031b088ade0fde06e2a73ad1d90284141b91ee6e3eb8a8be',
        'scripts/contract-renderer-autoload.php' => '09a810e87b0f499bcf738dd35c1ebc60b27eeab2c5b6499be102c133bd72be01',
        'app/Domain/Delivery/DeliveryAssetFiles.php' => '3a3771443bf527d56c4a521c01b9840c847b33a9da23b7ad455c96d52820764c',
        'app/Domain/Delivery/PreparedDeliveryStream.php' => 'e976fc7d0803cde84a769d83f1ca28b2449f7c1dcdd7effda0b8bf42e8c12e13',
        'app/Domain/Delivery/ActivationPolicy.php' => '23253c453e7edefcf877eb191a07b0fba69f877a48fdfc91b8cfd36bbdaa7086',
        'app/Domain/Delivery/DeliveryException.php' => 'a43ad378c7b5cb199471caa882e3fe0e276cd91d69800f5861eb202aa6070adc',
        'app/Domain/Customers/ProductionCustomerAccess.php' => 'f0fc0b70a058954ecc0c2853b0836af547b5f4cd4654d1ba71116a03d18b20f7',
        'app/Domain/Customers/ProductionCustomerPrincipal.php' => 'd49b587d1a4c22bda4797b90c65d15ee0e41f3fb3a01dda5a599bb817425457b',
        'app/Domain/Customers/ProductionIdentity/ProductionCustomerSessions.php' => '73efe65ab06626178fc1aa6ecd6ff426b30a04d58acd68b164acaa31c9a5d1d6',
        'app/Domain/Customers/ProductionIdentity/IdentityException.php' => '91259fe2f5e59549b8f385edc2194b51ea70266fbe2a8992fce3615696661563',
        'app/Domain/Commerce/ProductionPolicy/CurrentRows.php' => 'a7e682df609b29225c088b24a817169146066f8ff74e3fbafebd361c22ad350e',
        'app/Support/CanonicalJson.php' => '6e8f14f950bbb7ed3c4a8162ecf7c2fd2752031d1679285cea8bb0a5f5f82d89',
        'app/Support/Access/AdminMultiFactor.php' => 'dc83e64ae86754c6ac2a6b64cb420e687d5ecaf4f5d87225060d021597681710',
    ];

    public function test_main_resident_dependencies_are_the_exact_reviewed_bytes(): void
    {
        foreach (self::MAIN as $path => $sha256) {
            $this->assertSame($sha256, hash_file('sha256', base_path($path)), $path);
        }
    }

    public function test_sealed_profile_manifest_binds_every_renderer_implementation_file(): void
    {
        $manifest = json_decode(file_get_contents(base_path('resources/contracts/production-free-v1/profile-assets.json')), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('production-free-grant-pdf-v1', $manifest['version']);
        foreach ($manifest['files'] as $path => $sha256) {
            $this->assertSame($sha256, hash_file('sha256', base_path($path)), $path);
        }
        $this->assertSame(['app/Domain/Grants/ProductionFree/ProductionFreeGrantPdfRenderer.php', 'app/Domain/Grants/ProductionFree/ProductionFreeGrantRenderInput.php',
            'app/Domain/Grants/ProductionFree/ProductionFreeGrantRendererProcess.php', 'app/Domain/Grants/ProductionFree/ProductionFreeGrantText.php',
            'scripts/render-production-free-grant.php'], $this->sorted(array_keys($manifest['files'])));
        $profile = ProductionFreeGrantRenderProfile::current('synthetic_rehearsal');
        ProductionFreeGrantRenderProfile::verifyRuntime($profile);
        $this->assertSame('production-free-grant-pdf-v1', $profile['version']);
        $this->assertSame('test-buyer-pdf-v2', $profile['base']['version']);
        $this->assertNotSame('test-free-grant-pdf-v1', $profile['version']);
    }

    public function test_no_paid_lane_or_old_free_family_file_is_copied_or_imported(): void
    {
        // The paid lane (Paid252, PR #56) is now composed on main beside this family. Family 256 still must not import
        // it or the old free family, and none of its files may be a byte copy of one of theirs.
        $foreign = [];
        foreach ([...glob(base_path('app/Domain/Grants/Paid/*.php')), ...glob(base_path('app/Domain/Grants/Free/*.php'))] as $file) {
            $foreign[hash_file('sha256', $file)] = $file;
        }
        $this->assertNotEmpty($foreign);
        foreach (glob(base_path('app/Domain/Grants/ProductionFree/*.php')) as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('App\\Domain\\Grants\\Paid', $source, $file);
            $this->assertStringNotContainsString('App\\Domain\\Grants\\Free\\', $source, $file);
            $this->assertArrayNotHasKey(hash('sha256', $source), $foreign, $file);
        }
    }

    private function sorted(array $paths): array
    {
        sort($paths, SORT_STRING);

        return $paths;
    }
}
