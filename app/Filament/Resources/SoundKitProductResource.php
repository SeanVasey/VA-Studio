<?php

namespace App\Filament\Resources;

use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\SoundKitLicenseKind;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProductMedia;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Filament\Resources\SoundKitProductResource\Pages\ManageSoundKitProducts;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Staff editor for sound-kit products (BL-53, FP-007). Every write goes through SoundKitProducts; nothing is decided here. A
 * product binds a private kit to its verified archive revision, a published sound-kit license, a price, a rights clearance and
 * an offer approval, plus a staff-approved cover and demo audio (separate public assets, never the archive). Products are retained: there is no delete, only unpublish. The public pages stay off behind a code flag and
 * no purchase path exists yet.
 */
class SoundKitProductResource extends OperatorResource
{
    protected static ?string $model = SoundKitProduct::class;

    protected static ?string $slug = 'sound-kit-products';

    protected static ?string $navigationLabel = 'Sound kit products';

    protected static string|UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $recordTitleAttribute = 'title';

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
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())?->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[($path === null ? '' : $path.'.').$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'),
            Select::make('sound_kit_draft_id')->label('Private kit')->required()->searchable()->native(false)->live()
                ->options(fn (?SoundKitProduct $record): array => static::draftOptions($record))
                ->disabled(fn (?SoundKitProduct $record): bool => $record?->exists === true)->dehydrated()
                ->helperText('The private kit this product sells. It cannot change once chosen.'),
            TextInput::make('title')->required()->maxLength(160),
            TextInput::make('slug')->label('Web address')->required()->maxLength(80)->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                ->helperText('Lowercase letters, numbers and hyphens. The page will be /sound-kits/ followed by this.'),
            Textarea::make('summary')->label('Public summary')->rows(4)->maxLength(2000)->helperText('Required to publish. Plain text, written for the public page.'),
            Select::make('revision_public_id')->label('Archive revision')->searchable()->native(false)
                ->options(fn (Get $get): array => static::revisionOptions($get('sound_kit_draft_id')))
                ->helperText('Only technically verified (ready) revisions of the chosen kit. Changing it voids the rights clearance and the offer approval.'),
            Select::make('license_version_id')->label('Sound-kit license version')->searchable()->native(false)
                ->options(fn (): array => static::licenseOptions())
                ->helperText('Only a published sound-kit license is accepted at publication. The text is private and is never shown here.'),
            TextInput::make('price_minor')->label('Price (USD cents)')->numeric()->integer()->minValue(1)->maxValue(2147483647)
                ->helperText('Whole cents, entered by staff. Nothing is pre-filled.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->description('Sound-kit products for the public kit pages. Publishing needs a verified archive, rights clearance, a published sound-kit license, a price and an approved offer. The pages stay off, and no kit can be bought, until reviewed code changes say otherwise.')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->label('Web address'),
                TextColumn::make('is_published')->label('Status')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Published' : 'Draft')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('price_minor')->label('Price (cents)')->placeholder('Not set'),
                TextColumn::make('rights_cleared_at')->label('Rights cleared (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('Not cleared'),
                TextColumn::make('offer_approved_at')->label('Offer approved (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('Not approved'),
                TextColumn::make('cover_state')->label('Cover art')->state(fn (SoundKitProduct $record): string => static::mediaState($record, 'cover'))->wrap(),
                TextColumn::make('demo_state')->label('Demo audio')->state(fn (SoundKitProduct $record): string => static::mediaState($record, 'demo'))->wrap(),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('id', 'desc')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->modalHeading('Edit kit product')->modalWidth('4xl')->databaseTransaction(false)
                    ->fillForm(fn (SoundKitProduct $record): array => static::formState($record))
                    ->using(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire): SoundKitProduct => static::save($record, $data, $livewire, isset($data['revision']) ? (int) $data['revision'] : -1)),
                Action::make('chooseCover')->label('Cover art')->color('gray')->modalHeading('Choose the cover art')->modalWidth('2xl')
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision, 'cover_image_id' => $record->cover_image_id, 'cover_alt' => $record->cover_alt])
                    ->schema([Hidden::make('revision'),
                        Select::make('cover_image_id')->label('Cover image')->required()->native(false)->options(fn (): array => static::coverOptions())
                            ->helperText('Upload square cover art (at least 1200 × 1200 px) under Site images with the use "Release cover art", then choose it here. It is scanned and prepared in several sizes. Choosing a different image voids the cover approval.'),
                        TextInput::make('cover_alt')->label('Cover art description')->required()->maxLength(200)->helperText('For people who cannot see the cover. Plain text.')])
                    ->visible(fn (SoundKitProduct $record): bool => ! $record->is_published)
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Cover art chosen', $livewire,
                        fn (User $actor) => app(SoundKitProductMedia::class)->chooseCover($record, (int) ($data['revision'] ?? -1), $data['cover_image_id'] ?? null, $data['cover_alt'] ?? null, $actor))),
                Action::make('approveCover')->label('Approve cover')->color('warning')->requiresConfirmation()
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription('Approves exactly the chosen image for the public kit page. Choosing a different image voids the approval.')
                    ->visible(fn (SoundKitProduct $record): bool => $record->cover_image_id !== null && ($record->cover_approval_hash === null || array_key_exists('cover', app(SoundKitProductMedia::class)->problems($record, false))))
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Cover art approved', $livewire,
                        fn (User $actor) => app(SoundKitProductMedia::class)->approveCover($record, (int) ($data['revision'] ?? -1), $actor))),
                Action::make('uploadDemo')->label('Upload demo')->color('gray')->modalHeading('Upload the demo audio')->modalSubmitActionLabel('Upload privately')->modalWidth('2xl')
                    ->modalDescription('A short WAV taster (up to 100 MiB and 5 minutes). It is scanned, then prepared as the same seller-tagged MP3 preview a track gets. The original stays private and is never served. Uploading replaces the current demo and voids its approval.')
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision'),
                        // Only a file uploaded through this form: a stored path placed in the form's state is neither described nor accepted.
                        FileUpload::make('upload')->label('Demo (WAV)')->disk('local')->visibility('private')->storeFiles(false)->preventFilePathTampering()
                            ->getUploadedFileUsing(fn (): ?array => null)->maxSize(102400)->acceptedFileTypes(['audio/wav', 'audio/x-wav', 'audio/vnd.wave', 'audio/wave'])->required()
                            ->downloadable(false)->openable(false)->previewable(false)])
                    ->visible(fn (SoundKitProduct $record): bool => ! $record->is_published)
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Demo uploaded; it is being prepared', $livewire,
                        fn (User $actor) => app(SoundKitProductMedia::class)->uploadDemo($record, (int) ($data['revision'] ?? -1), $data['upload'] ?? null, $actor))),
                Action::make('retryDemo')->label('Retry demo')->color('gray')->requiresConfirmation()
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription('Queues processing again after the cause (scanner, media tools, storage or the seller tag setting) is fixed, or when processing is stuck after its time allowance ended.')
                    ->visible(fn (SoundKitProduct $record): bool => $record->demo !== null && SoundKitProductMedia::retryable($record->demo))
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Demo processing queued', $livewire,
                        fn (User $actor) => app(SoundKitProductMedia::class)->retryDemo($record, (int) ($data['revision'] ?? -1), $actor))),
                Action::make('approveDemo')->label('Approve demo')->color('warning')->requiresConfirmation()
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription('Approves exactly this prepared, tagged preview for the public kit page. Uploading a different demo voids the approval.')
                    ->visible(fn (SoundKitProduct $record): bool => $record->demo?->status === 'ready' && ($record->demo_approval_hash === null || array_key_exists('demo', app(SoundKitProductMedia::class)->problems($record, false))))
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Demo approved', $livewire,
                        fn (User $actor) => app(SoundKitProductMedia::class)->approveDemo($record, (int) ($data['revision'] ?? -1), $actor))),
                Action::make('clearRights')->label('Clear rights')->color('warning')->modalHeading('Record rights clearance')
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision'), Textarea::make('rights_note')->label('How rights were cleared')->required()->rows(3)->maxLength(500)
                        ->helperText('For the audit record: who cleared what, and where the evidence is kept. Do not paste private documents.')])
                    ->modalDescription('Confirms staff may sell exactly the chosen archive revision. A provenance note on the private kit does not approve rights.')
                    ->visible(fn (SoundKitProduct $record): bool => $record->sound_kit_revision_id !== null && $record->rights_cleared_revision_id !== $record->sound_kit_revision_id)
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Rights clearance recorded', $livewire,
                        fn (User $actor) => app(SoundKitProducts::class)->clearRights($record, (int) ($data['revision'] ?? -1), $data['rights_note'] ?? null, $actor))),
                Action::make('revokeRights')->label('Revoke rights')->color('danger')->requiresConfirmation()
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription('The kit stops being listed at once. The product record is kept.')
                    ->visible(fn (SoundKitProduct $record): bool => $record->rights_cleared_revision_id !== null)
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Rights clearance revoked', $livewire,
                        fn (User $actor) => app(SoundKitProducts::class)->revokeRights($record, (int) ($data['revision'] ?? -1), $actor))),
                Action::make('approveOffer')->label('Approve offer')->color('warning')->requiresConfirmation()
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription('Approves the price, license version and archive revision now on this product. Changing any of them voids the approval.')
                    ->visible(fn (SoundKitProduct $record): bool => array_key_exists('offer', app(SoundKitProducts::class)->blockers($record)))
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Offer approved', $livewire,
                        fn (User $actor) => app(SoundKitProducts::class)->approveOffer($record, (int) ($data['revision'] ?? -1), $actor))),
                Action::make('publish')->label('Publish')->color('success')->requiresConfirmation()
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription(fn (SoundKitProduct $record): string => ($blockers = app(SoundKitProducts::class)->blockers($record)) === []
                        ? 'The archive bytes and scan evidence are verified again now. The kit becomes visible only once a reviewed code change enables the kit pages, and it cannot be bought yet.'
                        : 'Not ready: '.implode(' ', $blockers))
                    ->visible(fn (SoundKitProduct $record): bool => ! $record->is_published)
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Kit published', $livewire,
                        fn (User $actor) => app(SoundKitProducts::class)->publish($record, (int) ($data['revision'] ?? -1), $actor))),
                Action::make('unpublish')->label('Unpublish')->color('danger')->requiresConfirmation()
                    ->fillForm(fn (SoundKitProduct $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription('The kit disappears from the kit pages and the sitemap. The private kit and its archive are not changed.')
                    ->visible(fn (SoundKitProduct $record): bool => $record->is_published)
                    ->action(fn (SoundKitProduct $record, array $data, ManageSoundKitProducts $livewire) => static::run('Kit unpublished', $livewire,
                        fn (User $actor) => app(SoundKitProducts::class)->unpublish($record, (int) ($data['revision'] ?? -1), $actor))),
            ])->emptyStateHeading('No kit products')
            ->emptyStateDescription('Add a product for a verified private kit. Nothing is pre-filled and no price is invented.');
    }

    /** Create or edit: hands the exact fields to the domain. The private kit of an existing product is never taken from the form. */
    public static function save(?SoundKitProduct $record, array $data, ManageRecords $livewire, ?int $expectedRevision = null): SoundKitProduct
    {
        return static::guarded($livewire, fn (User $actor): SoundKitProduct => app(SoundKitProducts::class)->save($record, [
            'sound_kit_draft_id' => $record?->exists ? $record->sound_kit_draft_id : ($data['sound_kit_draft_id'] ?? null),
            'title' => $data['title'] ?? null, 'slug' => $data['slug'] ?? null, 'summary' => $data['summary'] ?? null,
            'revision_public_id' => ($data['revision_public_id'] ?? null) ?: null, 'license_version_id' => ($data['license_version_id'] ?? null) ?: null,
            'price_minor' => ($data['price_minor'] ?? null) === null || $data['price_minor'] === '' ? null : $data['price_minor'],
        ], $actor, $record === null ? null : $expectedRevision));
    }

    private static function run(string $done, ManageRecords $livewire, callable $command): void
    {
        try {
            static::guarded($livewire, $command);
        } catch (ValidationException $exception) {
            // These actions have no fields to hang an error on, so say what is missing in a notification.
            Notification::make()->danger()->title('Not done')->body(implode(' ', array_merge(...array_values($exception->errors()))))->send();

            return;
        }
        Notification::make()->success()->title($done)->send();
    }

    /** What a product's cover or demo needs next, or "Approved". */
    private static function mediaState(SoundKitProduct $record, string $which): string
    {
        return app(SoundKitProductMedia::class)->problems($record, false)[$which] ?? 'Approved';
    }

    /** @return array<int, string> ready cover images (the release cover slot), newest first */
    private static function coverOptions(): array
    {
        return SiteImage::query()->where('slot', SoundKitProductMedia::COVER_SLOT)->where('status', 'ready')->orderByDesc('id')->get()
            ->mapWithKeys(fn (SiteImage $image): array => [$image->id => '#'.$image->id.' · '.$image->original_name.' ('.$image->width.' × '.$image->height.')'])->all();
    }

    /** @return array<string, mixed> */
    private static function formState(SoundKitProduct $record): array
    {
        return [
            'revision' => $record->revision, 'sound_kit_draft_id' => $record->sound_kit_draft_id, 'title' => $record->title, 'slug' => $record->slug,
            'summary' => $record->summary, 'license_version_id' => $record->license_version_id, 'price_minor' => $record->price_minor,
            'revision_public_id' => $record->sound_kit_revision_id === null ? null : SoundKitRevision::query()->whereKey($record->sound_kit_revision_id)->value('public_id'),
        ];
    }

    /** @return array<int, string> private kits that have no product yet (or the current product's own kit) */
    private static function draftOptions(?SoundKitProduct $record): array
    {
        return SoundKitDraft::query()->where(fn (Builder $query) => $query->whereNotIn('id', SoundKitProduct::query()->select('sound_kit_draft_id'))
            ->when($record?->exists, fn (Builder $inner) => $inner->orWhere('id', $record->sound_kit_draft_id)))
            ->orderBy('title')->orderBy('id')->pluck('title', 'id')->all();
    }

    /** @return array<string, string> ready revisions of the chosen kit, labelled by number and manifest prefix */
    private static function revisionOptions(mixed $draftId): array
    {
        if (! is_numeric($draftId)) {
            return [];
        }

        return SoundKitRevision::query()->where('sound_kit_draft_id', (int) $draftId)->where('status', 'ready')->orderByDesc('number')->get(['public_id', 'number', 'manifest_sha256'])
            ->mapWithKeys(fn (SoundKitRevision $revision): array => [$revision->public_id => 'Revision '.$revision->number.' (manifest '.substr((string) $revision->manifest_sha256, 0, 12).')'])->all();
    }

    /** @return array<int, string> published sound-kit license versions, by name only (the text is private) */
    private static function licenseOptions(): array
    {
        return LicenseVersion::query()->with('template')->where('status', 'published')->orderByDesc('id')->get()
            ->filter(fn (LicenseVersion $version): bool => SoundKitLicenseKind::isKitType($version->template?->type))
            ->mapWithKeys(fn (LicenseVersion $version): array => [$version->id => ($version->template->name ?? 'License').' (version #'.$version->id.')'])->all();
    }

    public static function getPages(): array
    {
        return ['index' => ManageSoundKitProducts::route('/')];
    }
}
