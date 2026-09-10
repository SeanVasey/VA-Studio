<?php

namespace App\Filament\Resources\LicenseVersionResource\Pages;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Filament\Resources\LicenseVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Arr;

class ManageLicenseVersions extends ManageRecords
{
    protected static string $resource = LicenseVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data, $livewire) => LicenseVersionResource::withFormErrors(fn () => app(CreateLicenseDraft::class)->handle(LicenseTemplate::findOrFail($data['license_template_id']), Arr::only($data, ['authored_source', 'structured_terms', 'effective_from', 'effective_until']), auth()->user()), $livewire))];
    }
}
