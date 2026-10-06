<?php

namespace App\Domain\Memberships;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Explicit synthetic policy only; this module has no production enablement path. */
final class MembershipPolicy
{
    public const MAX_ALLOWANCE = 1000000;

    public const MAX_EVENTS = 10000;

    public function standalone(): void
    {
        $this->requireEnabled();
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Membership commands require their own transaction.');
        }
    }

    public function requireEnabled(): void
    {
        if (! app()->environment('local', 'testing') || config('memberships.test_mode_enabled') !== true) {
            throw new AuthorizationException('Synthetic memberships are unavailable.');
        }
    }

    public function plan(array $data): array
    {
        $this->keys($data, ['title', 'policy'], 'plan');
        $title = $data['title'];
        if (! is_string($title) || trim($title) !== $title || $title === '' || ! mb_check_encoding($title, 'UTF-8')
            || mb_strlen($title) > 180 || preg_match('/[\x00-\x1f\x7f]/', $title)) {
            $this->reject('plan', 'Use an explicit bounded private plan title.');
        }
        $policy = $data['policy'];
        if (! is_array($policy)) {
            $this->reject('policy', 'Supply every synthetic benefit policy field.');
        }
        $this->keys($policy, ['schema_version', 'unit', 'allowance', 'validity_seconds', 'rollover', 'reversal_allowed'], 'policy');
        if ($policy['schema_version'] !== 1 || ! is_string($policy['unit']) || ! preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $policy['unit'])
            || ! is_int($policy['allowance']) || $policy['allowance'] < 1 || $policy['allowance'] > self::MAX_ALLOWANCE
            || ($policy['validity_seconds'] !== null && (! is_int($policy['validity_seconds']) || $policy['validity_seconds'] < 1 || $policy['validity_seconds'] > 31536000))
            || $policy['rollover'] !== 'none' || ! is_bool($policy['reversal_allowed'])) {
            $this->reject('policy', 'Supply supported explicit synthetic policy; rollover and renewal need a separate contract.');
        }

        $normalized = [];
        foreach (['schema_version', 'unit', 'allowance', 'validity_seconds', 'rollover', 'reversal_allowed'] as $key) {
            $normalized[$key] = $policy[$key];
        }

        return ['title' => $title, 'policy' => $normalized];
    }

    public function token(string $value, string $field, bool $synthetic = false): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $value) || ($synthetic && ! str_starts_with($value, 'synthetic:'))) {
            $this->reject($field, 'Use a bounded explicit synthetic resource identity.');
        }

        return $value;
    }

    public function amount(mixed $value): int
    {
        if (! is_int($value) || $value < 1 || $value > self::MAX_ALLOWANCE) {
            $this->reject('credits', 'Use a positive integer credit amount within the supported bound.');
        }

        return $value;
    }

    public function keys(array $value, array $keys, string $field): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        $expected = $keys;
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->reject($field, 'Unexpected membership fields; review the exact supported contract.');
        }
    }

    public function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
