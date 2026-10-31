<?php

namespace App\Domain\Customers\DataRights;

use App\Domain\Customers\CustomerAccessPolicy;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Default-off admission, bounded export settings and the retention schedule that must be set before any deletion. */
final class DataRightsPolicy
{
    public const RETENTION_KEYS = ['financial_records_years', 'consent_records_years', 'correspondence_records_years', 'security_records_years'];

    public function enabled(): bool
    {
        return config('customer-data-rights.enabled') === true && app(CustomerAccessPolicy::class)->enabled();
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new DataRightsException(404, 'not_enabled');
        }
    }

    public function exportTtlMinutes(): int
    {
        $value = config('customer-data-rights.export_ttl_minutes');
        if (! is_int($value) || $value < 5 || $value > 60) {
            throw new DataRightsException(503, 'export_configuration_invalid');
        }

        return $value;
    }

    public function exportMaxBytes(): int
    {
        $value = config('customer-data-rights.export_max_bytes');
        if (! is_int($value) || $value < 65536 || $value > 4194304) {
            throw new DataRightsException(503, 'export_configuration_invalid');
        }

        return $value;
    }

    /**
     * The approved retention schedule (U-13). Every value must be set: a missing, empty, non-integer or out-of-range value
     * refuses, so no deletion can run on an invented policy.
     *
     * @return array{policy_reference: string, financial_records_years: int, consent_records_years: int, correspondence_records_years: int, security_records_years: int}
     */
    public function retention(): array
    {
        $configured = config('customer-data-rights.retention');
        if (! is_array($configured)) {
            throw new DataRightsException(409, 'retention_policy_unset');
        }
        $reference = $configured['policy_reference'] ?? null;
        if (! is_string($reference) || trim($reference) === '' || mb_strlen($reference) > 200 || ! mb_check_encoding($reference, 'UTF-8')
            || preg_match('/[<>\x00-\x1F\x7F]/u', $reference) === 1) {
            throw new DataRightsException(409, 'retention_policy_unset');
        }
        $retention = ['policy_reference' => $reference];
        foreach (self::RETENTION_KEYS as $key) {
            $value = $configured[$key] ?? null;
            if (is_string($value) && preg_match('/\A[1-9][0-9]{0,2}\z/D', $value) === 1) {
                $value = (int) $value;
            }
            if (! is_int($value) || $value < 1 || $value > 100) {
                throw new DataRightsException(409, 'retention_policy_unset');
            }
            $retention[$key] = $value;
        }

        return $retention;
    }

    /**
     * Staff authority for irreversible customer data actions: the catalog administrator gate and the admin panel MFA rule,
     * re-read under a row lock, plus an enrolled authenticator even where the panel does not require one.
     */
    public function staff(User $actor): User
    {
        if (DB::transactionLevel() === 0) {
            throw new AuthorizationException;
        }
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null || ! Gate::forUser($current)->allows('administer-catalog')
            || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)
            || ! is_string($current->app_authentication_secret) || $current->app_authentication_secret === '') {
            throw new AuthorizationException;
        }

        return $current;
    }
}
