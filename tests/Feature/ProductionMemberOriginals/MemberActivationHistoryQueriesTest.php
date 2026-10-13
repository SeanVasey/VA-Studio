<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberActivationHistoryReader;
use App\Domain\Grants\Member\MemberGrantException;
use App\Support\CanonicalJson;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Fixed-query mechanics on explicit SQLite rows; no schema, record authentication or authority claim. */
class MemberActivationHistoryQueriesTest extends TestCase
{
    private const PERIOD = '11111111-1111-4111-8111-111111111111';

    private const REDEMPTION = '22222222-2222-4222-8222-222222222222';

    private const FOREIGN = '33333333-3333-4333-8333-333333333333';

    private const DEFINITION = '44444444-4444-4444-8444-444444444444';

    private const PROFILE = '55555555-5555-4555-8555-555555555555';

    private const PLAN = '66666666-6666-4666-8666-666666666666';

    private const ACTIVATION = '77777777-7777-4777-8777-777777777777';

    private const ARTIFACT = '88888888-8888-4888-8888-888888888888';

    private array $state;

    private array $templates;

    protected function setUp(): void
    {
        $h = str_repeat('a', 64);
        $at = '2026-10-13 12:00:00';
        $base = ['payload_ciphertext' => 'synthetic-query-mechanics-only', 'seal' => $h, 'created_at' => $at];
        $this->templates = [
            'period' => ['id' => self::PERIOD, 'account_id' => 1, 'user_id' => 2, 'identity_origin_id' => 3,
                'plan_version_id' => self::PLAN, 'source_invoice_hash' => $h, 'invoice_graph_hash' => $h,
                'owner_binding_hash' => $h, 'allowance' => 10, 'period_start' => $at, 'period_end' => '2026-11-13 12:00:00',
                'credit_expires_at' => null, ...$base],
            'redemptions' => ['id' => self::REDEMPTION, 'period_id' => self::PERIOD, 'request_key_hash' => $h,
                'owner_binding_hash' => $h, 'credit_amount' => 1, 'selection_hash' => $h, 'license_manifest_hash' => $h,
                'asset_manifest_hash' => $h, 'original_terms_hash' => $h, 'intent_hash' => $h,
                'honor_deadline' => '2026-10-13 12:05:00', ...$base],
            'events' => ['id' => '99999999-9999-4999-8999-999999999999', 'period_id' => self::PERIOD, 'sequence' => 1,
                'kind' => 'award', 'amount' => 10, 'redemption_id' => null, 'reservation_event_id' => null,
                'grant_origin_id' => null, 'grant_receipt_hash' => null, 'grant_purpose' => null,
                'key_hash' => $h, 'request_hash' => $h, 'prior_seal' => $h, 'actor_binding_hash' => $h,
                'before_available' => 0, 'before_reserved' => 0, 'before_consumed' => 0, 'before_expired' => 0,
                'after_available' => 10, 'after_reserved' => 0, 'after_consumed' => 0, 'after_expired' => 0,
                'seal' => $h, 'created_at' => $at],
            'origins' => ['id' => self::REDEMPTION, 'redemption_id' => self::REDEMPTION,
                'definition_id' => self::DEFINITION, 'profile_id' => self::PROFILE,
                'account_id' => 1, 'user_id' => 2, 'identity_origin_id' => 3, 'invoice_identity_hash' => $h,
                'owner_binding_hash' => $h, 'intent_hash' => $h, 'license_manifest_hash' => $h, 'asset_manifest_hash' => $h,
                'original_terms_hash' => $h, 'artifact_count' => 1, 'artifact_manifest_hash' => $h,
                'honor_deadline' => '2026-10-13 12:05:00', 'provenance' => 'synthetic_rehearsal', ...$base],
            'artifacts' => ['id' => self::ARTIFACT, 'origin_id' => self::REDEMPTION, 'ordinal' => 0,
                'role' => 'license_pdf', 'sha256' => $h, 'bytes' => 100, 'storage_policy_hash' => $h, ...$base],
            'activations' => ['id' => self::ACTIVATION, 'origin_id' => self::REDEMPTION, 'redemption_id' => self::REDEMPTION,
                'artifact_manifest_hash' => $h, 'readiness_receipt_hash' => $h, 'reservation_event_hash' => $h,
                'purpose' => 'production-member-license-grant-v1', ...$base],
            'profiles' => ['id' => self::PROFILE, 'profile_hash' => $h, 'original_terms_hash' => $h,
                'implementation_hash' => $h, 'font_manifest_hash' => $h, 'provenance' => 'synthetic_rehearsal', ...$base],
            'definitions' => ['id' => self::DEFINITION, 'profile_id' => self::PROFILE, 'definition_hash' => $h,
                'original_terms_hash' => $h, 'policy_hash' => $h, 'license_manifest_hash' => $h, 'asset_manifest_hash' => $h,
                'retention_policy_hash' => $h, 'family' => 'production-member-origin-v1',
                'purpose' => 'production-member-license-grant-v1', 'version' => 1, 'provenance' => 'synthetic_rehearsal', ...$base],
            'plans' => ['id' => self::PLAN, 'policy_hash' => $h, 'original_terms_hash' => $h,
                'provenance' => 'synthetic_rehearsal', ...$base],
        ];
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = [];
        foreach ($this->templates as $name => $template) {
            $tables[$name] = '`main`.`'.$name.'`';
            $columns = [];
            foreach ($template as $column => $value) {
                $columns[] = '`'.$column.'` '.(is_int($value) ? 'INTEGER' : 'TEXT');
            }
            $pdo->exec('CREATE TABLE '.$tables[$name].' ('.implode(',', $columns).', PRIMARY KEY (id))');
        }
        $this->state = ['pdo' => $pdo, 'driver' => 'sqlite', 'tables' => $tables, 'periodId' => self::PERIOD];
        $this->insert('period');
        $this->insert('redemptions');
        $this->insert('events');
    }

