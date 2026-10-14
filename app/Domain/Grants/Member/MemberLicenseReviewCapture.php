<?php

namespace App\Domain\Grants\Member;

/** Detached result after the exact owned read-only review closes. No readiness or effect authority. */
final readonly class MemberLicenseReviewCapture
{
    public function __construct(public MemberLicenseReviewDisplay $display) {}
}
