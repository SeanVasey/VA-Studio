<?php

namespace App\Filament\Forms;

use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/** Preserve scalar request types until validation; a malformed container is no selection and cannot render as a label. */
final class PreserveTrackIdState implements StateCast
{
    public function get(mixed $state): mixed
    {
        return is_scalar($state) || $state === null ? $state : null;
    }

    public function set(mixed $state): mixed
    {
        // Select exposes raw initial state to JavaScript. Decimal strings keep PHP's entire integer range exact.
        if (is_int($state)) {
            return (string) $state;
        }

        return is_scalar($state) || $state === null ? $state : null;
    }
}
