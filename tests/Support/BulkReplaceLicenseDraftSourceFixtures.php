<?php

namespace Tests\Support;

use App\Domain\Rights\CreateLicenseDraft;
use App\Models\User;
use LogicException;

/** Disposable synthetic text and policy fixtures only, never legal approval or production defaults. */
final class BulkReplaceLicenseDraftSourceFixtures
{
    public static function content(int $schema = 1): array
    {
        $fixture = match ($schema) {
            2 => TypedLicenseFixtures::class, 3 => ScopedLicenseFixtures::class,
            4 => EconomicLicenseFixtures::class, default => null,
        };

        return $fixture === null ? [
            'authored_source' => 'NONBINDING SYNTHETIC BULK SOURCE. Test evidence only.',
            'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic WAV'], 'required_asset_roles' => ['master_wav']],
        ] : ['authored_source' => $fixture::source(), 'structured_terms' => $fixture::terms()];
    }

    public static function replacement(int $schema = 1): string
    {
        return self::content($schema)['authored_source']."\nNONBINDING explicit replacement supplied by the synthetic operator.";
    }

    public static function drafts(int $count = 3, bool $sameTemplate = false, int $schema = 1, ?User $actor = null): array
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Bulk license fixtures require an isolated testing environment.');
        }
        $actor ??= LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $content = self::content($schema);
        $versions = [];
        for ($index = 0; $index < $count; $index++) {
            $version = $sameTemplate && $index > 0
                ? app(CreateLicenseDraft::class)->handle($versions[0]->template, $content, $actor)
                : LicenseFixtures::draft($actor, $content['structured_terms'], ['authored_source' => $content['authored_source']]);
            // Native JSON hydration is storage-defined; every baseline is a persisted-row baseline.
            $versions[] = $version->fresh();
        }

        return compact('actor', 'versions');
    }
}
