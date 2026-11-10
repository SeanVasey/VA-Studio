<?php

namespace App\Domain\Rights\Models;

use App\Domain\Rights\CollaboratorRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Staff catalog metadata: a credited name and role. No payment details, tax ids or contact data. */
class Collaborator extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['role' => CollaboratorRole::class];
    }

    public function splits(): HasMany
    {
        return $this->hasMany(TrackCollaboratorSplit::class);
    }
}
