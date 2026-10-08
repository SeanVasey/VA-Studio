<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Synthetic rehearsal: operator -> independent review -> open -> typed customer review -> assent -> render -> library -> delivery. */
final class ProductionFreeGrantJourneyTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_complete_synthetic_journey_delivers_every_exact_artifact_once(): void
    {
        $definition = $this->openDefinition();
        $this->assertTrue($definition['open']);
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        $this->assertSame('pending', $origin['documentStatus']);
        $this->assertSame(0, $origin['amountMinor']);

        $rendered = (new ProductionFreeGrantDocuments)->render($origin['id']);
        $this->assertSame('complete', $rendered['documentStatus']);
        $this->assertSame('contract', $rendered['artifacts'][0]['role']);

        $library = new ProductionFreeGrantLibrary;
        $index = $library->index($owner['principal'], $owner['user']);
        $this->assertSame(1, $index['total']);
        $this->assertSame($origin['id'], $index['items'][0]['id']);
        $detail = $library->show($origin['id'], $owner['principal'], $owner['user']);
        $this->assertSame(self::SYNTHETIC_TERMS, $detail['termsText']);
        $this->assertSame('unknown', $detail['marketingConsent']);

        $downloads = new ProductionFreeGrantDownloads;
        foreach ($detail['artifacts'] as $artifact) {
            $authorization = $downloads->authorize($origin['id'], ['originSeal' => $detail['originSeal'], 'role' => $artifact['role']], $owner['principal'], $owner['user']);
            $transfer = $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
            $bytes = '';
            $transfer->writeTo(function (string $chunk) use (&$bytes): void {
                $bytes .= $chunk;
            });
            $this->assertSame($artifact['sha256'], hash('sha256', $bytes), $artifact['role']);
            $this->assertSame($artifact['bytes'], strlen($bytes));
        }
        $this->assertSame(['originId' => $origin['id'], 'sha256' => $rendered['artifacts'][0]['sha256'],
            'bytes' => $rendered['artifacts'][0]['bytes'], 'identical' => true], (new ProductionFreeGrantDocuments)->recover($origin['id']));
    }
}
