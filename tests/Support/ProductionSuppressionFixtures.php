<?php

namespace Tests\Support;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** Synthetic provider binding for in-process rehearsal only; not a reviewed provider configuration. */
trait ProductionSuppressionFixtures
{
    use ProductionFeatureFixtures;

    protected function suppressionSetup(bool $provider = true): RecordingSuppressionProvider
    {
        $this->featureSetup();
        config(['production-suppression' => ['enabled' => true, 'provider' => $provider ? $this->providerBinding() : null]]);

        return new RecordingSuppressionProvider($provider ? CanonicalJson::hash($this->providerBinding()) : null);
    }

    protected function providerBinding(): array
    {
        return ['adapter' => 'synthetic-suppression-adapter', 'version' => 'synthetic-v1',
            'scope' => 'SYNTHETIC rehearsal scope; no provider account', 'reviewReference' => 'SYNTHETIC fixture review only'];
    }

    protected function suppressionRows(): array
    {
        $rows = [];
        foreach (['production_suppression_targets', 'production_suppression_intents', 'production_suppression_attempts', 'production_suppression_confirmations'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }
}
