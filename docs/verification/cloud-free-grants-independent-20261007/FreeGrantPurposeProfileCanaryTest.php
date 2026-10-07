<?php

namespace Tests\IndependentFreeGrants;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Grants\Free\FreeGrantDocuments;
use App\Domain\Grants\Free\FreeGrantRecords;
use App\Domain\Grants\Free\FreeGrantRendererProcess;
use App\Domain\Grants\Free\FreeGrantRenderInput;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantPurposeProfileCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_purchase_profile_and_relabelled_free_profile_cannot_render_an_original_free_assent(): void
    {
        $this->fakePrivateMediaStorage();
        $fixture = FreeGrantFixtures::source();
        $definition = FreeGrantFixtures::publish($fixture);
        $origin = FreeGrantFixtures::accept($fixture, $definition)['origin'];
        $before = (array) DB::table('free_origins')->sole();
        $payload = FreeGrantRecords::decode($before);
        $input = FreeGrantRenderInput::fromOrigin($payload);
        $wrongPurpose = $payload['profile'];
        $wrongPurpose['purpose'] = 'purchase';
        $factoryCalled = false;
        $renderer = new FreeGrantRendererProcess(function () use (&$factoryCalled): never {
            $factoryCalled = true;
            throw new \LogicException('The child must not start for an incompatible original profile.');
        });
        foreach ([$wrongPurpose, ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2')] as $profile) {
            try {
                $renderer->render($input, $profile);
                $this->fail('A purchase or relabelled profile cannot replace the retained free purpose.');
            } catch (ContractIssuanceException $error) {
                $this->assertSame('profile_changed', $error->reason);
            }
        }
        $this->assertFalse($factoryCalled);
        $this->assertSame($before, (array) DB::table('free_origins')->sole());
        $this->assertDatabaseCount('free_originals', 0);
        $issued = (new FreeGrantDocuments)->issue($origin['id'], $origin['originHash'], $fixture['customer']['principal'], $fixture['customer']['user']);
        $this->assertSame('complete', $issued['documentStatus']);
        $this->assertDatabaseCount('free_originals', 1);
        $this->assertSame($before, (array) DB::table('free_origins')->sole());
    }
}
