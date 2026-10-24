<?php

namespace App\Filament\Widgets;

use App\Filament\Reporting\OperationalHealthWidget;

/** Operational health section "content" (FP-028). */
final class ContentPipelineWidget extends OperationalHealthWidget
{
    protected const SECTION = 'content';

    protected static ?int $sort = -28;
}
