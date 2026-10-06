<?php

namespace App\Filament\Resources\SoundKitDraftResource\Pages;

use App\Domain\SoundKits\SoundKitDrafts;
use App\Filament\Resources\SoundKitDraftResource;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;

class UploadSoundKit extends Page
{
    protected static string $resource = SoundKitDraftResource::class;

    protected string $view = 'filament.sound-kits.resumable-upload';

    protected static ?string $title = 'Resumable kit upload';

    #[Locked]
    public int $kitId;

    #[Locked]
    public int $expectedVersion;

    #[Locked]
    public string $kitTitle;

    public function boot(): void
    {
        request()->attributes->set('_kit_resumable_page', true);
        SoundKitDraftResource::actor();
    }

    public function mount(int $record): void
    {
        $snapshot = app(SoundKitDrafts::class)->snapshot($record, SoundKitDraftResource::actor());
        $this->kitId = $snapshot['id'];
        $this->expectedVersion = $snapshot['version'];
        $this->kitTitle = $snapshot['title'];
    }

    public function prepareUpload(): void
    {
        SoundKitDraftResource::actor();
        // Capture the mounted version, never silently adopt a newer draft. The domain
        // binds this exact version again at start and completion under its locks.
        $this->dispatch('resumable-kit-upload-context', kitId: $this->kitId, expectedVersion: $this->expectedVersion);
    }
}
