<?php

namespace App\Domain\Rights\Models;

use App\Domain\Catalog\Models\Track;
use App\Domain\Rights\SplitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One collaborator's share of one split type on one track, in basis points. Metadata only. */
class TrackCollaboratorSplit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['split_type' => SplitType::class, 'share_bps' => 'integer'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }
}
