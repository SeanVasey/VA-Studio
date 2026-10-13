<?php

namespace App\Domain\Memberships\Production;

use DateTimeImmutable;
use DateTimeZone;

/** Executable award rules, explicitly selected by an author; no commercial defaults. */
final readonly class MembershipAwardTerms
{
    private function __construct(public array $facts) {}

    public static function from(array $facts): self
    {
        MembershipPolicyDocument::keys($facts, ['expiry', 'late_payment']);
        foreach (['expiry', 'late_payment'] as $key) {
            MembershipException::require(is_array($facts[$key]), 'invalid_policy_value');
            MembershipPolicyDocument::keys($facts[$key], ['mode', 'seconds']);
            MembershipException::require(is_string($facts[$key]['mode']) && is_int($facts[$key]['seconds']), 'invalid_policy_value');
        }
        MembershipException::require(in_array($facts['expiry']['mode'], ['none', 'period_end', 'after_period_end'], true)
            && in_array($facts['late_payment']['mode'], ['allow', 'before_period_end', 'grace'], true), 'unsupported_policy');
        foreach (['expiry' => 'after_period_end', 'late_payment' => 'grace'] as $key => $offset) {
            $seconds = $facts[$key]['seconds'];
            // Ten years is a representation bound, not a chosen expiry or grace period.
            MembershipException::require($facts[$key]['mode'] === $offset ? $seconds >= 1 && $seconds <= 315360000 : $seconds === 0, 'invalid_policy_value');
        }

        return new self($facts);
    }

    /** Provider-paid time, never retrieval/award time. End and grace boundaries are exclusive. */
    public function admitsPayment(string $periodEnd, string $paidAt): bool
    {
        MembershipValues::utc($periodEnd);
        MembershipValues::utc($paidAt);
        $rule = $this->facts['late_payment'];

        return $rule['mode'] === 'allow' || $paidAt < self::plus($periodEnd, $rule['seconds']);
    }

    public function expiresAt(string $periodEnd): ?string
    {
        MembershipValues::utc($periodEnd);
        $rule = $this->facts['expiry'];

        return $rule['mode'] === 'none' ? null : self::plus($periodEnd, $rule['seconds']);
    }

    private static function plus(string $date, int $seconds): string
    {
        $result = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
        MembershipException::require(strlen($result) === 19, 'invalid_policy_value');
        MembershipValues::utc($result);

        return $result;
    }
}
