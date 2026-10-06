<?php

namespace App\Filament\Resources;

use App\Domain\Merch\MerchDrafts;
use App\Domain\Merch\Models\MerchDraft;
use App\Filament\Resources\MerchDraftResource\Pages\ManageMerchDrafts;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class MerchDraftResource extends PrivateProductDraftResource
{
    protected static ?string $model = MerchDraft::class;

    protected static ?string $slug = 'private-merch-drafts';

    protected static ?string $navigationLabel = 'Merchandise drafts';

    protected static ?string $pluralModelLabel = 'Private merchandise drafts';

    public static function commandClass(): string
    {
        return MerchDrafts::class;
    }

    public static function fields(): array
    {
        return [TextInput::make('title')->required()->maxLength(180), Textarea::make('description')->rows(4)->maxLength(4000),
            Repeater::make('variants')->label('Variant order')->required()->minItems(1)->maxItems(50)->defaultItems(1)
                ->reorderableWithButtons()->addActionLabel('Add variant')->schema([
                    TextInput::make('id')->label('Stable variant identity')->required()->maxLength(64)
                        ->helperText('Use a unique lowercase identity. Retained versions keep this identity and its description.'),
                    TextInput::make('label')->label('Variant label')->required()->maxLength(180),
                    TextInput::make('size')->maxLength(80), TextInput::make('color')->maxLength(80),
                    TextInput::make('source_reference')->label('Private source reference')->maxLength(500)
                        ->helperText('Optional supplied SKU or source reference. Keep credentials out of this field.'),
                    ...self::declarationFields('availability', 'Availability'),
                ]), ...self::declarationFields('source', 'Fulfillment source'), ...self::declarationFields('shipping', 'Shipping'),
            ...self::declarationFields('returns', 'Returns')];
    }

    public static function getPages(): array
    {
        return ['index' => ManageMerchDrafts::route('/')];
    }
}
