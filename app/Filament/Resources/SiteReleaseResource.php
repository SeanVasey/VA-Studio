<?php

namespace App\Filament\Resources;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SiteBuilder\SiteContentUnavailable;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
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
                Repeater::make('content.navigation')->label('Navigation links')->addActionLabel('Add navigation link')->schema([
                    TextInput::make('label')->required()->maxLength(48),
                    Select::make('href')->label('Destination')->options([
                        '/' => 'Home', '/#catalog' => 'Catalog', '/#licenses' => 'Licensing', '/#studio' => 'Studio',
                        '/about' => 'About', '/contact' => 'Contact', '/blog' => 'Blog', '/videos' => 'Videos',
                    ])->required(),
                ])->minItems(1)->maxItems(8)->helperText('A page must be included in this release before linking to it.'),
                Textarea::make('content.footer.description')->label('Footer description')->required()->maxLength(300)->rows(2),
            ]),
            ...static::editorialFields(),
            Section::make('Homepage sharing metadata')->description('Published track pages retain their own title, description and canonical URL.')->schema([
                TextInput::make('content.seo.title')->label('Page title')->required()->maxLength(120),
                Textarea::make('content.seo.description')->label('Page description')->required()->maxLength(300)->rows(3),
            ]),
        ];
    }

    private static function editorialFields(): array
    {
        $sections = [];
        foreach (['about' => 'About', 'contact' => 'Contact', 'blog' => 'Blog', 'videos' => 'Videos'] as $key => $label) {
            $fields = [
                TextInput::make("content.{$key}.title")->label("{$label} page title")->required()->maxLength(120),
                Textarea::make("content.{$key}.description")->label("{$label} page description")->required()->maxLength(300)->rows(3),
            ];
            if (in_array($key, ['about', 'contact'], true)) {
                $fields[] = Repeater::make("content.{$key}.paragraphs")->label("{$label} paragraphs")
                    ->simple(Textarea::make('text')->label("{$label} paragraph")->required()->maxLength(1500)->rows(4))
                    ->minItems(1)->maxItems(12)->defaultItems(1);
            }
            if ($key === 'contact') {
                $fields[] = TextInput::make('content.contact.email')->label('Contact email address')->required()->email()->maxLength(254)
                    ->helperText('A public contact address. The published link opens the visitor’s email app; this site does not send a message.');
            }
            if (in_array($key, ['blog', 'videos'], true)) {
                $entryLabel = $key === 'blog' ? 'Article' : 'Video';
                $entryFields = [
                    TextInput::make('slug')->label("{$entryLabel} URL slug")->required()->maxLength(80)
                        ->helperText('Lowercase letters, digits and single hyphens. Each entry needs a unique URL.'),
                    TextInput::make('title')->label("{$entryLabel} title")->required()->maxLength(120),
                    Textarea::make('description')->label("{$entryLabel} description")->required()->maxLength(300)->rows(3),
                ];
                if ($key === 'blog') {
                    $entryFields[] = Repeater::make('paragraphs')->label('Article paragraphs')
                        ->simple(Textarea::make('text')->label('Article paragraph')->required()->maxLength(1500)->rows(4))
                        ->minItems(1)->maxItems(12)->defaultItems(1);
                } else {
                    $entryFields[] = Select::make('provider')->label('Video provider')->options(['youtube' => 'YouTube', 'vimeo' => 'Vimeo'])->native()->required();
                    $entryFields[] = TextInput::make('video_id')->label('Video ID')->required()->maxLength(12)
                        ->helperText('Use the 11-character YouTube ID or the numeric Vimeo ID. A watch link is created without loading an embedded player.');
                }
                $fields[] = Repeater::make("content.{$key}.entries")->label("{$label} entries")->schema($entryFields)
                    ->itemLabel(fn (array $state): string => ($state['title'] ?? '') ?: "New {$entryLabel}")->minItems(1)->maxItems(30)->defaultItems(1)->collapsible();
            }
            $sections[] = Section::make("{$label} content")->collapsible()->schema([
                Checkbox::make("enabled.{$key}")->label("Include {$label} page")->live(),
                Group::make($fields)->visible(fn (Get $get): bool => (bool) $get("enabled.{$key}")),
            ]);
        }

        return $sections;
    }

    private static function draftForm(array $content, string $label = ''): array
    {
        $content = SiteContentSchema::forEditing($content);
        $enabled = [];
        foreach (['about', 'contact', 'blog', 'videos'] as $key) {
            $enabled[$key] = $content[$key] !== null;
            if ($enabled[$key]) {
                continue;
            }
            $content[$key] = ['title' => '', 'description' => ''];
            if (in_array($key, ['about', 'contact'], true)) {
                $content[$key]['paragraphs'] = [''];
            }
            if ($key === 'contact') {
                $content[$key]['email'] = '';
            }
            if (in_array($key, ['blog', 'videos'], true)) {
                $content[$key]['entries'] = [['slug' => '', 'title' => '', 'description' => ''] + ($key === 'blog'
                    ? ['paragraphs' => ['']] : ['provider' => 'youtube', 'video_id' => ''])];
            }
        }

        return ['label' => $label, 'content' => $content, 'enabled' => $enabled];
    }

    public static function createDraftAction(): Action
    {
        return Action::make('createDraft')->label('New content draft')->schema(static::editor())
            ->modalHeading('Create a private content draft')->modalSubmitActionLabel('Save private draft')
            ->fillForm(function (Action $action): array {
                static::actor();
                try {
                    return static::draftForm(app(SiteContent::class)->current());
                } catch (SiteContentUnavailable $exception) {
                    Notification::make()->danger()->title('The published site content is unavailable')
                        ->body($exception->recoverableByStaff()
                            ? 'The active release failed its integrity check, so public pages are showing a temporary error. Publish or restore an intact release to recover.'
                            : 'The site publication record failed its integrity check or is missing, so public pages are showing a temporary error and publishing is refused. Restore verified data from a backup before retrying.')
                        ->persistent()->send();
                    $action->cancel();
                }
            })
            ->action(fn (array $data, ListSiteReleases $livewire) => static::saveDraft($data, $livewire));
    }

    public static function saveDraft(array $data, ListSiteReleases $livewire): void
    {
        try {
            // Schema version is a server contract, never an operator-editable field.
            $content = ['schema_version' => 2] + $data['content'];
            foreach (['about', 'contact', 'blog', 'videos'] as $key) {
                if (($data['enabled'][$key] ?? false) !== true) {
                    $content[$key] = null;
                }
            }
            app(SiteContent::class)->create($content, $data['label'], static::actor());
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
            ->modalDescription(fn (ListSiteReleases $livewire): string => 'This changes all included public pages, navigation and sharing metadata. Catalog, purchases and licenses retain their existing records.'
                .static::supersedeWarning($livewire->expectedScheduleId))
            ->mountUsing(function (ListSiteReleases $livewire): void {
                static::actor();
                // Retained on the locked component, not re-read when the operator confirms. Scheduling does not move
                // the revision, so the reviewed pending schedule is captured separately.
                $livewire->expectedPublicationRevision = SitePublication::findOrFail(1)->revision;
                $livewire->expectedScheduleId = app(SiteContent::class)->pendingSchedule()?->id;
            })
            // Stays callable while its confirmation is open, so a publication made meanwhile (including this release
            // by the scheduler) is reported rather than silently dropped.
            ->visible(fn (SiteRelease $record, ListSiteReleases $livewire): bool => ($livewire->expectedPublicationRevision !== null
                    && static::isOpen($livewire, $operation, $record))
                || (SitePublication::findOrFail(1)->active_release_id !== $record->id
                    && ($operation !== 'rollback' || SitePublicationRevision::where('release_id', $record->id)->exists())))
            ->action(function (SiteRelease $record, ListSiteReleases $livewire, Action $action) use ($operation): void {
                $actor = static::actor();
                abort_if($livewire->expectedPublicationRevision === null, 409);
                try {
                    app(SiteContent::class)->{$operation}($record->id, $livewire->expectedPublicationRevision, $actor, $livewire->expectedScheduleId);
                    Notification::make()->success()->title($operation === 'rollback' ? 'Previous release restored' : 'Site release published')->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Publication blocked')
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                    $action->cancel();
                } finally {
                    $livewire->expectedPublicationRevision = null;
                    $livewire->expectedScheduleId = null;
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Release')->sortable(),
            // Long labels without spaces must break too, or the table scrolls sideways again.
            TextColumn::make('label')->searchable()->wrap()->extraAttributes(['style' => 'overflow-wrap: anywhere']),
            TextColumn::make('publication_status')->label('Status')->badge()->state(fn (SiteRelease $record): string => SitePublication::findOrFail(1)->active_release_id === $record->id ? 'Active' :
                    (SitePublicationRevision::where('release_id', $record->id)->exists() ? 'Previously published' : 'Private draft')),
            TextColumn::make('schedule_status')->label('Schedule')->badge()->color('warning')->state(function (SiteRelease $record): ?string {
                $pending = app(SiteContent::class)->pendingSchedule();

                return $pending !== null && $pending->release_id === $record->id ? 'Scheduled '.static::utc($pending->publish_at) : null;
            }),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)->recordUrl(null)
            ->recordActions([
                Action::make('preview')->url(fn (SiteRelease $record): string => route('filament.admin.site-releases.preview', $record))->openUrlInNewTab(),
                static::publicationAction('publish', 'Publish release'),
                // Secondary actions share one menu so the table fits a 1440 px window without scrolling sideways.
                ActionGroup::make([
                    Action::make('duplicateDraft')->label('Edit as new draft')->schema(static::editor())
                        ->modalHeading('Edit a copy as a private draft')->modalSubmitActionLabel('Save private draft')
                        ->fillForm(fn (SiteRelease $record): array => static::draftForm(app(SiteContent::class)->preview($record->id, static::actor()), $record->label))
                        ->action(fn (array $data, ListSiteReleases $livewire) => static::saveDraft($data, $livewire)),
                    static::publicationAction('rollback', 'Restore previous release'),
                    static::scheduleAction(),
                ])->label('More')->link()->size(Size::Small)->icon(Heroicon::ChevronDown)->iconPosition(IconPosition::After)
                    // Each row's trigger names its release, starting with the visible word (WCAG 2.5.3).
                    ->extraAttributes(fn (SiteRelease $record): array => ['aria-label' => 'More actions for “'.$record->label.'”']),
            ])->toolbarActions([]);
    }

    public static function scheduleAction(): Action
    {
        return Action::make('schedulePublication')->label('Schedule publication')
            ->modalHeading('Schedule publication')
            ->modalDescription('At the chosen time the scheduler publishes this exact saved release with the same checks as Publish release. Publishing or restoring any release before then cancels this schedule.')
            ->modalSubmitActionLabel('Schedule publication')
            ->schema([
                DateTimePicker::make('publish_at')->label('Publish at (UTC)')->timezone('UTC')->seconds(false)->required()
                    ->helperText(fn (): string => 'Current time: '.static::utc(now()).'. Choose a time at least one minute ahead and within '
                        .SiteContent::SCHEDULE_MAX_DAYS.' days. If it is not published within '.SiteContent::SCHEDULE_GRACE_MINUTES
                        .' minutes of this time, the schedule expires unpublished.'),
            ])
            ->mountUsing(function (ListSiteReleases $livewire, SiteRelease $record, ?Schema $schema = null): void {
                static::actor();
                // Retained on the locked component, not re-read when the operator confirms.
                $livewire->expectedPublicationRevision = SitePublication::findOrFail(1)->revision;
                $livewire->schedulingReleaseId = $record->id;
                $schema?->fill();
            })
            // Filament silently drops a call to a hidden action, so the open confirmation stays callable and the domain
            // reports a schedule or publication made meanwhile.
            ->visible(fn (SiteRelease $record, ListSiteReleases $livewire): bool => ($livewire->schedulingReleaseId === $record->id
                    && static::isOpen($livewire, 'schedulePublication', $record))
                || (SitePublication::findOrFail(1)->active_release_id !== $record->id && app(SiteContent::class)->pendingSchedule() === null))
            ->action(function (array $data, SiteRelease $record, ListSiteReleases $livewire, Action $action): void {
                $actor = static::actor();
                abort_if($livewire->expectedPublicationRevision === null, 409);
                $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                $publishAt = static::scheduleTime($data['publish_at'] ?? null);
                if ($publishAt === null) {
                    throw ValidationException::withMessages([$path.'.publish_at' => 'Choose a valid date and time (UTC).']);
                }
                try {
                    $schedule = app(SiteContent::class)->schedule($record->id, $publishAt, $livewire->expectedPublicationRevision, $actor);
                } catch (ValidationException $exception) {
                    if (isset($exception->errors()['publish_at'])) {
                        // Keep the reviewed revision so the operator can correct only the time.
                        throw ValidationException::withMessages([$path.'.publish_at' => $exception->errors()['publish_at']]);
                    }
                    $livewire->expectedPublicationRevision = null;
                    $livewire->schedulingReleaseId = null;
                    Notification::make()->danger()->title('Scheduling blocked')
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                    $action->cancel();

                    return;
                }
                $livewire->expectedPublicationRevision = null;
                $livewire->schedulingReleaseId = null;
                Notification::make()->success()->title('Publication scheduled')
                    ->body('“'.$record->label.'” is scheduled for '.static::utc($schedule->publish_at).'.')->send();
            });
    }

    public static function cancelScheduleAction(): Action
    {
        return Action::make('cancelScheduledPublication')->label('Cancel scheduled publication')->color('danger')->requiresConfirmation()
            ->modalHeading('Cancel scheduled publication')
            // Describe the schedule this confirmation will cancel, not whichever one is pending when it re-renders.
            ->modalDescription(function (ListSiteReleases $livewire): string {
                $schedule = $livewire->expectedScheduleId === null ? null : SitePublicationSchedule::find($livewire->expectedScheduleId);
                if ($schedule === null) {
                    return 'No publication is scheduled.';
                }
                $subject = '“'.SiteRelease::findOrFail($schedule->release_id)->label.'” at '.static::utc($schedule->publish_at);

                return $schedule->state === 'pending' ? 'Cancel the scheduled publication of '.$subject.'? The live site does not change.'
                    : 'The scheduled publication of '.$subject.' was already resolved. Close this dialog and check the schedule history.';
            })
            ->mountUsing(function (ListSiteReleases $livewire): void {
                static::actor();
                // Cancel exactly the schedule the operator reviewed, never a different one created meanwhile.
                $livewire->expectedScheduleId = app(SiteContent::class)->pendingSchedule()?->id;
            })
            // Stays callable while its confirmation is open, so a schedule resolved meanwhile is reported, not dropped.
            ->visible(fn (ListSiteReleases $livewire): bool => ($livewire->expectedScheduleId !== null
                    && static::isOpen($livewire, 'cancelScheduledPublication'))
                || app(SiteContent::class)->pendingSchedule() !== null)
            ->action(function (ListSiteReleases $livewire, Action $action): void {
                $actor = static::actor();
                abort_if($livewire->expectedScheduleId === null, 409);
                try {
                    app(SiteContent::class)->cancelSchedule($livewire->expectedScheduleId, $actor);
                    Notification::make()->success()->title('Scheduled publication cancelled')->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Cancellation blocked')
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                    $action->cancel();
                } finally {
                    $livewire->expectedScheduleId = null;
                }
            });
    }

    public static function scheduleHistoryAction(): Action
    {
        return Action::make('scheduleHistory')->label('Schedule history')->color('gray')
            ->modalHeading('Scheduled publication history')->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(function () {
                static::actor();
                $schedules = app(SiteContent::class)->recentSchedules(20);
                $labels = SiteRelease::query()->whereIn('id', $schedules->pluck('release_id'))->pluck('label', 'id');
                $users = User::query()->whereIn('id', $schedules->pluck('created_by')->merge($schedules->pluck('resolved_by'))->filter())->pluck('name', 'id');

                return view('admin.site-schedule-history', ['rows' => $schedules->map(fn (SitePublicationSchedule $schedule): array => [
                    'id' => $schedule->id, 'release' => ($labels[$schedule->release_id] ?? 'Unavailable').' (#'.$schedule->release_id.')',
                    'publish_at' => static::utc($schedule->publish_at), 'state' => ucfirst($schedule->state),
                    'reason' => static::scheduleOutcome($schedule->outcome), 'scheduled_by' => $users[$schedule->created_by] ?? 'Unavailable',
                    'resolved' => $schedule->resolved_at === null ? '—' : static::utc($schedule->resolved_at)
                        .($schedule->resolved_by === null ? ' by the scheduler' : ' by '.($users[$schedule->resolved_by] ?? 'Unavailable')),
                    'revision' => $schedule->publication_revision,
                ])->all()]);
            });
    }

    /** Pending-schedule summary for the page heading; null when nothing is scheduled. */
    public static function scheduleSummary(): ?string
    {
        $pending = app(SiteContent::class)->pendingSchedule();
        if ($pending === null) {
            return null;
        }
        $summary = 'Scheduled: “'.SiteRelease::findOrFail($pending->release_id)->label.'” (release #'.$pending->release_id.') publishes at '.static::utc($pending->publish_at).'.';
        if ($pending->publish_at->addMinutes(2)->isPast()) {
            $summary .= ' Overdue: not yet published. Check that the scheduler runs every minute and review the application log.'
                .' It expires unpublished after '.static::utc($pending->publish_at->addMinutes(SiteContent::SCHEDULE_GRACE_MINUTES)).'.';
        }

        return $summary;
    }

    /**
     * Whether the operator's open confirmation is this action (and record). Filament lists an action as mounted before
     * checking its visibility, so callers pair this with state that only the action's mount sets.
     */
    private static function isOpen(ListSiteReleases $livewire, string $name, ?SiteRelease $record = null): bool
    {
        foreach ($livewire->mountedActions as $mounted) {
            if (($mounted['name'] ?? null) === $name
                && ($record === null || (string) ($mounted['context']['recordKey'] ?? '') === (string) $record->getKey())) {
                return true;
            }
        }

        return false;
    }

    /** Built from the schedule captured when the confirmation opened, matching what the domain will require. */
    private static function supersedeWarning(?int $scheduleId): string
    {
        $pending = $scheduleId === null ? null : SitePublicationSchedule::query()->whereKey($scheduleId)->where('state', 'pending')->first();

        return $pending === null ? '' : ' This also cancels the scheduled publication of “'.SiteRelease::findOrFail($pending->release_id)->label
            .'” at '.static::utc($pending->publish_at).'.';
    }

    private static function scheduleOutcome(?string $outcome): string
    {
        return match ($outcome) {
            null => 'Waiting for its time',
            'published' => 'Published by the scheduler',
            'cancelled' => 'Cancelled by staff',
            'manual_publish' => 'Replaced when staff published a release',
            'manual_rollback' => 'Replaced when staff restored a previous release',
            'grace_expired' => 'Expired: not published within '.SiteContent::SCHEDULE_GRACE_MINUTES.' minutes of its time',
            'actor_unauthorized' => 'Not published: the scheduling account lost administrator access or required MFA',
            'stale_revision' => 'Not published: the live site changed after scheduling',
            'integrity' => 'Not published: the release or publication failed its integrity check',
            default => 'Unknown outcome',
        };
    }

    private static function scheduleTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            try {
                $time = CarbonImmutable::createFromFormat($format, $value, 'UTC');
            } catch (\Throwable) {
                continue;
            }
            // Reject rollover such as 2026-02-30: the parsed value must print back exactly as submitted.
            if ($time instanceof CarbonImmutable && $time->format(substr($format, 1)) === $value) {
                return $time;
            }
        }

        return null;
    }

    private static function utc(\DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format('Y-m-d H:i').' UTC';
    }

    public static function getPages(): array
    {
        return ['index' => ListSiteReleases::route('/')];
    }
}
