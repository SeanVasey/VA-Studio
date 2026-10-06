<?php

namespace App\Filament\Resources;

use App\Domain\Memberships\MembershipAdministration;
use App\Domain\Memberships\MembershipPolicy;
use App\Domain\Memberships\Models\MembershipPlan;
use App\Filament\Resources\MembershipPlanResource\Pages\ManageMembershipPlans;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

final class MembershipPlanResource extends OperatorResource
{
    public const INPUT_FIELDS = ['title', 'unit', 'allowance', 'validity_seconds', 'rollover', 'reversal_allowed'];

    protected static ?string $model = MembershipPlan::class;

    protected static ?string $slug = 'private-membership-plans';

    protected static ?string $pluralModelLabel = 'Private test membership plans';

    protected static string|UnitEnum|null $navigationGroup = 'Test memberships';

    public static function canAccess(): bool
    {
        try {
            app(MembershipAdministration::class)->authorize(auth()->user());

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return $action === 'viewAny' && self::canAccess() ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        app(MembershipAdministration::class)->authorize(auth()->user());

        return parent::getEloquentQuery()->select('membership_plans.*')
            ->addSelect(['latest_title' => DB::table('membership_plan_versions')->select('title')
                ->whereColumn('membership_plan_id', 'membership_plans.id')->orderByDesc('number')->limit(1),
                'latest_number' => DB::table('membership_plan_versions')->select('number')
                    ->whereColumn('membership_plan_id', 'membership_plans.id')->orderByDesc('number')->limit(1)]);
    }

    public static function inputFields(): array
    {
        return [
            TextInput::make('title')->label('Private plan title')->required()->maxLength(180),
            TextInput::make('unit')->label('Credit unit')->required()->maxLength(32)->helperText('Choose an explicit lowercase unit name for this synthetic policy.'),
            TextInput::make('allowance')->label('Credits per synthetic award')->required()->integer()->minValue(1)->maxValue(MembershipPolicy::MAX_ALLOWANCE),
            TextInput::make('validity_seconds')->label('Validity in seconds')->integer()->minValue(1)->maxValue(31536000)
                ->helperText('Leave blank to explicitly retain credits without an expiry.'),
            Select::make('rollover')->label('Rollover policy')->required()->options(['none' => 'No rollover for this test bucket']),
            Select::make('reversal_allowed')->label('Full reversal policy')->required()->options(['allow' => 'Allow full reversal', 'deny' => 'Deny reversal']),
        ];
    }

    /** Form representation only. MembershipPolicy remains the single policy validator. */
    public static function inputData(array $input): array
    {
        if (array_diff(array_keys($input), self::INPUT_FIELDS) !== [] || array_diff(self::INPUT_FIELDS, array_keys($input)) !== []
            || ! is_string($input['title']) || ! is_string($input['unit'])
            || $input['rollover'] !== 'none' || ! in_array($input['reversal_allowed'], ['allow', 'deny'], true)) {
            throw ValidationException::withMessages(['title' => 'Enter every explicit private policy field, then review again.']);
        }
        $integer = function (mixed $value, string $field): int {
            if (is_int($value)) {
                return $value;
            }
            if (! is_string($value) || ! preg_match('/\A[1-9][0-9]*\z/D', $value) || (string) (int) $value !== $value) {
                throw ValidationException::withMessages([$field => 'Enter a positive whole number.']);
            }

            return (int) $value;
        };
        $validity = $input['validity_seconds'];

        return ['title' => $input['title'], 'policy' => ['schema_version' => 1, 'unit' => $input['unit'],
            'allowance' => $integer($input['allowance'], 'allowance'),
            'validity_seconds' => $validity === null || $validity === '' ? null : $integer($validity, 'validity_seconds'),
            'rollover' => $input['rollover'], 'reversal_allowed' => $input['reversal_allowed'] === 'allow']];
    }

    public static function inputDisplay(array $data): array
    {
        return ['title' => $data['title'], 'unit' => $data['policy']['unit'], 'allowance' => $data['policy']['allowance'],
            'validity_seconds' => $data['policy']['validity_seconds'], 'rollover' => $data['policy']['rollover'],
            'reversal_allowed' => $data['policy']['reversal_allowed'] ? 'allow' : 'deny'];
    }

    public static function table(Table $table): Table
    {
        return $table->description('Private synthetic policy drafts only. Saving a plan does not enroll anyone, bill an invoice or award credits.')
            ->columns([TextColumn::make('id')->label('Plan'), TextColumn::make('latest_title')->label('Current title')->wrap(),
                TextColumn::make('latest_number')->label('Version'), TextColumn::make('created_at')->label('Created (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')])
            ->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)->recordUrl(null)
            ->recordActions([
                Action::make('editPlan')->label('Revise private plan')->databaseTransaction(false)->authorize(fn () => self::canAccess())
                    ->modalHeading('Enter a private plan revision')->modalSubmitActionLabel('Review revision')->modalCancelActionLabel('Cancel')
                    ->extraModalWindowAttributes(LicenseTemplateResource::authoringModalAttributes())
                    ->modalDescription('Enter the explicit synthetic policy. Review captures the current immutable version; applying that review never updates earlier versions.')
                    ->schema(self::inputFields())
                    ->fillForm(fn (MembershipPlan $record, ManageMembershipPlans $livewire): array => $livewire->capturePlanInput($record))
                    ->action(fn (array $data, Action $action, ManageMembershipPlans $livewire) => $livewire->reviewPlan($data, $action)),
                Action::make('planHistory')->label('Version history')->databaseTransaction(false)->authorize(fn () => self::canAccess())
                    ->modalHeading('Private plan version history')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (MembershipPlan $record) => view('admin.membership-plan-history', [
                        'history' => app(MembershipAdministration::class)->planHistory((int) $record->id, auth()->user()),
                    ])),
            ])->toolbarActions([])->emptyStateHeading('No private test membership plans')
            ->emptyStateDescription('Create an explicit synthetic policy to prepare a private draft. No active memberships are implied.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembershipPlans::route('/')];
    }
}
