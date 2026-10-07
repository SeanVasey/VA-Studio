<?php

namespace App\Domain\SupportAttachments;

final class FixtureAttachmentPolicy implements AttachmentPolicy
{
    public function commitment(): array { return ['version' => 'support-fixture-policy-v1', 'provenance' => 'synthetic_technical_policy', 'max_bytes' => 5242880, 'max_files' => 10, 'lifetime_seconds' => 86400, 'types' => ['image/png', 'image/jpeg', 'application/pdf', 'text/plain']]; }
    public function assertCurrent(array $sourceBinding): void
    {
        AttachmentException::require(app()->environment(['local', 'testing']) && config('support-attachments.fixture_enabled') === true
            && in_array($sourceBinding['family'] ?? null, ['original_inquiry_session_v1', 'test_service_project_v1'], true));
    }
    public function maxBytes(): int { return 5242880; }
    public function maxFiles(): int { return 10; }
    public function lifetimeSeconds(): int { return 86400; }
    public function allowsMime(string $mime): bool { return in_array($mime, $this->commitment()['types'], true); }
    public function allowsScanEngine(string $engine): bool { return $engine === 'clamav' || ($engine === 'test-only' && app()->environment('testing')); }
}
