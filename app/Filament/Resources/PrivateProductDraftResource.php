<?php

namespace App\Filament\Resources;

use App\Domain\ProductAuthoring\PrivateDraft;
use App\Domain\ProductAuthoring\ReviewedPrivateDrafts;
use App\Filament\Resources\PrivateProductDraftResource\Pages\ManagePrivateProductDrafts;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

abstract class PrivateProductDraftResource extends OperatorResource
{
    protected static ?string $recordTitleAttribute = 'title';

    abstract public static function commandClass(): string;

    abstract public static function fields(): array;

    public static function command(): ReviewedPrivateDrafts
    {
        return app(static::commandClass());
    }

    public static function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && AdminMultiFactor::satisfiedBy($actor), 403);
        Gate::forUser($actor)->authorize('administer-catalog');

        return $actor;
    }

    public static function getEloquentQuery(): Builder
    {
        static::actor();

        return parent::getEloquentQuery();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::fields());
    }

    public static function declarationFields(string $name, string $label): array
    {
        return [Select::make($name.'.status')->label($label)->native()->required()->live()
            ->options(['unresolved' => 'Not supplied yet', 'authored' => 'Authored private text'])
            ->default('unresolved')->helperText('Authored text records your draft; it does not approve a commercial policy.'),
            Textarea::make($name.'.text')->label($label.' text')->rows(3)->maxLength(2000)
                ->visible(fn (Get $get): bool => $get($name.'.status') === 'authored')->required()->dehydratedWhenHidden(false),
            Textarea::make($name.'.reason')->label('What is still needed for '.$label)->rows(2)->maxLength(1000)
                ->visible(fn (Get $get): bool => $get($name.'.status') === 'unresolved')->required()->dehydratedWhenHidden(false)];
    }

    /** Add explicit nulls only to inactive UI fields; no commercial value is inferred. */
    public static function authoredInput(array $data): array
    {
        $data['description'] ??= '';
        $normalize = function (array $declaration): array {
            return ['status' => $declaration['status'] ?? null, 'text' => $declaration['text'] ?? null, 'reason' => $declaration['reason'] ?? null];
        };
        foreach ($data as $field => $value) {
            if (is_array($value) && array_key_exists('status', $value)) {
                $data[$field] = $normalize($value);
            }
        }
        if (isset($data['variants']) && is_array($data['variants'])) {
            $data['variants'] = array_values($data['variants']);
            foreach ($data['variants'] as &$variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $variant['size'] ??= '';
                $variant['color'] ??= '';
                $variant['source_reference'] = ($variant['source_reference'] ?? null) === '' ? null : ($variant['source_reference'] ?? null);
                if (is_array($variant['availability'] ?? null)) {
                    $variant['availability'] = $normalize($variant['availability']);
                }
            }
        }
        if (isset($data['brief_questions']) && is_array($data['brief_questions'])) {
            $data['brief_questions'] = array_values($data['brief_questions']);
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table->description('Private draft definitions with retained history. Commercial policy, checkout and fulfillment remain unavailable.')
            ->columns([TextColumn::make('title')->searchable()->sortable(), TextColumn::make('version')->label('Draft version')->sortable(),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable()])
            ->defaultSort('updated_at', 'desc')->recordUrl(null)->toolbarActions([])->recordActions([
                Action::make('editDraft')->label('Edit private draft')->modalSubmitActionLabel('Review changes')
                    ->databaseTransaction(false)->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->schema(static::fields())
                    ->fillForm(fn (PrivateDraft $record, ManagePrivateProductDrafts $livewire): array => $livewire->openDraft($record))
                    ->action(fn (PrivateDraft $record, array $data, ManagePrivateProductDrafts $livewire, Action $action) => $livewire->previewDraft($record, $data, $action)),
                Action::make('history')->label('Draft history')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->schema([Textarea::make('history')->label('Retained private versions')->readOnly()->dehydrated(false)->rows(18)])
                    ->fillForm(function (PrivateDraft $record): array {
                        $snapshot = static::command()->snapshot($record->id, static::actor());

                        return ['history' => implode("\n\n", array_map(fn (array $version): string => 'Version '.$version['number'].' — '.$version['created_at']."\n".static::describe($version['manifest']), $snapshot['history']))];
                    }),
            ])->emptyStateHeading('No private drafts')->emptyStateDescription('Author a definition, review its contents and retain the first version.');
    }

    public static function describe(array $manifest): string
    {
        $lines = [$manifest['title'], $manifest['description']];
        foreach ($manifest['brief_questions'] ?? [] as $index => $question) {
            $lines[] = 'Brief '.($index + 1).': '.$question;
        }
        foreach ($manifest['variants'] ?? [] as $index => $variant) {
            $lines[] = 'Variant '.($index + 1).': '.$variant['label'].' ['.$variant['id'].']';
            $lines[] = 'Size: '.($variant['size'] === '' ? 'Not supplied' : $variant['size']).'; color: '.($variant['color'] === '' ? 'Not supplied' : $variant['color']);
            $lines[] = 'Private source reference: '.($variant['source_reference'] ?? 'Not supplied');
            $lines[] = 'Availability: '.static::describeDeclaration($variant['availability']);
        }
        foreach (['scope', 'deposit', 'revisions', 'cancellation', 'source', 'shipping', 'returns'] as $field) {
            if (isset($manifest[$field])) {
                $lines[] = ucfirst($field).': '.static::describeDeclaration($manifest[$field]);
            }
        }

        return implode("\n", $lines);
    }

    private static function describeDeclaration(array $declaration): string
    {
        return $declaration['status'] === 'authored' ? 'Authored draft — '.$declaration['text'] : 'Not supplied — '.$declaration['reason'];
    }
}
