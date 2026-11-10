<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\RefundDispute\Models\ProductionTaxPaidOrder;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Commerce\RefundDispute\ResolveRefusedProductionOrder;
use App\Domain\Commerce\RefundDispute\StaffCommerceDirectory;
use App\Filament\Resources\ProductionTaxRefusedOrderResource\Pages\ListProductionTaxRefusedOrders;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paid production tax orders that have not been finalized, with the staff "Resolve refused order" command (FP-068). The command
 * runs only through {@see ResolveRefusedProductionOrder}, which rechecks staff authority, MFA, the policy and the provider state on
 * the server; a visible button is never the authorization. It sends no money, creates no grant, license, contract or download
 * authorization and rewrites nothing.
 */
final class ProductionTaxRefusedOrderResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = ProductionTaxPaidOrder::class;

    protected static ?string $slug = 'unfinalized-production-orders';

    protected static ?string $pluralModelLabel = 'Unfinalized paid production orders';

    public const REASONS = StaffOrderResource::REASONS + [
        'finalized' => 'This order is already finalized. Use the hold and release path on its finalized batch.',
        'not_paid' => 'This order has no reviewed payment.',
        'not_refused' => 'The provider does not show this order fully refunded or its dispute lost, so it is not refused. Nothing was changed.',
        'in_flight' => 'The refund has not succeeded for the full amount yet. Check again once it has. Nothing was changed.',
        'not_observed' => 'The provider check failed or returned unexpected data. Nothing was changed; retry later.',
        'reconciling' => 'A dispute is still open; the order stays held until its outcome. Nothing was changed.',
    ];

    public static function getEloquentQuery(): Builder
    {
        return app(StaffCommerceDirectory::class)->unfinalizedProductionOrders();
    }

    public static function table(Table $table): Table
    {
        return $table->description('Paid production orders the buyer has not finalized. When the provider shows an order fully refunded or its dispute lost, the buyer can no longer finalize it; resolving it records that decision for staff and keeps the order from ever being granted. The track and offers are untouched. Finalized orders are administered from their batch, not here.')
            ->columns([
                TextColumn::make('order_public_id')->label('Order')->searchable(['production_tax_checkout_orders.public_id'])->copyable(),
                TextColumn::make('amount_minor')->label('Paid (minor units)'),
                TextColumn::make('currency')->label('Currency'),
                TextColumn::make('paid_at')->label('Paid (UTC)'),
                TextColumn::make('resolution_reason')->label('Resolution')->placeholder('Unresolved')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Unresolved' : str_replace('_', ' ', $state)),
                TextColumn::make('resolved_at')->label('Resolved (UTC)')->placeholder('—'),
            ])->defaultSort('production_tax_checkout_orders.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                Action::make('resolveRefusedOrder')->label('Resolve refused order')->modalHeading('Resolve refused order')
                    ->modalDescription('Re-reads this payment from the configured Stripe test account. Only if it shows the order fully refunded (all refunds succeeded) or the dispute lost is the order resolved: it is closed to finalization for good and no grant, license, contract or download is created. Nothing is sent to the provider and nothing is deleted.')
                    ->requiresConfirmation()
                    ->visible(fn (ProductionTaxPaidOrder $record): bool => $record->getAttribute('resolution_reason') === null)
                    ->action(function (ProductionTaxPaidOrder $record, Action $action): void {
                        try {
                            $result = app(ResolveRefusedProductionOrder::class)->resolve((string) $record->getAttribute('order_public_id'), Filament::auth()->user());
                        } catch (RefundDisputeException $error) {
                            Notification::make()->title('Resolve refused order was not recorded')->body(self::REASONS[$error->reason] ?? self::REASONS['unavailable'])->danger()->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->title('Refused order resolved')->body('Reason: '.str_replace('_', ' ', $result['reason']).'. No grant was created.')->success()->send();
                    }),
            ])->toolbarActions([])
            ->emptyStateHeading('No unfinalized paid production orders')
            ->emptyStateDescription('Only retained test-mode orders for the configured account appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductionTaxRefusedOrders::route('/')];
    }
}
