<?php

namespace App\Domain\SupportAttachments;

use App\Domain\Inquiries\InquiryAdministration;
use Filament\Facades\Filament;
use WeakMap;

final class InquiryAttachmentAuthority implements AttachmentMutationAuthority
{
    private WeakMap $issued;

    public function __construct()
    {
        $this->issued = new WeakMap;
    }

    public function lock(string $sourceId, ?int $expectedVersion, string $purpose, AttachmentActor $actor, AttachmentRows $rows): AttachmentSourceProof
    {
        AttachmentException::require(preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $sourceId) === 1, 404);
        AttachmentException::require(in_array($purpose, ['list', 'intake', 'process', 'download', 'delete'], true));
        $user = [];
        $staffPolicy = $this->staffPolicy($actor);
        if ($actor->user !== null) {
            $user = $rows->one('users', 'id = ?', [$actor->user->getKey()]);
            AttachmentException::require($user !== [] && is_string($user['password'] ?? null)
                && hash_equals($user['password'], (string) $actor->user->getRawOriginal('password'))
                && $user['email'] === $actor->user->getRawOriginal('email')
                && ($user['email_verified_at'] ?? null) === $actor->user->getRawOriginal('email_verified_at'), 403);
        }
        if ($actor->audience === 'operator') {
            AttachmentException::require($actor->user !== null, 403);
            try {
                app(InquiryAdministration::class)->actor($actor->user, true);
            } catch (\Throwable) {
                throw new AttachmentException(403);
            }
            AttachmentException::require($rows->one('users', 'id = ?', [$actor->user->getKey()]) === $user, 403);
        } else {
            AttachmentException::require($actor->audience === 'visitor', 403);
        }
        $inquiry = $rows->one('customer_inquiries', 'public_id = ?', [$sourceId]);
        AttachmentException::require($inquiry !== [] && ($actor->audience === 'operator'
            || (is_string($actor->ownerHash()) && hash_equals((string) $inquiry['owner_hash'], $actor->ownerHash()))), 404);
        if (in_array($purpose, ['intake', 'process'], true)) {
            AttachmentException::require(config('inquiries.enabled') === true && $inquiry['state'] !== 'archived', 409, 'source_closed');
            AttachmentException::require($expectedVersion !== null && (int) $inquiry['version'] === $expectedVersion, 409, 'reload');
        }
        $origin = ['family' => 'original_inquiry_session_v1', 'source_id' => $sourceId,
            'provenance' => 'original_unclassified',
            'ownership_commitment' => hash_hmac('sha256', 'attachment-inquiry-origin-v1\0'.$inquiry['owner_hash'], (string) config('app.key')),
            'original_payload_hash' => $inquiry['payload_hash']];
        $binding = $origin + ['source_version' => (int) $inquiry['version'], 'graph_hash' => AttachmentRegistry::hash($inquiry),
            'intake_open' => config('inquiries.enabled') === true && $inquiry['state'] !== 'archived',
            'privacy_notice_hash' => hash('sha256', (string) $inquiry['privacy_notice']),
            'retention_reference_hash' => hash('sha256', (string) $inquiry['retention_policy_reference'])];
        $actorHash = hash_hmac('sha256', 'support-actor-v1\0'.($actor->audience === 'operator' ? 'operator:'.$user['id'] : 'visitor:'.$inquiry['owner_hash']), (string) config('app.key'));
        $token = InquiryAttachmentToken::capture($binding, $origin, (int) $inquiry['version'], $actorHash);
        $proof = new AttachmentSourceProof($token);
        $marker = 'support_inquiry_'.bin2hex(random_bytes(12));
        $rows->identity()->exec('SAVEPOINT '.$marker);
        $this->issued[$proof] = [$inquiry, $user, $actor, $purpose, config('app.key'), config('inquiries.enabled'), $rows, $marker, $staffPolicy];
        $this->proveCurrent($proof, $rows);

        return $proof;
    }

    public function proveCurrent(AttachmentSourceProof $proof, AttachmentRows $rows): void
    {
        AttachmentException::require(isset($this->issued[$proof]));
        [$inquiry, $user, $actor, $purpose, $key, $enabled, $issuedRows, $marker, $staffPolicy] = $this->issued[$proof];
        AttachmentException::require($issuedRows === $rows);
        $rows->assertCurrent();
        try {
            $rows->identity()->exec('RELEASE SAVEPOINT '.$marker);
            $rows->identity()->exec('SAVEPOINT '.$marker);
        } catch (\Throwable) {
            throw new AttachmentException;
        }
        if ($actor->audience === 'operator') {
            try {
                app(InquiryAdministration::class)->actor($actor->user, true);
            } catch (\Throwable) {
                throw new AttachmentException(403);
            }
        }
        AttachmentException::require(config('app.key') === $key && config('inquiries.enabled') === $enabled && $this->staffPolicy($actor) === $staffPolicy);
        AttachmentException::require($user === [] || $rows->one('users', 'id = ?', [$user['id']]) === $user, 403);
        AttachmentException::require($rows->one('customer_inquiries', 'id = ?', [$inquiry['id']]) === $inquiry, 409, 'reload');
        $rows->assertCurrent();
    }

    public function authorizeMutation(AttachmentSourceProof $proof, int $expectedVersion, string $purpose, AttachmentRows $rows): void
    {
        AttachmentException::require(isset($this->issued[$proof]) && in_array($purpose, ['intake', 'process'], true));
        [$inquiry] = $this->issued[$proof];
        AttachmentException::require(config('inquiries.enabled') === true && $inquiry['state'] !== 'archived', 409, 'source_closed');
        AttachmentException::require((int) $inquiry['version'] === $expectedVersion, 409, 'reload');
        $this->proveCurrent($proof, $rows);
    }

    private function staffPolicy(AttachmentActor $actor): array
    {
        if ($actor->audience !== 'operator') {
            return [];
        }
        $panel = Filament::getPanel('admin');
        AttachmentException::require($panel !== null, 403);

        return ['required' => $panel->isMultiFactorAuthenticationRequired(), 'providers' => array_map(fn ($provider) => [$provider::class, spl_object_id($provider)], $panel->getMultiFactorAuthenticationProviders())];
    }
}
