<?php

namespace App\Filament\Resources\MembershipCreditResource\Pages;

use App\Domain\Memberships\MembershipAdministration;
use App\Domain\Memberships\Models\MembershipCreditBucket;
use App\Filament\Resources\MembershipCreditResource;
use Filament\Resources\Pages\ListRecords;

final class ListMembershipCredits extends ListRecords
{
    protected static string $resource = MembershipCreditResource::class;

    public function boot(): void
    {
        app(MembershipAdministration::class)->authorize(auth()->user());
    }

    public function inspectCreditHistory(MembershipCreditBucket $record): array
    {
        $action = $this->getMountedAction();
        $context = $action?->getContext() ?? [];
        $account = $this->tableFilters['account']['value'] ?? null;
        abort_unless(count($this->mountedActions) === 1 && $action?->getName() === 'creditHistory'
            && $action->getArguments() === [] && count($context) === 2 && ($context['table'] ?? null) === true
            && ($context['recordKey'] ?? null) === (string) $record->id
            && (is_string($account) || is_int($account)) && (string) $account === (string) $record->customer_account_id, 403);

        return app(MembershipAdministration::class)->creditHistory((int) $record->customer_account_id, (int) $record->id, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
