<?php

namespace App\Filament\Resources;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\OrderInquiry;
use App\Filament\Resources\CustomerInquiryResource\Components\InquiryTextColumn;
use App\Filament\Resources\CustomerInquiryResource\Components\InquiryTextEntry;
use App\Filament\Resources\CustomerInquiryResource\Pages\ListCustomerInquiries;
use App\Filament\Resources\CustomerInquiryResource\Pages\ViewCustomerInquiry;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

final class CustomerInquiryResource extends OperatorResource
{
    protected static ?string $model = CustomerInquiry::class;

    protected static ?string $slug = 'customer-inquiries';

    protected static ?string $pluralModelLabel = 'Customer inquiries';

    protected static string|UnitEnum|null $navigationGroup = 'Customer support';

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (! in_array($action, ['viewAny', 'view'], true)) {
            return Response::deny();
        }
        app(InquiryAdministration::class)->actor(Filament::auth()->user());

        return Response::allow();
    }

    public static function getEloquentQuery(): Builder
    {
        app(InquiryAdministration::class)->actor(Filament::auth()->user());

        return parent::getEloquentQuery();
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'public_id';
    }

    public static function table(Table $table): Table
    {
        return $table->description('Private inquiries saved in this store. Open an inquiry to reply in app. No email is sent.')
            ->columns([
                InquiryTextColumn::make('public_id')->label('Receipt')->copyable(),
                InquiryTextColumn::make('payload.subject')->label('Subject')->limit(80),
                InquiryTextColumn::make('state')->badge(), InquiryTextColumn::make('created_at')->label('Received (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->filters([SelectFilter::make('state')->options(['new' => 'New', 'read' => 'Read', 'archived' => 'Archived'])->default('new')])
            ->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordActions([ViewAction::make(), self::stateAction('markRead', 'Mark read', 'read'), self::stateAction('archive', 'Archive', 'archived')])
            ->toolbarActions([])->emptyStateHeading('No matching customer inquiries');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            InquiryTextEntry::make('public_id')->label('Receipt'), InquiryTextEntry::make('state'),
            InquiryTextEntry::make('payload.name')->label('Name'), InquiryTextEntry::make('payload.email')->label('Email'),
            InquiryTextEntry::make('payload.subject')->label('Subject'), InquiryTextEntry::make('payload.message')->label('Message')->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-wrap']),
            InquiryTextEntry::make('created_at')->label('Received (UTC)')->dateTime('Y-m-d H:i:s', 'UTC'),
            InquiryTextEntry::make('retention_policy_reference')->label('Retention policy reference'),
            InquiryTextEntry::make('order_context')->label('Retained test order reference')->columnSpanFull()
                ->state(function (CustomerInquiry $record): string {
                    $context = app(OrderInquiry::class)->staffContext($record->id, Filament::auth()->user());

                    return $context['order'] === null ? 'This inquiry has no linked test order.'
                        : 'Linked test order '.$context['order']['id'].'. This retained reference does not confirm current payment, download access or usage rights.';
                }),
            InquiryTextEntry::make('conversation')->label('In-app conversation')->columnSpanFull()
                ->state(function (CustomerInquiry $record): string {
                    $snapshot = app(InquiryConversation::class)->staff($record->id, Filament::auth()->user());
                    $rows = array_map(fn (array $message): string => ($message['sender'] === 'you' ? 'Customer' : 'VASEY.AUDIO')
                        .' · '.$message['createdAt']."\n".$message['message'], $snapshot['messages']);

                    return ($rows === [] ? 'No replies yet.' : implode("\n\n", $rows))
                        ."\n\nReplies stay in this store; no email is sent. Refresh to check for new messages."
                        .($snapshot['canReply'] ? '' : ' This conversation is read-only.');
                })->extraAttributes(['class' => 'whitespace-pre-wrap']),
        ]);
    }

    public static function stateAction(string $name, string $label, string $target): Action
    {
        return Action::make($name)->label($label)->visible(fn (CustomerInquiry $record): bool => $target === 'read' ? $record->state === 'new' : $record->state !== 'archived')
            ->requiresConfirmation()->modalDescription($target === 'read' ? 'Mark this private inquiry as read. This does not send a reply.' : 'Archive this inquiry and stop new replies. Its original details and conversation remain readable; no email is sent.')
            ->mountUsing(fn (HasActions $livewire, CustomerInquiry $record) => $livewire->mergeMountedActionArguments(['expectedVersion' => $record->version]))
            ->action(function (CustomerInquiry $record, array $arguments, Action $action) use ($target): void {
                try {
                    $version = $arguments['expectedVersion'] ?? null;
                    if (! is_int($version)) {
                        throw ValidationException::withMessages(['inquiry' => 'Refresh the inbox before applying an action.']);
                    }
                    $persisted = app(InquiryAdministration::class)->transition($record->id, $target, $version, Filament::auth()->user());
                    // ViewRecord keeps this instance for the current render and header action visibility.
                    $record->setRawAttributes($persisted->getAttributes(), true);
                } catch (ValidationException) {
                    Notification::make()->danger()->title('Inquiry changed')->body('Refresh the inbox before applying an action.')->send();
                    $action->cancel();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomerInquiries::route('/'), 'view' => ViewCustomerInquiry::route('/{record}')];
    }
}
