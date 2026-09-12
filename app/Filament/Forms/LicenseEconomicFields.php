<?php

namespace App\Filament\Forms;

use App\Domain\Rights\EconomicLicenseTerms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class LicenseEconomicFields
{
    public static function make(): Section
    {
        $share = fn (Get $get) => $get('structured_terms.publishing_income.mode') === 'share';
        $rate = fn (Get $get) => $get('structured_terms.recording_royalty.mode') === 'rate';
        $fields = [];
        foreach (EconomicLicenseTerms::OWNERSHIP as $subject => $label) {
            $fields[] = TextInput::make('structured_terms.ownership.'.$subject.'.policy_key')->label($label.' policy key')->required()->maxLength(64)
                ->helperText('Retain the declaration in the policy below. Source variable: {{ownership.'.$subject.'}}. No ownership transfer or percentage is inferred.');
        }
        $fields[] = Select::make('structured_terms.publishing_income.mode')->label('Licensor publishing-income entitlement')->required()->live()
            ->options(['none' => 'No additional entitlement under this license', 'share' => 'Explicit share of total publishing income'])
            ->helperText('Source variable: {{publishing_income}}. Separate from composition ownership and collaborator sale proceeds.');
        $fields[] = self::basisPoints('structured_terms.publishing_income.licensor_bps', 'Licensor publishing share (basis points)')
            ->visible($share)->required($share)->dehydrated($share);
        $fields[] = TextInput::make('structured_terms.publishing_income.policy_key')->label('Publishing-income policy key')->required()->maxLength(64)
            ->helperText('Define the total publishing income attributable to the resulting composition and the licensor entitlement in this retained policy. Do not substitute a PRO writer/publisher percentage.');
        $fields[] = Select::make('structured_terms.recording_royalty.mode')->label('Additional contractual recording royalty')->required()->live()
            ->options(['none' => 'None payable under this license', 'rate' => 'Licensee pays licensor an explicit rate'])
            ->helperText('Source variable: {{recording_royalty}}. This does not remove external or pre-existing obligations.');
        $fields[] = self::basisPoints('structured_terms.recording_royalty.rate_bps', 'Recording royalty rate (basis points)')
            ->visible($rate)->required($rate)->dehydrated($rate);
        $fields[] = Select::make('structured_terms.recording_royalty.basis')->label('Resulting recording receipts basis')
            ->options(['gross_receipts' => 'Gross receipts', 'net_receipts' => 'Net receipts'])->visible($rate)->required($rate)->dehydrated($rate);
        $fields[] = TextInput::make('structured_terms.recording_royalty.policy_key')->label('Recording royalty policy key')->required()->maxLength(64)
            ->helperText('Retain the receipts definition, permitted deductions, accounting and payment provisions. The application does not calculate payouts.');
        $fields[] = Repeater::make('structured_terms.policies')->label('Retained buyer-facing policies')->defaultItems(0)->minItems(1)->maxItems(6)->required()
            ->schema([
                TextInput::make('key')->label('Unique policy key')->required()->maxLength(64)->regex('/\A[a-z][a-z0-9-]{0,63}\z/'),
                TextInput::make('version')->label('Policy version')->required()->maxLength(32)->regex('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/'),
                Textarea::make('text')->label('Complete buyer-facing policy text')->required()->rows(8)->columnSpanFull()
                    ->helperText('Retain exact plain text, not only a link. Up to 20,000 UTF-8 bytes per policy and 60,000 combined. No template variables. Keep internal notes out of buyer-facing policy text.'),
            ])->columns(2)->columnSpanFull()->helperText('Each key must be referenced above. Policy identity belongs to this license version; the server hashes its exact text. Include {{policy_texts}} exactly once in the source. Review all policy text and generated declarations together.');

        return Section::make('Ownership and economic policies')->description('Choose every declaration explicitly. No production rates, ownership allocations or policy approvals are supplied by the editor.')
            ->schema($fields)->columns(2)->columnSpanFull()->visible(fn (Get $get) => (int) $get('structured_terms.schema_version') === 4);
    }

    private static function basisPoints(string $path, string $label): TextInput
    {
        return TextInput::make($path)->label($label)->numeric()->integer()->minValue(1)->maxValue(10000)
            ->helperText('Integer basis points: 100 = 1.00%, 10,000 = 100.00%. No remainder or third-party share is inferred.')
            ->dehydrateStateUsing(fn ($state) => is_string($state) && ctype_digit($state) ? (int) $state : $state);
    }
}
