<?php

namespace App\Domain\Customers\ProductionFeatures\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionConsentEvent extends Model
{
    protected $table = 'production_consent_events';

    public $timestamps = false;

    protected $guarded = ['id'];
}
