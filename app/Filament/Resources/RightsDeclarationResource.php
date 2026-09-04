<?php

namespace App\Filament\Resources;

use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
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
            Select::make('track_id')->relationship('track', 'title')->searchable()->required(),
            Textarea::make('provenance_reference')->required()->helperText('Reference ownership, contributors and the source of clearance evidence.'),
            Textarea::make('sample_disclosure')->required()->helperText('Declare samples and loops, including none when appropriate. This is not automated legal clearance.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('track.title'), TextColumn::make('status')->badge(), TextColumn::make('verified_at')->dateTime()])->recordActions([
            EditAction::make()->visible(fn (RightsDeclaration $record) => $record->status === 'pending'),
            Action::make('verify')->visible(fn (RightsDeclaration $record) => $record->status === 'pending')->requiresConfirmation()->modalDescription('Confirm you have reviewed the referenced ownership and sample evidence.')->action(fn (RightsDeclaration $record) => app(VerifyRightsDeclaration::class)->handle($record, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => RightsDeclarationResource\Pages\ManageRightsDeclarations::route('/')];
    }
}
