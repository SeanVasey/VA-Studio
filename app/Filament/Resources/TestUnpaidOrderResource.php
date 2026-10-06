<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Filament\Resources\TestUnpaidOrderResource\Pages\ListTestUnpaidOrders;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;

final class TestUnpaidOrderResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'test-unpaid-orders';

    protected static ?string $pluralModelLabel = 'Test unpaid release';

    public static function getEloquentQuery(): Builder
    {
        return app(ReleaseTestOrderResources::class)->query();
    }

    public static function table(Table $table): Table
    {
        return $table->description('Review original test orders for the configured account. Listing an order does not establish that it is unpaid or eligible for release.')
            ->columns([
                TextColumn::make('public_id')->label('Order')->searchable()->copyable(),
                TextColumn::make('created_at')->label('Prepared (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('orders.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                Action::make('releaseHistory')->label('Release history')->modalHeading('Test unpaid release history')
                    ->modalContent(fn (Order $record) => view('admin.test-unpaid-order-history', [
                        'review' => app(ReleaseTestOrderResources::class)->review($record->public_id, Filament::auth()->user()),
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('releaseUnpaid')->label('Verify unpaid status and release')->modalHeading('Verify unpaid status and release')
                    ->modalDescription('Use GET-only provider checks to establish terminal unpaid status before releasing this test order’s pending inventory and promotion resources. Expiry alone is not proof. Uncertain evidence prevents release. This does not cancel or refund a payment or revoke paid rights. Close and reopen this dialog to review a new history sequence.')
                    ->fillForm(fn (Order $record): array => self::reviewFields($record))
                    ->schema([
                        Hidden::make('sequence')->required()->rules(['integer', 'min:0']),
                        Hidden::make('request_id')->required()->rules(['uuid']),
                    ])
                    ->action(function (Order $record, array $data, Action $action): void {
                        try {
                            $result = app(ReleaseTestOrderResources::class)->release($record->public_id, Filament::auth()->user(),
                                $data['request_id'], (int) $data['sequence']);
                        } catch (RuntimeException) {
                            Notification::make()->title('Release was not confirmed')
                                ->body('The outcome is uncertain. Retry this same request, or close the dialog and inspect release history before starting a new review.')
                                ->danger()->send();
                            $action->halt();
                        }

                        $status = ($result['testOnly'] ?? false) === true ? ($result['status'] ?? null) : null;
                        if ($status === 'released') {
                            Notification::make()->title('Test resources released')
                                ->body('The retained release records the original attempt. This does not issue rights or refund a payment.')
                                ->success()->send();

                            return;
                        }

                        [$title, $body] = match ($status) {
                            'not_unpaid' => ['Unpaid status was not established', 'This check did not establish eligible unpaid status. Close this dialog and review current history before requesting another check.'],
                            'attention' => ['Release evidence needs attention', 'This check cannot authorize release. Inspect current history; uncertain or conflicting evidence cannot establish unpaid status.'],
                            'unavailable' => ['Unpaid verification is unavailable', 'This check did not confirm release. Inspect current history before starting a new review.'],
                            'payment_recorded' => ['Payment is already recorded', 'This check cannot authorize unpaid release. Inspect current release history and the retained payment workflow.'],
                            'busy' => ['Release check is in progress', 'Retry this same request or inspect release history. No release is confirmed by this response.'],
                            'stale' => ['Release review is out of date', 'Close this dialog and review current history before submitting a new request. No release is confirmed by this response.'],
                            default => ['Release was not confirmed', 'Inspect release history before starting a new review. No release is confirmed by this response.'],
                        };
                        Notification::make()->title($title)->body($body)->warning()->send();
                        $action->halt();
                    }),
            ])->toolbarActions([])
            ->emptyStateHeading('No matching test orders')
            ->emptyStateDescription('Only retained test orders for the configured account appear here.');
    }

    private static function reviewFields(Order $record): array
    {
        $review = app(ReleaseTestOrderResources::class)->review($record->public_id, Filament::auth()->user());

        return ['sequence' => $review['sequence'], 'request_id' => (string) Str::uuid()];
    }

    public static function getPages(): array
    {
        return ['index' => ListTestUnpaidOrders::route('/')];
    }
}
