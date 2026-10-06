<?php

namespace App\Domain\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CustomerAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['owner_key'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'active' => 'boolean', 'access_version' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $account): void {
            if ($account->isDirty(['id', 'public_id', 'user_id', 'owner_key', 'created_at'])
                || ! $account->isDirty('active') || $account->access_version !== $account->getOriginal('access_version') + 1) {
                throw new LogicException('Customer identity is retained; access changes require a new version.');
            }
        });
        self::deleting(fn () => throw new LogicException('Customer account identity must be retained.'));
    }
}
