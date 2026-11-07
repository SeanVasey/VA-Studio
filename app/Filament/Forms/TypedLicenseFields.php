<?php

namespace App\Filament\Forms;

use App\Domain\Rights\TieredLicenseTerms;
use App\Domain\Rights\TypedLicenseTerms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class TypedLicenseFields
{
    /**
     * One component per usage state path. Both orders are preserved: schemas 2 to 4 show only the
     * v2 vocabulary and schema 5 only its own, so a shared key never carries the other schema's label.
     */
    private const USAGE_ORDER = [
        'audio_releases', 'copies_downloads', 'monetized_streams', 'non_monetized_streams', 'audio_streams',
        'monetized_video_streams', 'non_monetized_video_streams', 'music_videos', 'live_performances',
        'radio_stations', 'free_downloads', 'for_profit_performances', 'not_for_profit_performances',
    ];

    public static function make(): Section
    {
        $fields = [];
        foreach (self::USAGE_ORDER as $key) {
            $path = 'structured_terms.usage.'.$key;
            $label = fn (Get $get) => self::usage($get)[$key] ?? $key;
            $applies = fn (Get $get) => array_key_exists($key, self::usage($get));
            $limited = fn (Get $get) => $applies($get) && $get($path.'.mode') === 'limited';
            $fields[] = Select::make($path.'.mode')->label($label)->options(['prohibited' => 'Not permitted', 'limited' => 'Limited', 'unlimited' => 'Unlimited'])->required()->live()->helperText('Source variable: {{usage.'.$key.'}}')
                ->visible($applies)->dehydrated($applies);
            $fields[] = TextInput::make($path.'.limit')->label(fn (Get $get) => $label($get).' limit')->numeric()->integer()->minValue(1)->maxValue(2147483647)
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
            ->schema($fields)->columns(2)->columnSpanFull()->visible(fn (Get $get) => in_array((int) $get('structured_terms.schema_version'), [2, 3, 4, 5], true));
    }

    /** @return array<string, string> the usage vocabulary of the schema being authored */
    private static function usage(Get $get): array
    {
        return (int) $get('structured_terms.schema_version') === 5 ? TieredLicenseTerms::USAGE : TypedLicenseTerms::USAGE;
    }
}
