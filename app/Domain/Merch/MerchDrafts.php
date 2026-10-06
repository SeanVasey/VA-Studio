<?php

namespace App\Domain\Merch;

use App\Domain\Merch\Models\MerchDraft;
use App\Domain\Merch\Models\MerchDraftVersion;
use App\Domain\ProductAuthoring\PrivateDraftManifest;
use App\Domain\ProductAuthoring\ReviewedPrivateDrafts;

final class MerchDrafts extends ReviewedPrivateDrafts
{
    protected function draftClass(): string
    {
        return MerchDraft::class;
    }

    protected function versionClass(): string
    {
        return MerchDraftVersion::class;
    }

    protected function format(): PrivateDraftManifest
    {
        return new MerchDraftManifest;
    }
}
