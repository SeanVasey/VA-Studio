<?php

namespace App\Domain\Catalog\SyntheticRehearsal;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Environment\TestEnvironment;
use Illuminate\Support\Facades\Gate;

/**
 * Admission for the synthetic rehearsal catalog. Every check here runs before the command's first write, and none of
 * them changes a guard of the domain services it calls: those services re-check staff authority, MFA enrollment,
 * rights, media and license evidence themselves.
 */
final class SyntheticRehearsalPolicy
{
    /**
     * Environment, flag, Stripe mode and catalog. Production is refused first, whether it is the configured
     * environment (`config('app.env')`, which a cached configuration keeps) or the running one. Artisan's `--env`
     * switch changes only the running environment, so the two must also agree. These are configuration checks, not
     * proof of host identity; the catalog check below keeps the command off a real catalog if every signal is wrong.
     */
    public function admit(): void
    {
        $configured = config('app.env');
        if ($configured === 'production' || app()->environment('production') || app()->isProduction()) {
            $this->refuse('The synthetic rehearsal catalog is refused in production, whatever VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED says.');
        }
        if (! is_string($configured) || $configured !== app()->environment()) {
            $this->refuse('The configured environment (APP_ENV) and the running environment differ, for example through --env; the synthetic rehearsal catalog refuses an ambiguous environment.');
        }
        if (! TestEnvironment::admitsTestCommerce()) {
            $this->refuse('The synthetic rehearsal catalog runs only when APP_ENV is local, testing or staging.');
        }
        if (config('staging-synthetic-catalog.enabled') !== true) {
            $this->refuse('Set VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true to run the synthetic rehearsal catalog (default off).');
        }
        if (! $this->stripeTestModeOnly()) {
            $this->refuse('The synthetic rehearsal catalog requires Stripe test mode: STRIPE_MODE=test, an sk_test_ key or none, and no live production checkout configuration.');
        }
        if ($this->realCatalogPresent()) {
            $this->refuse('This catalog has a published (or once published) track or an approved license outside the synthetic definition; the synthetic rehearsal catalog runs only on an empty or synthetic-only catalog.');
        }
    }

    /**
     * The two named synthetic staff accounts. Each must already exist (created with `vasey:create-admin`), hold catalog
     * authority and satisfy the admin panel's MFA rule as it stands in this environment; license approval needs a
     * reviewer who did not author the license, so they must differ.
     *
     * @return array{0: User, 1: User}
     */
    public function operators(mixed $operatorEmail, mixed $reviewerEmail): array
    {
        if (! is_string($operatorEmail) || $operatorEmail === '' || ! is_string($reviewerEmail) || $reviewerEmail === '') {
            $this->refuse('Name the synthetic catalog operator and the independent synthetic license reviewer with --operator=EMAIL and --reviewer=EMAIL.');
        }
        $users = [];
        foreach (['operator' => $operatorEmail, 'reviewer' => $reviewerEmail] as $role => $email) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->refuse("No staff account has the {$role} email. Create it first with `php artisan vasey:create-admin`.");
            }
            if (! is_string($user->name) || ! str_starts_with($user->name, SyntheticRehearsalCatalog::OPERATOR_NAME_PREFIX)) {
                $this->refuse("The {$role} account's name must start with \"".SyntheticRehearsalCatalog::OPERATOR_NAME_PREFIX
                    .'" so synthetic writes are never attributed to a real operator.');
            }
            if (! Gate::forUser($user)->allows('administer-catalog')) {
                $this->refuse("The {$role} account lacks catalog authority (an administrator with a verified email).");
            }
            if (! AdminMultiFactor::satisfiedBy($user)) {
                $this->refuse("The {$role} account does not satisfy the admin panel's MFA rule here. Sign in at /admin as that account and enroll the authenticator app first.");
            }
            $users[] = $user;
        }
        if ($users[0]->is($users[1])) {
            $this->refuse('License approval needs an independent reviewer: --operator and --reviewer must be different accounts.');
        }

        return [$users[0], $users[1]];
    }

    /**
     * The media pipeline refuses master processing without the approved seller tag (`tag_not_configured`) and never
     * skips the tagged preview, so the command checks the configured tag and the tools before writing anything. It
     * never generates, replaces or overrides the seller tag.
     */
    public function media(): void
    {
        foreach (['ffmpeg', 'ffprobe', 'prlimit'] as $tool) {
            $binary = config('media.'.$tool);
            if (! is_string($binary) || ! is_executable($binary)) {
                $this->refuse("The media tool {$tool} is unavailable (config media.{$tool}).");
            }
        }
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->refuse('The PHP GD extension is required to draw the synthetic artwork.');
        }
        $path = config('media.tag_path');
        $sha256 = config('media.tag_sha256');
        if (! is_string($path) || $path === '' || ! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            $this->refuse('The approved seller tag is not configured (MEDIA_TAG_PATH and MEDIA_TAG_SHA256). The media pipeline refuses master '
                .'processing without it (tag_not_configured); configure it as the staging runbook describes. This command never generates a tag.');
        }
        try {
            $resolved = app(PrivateMediaFiles::class)->resolve($path);
        } catch (MediaFailure) {
            $this->refuse('The configured seller tag file is unavailable in private storage.');
        }
        if (! hash_equals($sha256, (string) hash_file('sha256', $resolved))) {
            $this->refuse('The configured seller tag does not match MEDIA_TAG_SHA256 (tag_hash_mismatch).');
        }
    }

    /**
     * Two bounded existence reads against the exact definition slugs (no pattern matching, so no collation or LIKE
     * semantics are involved): any track that is or was published, or any approved or published license version,
     * outside the synthetic definition means this is not a rehearsal-only catalog.
     */
    private function realCatalogPresent(): bool
    {
        $tracks = array_column(SyntheticRehearsalCatalog::tracks(), 'slug');
        $licenses = array_column(SyntheticRehearsalCatalog::licenses(), 'slug');

        return Track::query()->where(fn ($query) => $query->where('status', 'published')->orWhereNotNull('published_slug'))
            ->whereNotIn('slug', $tracks)->exists()
            || LicenseVersion::query()->whereIn('status', ['approved', 'published'])
                ->whereHas('template', fn ($query) => $query->whereNotIn('slug', $licenses))->exists();
    }

    /** Configuration shape only, as the installation report checks it: no key is used or reported and no provider is contacted. */
    private function stripeTestModeOnly(): bool
    {
        $secret = config('payments.stripe.secret_key');
        $productionSecret = config('production_checkout.secret_key');

        return config('payments.stripe.mode') === 'test'
            && ($secret === null || $secret === '' || (is_string($secret) && preg_match('/\Ask_test_[A-Za-z0-9]{8,200}\z/D', $secret) === 1))
            && config('production_checkout.funds_mode') !== 'live'
            && ! (is_string($productionSecret) && preg_match('/\A(?:sk|rk)_live_/', $productionSecret) === 1);
    }

    private function refuse(string $message): never
    {
        throw new SyntheticRehearsalRefusal($message);
    }
}
