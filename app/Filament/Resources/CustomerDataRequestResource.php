<?php

namespace App\Filament\Resources;

use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\DataRightsPolicy;
use App\Domain\Customers\DataRights\Models\CustomerDataRequest;
use App\Filament\Resources\CustomerDataRequestResource\Pages\ListCustomerDataRequests;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Customer export and deletion requests (FP-082). Read-mostly: staff approve or reject deletion requests through the
 * domain service, which re-reads the gate and MFA, takes the reviewed version and refuses while retention is unset.
 */
final class CustomerDataRequestResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = CustomerDataRequest::class;

    protected static ?string $slug = 'customer-data-requests';

    protected static ?string $pluralModelLabel = 'Customer data requests';

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    public static function table(Table $table): Table
    {
        return $table->description('Export requests are handled automatically for the signed-in customer. Deletion requests wait here for review. Approving withdraws the customer\'s access and erases their sign-in profile and listening library; orders, contracts, grants, payment evidence, inquiries, consent records and audit history are kept for the configured retention periods. Approval is refused until the retention schedule is configured.')
            ->columns([
                TextColumn::make('public_id')->label('Request'),
                TextColumn::make('kind')->label('Kind')->badge(),
                TextColumn::make('state')->label('State')->badge(),
                TextColumn::make('customer_account_id')->label('Account')->formatStateUsing(fn (int $state): string => '#'.$state),
                TextColumn::make('created_at')->label('Requested (UTC)')->dateTime('Y-m-d H:i', 'UTC')->sortable(),
                TextColumn::make('resolved_at')->label('Resolved (UTC)')->dateTime('Y-m-d H:i', 'UTC')->placeholder('Open'),
            ])
            ->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)->recordUrl(null)
            ->recordActions([self::approveAction(), self::rejectAction()])->toolbarActions([])
            ->emptyStateHeading('No customer data requests');
    }

    private static function approveAction(): Action
    {
        return Action::make('approveDeletion')->label('Approve deletion')->color('danger')->requiresConfirmation()
            ->modalHeading('Approve this deletion request')
            ->modalDescription(function (): string {
                try {
                    $retention = app(DataRightsPolicy::class)->retention();
                } catch (DataRightsException) {
                    return 'The retention schedule is not configured, so approval is refused. Nothing will change.';
                }

                return 'This withdraws the customer\'s access, ends their sessions, erases their listening library and replaces their name and sign-in email. Retained under "'
                    .$retention['policy_reference'].'": financial records '.$retention['financial_records_years'].' years, consent records '
                    .$retention['consent_records_years'].' years, correspondence '.$retention['correspondence_records_years'].' years, security records '
                    .$retention['security_records_years'].' years. This cannot be undone.';
            })
            ->visible(fn (CustomerDataRequest $record): bool => $record->kind === 'deletion' && $record->state === 'received')
            ->mountUsing(fn (CustomerDataRequest $record, ListCustomerDataRequests $livewire) => $livewire->expectedRequestVersion = $record->fresh()?->version)
            ->action(fn (CustomerDataRequest $record, ListCustomerDataRequests $livewire, Action $action) => self::run($livewire, $action,
                fn (User $actor, int $version) => app(CustomerDataRights::class)->approveDeletion($record->public_id, $actor, $version), 'Deletion completed'));
    }

    private static function rejectAction(): Action
    {
        return Action::make('rejectDeletion')->label('Reject')->color('gray')
            ->modalHeading('Reject this deletion request')
            ->schema([Textarea::make('reason')->label('Reason (kept with the request; do not include customer details)')->required()->maxLength(500)->rows(3)])
            ->visible(fn (CustomerDataRequest $record): bool => $record->kind === 'deletion' && $record->state === 'received')
            ->mountUsing(function (CustomerDataRequest $record, ListCustomerDataRequests $livewire, $schema = null): void {
                $livewire->expectedRequestVersion = $record->fresh()?->version;
                $schema?->fill();
            })
            ->action(fn (array $data, CustomerDataRequest $record, ListCustomerDataRequests $livewire, Action $action) => self::run($livewire, $action,
                fn (User $actor, int $version) => app(CustomerDataRights::class)->rejectDeletion($record->public_id, $actor, $version, (string) ($data['reason'] ?? '')), 'Request rejected'));
    }

    private static function run(ListCustomerDataRequests $livewire, Action $action, callable $operation, string $success): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $livewire->expectedRequestVersion !== null, 403);
        try {
            $operation($actor, $livewire->expectedRequestVersion);
            Notification::make()->success()->title($success)->send();
        } catch (DataRightsException $error) {
            Notification::make()->danger()->title('Request not changed')->body(match ($error->reason) {
                'retention_policy_unset' => 'The retention schedule is not configured. Configure the approved schedule before approving deletions.',
                'request_changed' => 'This request changed after you opened the confirmation. Refresh the list.',
                'staff_account' => 'Staff accounts are never erased through customer requests.',
                default => 'The request could not be resolved.',
            })->persistent()->send();
            $action->cancel();
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Request not changed')->body('Approving or rejecting needs administrator access with an enrolled authenticator.')->persistent()->send();
            $action->cancel();
        } catch (ValidationException $error) {
            Notification::make()->danger()->title('Request not changed')->body(implode(' ', array_merge(...array_values($error->errors()))))->persistent()->send();
            $action->cancel();
        } catch (QueryException $error) {
            // Contention that outlived the service's retries rolled the whole transaction back, so nothing changed and a retry is
            // safe. Any other database error is a defect and keeps the framework's error handling and report.
            if (! CustomerDataRights::concurrencyError($error)) {
                throw $error;
            }
            Log::warning('Customer data request action hit lasting database contention.', ['exception_class' => $error::class]);
            Notification::make()->danger()->title('Request not changed')->body('The database was busy and nothing was changed. Try again in a moment.')->persistent()->send();
            $action->cancel();
        } finally {
            $livewire->expectedRequestVersion = null;
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomerDataRequests::route('/')];
    }
}
