<?php

namespace App\Filament\Forms;

use App\Domain\Rights\LicenseTerritories;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class LicenseScopeFields
{
    public static function make(): Section
    {
        $scoped = fn (Get $get) => (int) $get('structured_terms.schema_version') === 3;
        $countries = fn (Get $get) => $get('structured_terms.territory.mode') === 'countries';
        $fixed = fn (Get $get) => $get('structured_terms.duration.mode') === 'fixed_months';

        return Section::make('Territory and license duration')->description('Choose the permitted territory and how long the licensed use lasts after a rights grant. These are independent of offer availability. Include {{territory}} and {{duration}} in the authored source.')
            ->schema([
                Select::make('structured_terms.territory.mode')->label('Permitted territory')->options(['worldwide' => 'Worldwide', 'countries' => 'Selected countries'])->required()->live()->helperText('Source variable: {{territory}}. This describes permitted use, not the buyer’s location.'),
                Select::make('structured_terms.territory.country_codes')->label('Countries')->options(LicenseTerritories::options())->multiple()->searchable()->required($countries)->visible($countries)->dehydrated($countries),
                Select::make('structured_terms.duration.mode')->label('License duration')->options(['perpetual' => 'Perpetual from grant', 'fixed_months' => 'Calendar months from grant'])->required()->live()->helperText('Source variable: {{duration}}. The grant starts this duration; saving or publishing an offer does not.'),
                Hidden::make('structured_terms.duration.starts_at')->default('grant')->dehydrated($scoped),
                TextInput::make('structured_terms.duration.months')->label('Calendar months')->numeric()->integer()->minValue(1)->maxValue(1200)
                    ->visible($fixed)->required($fixed)->dehydrated($fixed)->helperText('Count from the original grant timestamp in UTC. If its day is absent in the target month, use that month’s final day.')
                    ->dehydrateStateUsing(fn ($state) => is_string($state) && ctype_digit($state) ? (int) $state : $state),
            ])->columns(2)->columnSpanFull()->visible($scoped);
    }
}
