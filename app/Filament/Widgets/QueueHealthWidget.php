<?php

namespace App\Filament\Widgets;

use App\Filament\Reporting\OperationalHealthWidget;

/** Operational health section "queue" (FP-028). */
final class QueueHealthWidget extends OperationalHealthWidget
{
    protected const SECTION = 'queue';

    protected static ?int $sort = -30;
}
