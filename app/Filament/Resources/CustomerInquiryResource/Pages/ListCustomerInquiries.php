<?php

namespace App\Filament\Resources\CustomerInquiryResource\Pages;

use App\Domain\Inquiries\InquiryAdministration;
use App\Filament\Resources\CustomerInquiryResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ListCustomerInquiries extends ListRecords
{
    protected static string $resource = CustomerInquiryResource::class;

    public function boot(): void
    {
        app(InquiryAdministration::class)->actor(Filament::auth()->user());
    }

    public function mount(): void
    {
        app(InquiryAdministration::class)->openInbox(Filament::auth()->user());
        parent::mount();
    }

    public function getTableRecordsPerPage(): int
    {
        return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 25;
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::getTableRecords());
    }

    public function getTableRecord(?string $key): Model|array|null
    {
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::getTableRecord($key));
    }

    public function getAllTableRecordsCount(): int
    {
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::getAllTableRecordsCount());
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