    public function test_empty_effect_history_still_captures_complete_period_scope_and_ignores_unrelated_periods(): void
    {
        $this->insert('redemptions', ['id' => self::FOREIGN, 'period_id' => self::FOREIGN]);
        $this->insert('origins', ['id' => self::FOREIGN, 'redemption_id' => self::FOREIGN, 'invoice_identity_hash' => str_repeat('b', 64)]);
        $actual = $this->read();
        $this->assertSame(self::PERIOD, $actual['scope']['period']['id']);
        $this->assertSame([self::REDEMPTION], array_column($actual['scope']['redemptions'], 'id'));
        $this->assertCount(1, $actual['scope']['events']);
        $this->assertSame(array_fill_keys(['origins', 'artifacts', 'activations', 'profiles', 'definitions', 'plans'], []), $actual['member']);
    }

    public function test_every_overlapping_scope_predicate_deduplicates_the_exact_complete_row(): void
    {
        $this->completeQueryGraph();
        $this->state['pdo']->exec("UPDATE events SET grant_origin_id = '".self::REDEMPTION."'");
        $actual = $this->read();
        foreach ($actual['member'] as $rows) {
            $this->assertCount(1, $rows);
        }
        $this->assertSame('3', $actual['member']['origins'][0]['identity_origin_id']);
        $this->assertSame($this->templates['origins']['payload_ciphertext'], $actual['member']['origins'][0]['payload_ciphertext']);
    }

    public function test_invoice_predicate_includes_an_orphan_with_no_period_redemption(): void
    {
        $this->insert('origins', ['id' => self::FOREIGN, 'redemption_id' => self::FOREIGN]);
        $this->refuses($this->read(...), 'history_scope');
    }

    public function test_origin_id_matching_a_period_redemption_cannot_hide_its_foreign_redemption(): void
    {
        $this->insert('origins', ['redemption_id' => self::FOREIGN, 'invoice_identity_hash' => str_repeat('b', 64)]);
        $this->refuses($this->read(...), 'history_scope');
    }

    public function test_activation_redemption_predicate_reaches_a_misleading_foreign_origin(): void
    {
        $this->insert('activations', ['origin_id' => self::FOREIGN]);
        $this->insert('origins', ['id' => self::FOREIGN, 'redemption_id' => self::FOREIGN, 'invoice_identity_hash' => str_repeat('b', 64)]);
        $this->refuses($this->read(...), 'history_scope');
    }

    public function test_declared_grant_origin_is_required_even_without_an_activation(): void
    {
        $this->state['pdo']->exec("UPDATE events SET grant_origin_id = '".self::FOREIGN."'");
        $this->refuses($this->read(...), 'history_scope');
    }

    public function test_activation_origin_predicate_does_not_adopt_a_foreign_redemption(): void
    {
        $this->insert('origins');
        $this->insert('activations', ['redemption_id' => self::FOREIGN]);
        $this->refuses($this->read(...), 'history_scope');
    }

    public function test_artifact_bound_plus_one_detects_surplus_before_any_record_factory(): void
    {
        $this->completeQueryGraph();
        $this->insert('artifacts', ['id' => self::FOREIGN, 'ordinal' => 1]);
        $this->refuses($this->read(...), 'history_capacity');
    }

    public function test_missing_supporting_definition_cannot_be_an_empty_history(): void
    {
        $this->insert('origins');
        $this->refuses($this->read(...), 'history_scope');
    }

    public function test_zero_redemptions_still_queries_invoice_scope_with_one_overflow_row(): void
    {
        $this->state['pdo']->exec('DELETE FROM redemptions');
        $this->insert('origins');
        $this->refuses($this->read(...), 'history_capacity');
    }

