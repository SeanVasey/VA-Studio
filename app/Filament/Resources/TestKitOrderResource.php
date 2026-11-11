<?php

namespace App\Filament\Resources;

use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use App\Domain\SoundKits\Purchases\SoundKitStaffOperations;
use App\Filament\Resources\TestKitOrderResource\Pages\ListTestKitOrders;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * BL-13 staff view of Stripe-test sound-kit orders. List only; reachable only while the kit lane is admitted
 * ({@see SoundKitPurchasePolicy}) and to staff holding `administer-catalog`. Row actions call {@see SoundKitStaffOperations}, which calls
 * the same domain services as the kit commands.
 */
final class TestKitOrderResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = SoundKitOrder::class;

    protected static ?string $slug = 'test-kit-orders';

    protected static ?string $pluralModelLabel = 'Test kit orders';

    public static function canAccess(): bool
    {
        return app(SoundKitPurchasePolicy::class)->admitted() && Gate::allows(SoundKitStaffOperations::PERMISSION);
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
        return app(SoundKitStaffOperations::class)->orders();
    }

    public static function table(Table $table): Table
    {
        $operations = app(SoundKitStaffOperations::class);

        return $table->description('Stripe TEST-mode kit orders. No real money moves. Actions here run the same steps as the kit console commands and are recorded against your account.')
            ->columns([
                TextColumn::make('public_id')->label('Order')->searchable()->copyable(),
                TextColumn::make('kit_title')->label('Kit')->state(fn (SoundKitOrder $record): string => (string) ($record->snapshot['product']['title'] ?? '—')),
                TextColumn::make('price_minor')->label('Price')->formatStateUsing(fn (SoundKitOrder $record): string => number_format($record->price_minor / 100, 2).' '.$record->currency),
                TextColumn::make('stage')->label('Status')->badge()->state(fn (SoundKitOrder $record): string => $operations->stage($record)),
                TextColumn::make('has_payment')->label('Paid')->formatStateUsing(fn (mixed $state): string => (int) $state > 0 ? 'Yes' : 'No'),
                TextColumn::make('has_grant')->label('Granted')->formatStateUsing(fn (mixed $state): string => (int) $state > 0 ? 'Yes' : 'No'),
                TextColumn::make('has_contract')->label('Contract')->formatStateUsing(fn (mixed $state): string => (int) $state > 0 ? 'Issued' : 'None'),
                TextColumn::make('delivery_state')->label('Delivery')->placeholder('Not enabled')->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                TextColumn::make('created_at')->label('Created (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('sound_kit_orders.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                Action::make('recheckPayment')->label('Re-check payment')->modalHeading('Re-check payment')
                    ->modalDescription('Reads the Stripe test session again (GET only) and grants the kit once if the payment is verified. Nothing is charged or refunded.')
                    ->visible(fn (SoundKitOrder $record): bool => (int) $record->has_grant === 0)
                    ->action(fn (SoundKitOrder $record) => self::notify(app(SoundKitStaffOperations::class)->recheckPayment(self::actor(), $record->public_id))),
                Action::make('issueContract')->label('Issue license contract')->modalHeading('Issue license contract')
                    ->modalDescription('Renders and records the license contract original for this order. A recorded original is never re-rendered.')
                    ->visible(fn (SoundKitOrder $record): bool => (int) $record->has_grant > 0 && (int) $record->has_contract === 0)
                    ->action(fn (SoundKitOrder $record) => self::notify(app(SoundKitStaffOperations::class)->issueContract(self::actor(), $record->public_id))),
                self::deliveryAction('enableDelivery', 'enable', 'Enable delivery', 'Enabling lets the buyer download the kit archive and contract with a fresh single-use authorization, if the contract is issued. It grants no new right.', 'enabled'),
                self::deliveryAction('blockDelivery', 'block', 'Block delivery', 'Blocking stops further downloads for this order (for example after a refund or dispute). The purchase and license record stay unchanged.', 'blocked'),
            ])->toolbarActions([])
            ->emptyStateHeading('No kit test orders')
            ->emptyStateDescription('Test orders appear here once a buyer starts a kit purchase.');
    }

    private static function deliveryAction(string $name, string $verb, string $label, string $description, string $targetState): Action
    {
        return Action::make($name)->label($label)->modalHeading($label)->modalDescription($description)
            ->visible(fn (SoundKitOrder $record): bool => (int) $record->has_grant > 0 && $record->delivery_state !== $targetState)
            ->fillForm(fn (SoundKitOrder $record): array => ['expected_version' => app(SoundKitStaffOperations::class)->deliveryVersion($record->public_id)])
            ->schema([
                Hidden::make('expected_version')->required()->rules(['integer', 'min:0']),
                TextInput::make('reference')->label('Reference')->required()->maxLength(128)
                    ->helperText('A note you can find again, such as STAGING-KIT-1 or a refund id. 3 to 128 letters, digits, . : - _')
                    ->regex('/\A[A-Za-z0-9][A-Za-z0-9._:-]{2,127}\z/D'),
            ])
            ->action(fn (SoundKitOrder $record, array $data) => self::notify(app(SoundKitStaffOperations::class)
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
        return ['index' => ListTestKitOrders::route('/')];
    }
}
