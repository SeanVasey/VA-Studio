<?php

namespace App\Filament\Reporting;

use App\Domain\Reporting\OperationalHealth;
use App\Domain\Reporting\StaffReportingAuthority;
use Filament\Widgets\Widget;

/**
 * One dashboard section of read-only operational health. Authority is checked on every Livewire request: Filament runs
 * canView() before mount and on each update, and the read model rechecks the signed-in staff user again. A widget that is
 * merely hidden would not be authorization.
 */
abstract class OperationalHealthWidget extends Widget
{
    /** One of OperationalHealth::SECTIONS. */
    protected const SECTION = '';

    protected string $view = 'filament.reporting.health-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return StaffReportingAuthority::allows();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['section' => app(OperationalHealth::class)->section(static::SECTION, StaffReportingAuthority::currentUser())];
    }
}
