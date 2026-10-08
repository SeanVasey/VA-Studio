<?php

namespace App\Domain\Customers\ProductionFeatures\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionConsentPolicySnapshot extends Model
{
    protected $table = 'production_consent_policies';

    public $timestamps = false;

    protected $guarded = ['id'];
}
