<?php

namespace App\Filament\Forms;

use App\Domain\Rights\TypedLicenseTerms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class TypedLicenseFields
{
    public static function make(): Section
    {
        $fields = [];
        foreach (TypedLicenseTerms::USAGE as $key => $label) {
            $path = 'structured_terms.usage.'.$key;
            $limited = fn (Get $get) => $get($path.'.mode') === 'limited';
            $fields[] = Select::make($path.'.mode')->label($label)->options(['prohibited' => 'Not permitted', 'limited' => 'Limited', 'unlimited' => 'Unlimited'])->required()->live()->helperText('Source variable: {{usage.'.$key.'}}');
            $fields[] = TextInput::make($path.'.limit')->label($label.' limit')->numeric()->integer()->minValue(1)->maxValue(2147483647)
                ->visible($limited)->required($limited)->dehydrated($limited)
                ->dehydrateStateUsing(fn ($state) => is_string($state) && ctype_digit($state) ? (int) $state : $state);
        }
        foreach (TypedLicenseTerms::PERMISSIONS as $key => $label) {
            $fields[] = Select::make('structured_terms.permissions.'.$key)->label($label)->options(['permitted' => 'Permitted', 'prohibited' => 'Not permitted'])->required()->helperText('Source variable: {{permissions.'.$key.'}}');
        }
        $required = fn (Get $get) => $get('structured_terms.credit.mode') === 'required';
        $fields[] = Select::make('structured_terms.credit.mode')->label('Producer credit')->options(['required' => 'Required', 'not_required' => 'Not required'])->required()->live()->helperText('Source variable: {{credit}}');
        $fields[] = TextInput::make('structured_terms.credit.text')->label('Approved credit text')->maxLength(120)->visible($required)->required($required)->dehydrated($required);

        return Section::make('Usage rights')->description('Choose every permission explicitly. License-card summaries are generated from these values. Include each shown variable, plus {{deliverables}}, in the authored source and review the resulting text.')
            ->schema($fields)->columns(2)->columnSpanFull()->visible(fn (Get $get) => (int) $get('structured_terms.schema_version') === 2);
    }
}
