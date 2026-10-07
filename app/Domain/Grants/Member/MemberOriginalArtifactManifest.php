<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipValues;

/** Nonsecret immutable manifest VALUE. It confers no authority, path or readiness. */
final readonly class MemberOriginalArtifactManifest
{
    public function __construct(public string $originId, public string $manifestHash, public array $originals)
    {
        MembershipValues::id($originId);
        MembershipValues::hash($manifestHash);
        MemberGrantException::require(array_is_list($originals) && count($originals) >= 1
            && count($originals) <= MemberGrantPolicy::MAX_ARTIFACTS, 'technical_bound');
        $roles = [];
        foreach ($originals as $original) {
            MemberGrantException::require(is_array($original) && array_keys($original) === ['role', 'sha256', 'bytes', 'storage_policy_hash']
                && is_string($original['role']) && preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $original['role']) === 1
                && ! in_array($original['role'], $roles, true) && is_int($original['bytes'])
                && $original['bytes'] >= 1 && $original['bytes'] <= MemberGrantPolicy::MAX_ARTIFACT_BYTES, 'invalid_manifest');
            MembershipValues::hash($original['sha256']);
            MembershipValues::hash($original['storage_policy_hash']);
            $roles[] = $original['role'];
        }
    }
}
