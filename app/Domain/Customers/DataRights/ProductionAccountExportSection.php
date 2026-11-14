<?php

namespace App\Domain\Customers\DataRights;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a production account holds in the production feature stores, for its own export (BL-44, schema 4): which feature bindings it
 * has, whether its listening library exists and at which version, and its production consent ledger. Every row is found through the
 * locked account's id (`account_id` on a binding, then the binding for the library and ledger), never an identifier from the request.
 *
 * Deliberately not exported: the library's contents (they have their own export, `/customer/features/listening/export`, which needs
 * the library's version and the feature policy), the sealed binding, hashes and the encrypted recipient. The ledger rows carry no
 * address; the account's current email is already in the document.
 */
final class ProductionAccountExportSection
{
    /** @return array{bindings: list<array>, listeningLibrary: ?array, consent: list<array>} */
    public function build(int $accountId): array
    {
        $bindings = DB::table('production_account_feature_bindings')->where('account_id', $accountId)->orderBy('id')
            ->limit(CustomerDataExportDocument::MAX_ROWS + 1)->get(['id', 'public_id', 'feature', 'created_at']);
        if ($bindings->count() > CustomerDataExportDocument::MAX_ROWS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $ids = $bindings->pluck('id')->all();
        $library = null;
        $consent = [];
        if ($ids !== []) {
            $row = DB::table('production_listening_libraries')->whereIn('binding_id', $ids)->orderBy('id')->first(['version', 'created_at', 'updated_at']);
            $library = $row === null ? null : ['version' => (int) $row->version, 'createdAt' => $this->time($row->created_at), 'updatedAt' => $this->time($row->updated_at)];
            $events = DB::table('production_consent_events')->whereIn('binding_id', $ids)->orderBy('id')
                ->limit(CustomerDataExportDocument::MAX_ROWS + 1)->get(['purpose', 'revision', 'status', 'source', 'affirmative', 'created_at']);
            if ($events->count() > CustomerDataExportDocument::MAX_ROWS) {
                throw new DataRightsException(413, 'export_too_large');
            }
            $consent = $events->map(fn ($e): array => ['purpose' => $e->purpose, 'revision' => (int) $e->revision, 'status' => $e->status,
                'source' => $e->source, 'affirmative' => (bool) $e->affirmative, 'createdAt' => $this->time($e->created_at)])->all();
        }

        return ['bindings' => $bindings->map(fn ($b): array => ['id' => $b->public_id, 'feature' => $b->feature, 'createdAt' => $this->time($b->created_at)])->all(),
            'listeningLibrary' => $library, 'consent' => $consent];
    }

    private function time(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString();
    }
}
