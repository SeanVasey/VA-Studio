<?php

namespace App\Filament\Pages;

use App\Domain\Reporting\CustomerConsentStatus;
use App\Domain\Reporting\CustomerSegments;
use App\Domain\Reporting\ReportingException;
use App\Domain\Reporting\StaffReportingAuthority;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Consent-aware customer segmentation for staff (FP-090). Default-off: the page explains that, reads nothing and refuses
 * the export until the owner enables it (U-13). The screen shows counts; rows leave only through the audited export.
 */
final class CustomerSegmentsPage extends Page
{
    public const MESSAGES = [
        'disabled' => 'Customer segmentation or its export is off. It needs the owner policy decision U-13.',
        'marketing_disabled' => 'The marketing segment is off.',
        'unknown_segment' => 'Choose one of the listed segments.',
        'too_large' => 'Too many rows for one export.',
        'unavailable' => 'This segment cannot be evaluated truthfully right now.',
        'consent_filter' => 'The consent filter refused the export.',
    ];

    protected static ?string $slug = 'customer-segments';

    protected static string|UnitEnum|null $navigationGroup = 'Reporting';

    protected static ?string $navigationLabel = 'Customer segments';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Customer segments';

    protected string $view = 'filament.reporting.customer-segments';

    public string $segment = 'purchasers';

    public static function canAccess(): bool
    {
        return StaffReportingAuthority::allows();
    }

    /** Runs on every Livewire request, including updates to a component mounted before access was removed. */
    public function boot(): void
    {
        StaffReportingAuthority::abortUnlessStaff();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        try {
            return ['overview' => app(CustomerSegments::class)->overview(StaffReportingAuthority::currentUser()), 'error' => null, 'consentLabels' => CustomerConsentStatus::LABELS];
        } catch (ReportingException $error) {
            return ['overview' => null, 'error' => self::MESSAGES[$error->reason] ?? self::MESSAGES['unavailable'], 'consentLabels' => []];
        }
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Action::make('exportCsv')->label('Export selected segment (CSV)')->action(fn (): ?StreamedResponse => $this->download())];
    }

    private function download(): ?StreamedResponse
    {
        try {
            $export = app(CustomerSegments::class)->export(StaffReportingAuthority::currentUser(), $this->segment);
        } catch (AuthorizationException) {
            Notification::make()->title('Export refused')->body('Customer exports need staff authority and an enrolled authenticator app.')->danger()->send();

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
