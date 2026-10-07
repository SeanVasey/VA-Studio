<?php

namespace App\Domain\Customers\ProductionFeatures\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionFeatureBinding extends Model
{
    protected $table = 'production_account_feature_bindings';

    public $timestamps = false;

    protected $guarded = ['id'];
}
