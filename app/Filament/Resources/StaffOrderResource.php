<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Commerce\RefundDispute\RefundDisputeLedger;
use App\Domain\Commerce\RefundDispute\StaffCommerceDirectory;
use App\Filament\Resources\StaffOrderResource\Pages\ListStaffOrders;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Test orders with refund and dispute administration. Every change goes through the A5 domain services, which
 * recheck staff authority, MFA, policy and the ledger sequence on the server; a hidden or visible button is never
 * the authorization. No action sends money, revokes a license or rewrites an original.
 */
final class StaffOrderResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = OrderFinalization::class;

    protected static ?string $slug = 'test-orders';

    protected static ?string $pluralModelLabel = 'Test orders and refunds';

    public const REASONS = [
        'disabled' => 'Refund and dispute handling is disabled for this environment.',
        'policy_unset' => 'A refund or dispute policy value is not configured. Nothing was changed.',
        'policy_unknown' => 'A configured refund or dispute policy value is not supported. Nothing was changed.',
        'stale' => 'The case changed since this dialog opened. Close it and review the current history.',
        'busy' => 'A provider check is in progress. Retry this same request shortly.',
        'conflict' => 'This request was already used with different details.',
        'not_observed' => 'Record a successful provider check before deciding.',
        'not_qualified' => 'The current provider evidence does not qualify this decision.',
        'already_applied' => 'This decision is already recorded.',
        'reconciling' => 'The dispute is still open; the reconciling hold stays in place.',
        'policy_forbids' => 'The configured policy does not permit this decision.',
        'invalid' => 'The allocation must use existing line positions and sum exactly to the refund.',
        'not_found' => 'The selected record was not found for this order.',
        'unavailable' => 'The outcome is uncertain. Retry this same request or review the current history.',
    ];

    public static function getEloquentQuery(): Builder
    {
        return app(StaffCommerceDirectory::class)->orders();
    }

    public static function table(Table $table): Table
    {
        return $table->description('Paid and paid-exception test orders. Refunds are issued in the Stripe test dashboard; this page observes them and records reviewed effects. Download holds never change a license, grant or original contract.')
            ->columns([
                TextColumn::make('order_public_id')->label('Order')->searchable(['o.public_id'])->copyable(),
                TextColumn::make('customer_public_id')->label('Customer')->placeholder('Guest'),
                TextColumn::make('outcome')->label('Outcome')->badge(),
                TextColumn::make('amount_minor')->label('Paid (minor units)'),
                TextColumn::make('currency')->label('Currency'),
                TextColumn::make('refund_state')->label('Refund')->placeholder('Not observed')->badge(),
                TextColumn::make('dispute_state')->label('Dispute')->placeholder('Not observed')->badge(),
                TextColumn::make('active_holds')->label('Active holds'),
                TextColumn::make('finalized_at')->label('Recorded (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->filters([
                SelectFilter::make('outcome')->attribute('order_finalizations.outcome')->options(['paid' => 'Paid', 'paid_exception' => 'Paid exception']),
            ])->defaultSort('order_finalizations.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                Action::make('orderDetail')->label('Order evidence')->modalHeading('Test order evidence')
                    ->modalContent(fn (OrderFinalization $record) => view('admin.staff-order-detail', [
                        'order' => self::detail($record),
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                self::command('observe', 'Check refunds and disputes', 'Retrieve the current refunds and disputes for this payment from the configured Stripe test account and record them. No money moves.',
                    [], fn (OrderFinalization $record, array $data) => app(RefundDisputeLedger::class)->observe(self::orderId($record), Filament::auth()->user(), $data['request_id'], (int) $data['sequence'])),
                self::command('allocateRefund', 'Allocate partial refund', 'Assign a succeeded partial refund to original order lines by position. The amounts must sum exactly to the refund.',
                    [Select::make('refund')->label('Refund')->required()->options(fn (OrderFinalization $record): array => self::options($record, 'refunds')),
                        KeyValue::make('lines')->label('Allocation')->keyLabel('Line position')->valueLabel('Minor units')->required()],
                    fn (OrderFinalization $record, array $data) => app(RefundDisputeActions::class)->allocate(self::orderId($record), Filament::auth()->user(),
                        $data['request_id'], (int) $data['sequence'], (string) $data['refund'], self::lines($data['lines'] ?? []))),
                self::command('suspendLine', 'Suspend line downloads', 'Hold downloads for one fully refunded line or after a lost dispute. The license and contract are unchanged.',
                    [Select::make('grant')->label('Line grant')->required()->options(fn (OrderFinalization $record): array => self::options($record, 'grants'))],
                    fn (OrderFinalization $record, array $data) => app(RefundDisputeActions::class)->suspendLine(self::orderId($record), Filament::auth()->user(),
                        $data['request_id'], (int) $data['sequence'], (string) $data['grant'])),
                self::command('placeDisputeHold', 'Hold order downloads for dispute', 'Under the operator-review dispute policy, hold every download in this order while a dispute is open or lost.',
                    [], fn (OrderFinalization $record, array $data) => app(RefundDisputeActions::class)->placeDisputeHold(self::orderId($record), Filament::auth()->user(),
                        $data['request_id'], (int) $data['sequence'])),
                self::command('releaseHold', 'Release a download hold', 'Release one active hold after review.',
                    [Select::make('hold')->label('Hold')->required()->options(fn (OrderFinalization $record): array => self::options($record, 'holds'))],
                    fn (OrderFinalization $record, array $data) => app(RefundDisputeActions::class)->releaseHold(self::orderId($record), Filament::auth()->user(),
                        $data['request_id'], (int) $data['sequence'], (string) $data['hold'])),
                self::command('qualifyExclusive', 'Record exclusive reopening decision', 'Record that a refunded or charged-back exclusive line qualifies for reopening review. The exclusive sale and other grants are retained and catalog availability does not change.',
                    [Select::make('grant')->label('Exclusive line grant')->required()->options(fn (OrderFinalization $record): array => self::options($record, 'exclusive'))],
                    fn (OrderFinalization $record, array $data) => app(RefundDisputeActions::class)->qualifyExclusiveReopening(self::orderId($record), Filament::auth()->user(),
                        $data['request_id'], (int) $data['sequence'], (string) $data['grant'])),
            ])->toolbarActions([])
            ->emptyStateHeading('No matching test orders')
            ->emptyStateDescription('Only retained test orders for the configured account appear here.');
    }

    /** A refund/dispute command dialog: the ledger sequence and one request UUID are fixed when the dialog opens. */
    private static function command(string $name, string $label, string $description, array $fields, callable $run): Action
    {
        return Action::make($name)->label($label)->modalHeading($label)->modalDescription($description)
            ->visible(fn (OrderFinalization $record): bool => $record->outcome === 'paid')
            ->fillForm(fn (OrderFinalization $record): array => ['sequence' => app(RefundDisputeLedger::class)->review(self::orderId($record), Filament::auth()->user())['sequence'],
                'request_id' => (string) Str::uuid()])
            ->schema([Hidden::make('sequence')->required()->rules(['integer', 'min:0']), Hidden::make('request_id')->required()->rules(['uuid']), ...$fields])
            ->action(function (OrderFinalization $record, array $data, Action $action) use ($run, $label): void {
                try {
                    $result = $run($record, $data);
                } catch (RefundDisputeException $error) {
                    Notification::make()->title($label.' was not recorded')->body(self::REASONS[$error->reason] ?? self::REASONS['unavailable'])->danger()->send();
                    $action->halt();

                    return;
                }
                if (($result['status'] ?? null) !== 'recorded') {
                    Notification::make()->title($label.' was not recorded')->body(self::REASONS[$result['status'] ?? 'unavailable'] ?? self::REASONS['unavailable'])->warning()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title($label.' recorded')->body('Ledger entry '.$result['sequence'].'. This is test evidence only.')->success()->send();
            });
    }

    private static function orderId(OrderFinalization $record): string
    {
        return (string) $record->getAttribute('order_public_id');
    }

    private static function detail(OrderFinalization $record): array
    {
        app(\App\Domain\Commerce\RefundDispute\RefundDisputeAuthority::class)->staff(Filament::auth()->user());

        return app(StaffCommerceDirectory::class)->order((int) $record->getKey());
    }

    private static function options(OrderFinalization $record, string $kind): array
    {
        $order = self::detail($record);

        return match ($kind) {
            'refunds' => collect($order['providerObjects'])->where('kind', 'refund')
                ->mapWithKeys(fn ($o) => [$o['reference'] => $o['amountMinor'].' '.$o['currency'].' — '.implode(' → ', $o['statuses'])])->all(),
            'grants' => collect($order['lines'])->filter(fn ($l) => $l['grant'] !== null)
                ->mapWithKeys(fn ($l) => [$l['grant'] => 'Line '.$l['position'].': '.($l['title'] ?? 'untitled')])->all(),
            'exclusive' => collect($order['lines'])->filter(fn ($l) => $l['exclusive'] && $l['grant'] !== null)
                ->mapWithKeys(fn ($l) => [$l['grant'] => 'Line '.$l['position'].': '.($l['title'] ?? 'untitled')])->all(),
            'holds' => collect($order['holds'])->whereNull('releasedAt')
                ->mapWithKeys(fn ($h) => [$h['reference'] => str_replace('_', ' ', $h['reason']).($h['grant'] === null ? ' (whole order)' : ' (one line)')])->all(),
        };
    }

    /** Strict position => minor-unit parsing; anything else becomes an invalid allocation the domain refuses. */
    private static function lines(mixed $lines): array
    {
        $parsed = [];
        foreach (is_array($lines) ? $lines : [] as $position => $amount) {
            if (! is_string($amount) && ! is_int($amount) || preg_match('/\A(0|[1-9][0-9]{0,8})\z/D', (string) $position) !== 1
                || preg_match('/\A[1-9][0-9]{0,7}\z/D', (string) $amount) !== 1) {
                return [-1 => 0];
            }
            $parsed[(int) $position] = (int) $amount;
        }

        return $parsed;
    }

    public static function getPages(): array
    {
        return ['index' => ListStaffOrders::route('/')];
    }
}
