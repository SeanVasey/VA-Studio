<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\FreeGrants;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use Illuminate\Support\Str;

/** Explicit authored synthetic source; no paid order, old grant or production seed is created. */
final class FreeGrantFixtures
{
    public static function source(): array
    {
        CustomerFixtures::configure();
        config(['free-grants.test_enabled' => true]);
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $author->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $reviewer->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $license = LicenseFixtures::published($author, $reviewer);
        $track = Track::create(['slug' => 'free-fixture-'.Str::uuid(), 'title' => 'Explicit synthetic free recording', 'artist' => 'Synthetic author']);
        $rights = app(SaveRightsDeclaration::class)->create(['track_id' => $track->id,
            'provenance_reference' => 'SYNTHETIC-ORIGINAL-SOURCE-ONLY', 'sample_disclosure' => 'Synthetic generated sine; no real catalog source.'], $author);
        app(VerifyRightsDeclaration::class)->handle($rights, $reviewer);
        $assets = MediaFixtures::readyTrackMedia($track, $author);
        $scope = app(ManageRightsScope::class)->register('free-fixture-'.Str::uuid(), 'SYNTHETIC-FREE-SCOPE-ONLY', $author);
        $customer = CustomerFixtures::account();
        $input = ['requestKey' => (string) Str::uuid(), 'title' => 'Synthetic free WAV grant', 'freePurpose' => 'free-license-grant',
            'assentText' => 'SYNTHETIC TEST INPUT: I request this free grant under the exact displayed nonbinding license terms and explicit retrieval limits. No purchase or marketing enrollment.',
            'termsReference' => 'SYNTHETIC-FREE-PURPOSE-REVIEW', 'licenseId' => $license->id, 'trackId' => $track->id, 'scopeId' => $scope->id,
            'assetIds' => [$assets['master_wav']->id], 'maxOrigins' => 2, 'maxDownloads' => 3, 'tokenTtlSeconds' => 60];

        return compact('author', 'reviewer', 'license', 'track', 'assets', 'scope', 'customer', 'input');
    }

    public static function publish(array $f, ?array $input = null): array
    {
        $domain = new FreeGrantDefinitions;
        $definition = $domain->author($input ?? $f['input'], $f['author']);
        $definition = $domain->review($definition['id'], ['requestKey' => (string) Str::uuid(), 'definitionHash' => $definition['definitionHash'],
            'reference' => 'SYNTHETIC-SEPARATE-FREE-REVIEW', 'freeScopeConfirmed' => true, 'scopeBindingConfirmed' => true, 'assetManifestConfirmed' => true], $f['reviewer']);

        return $domain->availability($definition['id'], ['requestKey' => (string) Str::uuid(), 'expectedVersion' => $definition['version'], 'open' => true,
            'reason' => 'Explicit synthetic preparation admission'], $f['author']);
    }

    public static function accept(array $f, array $definition): array
    {
        $input = ['requestKey' => (string) Str::uuid(), 'definitionHash' => $definition['definitionHash'], 'reviewHash' => $definition['reviewHash'],
            'expectedVersion' => $definition['version'], 'declaredName' => 'Synthetic buyer declaration', 'affirmed' => true,
            'assentHash' => (new FreeGrants)->assentHash($definition, 'Synthetic buyer declaration')];
        $origin = (new FreeGrants)->accept($definition['id'], $input, $f['customer']['principal'], $f['customer']['user']);

        return compact('input', 'origin');
    }
}
