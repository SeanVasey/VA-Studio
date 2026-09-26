<?php

namespace Tests\Support;

final class ContractRendererFixtures
{
    public static function input(): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_grant_render_input', 'test_only' => true,
            'grant_id' => '00000000-0000-4000-8000-000000000001',
            'order_id' => '00000000-0000-4000-8000-000000000002',
            'grant_effective_at' => '2026-01-01T00:00:00Z',
            'buyer' => ['identity' => 'unverified_guest', 'legal_name' => 'Zoë Émile', 'email' => 'synthetic@example.test'],
            'seller' => ['legal_name' => 'Synthetic test seller'],
            'assent' => ['version' => 'synthetic-v1', 'accepted' => true, 'text' => 'Synthetic acceptance only.'],
            'selection' => ['offer_revision_id' => 1, 'license' => ['version' => 1, 'scope' => 'Synthetic scope']],
            'pricing' => ['currency' => 'GBP', 'total_minor' => 1000],
            'disclosure' => ['name' => 'Synthetic license', 'termsText' => 'FULL TERMS START. Ελληνικά Кириллица. FULL TERMS END.'],
            'inventory_binding' => ['scope_id' => 'synthetic-scope', 'mode' => 'shared']];
    }
}
