<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Operations\InspectRetainedTestPaymentException;
use App\Domain\Commerce\Operations\ReadTestCommerceOperations;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Filament\Resources\TestPaymentExceptionResource\Pages\ListTestPaymentExceptions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;

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
        return $table->description('Retained test-payment exceptions. Operational acknowledgments and current payment checks preserve blocked fulfillment and pending resources; they do not resolve or refund the payment.')
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
                Action::make('operationHistory')->label('Operational history')->modalHeading('Test exception operational history')
                    ->modalContent(fn (OrderFinalization $record) => view('admin.test-payment-exception-operations', [
                        'review' => app(TestPaymentExceptionOperations::class)->review($record->public_id, Filament::auth()->user()),
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('recordDisposition')->label('Record operational status')->modalHeading('Record test exception review')
                    ->modalDescription('Append an operational acknowledgment or mark this case for further review. The paid exception, payment, retained resources and blocked fulfillment remain unchanged.')
                    ->fillForm(fn (OrderFinalization $record): array => self::reviewFields($record))
                    ->schema([Hidden::make('sequence')->required()->rules(['integer', 'min:0']),
                        Hidden::make('request_id')->required()->rules(['uuid']),
                        Select::make('disposition')->label('Operational status')->required()->options([
                            'acknowledged' => 'Acknowledged — fulfillment remains blocked',
                            'needs_review' => 'Needs further review — fulfillment remains blocked',
                        ])])
                    ->action(function (OrderFinalization $record, array $data, Action $action): void {
                        try {
                            $result = app(TestPaymentExceptionOperations::class)->disposition($record->public_id, Filament::auth()->user(),
                                $data['request_id'], (int) $data['sequence'], $data['disposition']);
                        } catch (RuntimeException) {
                            Notification::make()->title('Operational status was not confirmed')->body('Retry this request or close the dialog and review the current history before submitting again.')->danger()->send();
                            $action->halt();
                        }
                        Notification::make()->title('Operational status recorded')->body('Fulfillment remains blocked. History entry '.$result['sequence'].'.')->success()->send();
                    }),
                Action::make('reconcilePayment')->label('Check current test payment')->modalHeading('Check current test payment')
                    ->modalDescription('Retrieve this original payment from the configured test account. This does not inspect refunds or disputes, change the exception, release resources or issue rights.')
                    ->fillForm(fn (OrderFinalization $record): array => self::reviewFields($record))
                    ->schema([Hidden::make('sequence')->required()->rules(['integer', 'min:0']),
                        Hidden::make('request_id')->required()->rules(['uuid'])])
                    ->action(function (OrderFinalization $record, array $data, Action $action): void {
                        try {
                            $result = app(TestPaymentExceptionOperations::class)->reconcile($record->public_id, Filament::auth()->user(),
                                $data['request_id'], (int) $data['sequence']);
                        } catch (RuntimeException) {
                            Notification::make()->title('Test payment check was not confirmed')->body('Retry this request or close the dialog and review the current history before submitting again.')->danger()->send();
                            $action->halt();
                        }
                        Notification::make()->title('Test payment check')->body('Result: '.($result['outcome'] ?? $result['status']).'. Fulfillment remains blocked.')->send();
                    }),
            ])->toolbarActions([])
            ->emptyStateHeading('No matching test payment exceptions')
            ->emptyStateDescription('Only retained test records for the configured account appear here.');
    }

    private static function reviewFields(OrderFinalization $record): array
    {
        $review = app(TestPaymentExceptionOperations::class)->review($record->public_id, Filament::auth()->user());

        return ['sequence' => $review['sequence'], 'request_id' => (string) Str::uuid()];
    }

    public static function getPages(): array
    {
        return ['index' => ListTestPaymentExceptions::route('/')];
    }
}
