<?php

namespace App\Filament\Resources;

use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantPolicy;
use App\Domain\Grants\Free\FreeGrantRows;
use App\Domain\Grants\Free\FreeGrantStaff;
use App\Domain\Grants\Free\Models\FreeDefinition;
use App\Filament\Resources\FreeDefinitionResource\Pages\ManageFreeDefinitions;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

final class FreeDefinitionResource extends OperatorResource
{
    protected static ?string $model = FreeDefinition::class;

    protected static ?string $slug = 'free-grant-definitions';

    protected static ?string $pluralModelLabel = 'Private free grant definitions';

    protected static string|UnitEnum|null $navigationGroup = 'Local preparation';

    public static function actor(): User
    {
        $actor = auth()->user();
        FreeGrantException::require($actor instanceof User, 403);
        DB::transaction(function () use ($actor): void {
            $rows = new FreeGrantRows;
            (new FreeGrantStaff)->lock($actor, $rows);
        });

        return $actor;
    }

    public static function canAccess(): bool
    {
        try {
            (new FreeGrantPolicy)->requireEnabled();
            self::actor();

            return true;
        } catch (FreeGrantException) {
            return false;
        }
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return $action === 'viewAny' && self::canAccess() ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        self::actor();

        return parent::getEloquentQuery()->select('free_definitions.*');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('public_id')->label('Retained definition'),
            TextColumn::make('title')->state(fn (FreeDefinition $record) => (new FreeGrantDefinitions)->readStaff($record->public_id, self::actor())['title']),
            TextColumn::make('created_at')->dateTime(),
        ])->recordActions([
            Action::make('inspect')->label('Read exact definition')->databaseTransaction(false)
                ->modalContent(fn (FreeDefinition $record) => view('admin.free-grant-definition', ['definition' => (new FreeGrantDefinitions)->readStaff($record->public_id, self::actor())]))->modalSubmitAction(false),
            Action::make('approveFreeScope')->label('Separate free-scope review')->databaseTransaction(false)
                ->mountUsing(fn (FreeDefinition $record, ManageFreeDefinitions $livewire) => $livewire->capture($record))
                ->schema([TextInput::make('reference')->label('Explicit free-purpose review reference')->required()->maxLength(192),
                    Checkbox::make('freeScopeConfirmed')->label('I reviewed this exact license for the explicitly authored free purpose.')->accepted(),
                    Checkbox::make('scopeBindingConfirmed')->label('I confirmed the recording belongs to the explicit rights scope.')->accepted(),
                    Checkbox::make('assetManifestConfirmed')->label('I confirmed every exact deliverable revision and hash.')->accepted()])
                ->modalContent(fn (FreeDefinition $record) => view('admin.free-grant-definition', ['definition' => (new FreeGrantDefinitions)->readStaff($record->public_id, self::actor())]))
                ->action(fn (array $data, ManageFreeDefinitions $livewire) => $livewire->reviewFree($data)),
            Action::make('openAdmission')->label('Open new requests')->databaseTransaction(false)
                ->mountUsing(fn (FreeDefinition $record, ManageFreeDefinitions $livewire) => $livewire->capture($record))
                ->schema([Textarea::make('reason')->label('Explicit admission reason')->required()->maxLength(1024)])
                ->action(fn (array $data, ManageFreeDefinitions $livewire) => $livewire->availability(true, $data)),
            Action::make('closeAdmission')->label('Close new requests')->databaseTransaction(false)
                ->mountUsing(fn (FreeDefinition $record, ManageFreeDefinitions $livewire) => $livewire->capture($record))
                ->schema([Textarea::make('reason')->label('Explicit closure reason')->required()->maxLength(1024)])
                ->action(fn (array $data, ManageFreeDefinitions $livewire) => $livewire->availability(false, $data)),
        ]);
    }

    public static function fields(): array
    {
        return [TextInput::make('title')->required()->maxLength(160),
            Textarea::make('assentText')->label('Exact affirmative free-purpose text')->required()->rows(6)->maxLength(8192),
            TextInput::make('termsReference')->label('Explicit free-purpose terms reference')->required()->maxLength(192),
            TextInput::make('licenseId')->label('Published non-exclusive license version ID')->required()->integer()->minValue(1),
            TextInput::make('trackId')->label('Exact recording ID')->required()->integer()->minValue(1),
            TextInput::make('scopeId')->label('Explicit reviewed rights scope ID')->required()->integer()->minValue(1),
            TagsInput::make('assetIds')->label('Exact processed WAV/MP3 asset IDs')->required()->helperText('Enter each immutable deliverable ID; the domain requires exactly the license roles.'),
            TextInput::make('maxOrigins')->label('Maximum new grants')->required()->integer()->minValue(1)->maxValue(100000),
            TextInput::make('maxDownloads')->label('Committed download attempt limit per grant')->required()->integer()->minValue(1)->maxValue(1000),
            TextInput::make('tokenTtlSeconds')->label('Authorization lifetime in seconds')->required()->integer()->minValue(30)->maxValue(300)];
    }

    public static function getPages(): array
    {
        return ['index' => ManageFreeDefinitions::route('/')];
    }
}