    public function test_ciphertext_projection_refuses_oversize_instead_of_accepting_null_or_truncated_bytes(): void
    {
        $this->insert('origins', ['payload_ciphertext' => str_repeat('x', 4194305)]);
        $this->refuses($this->read(...), 'history_capacity');
    }

    public function test_bounded_projection_checks_scalar_columns_too(): void
    {
        $this->insert('origins', ['owner_binding_hash' => str_repeat('x', 65)]);
        $this->refuses($this->read(...), 'history_capacity');
    }

    public function test_key_batches_read_the_final_key_after_256_without_adopting_unselected_rows(): void
    {
        $ids = [];
        for ($i = 0; $i < 257; $i++) {
            $id = sprintf('%08d-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $i);
            $ids[] = $id;
            $this->insert('profiles', ['id' => $id]);
        }
        $this->insert('profiles', ['id' => self::FOREIGN, 'payload_ciphertext' => str_repeat('x', 1572865)]);
        $sets = ['profiles' => []];
        $bytes = 0;
        (new ReflectionMethod(MemberActivationHistoryReader::class, 'byKeys'))->invokeArgs(null,
            [$this->state, 'profiles', 'id', array_reverse($ids), 257, false, &$sets, &$bytes]);
        $this->assertSame($ids, array_keys($sets['profiles']));
        $this->assertSame(array_sum(array_map(static fn (array $row): int => strlen(CanonicalJson::encode($row)), $sets['profiles'])), $bytes);
    }

    public function test_raw_budget_counts_canonical_row_bytes_once_across_overlapping_predicates(): void
    {
        $this->insert('origins');
        $sets = ['origins' => []];
        $bytes = 0;
        $collect = new ReflectionMethod(MemberActivationHistoryReader::class, 'collect');
        $collect->invokeArgs(null, [$this->state, 'origins', 'id = ?', [self::REDEMPTION], 1, false, &$sets, &$bytes]);
        $expected = strlen(CanonicalJson::encode($sets['origins'][self::REDEMPTION]));
        $this->assertSame($expected, $bytes);
        $collect->invokeArgs(null, [$this->state, 'origins', 'redemption_id = ?', [self::REDEMPTION], 1, false, &$sets, &$bytes]);
        $this->assertSame($expected, $bytes);
        $this->assertCount(1, $sets['origins']);
    }

    public function test_raw_budget_rejects_exact_one_byte_overflow_and_accepts_the_boundary(): void
    {
        $this->insert('origins', ['payload_ciphertext' => "synthetic\nquoted\"payload"]);
        $collect = new ReflectionMethod(MemberActivationHistoryReader::class, 'collect');
        $sets = ['origins' => []];
        $bytes = 0;
        $collect->invokeArgs(null, [$this->state, 'origins', 'id = ?', [self::REDEMPTION], 1, false, &$sets, &$bytes]);
        $rowBytes = $bytes;
        $sets = ['origins' => []];
        // This is the actual accumulator boundary, not a claim that a complete 64 MiB graph was decoded.
        $bytes = 67108864 - $rowBytes;
        $collect->invokeArgs(null, [$this->state, 'origins', 'id = ?', [self::REDEMPTION], 1, false, &$sets, &$bytes]);
        $this->assertSame(67108864, $bytes);
        $sets = ['origins' => []];
        $bytes = 67108864 - $rowBytes + 1;
        $this->refuses(function () use ($collect, &$sets, &$bytes): void {
            $collect->invokeArgs(null, [$this->state, 'origins', 'id = ?', [self::REDEMPTION], 1, false, &$sets, &$bytes]);
        }, 'history_capacity');
        $this->assertSame([], $sets['origins']);
    }

    public function test_257_rows_do_not_spend_the_member_only_raw_budget(): void
    {
        $sets = ['redemptions' => []];
        $bytes = 67108864;
        (new ReflectionMethod(MemberActivationHistoryReader::class, 'collect'))->invokeArgs(null,
            [$this->state, 'redemptions', 'period_id = ?', [self::PERIOD], 1, false, &$sets, &$bytes]);
        $this->assertSame(67108864, $bytes);
        $this->assertCount(1, $sets['redemptions']);
    }

    private function insert(string $name, array $overrides = []): void
    {
        $row = array_replace($this->templates[$name], $overrides);
        $statement = $this->state['pdo']->prepare('INSERT INTO '.$this->state['tables'][$name]
            .' (`'.implode('`,`', array_keys($row)).'`) VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private function completeQueryGraph(): void
    {
        foreach (['origins', 'artifacts', 'activations', 'profiles', 'definitions', 'plans'] as $table) {
            $this->insert($table);
        }
    }

    private function read(): array
    {
        return (new ReflectionMethod(MemberActivationHistoryReader::class, 'read'))->invoke(null, $this->state, false);
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Invalid retained query scope was admitted.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
