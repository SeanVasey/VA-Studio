<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Operations\InspectRetainedTestPaymentException;
use App\Domain\Commerce\Operations\ReadTestCommerceOperations;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\RefundResolution\ResolveRefundedTestException;
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
        return $table->description('Retained test-payment exceptions. Operational acknowledgments and current payment checks preserve blocked fulfillment and the recorded resource disposition; they do not resolve or refund the payment. A separate full-refund review can release still-pending resources without sending a refund or changing grants or contracts.')
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
                    ->modalDescription('Retrieve this original payment and bounded refund/dispute observations from the configured test account. Incomplete or unavailable observations remain unresolved. This does not change the exception, release resources, refund money or issue rights.')
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
                        Notification::make()->title('Test payment check')->body('Payment: '.($result['outcome'] ?? $result['status']).'. Refund/dispute observation: '.str_replace('_', ' ', $result['refundDisputeState'] ?? 'not_inspected').'. Fulfillment remains blocked.')->send();
                    }),
                Action::make('refundResolutionHistory')->label('Refund resolution history')->modalHeading('Test refund resolution history')
                    ->modalContent(fn (OrderFinalization $record) => view('admin.test-refund-resolution-history', [
                        'review' => app(ResolveRefundedTestException::class)->review($record->public_id, Filament::auth()->user()),
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('resolveFullRefund')->label('Verify full refund and release')->modalHeading('Verify full refund and release')
                    ->modalDescription('Verify an already completed full refund before releasing this test order’s still-pending inventory and promotion resources. No refund is sent. Grants and original contracts are unchanged; fulfillment remains blocked. Unsupported or uncertain evidence cannot authorize release. Close and reopen this dialog to review a new history sequence.')
                    // The native submit form owns disabling. A second loading attribute restores
                    // its captured disabled state after network failure and blocks an exact retry.
                    ->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes(['wire:loading.attr' => null]))
                    ->fillForm(function (OrderFinalization $record): array {
                        $review = app(ResolveRefundedTestException::class)->review($record->public_id, Filament::auth()->user());

                        return ['sequence' => $review['sequence'], 'request_id' => (string) Str::uuid()];
                    })
                    ->schema([
                        Hidden::make('sequence')->required()->rules(['integer', 'min:0']),
                        Hidden::make('request_id')->required()->rules(['uuid']),
                    ])
                    ->action(function (OrderFinalization $record, array $data, Action $action): void {
                        try {
                            $result = app(ResolveRefundedTestException::class)->resolve($record->public_id, Filament::auth()->user(),
                                $data['request_id'], (int) $data['sequence']);
                        } catch (RuntimeException) {
                            Notification::make()->title('Refund resolution was not confirmed')
                                ->body('The outcome is uncertain. Retry this same request, or close the dialog and inspect refund resolution history before starting a new review.')
                                ->danger()->send();
                            $action->halt();
                        }

                        $status = ($result['testOnly'] ?? false) === true ? ($result['status'] ?? null) : null;
                        if ($status === 'released') {
                            Notification::make()->title('Refunded test resources released')
                                ->body('The retained resolution records the original attempt. No refund was sent. Grants and original contracts are unchanged; fulfillment remains blocked.')
                                ->success()->send();

                            return;
                        }

                        [$title, $body] = match ($status) {
                            'not_refunded' => ['Full refund was not established', 'This check did not establish an eligible completed full refund. Inspect refund resolution history before starting a new review.'],
                            'attention' => ['Refund evidence needs attention', 'Unsupported, incomplete or conflicting evidence cannot authorize release. Inspect refund resolution history before starting a new review.'],
                            'unavailable' => ['Refund verification is unavailable', 'This check did not confirm release. Inspect refund resolution history before starting a new review.'],
                            'busy' => ['Refund check is in progress', 'Retry this same request or inspect refund resolution history. No release is confirmed by this response.'],
                            'stale' => ['Refund review is out of date', 'Close this dialog and review current refund resolution history before submitting a new request. No release is confirmed by this response.'],
                            default => ['Refund resolution was not confirmed', 'Inspect refund resolution history before starting a new review. No release is confirmed by this response.'],
                        };
                        Notification::make()->title($title)->body($body)->warning()->send();
                        $action->halt();
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
