<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Current-owner library read side. Foreign owners see nothing and learn nothing. */
final class ProductionFreeGrantLibraryTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_owner_library_lists_exact_artifact_hashes_and_status_without_private_material(): void
    {
        [$owner, $origin] = $this->granted();
        $library = new ProductionFreeGrantLibrary;
        $pending = $library->index($owner['principal'], $owner['user']);
        $this->assertSame(1, $pending['total']);
        $this->assertSame('pending', $pending['items'][0]['documentStatus']);
        $this->assertFalse($pending['items'][0]['deliverable']);
        $this->assertSame(['master_wav', 'download_mp3', 'stems_zip'], array_column($pending['items'][0]['artifacts'], 'role'));
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $item = $library->index($owner['principal'], $owner['user'])['items'][0];
        $this->assertSame('complete', $item['documentStatus']);
        $this->assertTrue($item['deliverable']);
        $this->assertSame(['contract', 'master_wav', 'download_mp3', 'stems_zip'], array_column($item['artifacts'], 'role'));
        foreach (array_slice($item['artifacts'], 1) as $artifact) {
            $this->assertSame(hash('sha256', $this->sources->bytes['synthetic-'.$artifact['role']]), $artifact['sha256']);
        }
        $encoded = json_encode($library->show($origin['id'], $owner['principal'], $owner['user']));
        foreach (['storage_path', 'contracts/production-free-v1', 'synthetic-license-1', 'owner_key', 'account_public_id', 'token'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_foreign_owner_sees_an_empty_library_and_cannot_open_another_origin(): void
    {
        [$owner, $origin] = $this->granted();
        $other = $this->customer('other@example.test');
        $library = new ProductionFreeGrantLibrary;
        $this->assertSame(['schemaVersion' => 1, 'total' => 0, 'limit' => 50, 'items' => []], $library->index($other['principal'], $other['user']));
        try {
            $library->show($origin['id'], $other['principal'], $other['user']);
            $this->fail('A foreign owner must not read another origin.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('not_found', $error->reason);
        }
        try {
            $library->show($origin['id'], $owner['principal'], $other['user']);
            $this->fail('A mismatched principal/actor pair must be refused.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('identity_refused', $error->reason);
        }
    }

    public function test_revocation_and_closing_keep_the_original_visible_to_its_owner(): void
    {
        [$owner, $origin, $definition, $reviewer] = $this->granted();
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $library = new ProductionFreeGrantLibrary;
        (new ProductionFreeGrantDefinitions)->close($definition['id'], ['definitionHash' => $definition['definitionHash'], 'expectedOrdinal' => 1], $reviewer);
        $this->assertTrue($library->show($origin['id'], $owner['principal'], $owner['user'])['deliverable']);
        $detail = $library->show($origin['id'], $owner['principal'], $owner['user']);
        (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $detail['originSeal'], 'reason' => 'Synthetic rehearsal revocation.'], $reviewer);
        $after = $library->show($origin['id'], $owner['principal'], $owner['user']);
        $this->assertTrue($after['revoked']);
        $this->assertFalse($after['deliverable']);
        $this->assertSame($detail['artifacts'], $after['artifacts']);
        $this->assertSame(self::SYNTHETIC_TERMS, $after['termsText']);
    }

    private function granted(): array
    {
        $reviewer = $this->staff();
        $definition = $this->openDefinition(reviewer: $reviewer);
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return [$owner, $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), $definition, $reviewer];
    }
}
