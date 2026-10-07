<?php

namespace App\Domain\Customers\ProductionFeatures\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionListeningLibrary extends Model
{
    protected $table = 'production_listening_libraries';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
