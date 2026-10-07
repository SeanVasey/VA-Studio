<?php

namespace Tests\Feature;

use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\FreeGrantException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantDefinitionsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_explicit_source_separate_review_and_versioned_availability_preserve_full_license_and_asset_commitments(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $domain = new FreeGrantDefinitions;
        $draft = $domain->author($f['input'], $f['author']);
        $this->assertFalse($draft['open']);
        $this->assertNull($draft['reviewHash']);
        $this->assertSame($draft, $domain->author($f['input'], $f['author']));
        $review = ['requestKey' => (string) Str::uuid(), 'definitionHash' => $draft['definitionHash'],
            'reference' => 'SYNTHETIC-TEST-ONLY', 'freeScopeConfirmed' => true, 'scopeBindingConfirmed' => true, 'assetManifestConfirmed' => true];
        try {
            $domain->review($draft['id'], $review, $f['author']);
            $this->fail('An author cannot approve their own free purpose.');
        } catch (FreeGrantException $error) {
            $this->assertSame(403, $error->status);
        }
        $approved = $domain->review($draft['id'], $review, $f['reviewer']);
        $open = $domain->availability($draft['id'], ['requestKey' => (string) Str::uuid(), 'expectedVersion' => 0, 'open' => true, 'reason' => 'Synthetic open'], $f['author']);
        $this->assertTrue($open['open']);
        $this->assertSame(1, $open['version']);
        $this->assertSame($approved['reviewHash'], $open['reviewHash']);
        $this->assertSame($f['license']->authored_source, $open['license']['termsText']);
        $this->assertSame($f['assets']['master_wav']->sha256, $open['assets'][0]['sha256']);
        $this->assertArrayNotHasKey('storage_path', $open['assets'][0]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('free_origins', 0);
        $this->assertStringNotContainsString('SYNTHETIC TEST INPUT', DB::table('free_definitions')->value('payload'));
    }
}
