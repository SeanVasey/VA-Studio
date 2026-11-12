<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\Order;
use App\Domain\Delivery\TestDeliveryStaffOperations;
use App\Filament\Resources\TestTrackOrderResource\Pages\ListTestTrackOrders;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Staff view of Stripe-test track orders with the per-order delivery control. List only; reachable only where
 * `vasey:control-test-delivery` can act ({@see TestDeliveryStaffOperations::admitted()}) and to staff holding `administer-catalog`.
 * Row actions call {@see TestDeliveryStaffOperations}, which calls the same control service as the command.
 */
final class TestTrackOrderResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'test-track-orders';

    protected static ?string $pluralModelLabel = 'Test track orders';

    public static function canAccess(): bool
    {
        return app(TestDeliveryStaffOperations::class)->admitted() && Gate::allows(TestDeliveryStaffOperations::PERMISSION);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return $action === 'viewAny' && self::canAccess() ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return app(TestDeliveryStaffOperations::class)->orders();
    }

    public static function table(Table $table): Table
    {
        $operations = app(TestDeliveryStaffOperations::class);

        return $table->description('Stripe TEST-mode track orders. No real money moves. Payment, grant, contract and activation happen by themselves; enabling downloads is a deliberate step per order unless the optional automatic test policy is on, which enables only orders nobody has enabled or blocked yet (audited as commerce.delivery.auto_enabled, with no actor). Actions here run the same step as the vasey:control-test-delivery command and are recorded against your account.')
            ->columns([
                TextColumn::make('public_id')->label('Order')->searchable(['orders.public_id'])->copyable(),
                TextColumn::make('created_at')->label('Created (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(['orders.id']),
                TextColumn::make('customer_public_id')->label('Customer')->placeholder('Guest'),
                TextColumn::make('track_title')->label('Track and license')->placeholder('—')->wrap()
                    ->formatStateUsing(fn (Order $record): string => trim($record->getAttribute('track_title').' · '.($record->getAttribute('license_name') ?? '—'))
                        .((int) $record->getAttribute('line_count') > 1 ? ' (+'.((int) $record->getAttribute('line_count') - 1).' more)' : '')),
                TextColumn::make('stage')->label('Status')->badge()->state(fn (Order $record): string => $operations->stage($record)),
                TextColumn::make('has_payment')->label('Paid')->formatStateUsing(fn (mixed $state): string => (int) $state > 0 ? 'Yes' : 'No'),
                TextColumn::make('grant_count')->label('Grants')->formatStateUsing(fn (mixed $state): string => (string) (int) $state),
                TextColumn::make('contract_count')->label('Contracts issued')->formatStateUsing(fn (mixed $state): string => (string) (int) $state),
                TextColumn::make('delivery_version')->label('Delivery')->placeholder('Not enabled')
                    ->formatStateUsing(fn (Order $record): string => ucfirst((string) $operations->deliveryState($record)).' (version '.(int) $record->getAttribute('delivery_version').')'),
            ])->filters([
                SelectFilter::make('delivery')->label('Delivery')->options(['awaiting' => 'Awaiting delivery'])
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === 'awaiting' ? TestDeliveryStaffOperations::filterAwaiting($query) : $query),
            ])->defaultSort('orders.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                self::deliveryAction('enableDelivery', 'enable', 'Enable delivery', 'Enabling lets the buyer download this order\'s contract and purchased files with a fresh short-lived authorization, in the browser session that placed the order. It grants no new right. An order that was never enabled is first recorded as blocked and then enabled, as with the command.', false),
                self::deliveryAction('blockDelivery', 'block', 'Block delivery', 'Blocking stops new downloads for this order. The purchase, license grant and contract stay unchanged, and a transfer already started is not recalled.', true),
            ])->toolbarActions([])
            ->emptyStateHeading('No track test orders')
            ->emptyStateDescription('Track orders appear here once a buyer opens Stripe test checkout.');
    }

    private static function deliveryAction(string $name, string $verb, string $label, string $description, bool $blocks): Action
    {
        return Action::make($name)->label($label)->modalHeading($label)->modalDescription($description)
            ->visible(fn (Order $record): bool => $blocks
                ? app(TestDeliveryStaffOperations::class)->deliveryState($record) === 'enabled'
                : (int) $record->getAttribute('activated') > 0 && app(TestDeliveryStaffOperations::class)->deliveryState($record) !== 'enabled')
            ->fillForm(fn (Order $record): array => ['expected_version' => app(TestDeliveryStaffOperations::class)->deliveryVersion($record->public_id)])
            ->schema([
                Hidden::make('expected_version')->required()->rules(['integer', 'min:0']),
                TextInput::make('reference')->label('Reference')->required()->maxLength(192)
                    ->helperText('A note you can find again, such as ALPHA-TRACK-1. 1 to 192 letters, digits, . : / - _ (only its hash is stored).')
                    ->regex(TestDeliveryStaffOperations::REFERENCE),
            ])
            ->action(fn (Order $record, array $data) => self::notify(app(TestDeliveryStaffOperations::class)
                ->controlDelivery(self::actor(), $record->public_id, $verb, (int) $data['expected_version'], (string) $data['reference'])));
    }

    private static function actor(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @param array{ok: bool, message: string} $result */
    private static function notify(array $result): void
    {
        $notification = Notification::make()->title($result['ok'] ? 'Done' : 'Not done')->body($result['message']);
        ($result['ok'] ? $notification->success() : $notification->danger())->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListTestTrackOrders::route('/')];
    }
}
