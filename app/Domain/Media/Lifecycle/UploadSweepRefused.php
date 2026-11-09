<?php

namespace App\Domain\Media\Lifecycle;

use RuntimeException;

/** A refusal with a fixed reason code; it never carries a path, a session identity or a customer value. */
final class UploadSweepRefused extends RuntimeException
{
    public const MESSAGES = [
        'age_too_short' => 'The minimum age is shorter than the upload lifetime (24 hours).',
        'root_user' => 'Refusing to sweep as root: run it as the site user that owns the private disk.',
        'private_disk' => 'The local disk is not an unserved private local disk.',
        'root_unsafe' => 'The private disk root is not a real directory, is writable by other users, or is a link.',
        'root_public' => 'The private disk root is, or is inside, a public or served directory.',
        'temp_root_unsafe' => 'The upload temp root is not a private (0700) directory owned by the sweeping user.',
        'cleanup_incomplete' => 'A transport directory could not be removed completely.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'The upload session sweep was refused.');
    }
}
