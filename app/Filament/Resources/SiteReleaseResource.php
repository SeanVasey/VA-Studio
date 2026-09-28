<?php

namespace App\Filament\Resources;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** All edits create immutable releases; no generic model update or delete action is exposed. */
class SiteReleaseResource extends OperatorResource
{
    protected static ?string $model = SiteRelease::class;

    protected static ?string $navigationLabel = 'Site content';

    protected static string|UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $recordTitleAttribute = 'label';

    public static function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('administer-catalog');

        return $actor;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $actor = auth()->user()?->fresh();

        return $action === 'viewAny' && $actor instanceof User && Gate::forUser($actor)->allows('administer-catalog')
            ? Response::allow() : Response::deny();
    }

    public static function editor(): array
    {
        return [
            TextInput::make('label')->label('Release label')->required()->maxLength(120)
                ->helperText('An internal label. Saving creates a private draft; the published site changes only after publication.'),
            Section::make('Hero')->schema([
                TextInput::make('content.hero.eyebrow')->label('Eyebrow')->required()->maxLength(120),
                TextInput::make('content.hero.title')->label('Heading, first line')->required()->maxLength(80),
                TextInput::make('content.hero.line_two')->label('Heading, second line')->required()->maxLength(80),
                Textarea::make('content.hero.description')->label('Description')->required()->maxLength(600)->rows(3),
            ]),
            Section::make('Studio')->schema([
                TextInput::make('content.studio.eyebrow')->label('Eyebrow')->required()->maxLength(120),
                TextInput::make('content.studio.title')->label('Heading, first line')->required()->maxLength(80),
                TextInput::make('content.studio.line_two')->label('Heading, second line')->required()->maxLength(80),
                TextInput::make('content.studio.lead')->label('Introduction')->required()->maxLength(240),
                Repeater::make('content.studio.paragraphs')->label('Paragraphs')
                    ->simple(Textarea::make('text')->required()->maxLength(1500)->rows(3))->minItems(1)->maxItems(4),
            ]),
            Section::make('Navigation and footer')->schema([
                Repeater::make('content.navigation')->label('Navigation links')->schema([
                    TextInput::make('label')->required()->maxLength(48),
                    Select::make('href')->label('Destination')->options([
                        '/' => 'Home', '/#catalog' => 'Catalog', '/#licenses' => 'Licensing', '/#studio' => 'Studio',
                    ])->required(),
                ])->minItems(1)->maxItems(4),
                Textarea::make('content.footer.description')->label('Footer description')->required()->maxLength(300)->rows(2),
            ]),
            Section::make('Homepage sharing metadata')->description('Published track pages retain their own title, description and canonical URL.')->schema([
                TextInput::make('content.seo.title')->label('Page title')->required()->maxLength(120),
                Textarea::make('content.seo.description')->label('Page description')->required()->maxLength(300)->rows(3),
            ]),
        ];
    }

    public static function createDraftAction(): Action
    {
        return Action::make('createDraft')->label('New content draft')->schema(static::editor())
            ->modalHeading('Create a private content draft')->modalSubmitActionLabel('Save private draft')
            ->fillForm(function (): array {
                static::actor();

                return ['label' => '', 'content' => app(SiteContent::class)->current()];
            })
            ->action(fn (array $data, ListSiteReleases $livewire) => static::saveDraft($data, $livewire));
    }

    public static function saveDraft(array $data, ListSiteReleases $livewire): void
    {
        try {
            // Schema version is a server contract, never an operator-editable field.
            app(SiteContent::class)->create(['schema_version' => 1] + $data['content'], $data['label'], static::actor());
            Notification::make()->success()->title('Private draft saved')->body('Preview the release before publishing.')->send();
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private static function publicationAction(string $operation, string $label): Action
    {
        return Action::make($operation)->label($label)->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription('This changes the public site text and homepage sharing metadata. Catalog, purchases and licenses retain their existing records.')
            ->mountUsing(function (ListSiteReleases $livewire): void {
                static::actor();
                // Retained on the locked component, not re-read when the operator confirms.
                $livewire->expectedPublicationRevision = SitePublication::findOrFail(1)->revision;
            })
            ->visible(fn (SiteRelease $record): bool => SitePublication::findOrFail(1)->active_release_id !== $record->id
                && ($operation !== 'rollback' || SitePublicationRevision::where('release_id', $record->id)->exists()))
            ->action(function (SiteRelease $record, ListSiteReleases $livewire, Action $action) use ($operation): void {
                $actor = static::actor();
                abort_if($livewire->expectedPublicationRevision === null, 409);
                try {
                    app(SiteContent::class)->{$operation}($record->id, $livewire->expectedPublicationRevision, $actor);
                    Notification::make()->success()->title($operation === 'rollback' ? 'Previous release restored' : 'Site release published')->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Publication blocked')
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                    $action->cancel();
                } finally {
                    $livewire->expectedPublicationRevision = null;
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Release')->sortable(),
            TextColumn::make('label')->searchable(),
            TextColumn::make('publication_status')->label('Status')->badge()->state(fn (SiteRelease $record): string => SitePublication::findOrFail(1)->active_release_id === $record->id ? 'Active' :
                    (SitePublicationRevision::where('release_id', $record->id)->exists() ? 'Previously published' : 'Private draft')),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)->recordUrl(null)
            ->recordActions([
                Action::make('preview')->url(fn (SiteRelease $record): string => route('filament.admin.site-releases.preview', $record))->openUrlInNewTab(),
                Action::make('duplicateDraft')->label('Edit as new draft')->schema(static::editor())
                    ->modalHeading('Edit a copy as a private draft')->modalSubmitActionLabel('Save private draft')
                    ->fillForm(fn (SiteRelease $record): array => ['label' => $record->label, 'content' => app(SiteContent::class)->preview($record->id, static::actor())])
                    ->action(fn (array $data, ListSiteReleases $livewire) => static::saveDraft($data, $livewire)),
                static::publicationAction('publish', 'Publish release'),
                static::publicationAction('rollback', 'Restore previous release'),
            ])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSiteReleases::route('/')];
    }
}
