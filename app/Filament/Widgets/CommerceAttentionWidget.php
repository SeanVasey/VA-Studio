<?php

namespace App\Filament\Widgets;

use App\Filament\Reporting\OperationalHealthWidget;

/** Operational health section "commerce" (FP-028). */
final class CommerceAttentionWidget extends OperationalHealthWidget
{
    protected const SECTION = 'commerce';

    protected static ?int $sort = -29;
}
