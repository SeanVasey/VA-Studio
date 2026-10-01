<?php

namespace App\Filament\Resources;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Filament\Resources\CustomerInquiryResource\Pages\ListCustomerInquiries;
use App\Filament\Resources\CustomerInquiryResource\Pages\ViewCustomerInquiry;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
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
        return $table->description('Private inquiries saved in this store. No email or reply is sent by these actions.')
            ->columns([
                TextColumn::make('public_id')->label('Receipt')->copyable(),
                TextColumn::make('payload.subject')->label('Subject')->limit(80),
                TextColumn::make('state')->badge(), TextColumn::make('created_at')->label('Received (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->filters([SelectFilter::make('state')->options(['new' => 'New', 'read' => 'Read', 'archived' => 'Archived'])->default('new')])
            ->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordActions([ViewAction::make(), self::stateAction('markRead', 'Mark read', 'read'), self::stateAction('archive', 'Archive', 'archived')])
            ->toolbarActions([])->emptyStateHeading('No matching customer inquiries');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('public_id')->label('Receipt'), TextEntry::make('state'),
            TextEntry::make('payload.name')->label('Name'), TextEntry::make('payload.email')->label('Email'),
            TextEntry::make('payload.subject')->label('Subject'), TextEntry::make('payload.message')->label('Message')->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-wrap']),
            TextEntry::make('created_at')->label('Received (UTC)')->dateTime('Y-m-d H:i:s', 'UTC'),
            TextEntry::make('retention_policy_reference')->label('Retention policy reference'),
        ]);
    }

    public static function stateAction(string $name, string $label, string $target): Action
    {
        return Action::make($name)->label($label)->visible(fn (CustomerInquiry $record): bool => $target === 'read' ? $record->state === 'new' : $record->state !== 'archived')
            ->requiresConfirmation()->modalDescription($target === 'read' ? 'Mark this private inquiry as read. This does not send a reply.' : 'Move this inquiry out of the new inbox. Its original details remain retained; no reply is sent.')
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
