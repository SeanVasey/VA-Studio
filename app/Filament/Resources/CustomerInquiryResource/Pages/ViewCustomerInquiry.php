<?php

namespace App\Filament\Resources\CustomerInquiryResource\Pages;

use App\Domain\Inquiries\InquiryAdministration;
use App\Filament\Resources\CustomerInquiryResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewCustomerInquiry extends ViewRecord
{
    protected static string $resource = CustomerInquiryResource::class;

    public function boot(): void
    {
        app(InquiryAdministration::class)->actor(Filament::auth()->user());
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->record = app(InquiryAdministration::class)->view($this->getRecord()->id, Filament::auth()->user());
    }

    protected function resolveRecord(int|string $key): Model
    {
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::resolveRecord($key));
    }

    public function getRecord(): Model
    {
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [CustomerInquiryResource::stateAction('markRead', 'Mark read', 'read'), CustomerInquiryResource::stateAction('archive', 'Archive', 'archived')];
    }
}
