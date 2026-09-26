<?php

namespace App\Filament\Resources;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Operations\ReadTestCommerceOperations;
use App\Filament\Resources\TestContractIssuanceResource\Pages\ListTestContractIssuance;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class TestContractIssuanceResource extends ReadOnlyCommerceResource
{
    protected static ?string $model = LicenseGrant::class;
    protected static ?string $slug = 'test-contract-issuance';
    protected static ?string $pluralModelLabel = 'Test contract issuance';

    public static function getEloquentQuery(): Builder { return app(ReadTestCommerceOperations::class)->contracts(); }

    public static function table(Table $table): Table
    {
        return $table->description('Recorded test-contract progress for the configured account. Original recorded means a linked database manifest; file health is not checked and downloads remain inactive.')
            ->columns([
                TextColumn::make('public_id')->label('Grant')->searchable(['license_grants.public_id'])->copyable(),
                TextColumn::make('order_public_id')->label('Order')->searchable(['o.public_id'])->copyable(),
                TextColumn::make('issuance_state')->label('Recorded status')->badge()->formatStateUsing(
                    fn (?string $state): string => ReadTestCommerceOperations::STATES[$state] ?? 'Evidence needs attention'),
                TextColumn::make('work_reason')->label('Recorded reason')->formatStateUsing(
                    fn (?string $state): string => ReadTestCommerceOperations::WORK_REASONS[$state] ?? '—')->placeholder('—'),
                TextColumn::make('attempts')->label('Attempts')->placeholder('—'),
                TextColumn::make('request_public_id')->label('Request')->copyable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('document_public_id')->label('Original')->copyable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('next_attempt_at')->label('Retry after (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('—'),
                TextColumn::make('lease_expires_at')->label('Claim expires (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('—'),
                TextColumn::make('issued_at')->label('Original recorded (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('—'),
            ])->filters([
                SelectFilter::make('issuance_state')->options(ReadTestCommerceOperations::STATES)
                    ->query(fn (Builder $query, array $data): Builder => ReadTestCommerceOperations::filterContracts($query, $data['value'] ?? null)),
            ])->defaultSort('license_grants.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([])->toolbarActions([])
            ->emptyStateHeading('No matching test contract records')
            ->emptyStateDescription('Paid test grants appear here even before contract work is requested.');
    }

    public static function getPages(): array { return ['index' => ListTestContractIssuance::route('/')]; }
}
