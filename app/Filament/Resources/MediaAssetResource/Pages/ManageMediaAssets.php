<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Filament\Resources\MediaAssetResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMediaAssets extends ManageRecords
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('resumable_upload')->label('Resumable upload')->url(MediaAssetResource::getUrl('resumable-upload')),
            CreateAction::make()->using(fn (array $data): MediaAsset => app(IngestMediaUpload::class)->handle(
                Track::findOrFail($data['track_id']),
                $data['upload'] ?? null,
                $data['role'],
                auth()->user(),
            ))];
    }
}
