<?php

namespace App\Filament\Resources;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Models\TransactionalMailDelivery;
use App\Domain\Notifications\NotificationException;
use App\Filament\Resources\StaffMailDeliveryResource\Pages\ListStaffMailDeliveries;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Order-ready email delivery attempts (BL-47, FP-075). Staff can requeue a message that failed or whose outcome is uncertain.
 * The action only calls TransactionalMailAdapter::requeueOrderReady, which rechecks staff authority, MFA, the admitted mail
 * configuration and the duplicate-send rules on the server; a visible button is never the authorization. The customer does
 * not need the email: their order stays reachable from their account.
 */
final class StaffMailDeliveryResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = TransactionalMailDelivery::class;

    protected static ?string $slug = 'order-ready-mail';

    protected static ?string $pluralModelLabel = 'Order-ready emails';

    public const REASONS = [
        'mail_not_configured' => 'Order-ready email is not enabled or not fully configured for this environment. Nothing was queued.',
        'mail_not_faked' => 'This environment never reaches a real mail transport. Nothing was queued.',
        'already_delivered' => 'An earlier attempt was already accepted by the mail provider. It is not sent again.',
        'uncertain_unchecked' => 'The earlier attempt may have been delivered. Confirm in the provider logs that it was not, then requeue.',
        'authority_withdrawn' => 'The customer or purchase no longer qualifies for this email. Nothing was queued.',
        'no_delivery' => 'No delivery was ever queued for this message.',
        'outer_transaction' => 'The request could not be processed. Retry shortly.',
    ];

    public static function getEloquentQuery(): Builder
    {
        return TransactionalMailDelivery::query();
    }

    public static function table(Table $table): Table
    {
        return $table->description('One row per attempt. A requeue adds a new attempt for a failed message (or an uncertain one you confirm was not delivered); a delivered message is never sent again. The customer can always open the order from their account without the email. No address or message body is shown.')
            ->columns([
                TextColumn::make('public_id')->label('Delivery')->copyable(),
                TextColumn::make('notice_public_id')->label('Notice')->copyable()->searchable(),
                TextColumn::make('attempt')->label('Attempt'),
                TextColumn::make('state')->label('State')->badge(),
                TextColumn::make('reason')->label('Reason')->placeholder('None'),
                TextColumn::make('queued_at')->label('Queued (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
                TextColumn::make('finished_at')->label('Finished (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('Not finished'),
            ])->filters([
                SelectFilter::make('state')->options(['queued' => 'Queued', 'submitting' => 'Submitting', 'accepted' => 'Accepted',
                    'failed' => 'Failed', 'uncertain' => 'Uncertain']),
            ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([
                Action::make('requeue')->label('Requeue email')->modalHeading('Requeue order-ready email')
                    ->modalDescription('Queues one new attempt for this order-ready message. Nothing is sent unless order-ready email is enabled and configured, and a message already accepted by the provider is never sent again.')
                    ->visible(fn (TransactionalMailDelivery $record): bool => in_array($record->state, ['failed', 'uncertain'], true) && self::isLatest($record))
                    ->schema(fn (TransactionalMailDelivery $record): array => $record->state === 'uncertain' ? [
                        Checkbox::make('providerLogChecked')->label('I checked the provider logs for this delivery id and the message was not delivered.')->accepted(),
                    ] : [])
                    ->action(function (TransactionalMailDelivery $record, array $data, Action $action): void {
                        try {
                            $result = app(TransactionalMailAdapter::class)->requeueOrderReady($record->notice_public_id, Filament::auth()->user(), (bool) ($data['providerLogChecked'] ?? false));
                        } catch (AuthorizationException) {
                            Notification::make()->title('Requeue refused')->body('Staff access with multi-factor authentication is required.')->danger()->send();
                            $action->halt();

                            return;
                        } catch (NotificationException $error) {
                            Notification::make()->title('Requeue refused')->body(self::REASONS[$error->reason] ?? 'The email could not be requeued. Nothing was changed.')->warning()->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->title($result['replayed'] ? 'Already queued' : 'Email requeued')
                            ->body('Attempt '.$result['attempt'].' is '.$result['state'].'.')->success()->send();
                    }),
            ])->toolbarActions([])
            ->emptyStateHeading('No order-ready emails')
            ->emptyStateDescription('Order-ready email attempts appear here once the email is enabled.');
    }

    /** Only the newest attempt of a notice can be requeued; the server rechecks this under lock. */
    private static function isLatest(TransactionalMailDelivery $record): bool
    {
        return ! TransactionalMailDelivery::where('notice_id', $record->notice_id)->where('id', '>', $record->id)->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ListStaffMailDeliveries::route('/')];
    }
}
