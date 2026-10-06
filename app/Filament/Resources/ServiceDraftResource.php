<?php

namespace App\Filament\Resources;

use App\Domain\Services\Models\ServiceDraft;
use App\Domain\Services\ServiceDrafts;
use App\Filament\Resources\ServiceDraftResource\Pages\ManageServiceDrafts;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class ServiceDraftResource extends PrivateProductDraftResource
{
    protected static ?string $model = ServiceDraft::class;

    protected static ?string $slug = 'private-service-drafts';

    protected static ?string $navigationLabel = 'Service drafts';

    protected static ?string $pluralModelLabel = 'Private service drafts';

    public static function commandClass(): string
    {
        return ServiceDrafts::class;
    }

    public static function fields(): array
    {
        return [TextInput::make('title')->required()->maxLength(180), Textarea::make('description')->rows(4)->maxLength(4000),
            Repeater::make('brief_questions')->label('Brief questions')->simple(Textarea::make('question')->required()->maxLength(500))
                ->defaultItems(0)->maxItems(20)->reorderableWithButtons()->addActionLabel('Add brief question'),
            ...self::declarationFields('scope', 'Scope'), ...self::declarationFields('deposit', 'Deposit'),
            ...self::declarationFields('revisions', 'Revisions'), ...self::declarationFields('cancellation', 'Cancellation')];
    }

    public static function getPages(): array
    {
        return ['index' => ManageServiceDrafts::route('/')];
    }
}
