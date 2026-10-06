<?php

namespace App\Domain\Merch\Models;

use App\Domain\ProductAuthoring\PrivateDraftVersion;

final class MerchDraftVersion extends PrivateDraftVersion
{
    protected $table = 'merch_draft_versions';
}
