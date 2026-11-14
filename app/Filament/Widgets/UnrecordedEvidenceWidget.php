<?php

namespace App\Filament\Widgets;

use App\Filament\Reporting\OperationalHealthWidget;

/** Operational health section "evidence" (FP-028). */
final class UnrecordedEvidenceWidget extends OperationalHealthWidget
{
    protected const SECTION = 'evidence';

    protected static ?int $sort = -27;
}
