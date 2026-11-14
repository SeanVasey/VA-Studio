<?php

namespace App\Filament\Pages;

use App\Domain\Reporting\ReportingException;
use App\Domain\Reporting\SalesReport;
use App\Domain\Reporting\StaffReportingAuthority;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** Read-only sales and conversion report for staff (FP-089). Authority is rechecked on every Livewire request and in the export. */
final class SalesReportPage extends Page
{
    public const MESSAGES = [
        'invalid_period' => 'Enter both dates as YYYY-MM-DD.',
        'period_order' => 'The start date must not be after the end date.',
        'period_too_long' => 'The period is too long. Choose 400 days or fewer.',
        'granularity' => 'Choose day or month buckets.',
        'day_buckets_too_many' => 'Day buckets are limited to 92 days. Choose month buckets or a shorter period.',
        'too_large' => 'The export is too large. Choose a shorter period.',
        'unsupported_database' => 'This database engine is not supported by the report.',
        'unavailable' => 'The report could not be produced.',
    ];

    protected static ?string $slug = 'sales-report';

    protected static string|UnitEnum|null $navigationGroup = 'Reporting';

    protected static ?string $navigationLabel = 'Sales report';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Sales and conversion report';

    protected string $view = 'filament.reporting.sales-report';

    public string $from = '';

    public string $to = '';

    public string $granularity = 'day';

    public static function canAccess(): bool
    {
        return StaffReportingAuthority::allows();
    }

    /** Runs on every Livewire request, including updates to a component mounted before access was removed. */
    public function boot(): void
    {
        StaffReportingAuthority::abortUnlessStaff();
    }

    public function mount(): void
    {
        ['from' => $this->from, 'to' => $this->to] = SalesReport::defaultRange();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        try {
            return ['report' => app(SalesReport::class)->build(StaffReportingAuthority::currentUser(), $this->from, $this->to, $this->granularity), 'error' => null];
        } catch (ReportingException $error) {
            return ['report' => null, 'error' => self::MESSAGES[$error->reason] ?? self::MESSAGES['unavailable']];
        }
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Action::make('exportCsv')->label('Export CSV')->action(fn (): ?StreamedResponse => $this->download())];
    }

    private function download(): ?StreamedResponse
    {
        try {
            $export = app(SalesReport::class)->export(StaffReportingAuthority::currentUser(), $this->from, $this->to, $this->granularity);
        } catch (AuthorizationException) {
            Notification::make()->title('Export refused')->body('Exports need staff authority and an enrolled authenticator app.')->danger()->send();

            return null;
        } catch (ReportingException $error) {
            Notification::make()->title('Export refused')->body(self::MESSAGES[$error->reason] ?? self::MESSAGES['unavailable'])->danger()->send();

            return null;
        }

        return response()->streamDownload(function () use ($export): void {
            echo $export['csv'];
        }, $export['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
