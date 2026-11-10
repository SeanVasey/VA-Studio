<?php

namespace App\Filament\Resources;

use App\Domain\Rights\CollaboratorRole;
use App\Domain\Rights\CollaboratorSplits;
use App\Domain\Rights\Models\Collaborator;
use App\Filament\Resources\CollaboratorResource\Pages\ManageCollaborators;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Staff editor for credited collaborators. Every write goes through CollaboratorSplits; nothing is decided here. */
class CollaboratorResource extends OperatorResource
{
    protected static ?string $model = Collaborator::class;

    protected static ?string $slug = 'collaborators';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Current staff authority and MFA enrollment, re-read on every request. */
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

    /** Run a domain command and show its refusals on the mounted form's visible fields. */
    public static function guarded(ManageRecords $livewire, array $visible, string $fallback, callable $command): mixed
    {
        try {
            return $command(static::actor());
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $shown = in_array($field, $visible, true) ? $field : $fallback;
                $errors[$path.'.'.$shown] = [...($errors[$path.'.'.$shown] ?? []), ...$messages];
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')->label('Display name')->required()->maxLength(255)
                ->helperText('The credited name only. Do not record payment details, tax ids, email addresses or other contact data.'),
            Select::make('role')->options(CollaboratorRole::options())->required()
                ->helperText('One role per record. Add a second record for a person credited in two roles.'),
            TextInput::make('source_reference')->label('Source reference')->maxLength(255)
                ->helperText('Optional. Where this credit comes from, such as an agreement or export reference. Not contact data.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->description('Credited collaborators for per-track splits. Metadata only: nothing here feeds pricing, accounting, contracts or payouts.')
            ->columns([
                TextColumn::make('display_name')->label('Display name')->searchable()->sortable(),
                TextColumn::make('role')->badge()->formatStateUsing(fn (CollaboratorRole $state): string => $state->label()),
                TextColumn::make('source_reference')->label('Source reference')->placeholder('None'),
                TextColumn::make('splits_count')->counts('splits')->label('Splits'),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('display_name')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->modalHeading('Edit collaborator')->databaseTransaction(false)
                    ->fillForm(fn (Collaborator $record): array => ['display_name' => $record->display_name,
                        'role' => $record->role->value, 'source_reference' => $record->source_reference])
                    ->using(fn (Collaborator $record, array $data, ManageCollaborators $livewire): Collaborator => static::save($record, $data, $livewire)),
            ])->emptyStateHeading('No collaborators')
            ->emptyStateDescription('Add a collaborator, then record their shares under Collaborator splits.');
    }

    public static function save(?Collaborator $record, array $data, ManageCollaborators $livewire): Collaborator
    {
        return static::guarded($livewire, ['display_name', 'role', 'source_reference'], 'display_name',
            fn (User $actor): Collaborator => app(CollaboratorSplits::class)->saveCollaborator($record, $data + ['source_reference' => null], $actor));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCollaborators::route('/')];
    }
}
