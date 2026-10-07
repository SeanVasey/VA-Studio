<?php

namespace App\Domain\SupportAttachments\Models;

use Illuminate\Database\Eloquent\Model;

/** Inspection only. All writes pass through the raw primary attachment service and immutable DB guards. */
final class SupportAttachment extends Model
{
    protected $table = 'support_attachments';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['source_binding', 'source_hash', 'origin_hash', 'actor_hash', 'request_key', 'policy_binding', 'policy_hash', 'manifest', 'manifest_hash', 'lease_token', 'scan_evidence'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted(): void
    {
        self::saving(fn () => throw new \LogicException('Use the attachment authority service.'));
        self::deleting(fn () => throw new \LogicException('Attachment tombstones must be retained.'));
    }
}
