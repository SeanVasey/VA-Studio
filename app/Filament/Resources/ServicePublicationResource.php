<?php

namespace App\Filament\Resources;

use App\Domain\Services\Catalog\ServiceCatalog;
use App\Domain\Services\Catalog\ServicePublications;
use App\Domain\Services\Models\ServiceDraft;
use App\Domain\Services\Models\ServiceDraftVersion;
use App\Domain\Services\Models\ServicePublication;
use App\Filament\Resources\ServicePublicationResource\Pages\ManageServicePublications;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Staff approval of service drafts for the public service pages. Every write goes through ServicePublications. */
class ServicePublicationResource extends OperatorResource
{
    protected static ?string $model = ServicePublication::class;

    protected static ?string $slug = 'service-pages';

    protected static ?string $navigationLabel = 'Service pages';

    protected static ?string $pluralModelLabel = 'Service pages';

    protected static ?string $recordTitleAttribute = 'slug';

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

    /** Run a domain command and show its refusals on the mounted form's fields. */
    public static function guarded(ManageRecords $livewire, callable $command): mixed
    {
        try {
            return $command(static::actor());
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_draft_id')->label('Service draft')->native()->required()
                ->options(fn (): array => ServiceDraft::query()->orderBy('title')->pluck('title', 'id')->all())
                ->helperText('Approving pins the draft\'s latest saved version. Saving a newer draft version never changes the public page until you approve again.'),
            TextInput::make('slug')->label('Page address')->required()->maxLength(80)->prefix('/services/')
                ->helperText('Lowercase letters, numbers and single hyphens. "projects" is reserved.'),
            TextInput::make('price_minor')->label('Price in minor units (cents)')->numeric()->integer()->minValue(1)
                ->helperText('Optional and entered by you; the draft has no price. Leave this and the currency empty to show "Quoted on request". 25000 means 250.00.'),
            Select::make('currency')->native()->placeholder('No price')->options(array_combine(ServicePublications::CURRENCIES, ServicePublications::CURRENCIES)),
        ]);
    }

    /** Form state to the exact command shape: an empty price is null, a numeric one an integer. */
    public static function commandInput(array $data): array
    {
        $minor = $data['price_minor'] ?? null;
        $currency = $data['currency'] ?? null;

        return ['service_draft_id' => isset($data['service_draft_id']) ? (int) $data['service_draft_id'] : 0, 'slug' => $data['slug'] ?? null,
            'price_minor' => $minor === null || $minor === '' ? null : (is_numeric($minor) ? (int) $minor : $minor),
            'currency' => $currency === null || $currency === '' ? null : $currency];
    }

    public static function save(?ServicePublication $record, array $data, ManageRecords $livewire): ServicePublication
    {
        if ($record !== null) {
            $data['service_draft_id'] = $record->service_draft_id;
        }

        return static::guarded($livewire, fn (User $actor): ServicePublication => app(ServicePublications::class)->approve(static::commandInput($data), $actor));
    }

    public static function table(Table $table): Table
    {
        return $table->description('Services published on the public service pages. The pages stay hidden (404) until the catalog switch is turned on in a reviewed change. A page shows only the pinned draft version and the price entered here; requests go to the ordinary contact inquiry and take no payment.')
            ->columns([
                TextColumn::make('slug')->label('Page address')->prefix('/services/')->searchable()->sortable(),
                TextColumn::make('service_draft_id')->label('Service draft')->formatStateUsing(fn ($state): string => ServiceDraft::query()->find($state)?->title ?? 'Unavailable'),
                TextColumn::make('service_draft_version_id')->label('Approved version')->formatStateUsing(fn ($state): string => (string) (ServiceDraftVersion::query()->find($state)?->number ?? 'Unavailable')),
                TextColumn::make('price_minor')->label('Price')->state(fn (ServicePublication $record): string => ServiceCatalog::price($record->price_minor, $record->currency)),
                TextColumn::make('withdrawn_at')->label('Status')->state(fn (ServicePublication $record): string => $record->withdrawn_at === null ? 'Approved' : 'Withdrawn')->badge(),
                TextColumn::make('approved_at')->label('Approved (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('slug')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->label('Re-approve or edit')->modalHeading('Re-approve or edit service page')->databaseTransaction(false)
                    ->fillForm(fn (ServicePublication $record): array => ['service_draft_id' => $record->service_draft_id, 'slug' => $record->slug,
                        'price_minor' => $record->price_minor, 'currency' => $record->currency])
                    ->using(fn (ServicePublication $record, array $data, ManageServicePublications $livewire): ServicePublication => static::save($record, $data, $livewire)),
                Action::make('withdraw')->label('Withdraw')->color('danger')->requiresConfirmation()->databaseTransaction(false)
                    ->modalDescription('The public page answers 404 immediately. The approval record is kept.')
                    ->visible(fn (ServicePublication $record): bool => $record->withdrawn_at === null)
                    ->action(function (ServicePublication $record, ManageServicePublications $livewire): void {
                        static::guarded($livewire, fn (User $actor) => app(ServicePublications::class)->withdraw($record, $actor));
                        Notification::make()->title('Service page withdrawn')->success()->send();
                    }),
            ])->emptyStateHeading('No service pages')
            ->emptyStateDescription('Approve a saved service draft to give it a public page. Nothing is public until the catalog switch is on.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageServicePublications::route('/')];
    }
}
