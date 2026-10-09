<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\PromotionAdministration;
use App\Filament\Resources\TestPromotionResource\Pages\ListTestPromotions;
use App\Models\User;
use App\Support\Environment\TestEnvironment;
use App\Support\Money\MinorUnits;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** Immutable test policies and guarded availability commands; no commerce record editing. */
class TestPromotionResource extends OperatorResource
{
    protected static ?string $model = PromotionCampaign::class;
    protected static ?string $slug = 'test-promotions';
    protected static ?string $navigationLabel = 'Test promotions';
    protected static ?string $pluralModelLabel = 'Test promotions';
    protected static string|UnitEnum|null $navigationGroup = 'Test commerce';

    public static function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && TestEnvironment::admitsTestCommerce(), 403);
        Gate::forUser($actor)->authorize('administer-catalog');

        return $actor;
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user()?->fresh();

        return TestEnvironment::admitsTestCommerce() && $actor instanceof User && Gate::forUser($actor)->allows('administer-catalog');
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return $action === 'viewAny' && static::canAccess() ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return app(PromotionAdministration::class)->campaigns(static::actor());
    }

    /** Exact digit strings from HTML controls become integers only after type and bounds checks. */
    public static function integer(mixed $value, string $field, int $minimum = 0, int $maximum = MinorUnits::MAX): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[0-9]+\z/D', (string) $value)) {
            throw ValidationException::withMessages([$field => 'Use whole digits only; decimals and scientific notation are not accepted.']);
        }
        $digits = ltrim((string) $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $bound = (string) $maximum;
        if (strlen($digits) > strlen($bound) || (strlen($digits) === strlen($bound) && strcmp($digits, $bound) > 0) || (int) $digits < $minimum) {
            throw ValidationException::withMessages([$field => "Use a whole number from {$minimum} to {$maximum}."]);
        }

        return (int) $digits;
    }

    private static function integerInput(string $name, string $label, int $minimum = 0, int $maximum = MinorUnits::MAX): TextInput
    {
        return TextInput::make($name)->label($label)->inputMode('numeric')->required()->maxLength(32)
            ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail) use ($name, $minimum, $maximum): void {
                try { static::integer($value, $name, $minimum, $maximum); }
                catch (ValidationException $exception) { $fail($exception->errors()[$name][0]); }
            }]);
    }

    public static function editor(): array
    {
        return [
            Section::make('Campaign identity')->description('Test mode · USD. Saving retains these terms permanently and leaves the promotion disabled.')->schema([
                TextInput::make('key')->label('Campaign key')->required()->maxLength(64)->regex('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D')
                    ->helperText('A new lowercase internal identity. Existing campaign keys and codes cannot be reused.'),
                TextInput::make('code')->label('Promotion code')->required()->maxLength(32)->regex('/\A[A-Z0-9][A-Z0-9_-]{2,31}\z/D')
                    ->helperText('3–32 uppercase letters, digits, underscores or hyphens; begin with a letter or digit.'),
                TextInput::make('effective_from')->label('Starts at (UTC)')->required()->maxLength(20)->placeholder('2026-10-01T00:00:00Z'),
                TextInput::make('effective_until')->label('Ends at (UTC)')->required()->maxLength(20)->placeholder('2026-10-31T23:59:59Z')
                    ->helperText('Use exact UTC seconds: YYYY-MM-DDTHH:MM:SSZ. The end instant is excluded.'),
            ]),
            Section::make('Eligibility and discount')->description('One promotion per selection. The server checks current offers, inventory, eligible subtotal and final discount.')->schema([
                Select::make('eligibility_mode')->label('Eligible offers')->options([
                    'all_non_exclusive' => 'All non-exclusive offers', 'offer_revisions' => 'Selected exact offer revisions',
                ])->required()->default('all_non_exclusive')->live(),
                Select::make('offer_revision_ids')->label('Exact offer revisions')->multiple()->searchable()->maxItems(100)
                    ->getSearchResultsUsing(fn (string $search): array => static::revisionOptions($search))
                    ->getOptionLabelsUsing(fn (array $values): array => static::revisionOptions(null, $values))
                    ->visible(fn (Get $get): bool => $get('eligibility_mode') === 'offer_revisions')
                    ->required(fn (Get $get): bool => $get('eligibility_mode') === 'offer_revisions')
                    ->helperText('Search a track title. These exact revisions remain the eligibility list after an offer changes.'),
                static::integerInput('minimum_subtotal_minor', 'Minimum eligible subtotal (cents)')->default('0')->helperText('USD cents; 100 cents = $1.00. Applies to eligible lines only.'),
                Select::make('discount_type')->label('Discount type')->options(['fixed' => 'Fixed amount', 'percentage' => 'Percentage'])->default('fixed')->required()->live(),
                static::integerInput('amount_minor', 'Fixed discount (cents)', 1)->visible(fn (Get $get): bool => $get('discount_type') === 'fixed')
                    ->helperText('USD cents; 500 cents = $5.00. A discount exceeding the eligible subtotal is rejected.'),
                static::integerInput('rate_bps', 'Percentage (basis points)', 1, 10000)->visible(fn (Get $get): bool => $get('discount_type') === 'percentage')
                    ->helperText('100 basis points = 1%; 1250 = 12.5%; maximum 10000 = 100%.'),
                static::integerInput('max_discount_minor', 'Maximum discount (cents)', 1)->visible(fn (Get $get): bool => $get('discount_type') === 'percentage')
                    ->helperText('Required USD cap for the percentage discount.'),
                static::integerInput('max_uses', 'Lifetime use limit', 1, 10000)
                    ->helperText('Shared by all buyers. Pending and consumed uses retain capacity; expired unstarted holds do not.'),
            ]),
        ];
    }

    private static function revisionOptions(?string $search = null, ?array $ids = null): array
    {
        static::actor();

        return OfferRevision::query()->with('track')->when($ids !== null, fn (Builder $query) => $query->whereIn('id', array_slice($ids, 0, 100)))
            ->when($search !== null, fn (Builder $query) => $query->whereHas('track', fn (Builder $track) => $track->where('title', 'like', '%'.mb_substr($search, 0, 120).'%')))
            ->orderByDesc('id')->limit($ids !== null ? 100 : 50)->get()->mapWithKeys(fn (OfferRevision $revision): array => [
                $revision->id => $revision->track->title.' · offer #'.$revision->offer_id.' revision '.$revision->revision.' · '.$revision->currency.' '.$revision->price_minor.' cents',
            ])->all();
    }

    public static function policyFromForm(array $data): array
    {
        $mode = $data['eligibility_mode'] ?? null;
        $eligibility = ['mode' => $mode];
        if ($mode === 'offer_revisions') {
            $ids = $data['offer_revision_ids'] ?? [];
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 100) {
                throw ValidationException::withMessages(['offer_revision_ids' => 'Choose up to 100 exact offer revisions.']);
            }
            $ids = array_map(fn ($id): int => static::integer($id, 'offer_revision_ids', 1), $ids);
            sort($ids, SORT_NUMERIC);
            $eligibility['offer_revision_ids'] = $ids;
        }
        $discount = ['type' => $data['discount_type'] ?? null];
        if ($discount['type'] === 'fixed') {
            $discount['amount_minor'] = static::integer($data['amount_minor'] ?? null, 'amount_minor', 1);
        } else {
            $discount['rate_bps'] = static::integer($data['rate_bps'] ?? null, 'rate_bps', 1, 10000);
            $discount['max_discount_minor'] = static::integer($data['max_discount_minor'] ?? null, 'max_discount_minor', 1);
        }

        return ['schema_version' => 1, 'scope' => 'test', 'currency' => 'USD', 'version' => 1,
            'key' => $data['key'], 'code' => $data['code'], 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'],
            'eligibility' => $eligibility, 'minimum_subtotal_minor' => static::integer($data['minimum_subtotal_minor'] ?? null, 'minimum_subtotal_minor'),
            'discount' => $discount, 'stacking' => 'none', 'allocation' => 'largest_remainder_v1', 'release' => 'unstarted_at_expiry',
            'max_uses' => static::integer($data['max_uses'] ?? null, 'max_uses', 1, 10000)];
    }

    public static function createAction(string $name = 'createPromotion'): Action
    {
        $action = Action::make($name)->label($name === 'createPromotion' ? 'New test promotion' : 'Copy as new promotion')
            ->schema(static::editor())->modalHeading('Create a disabled test promotion')->modalSubmitActionLabel('Create disabled promotion')
            ->action(function (array $data, ListTestPromotions $livewire): void {
                try {
                    app(PromotionAdministration::class)->create(static::policyFromForm($data), static::actor());
                    $livewire->clearCampaignDetails();
                    Notification::make()->success()->title('Disabled test promotion created')->body('Review the saved terms and usage before enabling it.')->send();
                } catch (ValidationException $exception) {
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) { $errors[$path.'.'.$field] = $messages; }
                    // Domain-level policy errors must remain visible even without a matching form field.
                    Notification::make()->danger()->title('Promotion could not be saved')->body(implode(' ', array_merge(...array_values($exception->errors()))))->send();
                    throw ValidationException::withMessages($errors);
                }
            });

        if ($name !== 'createPromotion') {
            $action->fillForm(function (PromotionCampaign $record): array {
                $policy = app(PromotionAdministration::class)->detail($record->id, static::actor())['policy'];

                return ['key' => '', 'code' => '', 'effective_from' => $policy['effective_from'], 'effective_until' => $policy['effective_until'],
                    'eligibility_mode' => $policy['eligibility']['mode'], 'offer_revision_ids' => $policy['eligibility']['offer_revision_ids'] ?? [],
                    'minimum_subtotal_minor' => (string) $policy['minimum_subtotal_minor'], 'discount_type' => $policy['discount']['type'],
                    'amount_minor' => isset($policy['discount']['amount_minor']) ? (string) $policy['discount']['amount_minor'] : null,
                    'rate_bps' => isset($policy['discount']['rate_bps']) ? (string) $policy['discount']['rate_bps'] : null,
                    'max_discount_minor' => isset($policy['discount']['max_discount_minor']) ? (string) $policy['discount']['max_discount_minor'] : null,
                    'max_uses' => (string) $policy['max_uses']];
            });
        }

        return $action;
    }

    private static function availabilityAction(bool $enabled): Action
    {
        $label = $enabled ? 'Enable promotion' : 'Disable promotion';

        return Action::make($enabled ? 'enable' : 'disable')->label($label)->requiresConfirmation()->modalHeading($label)
            ->modalDescription($enabled ? 'Allow this saved test policy within its UTC schedule and existing capacity limits.' : 'Stop new use and current selection progression. Retained pricing, pending attempts and consumed uses stay recorded.')
            ->visible(fn (PromotionCampaign $record): bool => $record->availability !== null && $record->availability->enabled !== $enabled)
            ->mountUsing(function (PromotionCampaign $record, ListTestPromotions $livewire): void {
                $detail = app(PromotionAdministration::class)->detail($record->id, static::actor());
                abort_unless($detail['managed'], 403);
                $livewire->expectedCampaignId = $record->id;
                $livewire->expectedAvailabilityRevision = $detail['revision'];
            })
            ->action(function (PromotionCampaign $record, ListTestPromotions $livewire, Action $action) use ($enabled): void {
                $actor = static::actor();
                abort_unless($livewire->expectedCampaignId === $record->id && $livewire->expectedAvailabilityRevision !== null, 409);
                try {
                    app(PromotionAdministration::class)->setAvailability($record->id, $livewire->expectedAvailabilityRevision, $enabled, $actor);
                    Notification::make()->success()->title($enabled ? 'Test promotion enabled' : 'Test promotion disabled')->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Availability change blocked')->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                    $action->cancel();
                } finally {
                    $livewire->clearCampaignDetails();
                    $livewire->expectedCampaignId = null;
                    $livewire->expectedAvailabilityRevision = null;
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->description('Local/testing campaigns only. Terms are immutable; create a new key and code to change them. Availability never releases pending uses or changes payment or grant records.')
            ->columns([
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('policy_key')->label('Campaign key')->searchable(),
                TextColumn::make('source')->state(fn (PromotionCampaign $record): string => $record->availability === null ? 'Configuration (read only)' : 'Managed here'),
                TextColumn::make('status')->badge()->state(fn (PromotionCampaign $record, ListTestPromotions $livewire): string => $record->availability === null ? 'Configuration managed' : ucfirst($livewire->campaignDetail($record->id)['status'])),
                TextColumn::make('capacity')->label('Capacity used')->state(function (PromotionCampaign $record, ListTestPromotions $livewire): string {
                    $detail = $livewire->campaignDetail($record->id);
                    $usage = $detail['usage'];

                    return ($usage['held'] + $usage['pending'] + $usage['consumed']).' / '.$detail['policy']['max_uses'];
                }),
                TextColumn::make('created_at')->label('Created (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)->recordUrl(null)
            ->recordActions([
                Action::make('review')->label('Review and usage')->modalHeading('Test promotion terms and usage')
                    ->modalContent(fn (PromotionCampaign $record) => view('admin.test-promotion-usage', ['detail' => app(PromotionAdministration::class)->detail($record->id, static::actor())]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                static::createAction('copyPromotion'), static::availabilityAction(true), static::availabilityAction(false),
            ])->toolbarActions([])->emptyStateHeading('No retained test promotions')
            ->emptyStateDescription('Create a disabled promotion, review its terms, then explicitly enable it.');
    }

    public static function getPages(): array { return ['index' => ListTestPromotions::route('/')]; }
}
