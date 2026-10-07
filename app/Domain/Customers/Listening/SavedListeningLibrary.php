<?php

namespace App\Domain\Customers\Listening;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SavedListeningLibrary extends Model
{
    protected $table = 'customer_saved_tracks';

    protected $guarded = ['id'];

    protected $hidden = ['customer_account_id', 'payload'];

    protected function casts(): array
    {
        return ['customer_account_id' => 'integer', 'version' => 'integer', 'payload' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $library): void {
            if ($library->isDirty(['id', 'customer_account_id', 'created_at'])
                || $library->version !== $library->getOriginal('version') + 1) {
                throw new LogicException('Saved listening updates require the retained owner and next version.');
            }
        });
        self::deleting(fn () => throw new LogicException('Clear saved lists through the owned versioned command.'));
    }
}
