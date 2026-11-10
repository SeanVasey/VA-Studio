<?php

namespace App\Filament\Resources\TrackCollaboratorSplitResource\Pages;

use App\Domain\Rights\CollaboratorSplits;
use App\Filament\Resources\CollaboratorResource;
use App\Filament\Resources\TrackCollaboratorSplitResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\Locked;

class ManageTrackCollaboratorSplits extends ManageRecords
{
    protected static string $resource = TrackCollaboratorSplitResource::class;

    #[Locked]
    public ?int $expectedSplitId = null;

    #[Locked]
    public ?int $expectedShareBps = null;

    public function boot(): void
    {
        CollaboratorResource::actor();
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->expectedSplitId = null;
        $this->expectedShareBps = null;
        parent::unmountAction($cancelParentActions);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add split')->modalHeading('Add collaborator split')->createAnother(false)
            ->databaseTransaction(false)
            ->using(fn (array $data) => CollaboratorResource::guarded($this, ['track_id', 'collaborator_id', 'split_type', 'share_bps'], 'share_bps',
                fn (User $actor) => app(CollaboratorSplits::class)->addSplit($data, $actor)))];
    }
}
