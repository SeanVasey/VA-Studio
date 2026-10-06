<?php

namespace App\Filament\Resources;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Memberships\MembershipAdministration;
use App\Domain\Memberships\Models\MembershipCreditBucket;
use App\Filament\Resources\MembershipCreditResource\Pages\ListMembershipCredits;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class MembershipCreditResource extends OperatorResource
{
    protected static ?string $model = MembershipCreditBucket::class;

    protected static ?string $slug = 'private-membership-credits';

    protected static ?string $pluralModelLabel = 'Private test credit history';

    protected static string|UnitEnum|null $navigationGroup = 'Test memberships';

    public static function canAccess(): bool
    {
        try {
            app(CustomerAccessPolicy::class)->requireEnabled();

            return MembershipPlanResource::canAccess();
        } catch (CustomerAccessException) {
            return false;
        }
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return $action === 'viewAny' && self::canAccess() ? Response::allow() : Response::deny();
    }

    public static function scopedQuery(mixed $account): Builder
    {
        app(MembershipAdministration::class)->authorize(auth()->user());
        app(CustomerAccessPolicy::class)->requireEnabled();
        $valid = (is_string($account) || is_int($account)) && preg_match('/\A[1-9][0-9]*\z/D', (string) $account)
            && (string) (int) $account === (string) $account;

        return parent::getEloquentQuery()->select(['membership_credit_buckets.id', 'customer_account_id', 'membership_plan_version_id', 'created_at', 'expires_at'])
            ->when($valid, fn (Builder $query) => $query->where('customer_account_id', (int) $account)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('customer_accounts as a')->join('users as u', 'u.id', '=', 'a.user_id')
                    ->whereColumn('a.id', 'membership_credit_buckets.customer_account_id')->where('a.active', true)->where('a.access_version', '>=', 1)
                    ->where('u.is_admin', false)->whereNotNull('u.email_verified_at')),
                fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    public static function table(Table $table): Table
    {
        return $table->query(fn (ListMembershipCredits $livewire) => self::scopedQuery($livewire->tableFilters['account']['value'] ?? null))
            ->description('Select an explicit test account to inspect retained synthetic credit history. No award, redemption, invoice or active membership is created here.')
            ->columns([TextColumn::make('id')->label('Bucket'), TextColumn::make('customer_account_id')->label('Test account'),
                TextColumn::make('membership_plan_version_id')->label('Original plan version'),
                TextColumn::make('created_at')->label('Created (UTC)')->dateTime('Y-m-d H:i:s', 'UTC'),
                TextColumn::make('expires_at')->label('Retained expiry (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('No expiry')])
            ->filters([SelectFilter::make('account')->label('Test account')->options(fn () => app(MembershipAdministration::class)->accounts(auth()->user()))
                ->query(fn (Builder $query) => $query)])
            ->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)->recordUrl(null)
            ->recordActions([Action::make('creditHistory')->label('Inspect credit history')->databaseTransaction(false)
                ->authorize(fn () => self::canAccess())->modalHeading('Private test credit history')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (MembershipCreditBucket $record, ListMembershipCredits $livewire) => view('admin.membership-credit-history', [
                    'history' => $livewire->inspectCreditHistory($record),
                ]))])->toolbarActions([])->emptyStateHeading('No credit buckets in this account scope')
            ->emptyStateDescription('Select a test account. An empty result means no retained synthetic buckets are listed; it does not confirm membership or billing status.');
    }

    public static function getPages(): array
    {
        return ['index' => ListMembershipCredits::route('/')];
    }
}
