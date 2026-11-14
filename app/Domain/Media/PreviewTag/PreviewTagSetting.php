<?php

namespace App\Domain\Media\PreviewTag;

use Illuminate\Database\Eloquent\Model;

/** One row of `preview_tag_settings`: the store default (`scope_key` "default") or one track's override ("track:<id>"). */
class PreviewTagSetting extends Model
{
    public const DEFAULT_KEY = 'default';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tagged' => 'boolean', 'revision' => 'integer', 'track_id' => 'integer'];
    }

    public static function trackKey(int $trackId): string
    {
        return 'track:'.$trackId;
    }
}
