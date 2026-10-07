<?php

namespace App\Support;

/** Visibility only. Every private page and API operation still proves current source/policy/actor authority. */
final class SupportAttachmentUi
{
    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('support-attachments.fixture_enabled') === true;
    }
}
