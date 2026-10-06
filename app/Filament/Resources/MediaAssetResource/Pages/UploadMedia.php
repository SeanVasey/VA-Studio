<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Filament\Resources\MediaAssetResource;
use App\Support\Access\AdminMultiFactor;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class UploadMedia extends Page
{
    protected static string $resource = MediaAssetResource::class;

    protected string $view = 'filament.media.resumable-upload';

    protected static ?string $title = 'Resumable private upload';

    public ?array $data = [];

    public function mount(): void
    {
        $this->authorizeUpload();
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('track_id')->label('Track')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Track::where('title', 'like', '%'.mb_substr($search, 0, 120).'%')->orderBy('title')->limit(50)->pluck('title', 'id')->all())
                ->getOptionLabelUsing(fn ($value): ?string => Track::find($value)?->title),
            Select::make('role')->options(IngestMediaUpload::ROLES)->required(),
        ])->statePath('data');
    }

    public function prepareUpload(): void
    {
        $this->authorizeUpload();
        $state = $this->form->getState();
        $track = Track::findOrFail($state['track_id']);
        // A browser-side file is never hydrated into Livewire state. Only the selected context leaves this action.
        $this->dispatch('resumable-upload-context', trackId: $track->id, role: $state['role']);
    }

    private function authorizeUpload(): void
    {
        Gate::authorize('administer-catalog');
        abort_unless(AdminMultiFactor::satisfiedBy(auth()->user()), 403);
    }
}
