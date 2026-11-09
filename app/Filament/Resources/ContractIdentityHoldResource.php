<?php

namespace App\Filament\Resources;

use App\Domain\Grants\ContractIdentityHolds;
use App\Domain\Grants\ContractRenderHolds;
use App\Filament\Resources\ContractIdentityHoldResource\Pages\ListContractIdentityHolds;
use App\Support\Audit\AuditEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * D-30: paid orders whose fulfillment paid-grant finalization holds because the frozen contract identity declaration no
 * longer verifies, and paid orders whose `vasey-license-pdf-v2` original cannot be rendered after payment
 * (ContractRenderHolds). List-only, from the append-only hold records; the payment stays recorded and nothing here changes it.
 */
final class ContractIdentityHoldResource extends ReadOnlyCommerceResource
{
    public const CHAINS = ['production_checkout_v1' => 'Checkout (V1)', 'production_tax_checkout_v2' => 'Tax checkout (V2)'];

    public const HOLDS = [ContractIdentityHolds::ACTION => 'Contract identity', ContractRenderHolds::ACTION => 'Contract render'];

    protected static ?string $model = AuditEvent::class;

    protected static ?string $slug = 'contract-identity-holds';

    protected static ?string $pluralModelLabel = 'Held paid orders';

    protected static string|UnitEnum|null $navigationGroup = 'Fulfillment';

    public static function getEloquentQuery(): Builder
    {
        return ContractIdentityHolds::staffQuery();
    }

    public static function table(Table $table): Table
    {
        return $table->description('Paid orders whose license fulfillment is held: either the buyer\'s recorded contract identity no longer verifies (no grant was created), or its license PDF cannot be rendered under the profile recorded at payment (its originals are not prepared). The payment is recorded.')
            ->columns([
                TextColumn::make('context.order_public_id')->label('Order')->copyable(),
                TextColumn::make('context.chain')->label('Checkout')->formatStateUsing(fn (?string $state): string => self::CHAINS[$state] ?? 'Unknown'),
                TextColumn::make('action')->label('Hold')->formatStateUsing(fn (?string $state): string => self::HOLDS[$state] ?? 'Unknown'),
                TextColumn::make('context.reason')->label('Reason')->badge(),
                TextColumn::make('created_at')->label('Held since (UTC)')->dateTime('Y-m-d H:i:s', 'UTC'),
            ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->recordActions([])->toolbarActions([])
            ->emptyStateHeading('No held paid orders');
    }

    public static function getPages(): array
    {
        return ['index' => ListContractIdentityHolds::route('/')];
    }
}
