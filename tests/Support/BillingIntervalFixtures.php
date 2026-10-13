<?php

namespace Tests\Support;

use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Structural synthetic rows exercise raw SQL invariants; they are never invoice authority. */
trait BillingIntervalFixtures
{
    private function positions(int $count): array
    {
        $pdo = DB::connection()->getPdo();
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $pdo->prepare('INSERT INTO '.(new BillingSchema)->table(BillingSchema::TABLES[4]).' (kind, created_at) VALUES (?, ?)')
                ->execute(['retrieval', '2026-10-13 00:00:00']);
            $ids[] = (int) $pdo->lastInsertId();
        }

        return $ids;
    }

    private function intervalRows(array $binding, int $start, int $end): array
    {
        $id = (string) Str::uuid();
        $invoice = ['id' => $id, 'subscription_binding_id' => $binding['id'], 'invoice_ref_hash' => hash('sha256', 'invoice'.$id),
            'source_invoice_hash' => hash('sha256', 'source'.$id), 'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test',
            'payload_ciphertext' => 'synthetic interval fixture', 'seal' => hash('sha256', 'seal'.$id), 'created_at' => '2026-10-13 00:00:00'];
        $this->insertIntervalRow(BillingSchema::TABLES[1], $invoice);
        $observationId = (string) Str::uuid();
        $observation = ['id' => $observationId, 'invoice_id' => $id, 'sequence' => 1, 'outcome' => 'unknown',
            'facts_hash' => hash('sha256', 'facts'.$observationId), 'line_period_start' => null, 'line_period_end' => null,
            'amount_minor' => null, 'currency' => null, 'retrieved_at' => '2026-10-13 00:00:01',
            'retrieval_started_at' => '2026-10-13 00:00:00.500000', 'retrieval_position' => $start, 'retrieval_end_position' => $end,
            'freshness_deadline' => '2026-10-13 00:10:01', 'api_version' => '2026-08-26.dahlia',
            'sdk_reference' => '0d8b075e1a97d15c5324353a5277d0ea686ea525', 'prior_seal' => str_repeat('0', 64),
            'payload_ciphertext' => 'synthetic interval fixture', 'seal' => hash('sha256', 'seal'.$observationId), 'created_at' => '2026-10-13 00:00:02'];
        $attempt = ['id' => (string) Str::uuid(), 'subscription_binding_id' => $binding['id'], 'invoice_ref_hash' => $invoice['invoice_ref_hash'],
            'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test', 'provenance' => $binding['provenance'],
            'hint_event_id' => null, 'retrieval_position' => $start, 'retrieval_end_position' => $end,
            'outcome' => 'unknown', 'reason' => 'provider_unavailable', 'payload_ciphertext' => 'synthetic interval fixture',
            'seal' => hash('sha256', 'attempt'.$id), 'created_at' => '2026-10-13 00:00:02'];

        return [BillingSchema::TABLES[2] => $observation, BillingSchema::TABLES[5] => $attempt];
    }

    private function insertIntervalRow(string $table, array $row): void
    {
        DB::connection()->getPdo()->prepare('INSERT INTO '.(new BillingSchema)->table($table).' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')
            ->execute(array_values($row));
    }

    private function dropIntervalGuards(): void
    {
        foreach ([BillingSchema::TABLES[2], BillingSchema::TABLES[5]] as $table) {
            DB::connection()->getPdo()->exec('DROP TRIGGER '.$table.'_interval');
        }
    }

    private function billingSnapshot(): array
    {
        return array_map(fn ($table) => DB::connection()->getPdo()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), BillingSchema::TABLES);
    }
}
