<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Operations\InspectRetainedTestPaymentException;
use App\Domain\Commerce\Operations\ReadTestCommerceOperations;
use App\Filament\Resources\TestPaymentExceptionResource\Pages\ListTestPaymentExceptions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class TestPaymentExceptionResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = OrderFinalization::class;

    protected static ?string $slug = 'test-payment-exceptions';

    protected static ?string $pluralModelLabel = 'Test payment exceptions';

    public static function getEloquentQuery(): Builder
    {
        return app(ReadTestCommerceOperations::class)->exceptions();
    }

    public static function table(Table $table): Table
    {
        return $table->description('Recorded test-payment exceptions for the configured account. Resources remain retained; no resolution or refund is performed here.')
            ->columns([
                TextColumn::make('order_public_id')->label('Order')->searchable(['o.public_id'])->copyable(),
                TextColumn::make('public_id')->label('Finalization')->copyable(),
                TextColumn::make('reason')->label('Recorded reason')->badge()->formatStateUsing(
                    fn (?string $state): string => ReadTestCommerceOperations::EXCEPTION_REASONS[$state] ?? 'Evidence needs attention'),
                TextColumn::make('confirmed_at')->label('Confirmed (UTC)')->dateTime('Y-m-d H:i:s', 'UTC'),
                TextColumn::make('finalized_at')->label('Recorded (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->filters([
                SelectFilter::make('reason')->attribute('order_finalizations.reason')->options(ReadTestCommerceOperations::EXCEPTION_REASONS),
            ])->defaultSort('order_finalizations.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                Action::make('inspectEvidence')->label('Inspect retained evidence')->modalHeading('Retained test-payment evidence')
                    ->modalContent(fn (OrderFinalization $record) => view('admin.test-payment-exception-inspection', [
                        'detail' => app(InspectRetainedTestPaymentException::class)->handle($record->public_id, Filament::auth()->user()),
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            ])->toolbarActions([])
            ->emptyStateHeading('No matching test payment exceptions')
            ->emptyStateDescription('Only retained test records for the configured account appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => ListTestPaymentExceptions::route('/')];
    }
}
