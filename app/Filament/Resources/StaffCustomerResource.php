<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\RefundDispute\StaffCommerceDirectory;
use App\Filament\Resources\StaffCustomerResource\Pages\ListStaffCustomers;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only test customer list. Detail is a staff-authorized projection; changes happen only from the order list. */
final class StaffCustomerResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = OrderFinalization::class;

    protected static ?string $slug = 'test-customers';

    protected static ?string $pluralModelLabel = 'Test customers';

    public static function getEloquentQuery(): Builder
    {
        return app(StaffCommerceDirectory::class)->customers();
    }

    public static function table(Table $table): Table
    {
        return $table->description('Test customers with paid or paid-exception orders on the configured test account. Guest customers are shown by a keyed reference, never by their access key. This list is read-only.')
            ->columns([
                TextColumn::make('customer_public_id')->label('Customer')->placeholder('Guest (no account)'),
                TextColumn::make('order_count')->label('Orders'),
                TextColumn::make('paid_minor')->label('Paid (minor units, USD)'),
                TextColumn::make('active_holds')->label('Active download holds'),
                TextColumn::make('last_finalized_at')->label('Last order (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('last_finalized_at', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                Action::make('customerDetail')->label('Customer detail')->modalHeading('Test customer detail')
                    ->modalContent(fn (OrderFinalization $record) => view('admin.staff-customer-detail', [
                        'detail' => app(StaffCommerceDirectory::class)->customer((int) $record->getKey(), Filament::auth()->user()),
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            ])->toolbarActions([])
            ->emptyStateHeading('No test customers')
            ->emptyStateDescription('Only customers with retained test orders for the configured account appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => ListStaffCustomers::route('/')];
    }
}
