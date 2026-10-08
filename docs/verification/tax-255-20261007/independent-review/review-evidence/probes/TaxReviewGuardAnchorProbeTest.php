<?php

namespace Tests\ReviewProbes;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * Independent reviewer probe (question 3). Not part of the suite. Provider-identifier guards use REGEXP '^...$'
 * without a length bound on MySQL; ICU '$' also matches before a final line terminator. This records, per driver,
 * whether a trailing LF in an otherwise valid provider identifier is refused by the trigger. Rows are synthetic.
 */
final class TaxReviewGuardAnchorProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private static function base(string $publicId, string $created): array
    {
        return ['public_id' => $publicId, 'created_at' => $created, 'payload_ciphertext' => 'synthetic-ciphertext',
            'payload_hash' => str_repeat('a', 64), 'canonicalization_version' => 'vasey-json-v1'];
    }

    private function attempt(string $kind, array $row): string
    {
        $table = TaxCheckoutSchema::TABLES[$kind];
        $before = DB::table($table)->count();
        try {
            DB::table($table)->insert($row);
        } catch (QueryException) {
            $this->assertSame($before, DB::table($table)->count());

            return 'refused';
        }
        $this->assertSame($before + 1, DB::table($table)->count());

        return 'accepted';
    }

    public function test_q3_trailing_line_feed_in_provider_identifiers(): void
    {
        $order = (int) DB::table(TaxCheckoutSchema::TABLES['order'])->insertGetId(self::base('11111111-1111-4111-8111-111111111111', '2026-10-07T12:00:00Z') + [
            'buyer_origin_id' => '22222222-2222-4222-8222-222222222222', 'candidate_id' => 1, 'request_key' => str_repeat('b', 64),
            'request_hash' => str_repeat('c', 64), 'currency' => 'USD', 'subtotal_minor' => 4999, 'line_count' => 1]);
        $request = fn (string $id, string $account, int $orderId): array => self::base($id, '2026-10-07T12:00:01Z') + ['order_id' => $orderId, 'account_id' => $account,
            'funds_mode' => 'test', 'idempotency_key' => TaxCheckoutSchema::IDEMPOTENCY_PREFIX.$id, 'currency' => 'USD', 'subtotal_minor' => 4999,
            'tax_behavior' => 'exclusive', 'maximum_rate_bps' => 2500, 'provider_expires_at' => '2026-10-07T13:00:01Z'];
        $outcomes = [];
        $outcomes['request.account_id "acct_SYNTHETIC\\n"'] = $this->attempt('request', $request('33333333-3333-4333-8333-333333333333', "acct_SYNTHETIC\n", $order));
        DB::table(TaxCheckoutSchema::TABLES['order'])->insert(self::base('11111111-1111-4111-8111-111111111112', '2026-10-07T12:00:00Z') + [
            'buyer_origin_id' => '22222222-2222-4222-8222-222222222222', 'candidate_id' => 1, 'request_key' => str_repeat('d', 64),
            'request_hash' => str_repeat('c', 64), 'currency' => 'USD', 'subtotal_minor' => 4999, 'line_count' => 1]);
        $order = (int) DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', '11111111-1111-4111-8111-111111111112')->value('id');
        $requestId = (int) DB::table(TaxCheckoutSchema::TABLES['request'])->insertGetId($request('33333333-3333-4333-8333-333333333334', 'acct_SYNTHETIC', $order));
        $binding = fn (string $id, string $session): array => self::base($id, '2026-10-07T12:00:02Z') + ['request_id' => $requestId,
            'account_id' => 'acct_SYNTHETIC', 'funds_mode' => 'test', 'provider_session_id' => $session];
        $outcomes['binding.provider_session_id "cs_test_SYNTHETICTAX\\n"'] = $this->attempt('binding', $binding('44444444-4444-4444-8444-444444444444', "cs_test_SYNTHETICTAX\n"));
        // If the LF row was admitted it already holds the request's single binding; reuse it.
        $bindingId = (int) (DB::table(TaxCheckoutSchema::TABLES['binding'])->where('request_id', $requestId)->value('id')
            ?? DB::table(TaxCheckoutSchema::TABLES['binding'])->insertGetId($binding('44444444-4444-4444-8444-444444444445', 'cs_test_SYNTHETICTAX')));
        $session = DB::table(TaxCheckoutSchema::TABLES['binding'])->where('id', $bindingId)->value('provider_session_id');
        $outcomes['reviewed.provider_payment_id "pi_SYNTHETICTAX\\n"'] = $this->attempt('reviewed', self::base('55555555-5555-4555-8555-555555555555', '2026-10-07T12:00:04Z') + [
            'binding_id' => $bindingId, 'request_id' => $requestId, 'order_id' => $order, 'account_id' => 'acct_SYNTHETIC', 'funds_mode' => 'test',
            'provider_session_id' => $session, 'provider_payment_id' => "pi_SYNTHETICTAX\n", 'currency' => 'USD', 'tax_behavior' => 'exclusive',
            'amount_subtotal_minor' => 4999, 'amount_tax_minor' => 437, 'amount_total_minor' => 5436, 'observed_at' => '2026-10-07T12:00:03Z']);
        fwrite(STDERR, PHP_EOL.'DRIVER '.DB::getDriverName().' '.json_encode($outcomes).PHP_EOL);
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(['refused', 'refused', 'refused'], array_values($outcomes));
        } else {
            // Recorded, not asserted as desired behaviour: see DECISION.md finding on MySQL anchoring.
            $this->assertCount(3, $outcomes);
        }
    }
}
