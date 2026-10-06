<?php

namespace App\Domain\Merch\Models;

use App\Domain\ProductAuthoring\PrivateDraft;

final class MerchDraft extends PrivateDraft
{
    protected $table = 'merch_drafts';
}
