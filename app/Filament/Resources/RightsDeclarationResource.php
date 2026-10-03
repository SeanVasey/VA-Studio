<?php

namespace App\Filament\Resources;

use App\Domain\Rights\Models\RightsDeclaration;
use App\Filament\Resources\RightsDeclarationResource\Pages\ManageRightsDeclarations;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RightsDeclarationResource extends OperatorResource
{
    protected static ?string $model = RightsDeclaration::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('track_id')->relationship('track', 'title')->saveRelationshipsUsing(null)->searchable()->required(),
            Textarea::make('provenance_reference')->required()->helperText('Reference ownership, contributors and the source of clearance evidence.'),
            Textarea::make('sample_disclosure')->required()->helperText('Declare samples and loops, including none when appropriate. This is not automated legal clearance.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('track.title'), TextColumn::make('status')->badge(), TextColumn::make('verified_at')->dateTime()])->recordActions([
            EditAction::make()->visible(fn (RightsDeclaration $record) => $record->status === 'pending')
                ->databaseTransaction(false)
                ->modalDescription('Review the captured evidence before saving. Verified corrections require a new declaration.')
                ->modalContent(fn (ManageRightsDeclarations $livewire) => view('filament.rights.declaration-review', ['review' => $livewire->rightsReview]))
                ->mountUsing(function (RightsDeclaration $record, ManageRightsDeclarations $livewire, Schema $schema): void {
                    $review = $livewire->captureRightsReview($record, 'edit');
                    $schema->fill(['track_id' => $review['track_id'],
                        'provenance_reference' => $review['display']['provenance_reference'],
                        'sample_disclosure' => $review['display']['sample_disclosure']]);
                })
                ->using(fn (RightsDeclaration $record, array $data, Action $action, ManageRightsDeclarations $livewire) => $livewire->updateRightsDeclaration($record, $data, $action)),
            Action::make('verify')->visible(fn (RightsDeclaration $record) => $record->status === 'pending')
                ->databaseTransaction(false)->requiresConfirmation()
                ->modalDescription('Confirm you have reviewed the captured ownership and sample evidence.')
                ->modalContent(fn (ManageRightsDeclarations $livewire) => view('filament.rights.declaration-review', ['review' => $livewire->rightsReview]))
                ->mountUsing(fn (RightsDeclaration $record, ManageRightsDeclarations $livewire) => $livewire->captureRightsReview($record, 'verify'))
                ->successNotificationTitle('Rights declaration verified')
                ->action(fn (RightsDeclaration $record, Action $action, ManageRightsDeclarations $livewire) => $livewire->verifyRightsDeclaration($record, $action)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRightsDeclarations::route('/')];
    }
}
