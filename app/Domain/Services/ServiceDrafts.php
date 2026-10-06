<?php

namespace App\Domain\Services;

use App\Domain\ProductAuthoring\PrivateDraftManifest;
use App\Domain\ProductAuthoring\ReviewedPrivateDrafts;
use App\Domain\Services\Models\ServiceDraft;
use App\Domain\Services\Models\ServiceDraftVersion;

final class ServiceDrafts extends ReviewedPrivateDrafts
{
    protected function draftClass(): string
    {
        return ServiceDraft::class;
    }

    protected function versionClass(): string
    {
        return ServiceDraftVersion::class;
    }

    protected function format(): PrivateDraftManifest
    {
        return new ServiceDraftManifest;
    }
}
