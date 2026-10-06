<?php

namespace App\Domain\Services\Models;

use App\Domain\ProductAuthoring\PrivateDraft;

final class ServiceDraft extends PrivateDraft
{
    protected $table = 'service_drafts';
}
