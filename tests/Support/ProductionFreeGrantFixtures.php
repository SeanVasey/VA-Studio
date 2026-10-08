<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Synthetic rehearsal fixtures for family 256. Terms, titles and asset bytes are visibly synthetic: they are not
 * legal text, catalog facts, prices or real masters, and nothing here approves operative production use.
 */
trait ProductionFreeGrantFixtures
{
    use ProductionIdentityFixture;

    public const SYNTHETIC_TERMS = "SYNTHETIC REHEARSAL TERMS - NOT LEGAL TEXT.\nThis placeholder exists only to exercise the free grant workflow in local/testing.";

    protected FakeProductionFreeSources $sources;

    protected string $privateRoot;

    protected function freeSetup(bool $requireMfa = true): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('F', 32))]);
        $this->identitySetup();
        $root = sys_get_temp_dir().'/va-free256-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        chmod($root, 0700);
        $this->privateRoot = realpath($root);
        config(['filesystems.disks.local.root' => $this->privateRoot, 'filesystems.disks.local.serve' => false,
            'filesystems.disks.local.visibility' => 'private',
            'production-free-grants.enabled' => true, 'production-free-grants.provenance' => 'synthetic_rehearsal',
            'production-free-grants.approved_terms_hashes' => [hash('sha256', self::SYNTHETIC_TERMS)]]);
        $this->sources = new FakeProductionFreeSources;
        $this->app->instance(ProductionFreeGrantSources::class, $this->sources);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $requireMfa);
        $this->beforeApplicationDestroyed(function (): void {
            if (is_dir($this->privateRoot)) {
                $this->removeTree($this->privateRoot);
            }
        });
    }

    protected function staff(bool $mfa = true, array $attributes = []): User
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now(), ...$attributes]);
        if ($mfa) {
            $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        }

        return User::query()->findOrFail($user->getKey());
    }

    /** @return array{user:User,principal:ProductionCustomerPrincipal,binding:array} */
    protected function customer(string $email = 'free-owner@example.test'): array
    {
        return $this->enrollThroughLocalSmtp($email);
    }

    protected function definitionInput(array $overrides = []): array
    {
        $assets = [];
        foreach (['master_wav', 'download_mp3', 'stems_zip'] as $role) {
            $bytes = $this->sources->bytes['synthetic-'.$role];
            $assets[] = ['role' => $role, 'sourceId' => 'synthetic-'.$role, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        }

        return [...['title' => 'Synthetic rehearsal free grant', 'termsReference' => 'synthetic-terms-reference-v1',
            'termsText' => self::SYNTHETIC_TERMS, 'assentText' => 'I affirm this synthetic rehearsal assent.',
            'assets' => $assets, 'source' => ['license_id' => 'synthetic-license-1', 'license_hash' => str_repeat('a', 64),
                'track_id' => 'synthetic-track-1', 'rights_reference' => 'synthetic-rights-1'], 'maxOrigins' => 5], ...$overrides];
    }

    /** Author proposes, a different reviewer approves, the reviewer opens. Returns the staff projection. */
    protected function openDefinition(?User $author = null, ?User $reviewer = null, array $overrides = []): array
    {
        $author ??= $this->staff();
        $reviewer ??= $this->staff();
        $definitions = new ProductionFreeGrantDefinitions;
        $proposed = $definitions->propose($this->definitionInput($overrides), $author);
        $definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer);

        return $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0], $reviewer);
    }

    protected function assentInput(array $review, array $overrides = []): array
    {
        return [...['requestKey' => (string) Str::uuid(), 'definitionHash' => $review['definition']['definition_hash'],
            'termsHash' => $review['definition']['terms_hash'], 'availabilityId' => $review['definition']['availability_id'],
            'declaredName' => $review['declaredName'], 'affirmed' => true, 'displayHash' => $review['displayHash']], ...$overrides];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0600);
            @unlink($path);

            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
