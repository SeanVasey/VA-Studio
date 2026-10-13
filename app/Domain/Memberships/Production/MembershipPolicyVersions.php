<?php

namespace App\Domain\Memberships\Production;

use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Immutable authoring within the caller's captured transaction. No mounted operation or commit receipt. */
final class MembershipPolicyVersions
{
    /** Returns provisional identity; callers must not report durable success before their owned commit proof. */
    public function create(MembershipPolicyDocument $document, User $author, MembershipRows $rows): array
    {
        $policy = new MembershipPolicy;
        $configuration = $policy->authoring();
        MembershipException::require($document->facts['provenance'] === $configuration['provenance'], 'provenance');
        $staff = new MembershipPolicyStaff;
        $raw = $staff->lock($author, $rows);
        $existing = $rows->one(MembershipRecords::TABLE, 'policy_hash = ?', [$document->hash()]);
        if ($existing === []) {
            $id = (string) Str::uuid();
            $created = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
            $payload = ['schema_version' => 'membership-policy-record-v1', 'event' => 'policy_version_authored',
                'plan_version_id' => $id, 'document' => $document->facts, 'author_user_id' => (int) $author->getKey(),
                'author_binding_hash' => hash('sha256', CanonicalJson::encode(MembershipRecords::strings($raw))), 'created_at' => $created];
            $row = ['id' => $id, 'policy_hash' => $document->hash(), 'original_terms_hash' => $document->termsHash(),
                'provenance' => $configuration['provenance'], 'payload_ciphertext' => MembershipRecords::encrypt($payload), 'created_at' => $created];
            $row['seal'] = MembershipRecords::seal($row);
            // Reprove after encryption and all provider/gate callbacks before the immutable write.
            $staff->proveCurrent($author, $rows, $raw);
            $policy->proveConfiguration($configuration);
            $rows->execute('INSERT INTO '.(new MembershipSchema)->table(MembershipRecords::TABLE).' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')', array_values($row));
            $existing = $rows->one(MembershipRecords::TABLE, 'id = ?', [$id]);
        }
        $stored = self::document($existing);
        MembershipException::require($stored->hash() === $document->hash() && $stored->facts == $document->facts, 'conflicting_policy');
        $staff->proveCurrent($author, $rows, $raw);
        $policy->proveConfiguration($configuration);
        $rows->assertCurrent();

        return ['id' => $existing['id'], 'policy_hash' => $stored->hash(), 'provenance' => $existing['provenance']];
    }

    public static function document(array $row): MembershipPolicyDocument
    {
        $payload = MembershipRecords::verify($row);
        MembershipPolicyDocument::keys($payload, ['schema_version', 'event', 'plan_version_id', 'document', 'author_user_id', 'author_binding_hash', 'created_at']);
        MembershipException::require($payload['schema_version'] === 'membership-policy-record-v1' && $payload['event'] === 'policy_version_authored'
            && $payload['plan_version_id'] === $row['id'] && $payload['created_at'] === $row['created_at']
            && is_int($payload['author_user_id']) && $payload['author_user_id'] > 0 && is_string($payload['author_binding_hash'])
            && is_array($payload['document']), 'tampered');
        MembershipValues::id($row['id']);
        MembershipValues::utc($row['created_at']);
        MembershipValues::hash($payload['author_binding_hash']);
        $document = MembershipPolicyDocument::from($payload['document']);
        MembershipException::require($document->hash() === $row['policy_hash'] && $document->termsHash() === $row['original_terms_hash']
            && $document->facts['provenance'] === $row['provenance'], 'tampered');

        return $document;
    }
}
