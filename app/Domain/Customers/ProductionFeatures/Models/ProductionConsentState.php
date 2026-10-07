<?php

namespace App\Domain\Customers\ProductionFeatures\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionConsentState extends Model
{
    protected $table = 'production_consent_states';

    public $timestamps = false;

    protected $guarded = ['id'];
}
