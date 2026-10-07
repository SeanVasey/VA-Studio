<?php

namespace App\Providers;

use App\Domain\Services\Projects\Attachments\ServiceProjectAttachmentAuthority;
use App\Domain\SupportAttachments\AttachmentRegistry;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Domain\SupportAttachments\InquiryAttachmentAuthority;
use Illuminate\Support\ServiceProvider;

final class SupportAttachmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttachmentRegistry::class, fn () => new AttachmentRegistry(
            ['inquiry' => new InquiryAttachmentAuthority, 'project' => new ServiceProjectAttachmentAuthority],
            ['original_inquiry_session_v1' => new FixtureAttachmentPolicy, 'test_service_project_v1' => new FixtureAttachmentPolicy],
        ));
    }
}
