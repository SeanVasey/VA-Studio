<?php

namespace App\Filament\Resources;

use App\Domain\Rights\CollaboratorSplitReport;
use App\Domain\Rights\CollaboratorSplits;
use App\Domain\Rights\Models\TrackCollaboratorSplit;
use App\Domain\Rights\SplitType;
use App\Filament\Resources\TrackCollaboratorSplitResource\Pages\ManageTrackCollaboratorSplits;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Staff editor for per-track collaborator splits. Totals and locking are decided by CollaboratorSplits, not here. */
class TrackCollaboratorSplitResource extends OperatorResource
{
    protected static ?string $model = TrackCollaboratorSplit::class;

    protected static ?string $slug = 'track-collaborator-splits';

    protected static ?string $navigationLabel = 'Collaborator splits';

    protected static ?string $modelLabel = 'collaborator split';

    protected static ?string $pluralModelLabel = 'Collaborator splits';

    public static function canDelete(Model $record): bool
    {
        return false; // Removal is the audited "remove" action below, never Filament's direct delete.
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        CollaboratorResource::actor();

        // The total of the row's own set (same track and split type), for the complete/incomplete badge.
        return parent::getEloquentQuery()->select('track_collaborator_splits.*')->selectSub(
            DB::table('track_collaborator_splits AS peers')->selectRaw('COALESCE(SUM(peers.share_bps), 0)')
                ->whereColumn('peers.track_id', 'track_collaborator_splits.track_id')
                ->whereColumn('peers.split_type', 'track_collaborator_splits.split_type'), 'set_total_bps');
    }

    public static function form(Schema $schema): Schema
    {
        $share = TextInput::make('share_bps')->label('Share (basis points)')->required()->integer()->minValue(1)->maxValue(10000)
            ->helperText('Whole basis points: 10000 is 100%, 2500 is 25%. Shares of one track and split type cannot total more than 10000.');

        return $schema->components(fn (?TrackCollaboratorSplit $record): array => $record === null ? [
            Select::make('track_id')->label('Track')->relationship('track', 'title')->saveRelationshipsUsing(null)->searchable()->required(),
            Select::make('collaborator_id')->label('Collaborator')->relationship('collaborator', 'display_name')
                ->saveRelationshipsUsing(null)->searchable()->required(),
            Select::make('split_type')->label('Split type')->options(SplitType::options())->required(),
            $share,
        ] : [Hidden::make('expected_share_bps'), $share]);
    }

    public static function table(Table $table): Table
    {
        return $table->description('Shares per track and split type. A set is complete only at exactly 10000 basis points; anything else is incomplete. '
            .'Metadata only: no price, payout, contract or accounting reads these, and an incomplete set does not block publication.')
            ->columns([
                TextColumn::make('track.title')->label('Track')->searchable()->sortable(),
                TextColumn::make('split_type')->label('Split type')->badge()->formatStateUsing(fn (SplitType $state): string => $state->label()),
                TextColumn::make('collaborator.display_name')->label('Collaborator')->searchable(),
                TextColumn::make('share_bps')->label('Share (bps)')->sortable(),
                TextColumn::make('set_total_bps')->label('Set total (bps)')->state(fn (TrackCollaboratorSplit $record): int => (int) $record->set_total_bps),
                TextColumn::make('set_status')->label('Set')->badge()
                    ->state(fn (TrackCollaboratorSplit $record): string => (int) $record->set_total_bps === CollaboratorSplitReport::COMPLETE_BPS ? 'Complete' : 'Incomplete')
                    ->color(fn (string $state): string => $state === 'Complete' ? 'success' : 'warning'),
            ])->defaultSort('track_id')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->label('Edit share')->modalHeading('Edit share')->databaseTransaction(false)
                    ->fillForm(fn (TrackCollaboratorSplit $record): array => ['share_bps' => $record->share_bps, 'expected_share_bps' => $record->share_bps])
                    ->using(fn (TrackCollaboratorSplit $record, array $data, ManageTrackCollaboratorSplits $livewire): TrackCollaboratorSplit => CollaboratorResource::guarded(
                        $livewire, ['share_bps'], 'share_bps',
                        fn (User $actor): TrackCollaboratorSplit => app(CollaboratorSplits::class)->updateShare($record, $data['expected_share_bps'] ?? null, $data['share_bps'] ?? null, $actor))),
                Action::make('remove')->label('Remove')->color('danger')->requiresConfirmation()->databaseTransaction(false)
                    ->modalHeading('Remove collaborator split')
                    ->modalDescription('Removes this share and records an audit event. The set becomes incomplete until its shares total 10000 basis points again.')
                    ->mountUsing(function (TrackCollaboratorSplit $record, ManageTrackCollaboratorSplits $livewire): void {
                        CollaboratorResource::actor();
                        $livewire->expectedSplitId = (int) $record->id;
                        $livewire->expectedShareBps = $record->share_bps;
                    })
                    ->action(function (TrackCollaboratorSplit $record, ManageTrackCollaboratorSplits $livewire, Action $action): void {
                        $actor = CollaboratorResource::actor();
                        abort_unless($livewire->expectedSplitId === (int) $record->id && $livewire->expectedShareBps !== null, 409);
                        try {
                            // The share the confirmation showed is the expected one: a share changed meanwhile is not removed.
                            app(CollaboratorSplits::class)->removeSplit($record, $livewire->expectedShareBps, $actor);
                            Notification::make()->success()->title('Collaborator split removed')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Split could not be removed')
                                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                            $action->cancel();
                        } finally {
                            $livewire->expectedSplitId = null;
                            $livewire->expectedShareBps = null;
                        }
                    }),
            ])->emptyStateHeading('No collaborator splits')
            ->emptyStateDescription('Add collaborators first, then record each share. Tracks with no recorded shares are reported as incomplete by Check readiness.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTrackCollaboratorSplits::route('/')];
    }
}
