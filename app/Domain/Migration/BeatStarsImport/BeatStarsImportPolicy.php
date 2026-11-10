<?php

namespace App\Domain\Migration\BeatStarsImport;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalPolicy;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalRefusal;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Environment\TestEnvironment;
use Illuminate\Support\Facades\Gate;

/**
 * Admission for the BeatStars catalog import. Every check runs before the command's first write and none of them
 * changes a guard of the services it calls: the draft importer, the media intake and the stems association
 * re-check staff authority, MFA enrollment, media and evidence themselves.
 */
final class BeatStarsImportPolicy
{
    /**
     * Environment, flag, Stripe mode and catalog. Production is refused first, whether it is the configured environment
     * (`config('app.env')`, which a cached configuration keeps) or the running one. Artisan's `--env` switch changes only
     * the running environment, so the two must also agree. These are configuration checks, not proof of host identity.
     */
    public function admit(): void
    {
        $configured = config('app.env');
        if ($configured === 'production' || app()->environment('production') || app()->isProduction()) {
            $this->refuse('The BeatStars import is refused in production, whatever VASEY_STAGING_BEATSTARS_IMPORT_ENABLED says.');
        }
        if (! is_string($configured) || $configured !== app()->environment()) {
            $this->refuse('The configured environment (APP_ENV) and the running environment differ, for example through --env; the BeatStars import refuses an ambiguous environment.');
        }
        if (! TestEnvironment::admitsTestCommerce()) {
            $this->refuse('The BeatStars import runs only when APP_ENV is local, testing or staging.');
        }
        if (config('staging-beatstars-import.enabled') !== true) {
            $this->refuse('Set VASEY_STAGING_BEATSTARS_IMPORT_ENABLED=true to run the BeatStars import (default off).');
        }
        if (! $this->stripeTestModeOnly()) {
            $this->refuse('The BeatStars import requires Stripe test mode: STRIPE_MODE=test, an sk_test_ key or none, and no live production checkout configuration.');
        }
        if (Track::query()->whereIn('slug', array_column(SyntheticRehearsalCatalog::tracks(), 'slug'))->exists()) {
            $this->refuse('The synthetic rehearsal catalog is still present. Reset staging before loading real content (docs/ops/staging-runbook.md).');
        }
    }

    /**
     * The two named staff accounts. Each must already exist (`vasey:create-admin`), hold catalog authority and satisfy the
     * admin panel's MFA rule as it stands here. The operator is the actor of every write; the reviewer is a second named
     * person who confirms the run. Real content is never attributed to a synthetic rehearsal account.
     *
     * @return array{0: User, 1: User}
     */
    public function operators(mixed $operatorEmail, mixed $reviewerEmail): array
    {
        if (! is_string($operatorEmail) || $operatorEmail === '' || ! is_string($reviewerEmail) || $reviewerEmail === '') {
            $this->refuse('Name the import operator and the second staff account with --operator=EMAIL and --reviewer=EMAIL.');
        }
        $users = [];
        foreach (['operator' => $operatorEmail, 'reviewer' => $reviewerEmail] as $role => $email) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->refuse("No staff account has the {$role} email. Create it first with `php artisan vasey:create-admin`.");
            }
            if (is_string($user->name) && str_starts_with($user->name, SyntheticRehearsalCatalog::OPERATOR_NAME_PREFIX)) {
                $this->refuse("The {$role} account is a synthetic rehearsal account; real catalog content is attributed to named staff only.");
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
            $this->refuse('--operator and --reviewer must be different accounts.');
        }

        return [$users[0], $users[1]];
    }

    /**
     * The media pipeline refuses master processing without the approved seller tag and never skips the tagged preview, so
     * the same tool and tag checks as the synthetic rehearsal catalog run before anything is written. The command never
     * generates, replaces or overrides the seller tag.
     */
    public function media(): void
    {
        try {
            (new SyntheticRehearsalPolicy)->media();
        } catch (SyntheticRehearsalRefusal $refusal) {
            $this->refuse($refusal->getMessage());
        }
    }

    /** Configuration shape only, as the synthetic rehearsal catalog checks it: no key is used or reported and no provider is contacted. */
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
        throw new BeatStarsImportRefusal($message);
    }
}
