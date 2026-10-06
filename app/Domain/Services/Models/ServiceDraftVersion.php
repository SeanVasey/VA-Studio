<?php

namespace App\Domain\Services\Models;

use App\Domain\ProductAuthoring\PrivateDraftVersion;

final class ServiceDraftVersion extends PrivateDraftVersion
{
    protected $table = 'service_draft_versions';
}
