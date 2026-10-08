<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * Raw-SQL probes of every Tax255 insert guard. Each malformed or out-of-order row must be refused by the
 * trigger itself (not by PHP), and each valid row accepted. Runs on the configured driver; the MySQL-only
 * timestamp/collation behaviour has its own native class.
 */
class ProductionTaxCheckoutGuardTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MESSAGE = 'Invalid or immutable production tax checkout evidence';

    private static function base(string $publicId, string $created): array
    {
        return ['public_id' => $publicId, 'created_at' => $created, 'payload_ciphertext' => 'synthetic-ciphertext',
            'payload_hash' => str_repeat('a', 64), 'canonicalization_version' => 'vasey-json-v1'];
    }

    private static function order(array $replace = []): array
    {
        return array_replace(self::base('11111111-1111-4111-8111-111111111111', '2026-10-07T12:00:00Z') + [
            'buyer_origin_id' => '22222222-2222-4222-8222-222222222222', 'candidate_id' => 1, 'request_key' => str_repeat('b', 64),
            'request_hash' => str_repeat('c', 64), 'currency' => 'USD', 'subtotal_minor' => 4999, 'line_count' => 1], $replace);
    }

    private static function request(int $orderId, array $replace = [], string $behavior = 'exclusive'): array
    {
        $id = '33333333-3333-4333-8333-333333333333';

        return array_replace(self::base($id, '2026-10-07T12:00:01Z') + ['order_id' => $orderId, 'account_id' => 'acct_SYNTHETIC',
            'funds_mode' => 'test', 'idempotency_key' => TaxCheckoutSchema::IDEMPOTENCY_PREFIX.$id, 'currency' => 'USD', 'subtotal_minor' => 4999,
            'tax_behavior' => $behavior, 'maximum_rate_bps' => 2500, 'provider_expires_at' => '2026-10-07T13:00:01Z'], $replace);
    }

    private static function binding(int $requestId, array $replace = []): array
    {
        return array_replace(self::base('44444444-4444-4444-8444-444444444444', '2026-10-07T12:00:02Z') + ['request_id' => $requestId,
            'account_id' => 'acct_SYNTHETIC', 'funds_mode' => 'test', 'provider_session_id' => 'cs_test_SYNTHETICTAX'], $replace);
    }

    private static function reviewed(array $ids, array $replace = [], string $behavior = 'exclusive'): array
    {
        return array_replace(self::base('55555555-5555-4555-8555-555555555555', '2026-10-07T12:00:04Z') + ['binding_id' => $ids['binding'],
            'request_id' => $ids['request'], 'order_id' => $ids['order'], 'account_id' => 'acct_SYNTHETIC', 'funds_mode' => 'test',
            'provider_session_id' => 'cs_test_SYNTHETICTAX', 'provider_payment_id' => 'pi_SYNTHETICTAX', 'currency' => 'USD', 'tax_behavior' => $behavior,
            'amount_subtotal_minor' => 4999, 'amount_tax_minor' => 437, 'amount_total_minor' => $behavior === 'exclusive' ? 5436 : 4999,
            'observed_at' => '2026-10-07T12:00:03Z'], $replace);
    }

    private function refuses(string $kind, array $row, string $label): void
    {
        $table = TaxCheckoutSchema::TABLES[$kind];
        $count = DB::table($table)->count();
        try {
            DB::table($table)->insert($row);
            $this->fail($label.' was accepted by the '.$kind.' guard.');
        } catch (QueryException $error) {
            // SQLite reaches every case through the trigger. Under strict MySQL a calendar-invalid STR_TO_DATE can raise
            // its own conversion error first; either way nothing is stored (native specifics: ProductionTaxCheckoutNativeSchemaTest).
            if (DB::getDriverName() === 'sqlite') {
                $this->assertStringContainsString(self::MESSAGE, $error->getMessage(), $label);
            }
        }
        $this->assertSame($count, DB::table($table)->count(), $label);
    }

    private function accepts(string $kind, array $row): int
    {
        return (int) DB::table(TaxCheckoutSchema::TABLES[$kind])->insertGetId($row);
    }

    private static function common(): array
    {
        return [
            'uppercase uuid' => ['public_id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'],
            'version-1 uuid' => ['public_id' => '11111111-1111-1111-8111-111111111111'],
            'non-hex uuid' => ['public_id' => 'zzzzzzzz-zzzz-4zzz-8zzz-zzzzzzzzzzzz'],
            'short uuid' => ['public_id' => '11111111-1111-4111-8111-11111111111'],
            'space timestamp' => ['created_at' => '2026-10-07 12:00:00'],
            'impossible day' => ['created_at' => '2026-02-30T12:00:00Z'],
            'hour 24' => ['created_at' => '2026-10-07T24:00:00Z'],
            'unparsable timestamp' => ['created_at' => 'zzzz-zz-zzTzz:zz:zzZ'],
            'trailing newline timestamp' => ['created_at' => "2026-10-07T12:00:0\n"],
            'empty ciphertext' => ['payload_ciphertext' => ''],
            'uppercase hash' => ['payload_hash' => str_repeat('A', 64)],
            'short hash' => ['payload_hash' => str_repeat('a', 63)],
            'foreign canonicalization' => ['canonicalization_version' => 'json-v0'],
        ];
    }

    /**
     * SQLite keeps any non-integer storage class in an INTEGER column (integer-looking text is converted by affinity
     * and is a genuine integer), so only its guard can and must refuse non-integer text, reals and negatives. MySQL's
     * typed unsigned columns convert or reject those before the trigger: not a guard case there.
     */
    private static function affinity(array $cases): array
    {
        return DB::getDriverName() === 'sqlite' ? $cases : [];
    }

    public function test_order_guard_refuses_every_malformed_field_and_accepts_the_valid_row(): void
    {
        $cases = self::common() + [
            'non-uuid buyer origin' => ['buyer_origin_id' => 'not-a-uuid-not-a-uuid-not-a-uuid-123'],
            'zero candidate' => ['candidate_id' => 0],
            'short request key' => ['request_key' => str_repeat('b', 63)],
            'non-hex request hash' => ['request_hash' => str_repeat('g', 64)],
            'foreign currency' => ['currency' => 'EUR'],
            'lowercase currency' => ['currency' => 'usd'],
            'below minimum' => ['subtotal_minor' => 49],
            'above maximum' => ['subtotal_minor' => 100000000],
            'no lines' => ['line_count' => 0],
            'too many lines' => ['line_count' => 11],
        ] + self::affinity([
            'non-integer text candidate' => ['candidate_id' => '1a'],
            'non-integer text money' => ['subtotal_minor' => '4999 USD'],
            'real money' => ['subtotal_minor' => 4999.5],
        ]);
        foreach ($cases as $label => $replace) {
            $this->refuses('order', self::order($replace), $label);
        }
        $this->assertGreaterThan(0, $this->accepts('order', self::order()));
    }

    public function test_request_guard_refuses_malformed_and_out_of_order_rows(): void
    {
        $order = $this->accepts('order', self::order());
        $cases = self::common() + [
            'missing order' => ['order_id' => $order + 99],
            'before its order' => ['created_at' => '2026-10-07T11:59:59Z'],
            'empty account' => ['account_id' => 'acct_'],
            'account punctuation' => ['account_id' => 'acct_bad-id'],
            'not an account' => ['account_id' => 'ACCT_SYNTHETIC'],
            'unknown funds mode' => ['funds_mode' => 'prod'],
            'foreign idempotency key' => ['idempotency_key' => 'va-production-checkout-v1-33333333-3333-4333-8333-333333333333'],
            'idempotency key for another request' => ['idempotency_key' => TaxCheckoutSchema::IDEMPOTENCY_PREFIX.'11111111-1111-4111-8111-111111111111'],
            'foreign currency' => ['currency' => 'EUR'],
            'subtotal differs from order' => ['subtotal_minor' => 4998],
            'unspecified tax behavior' => ['tax_behavior' => 'unspecified'],
            'ceiling above 100 percent' => ['maximum_rate_bps' => 10001],
            'provider expiry not after creation' => ['provider_expires_at' => '2026-10-07T12:00:01Z'],
        ];
        foreach ($cases as $label => $replace) {
            $this->refuses('request', self::request($order, $replace), $label);
        }
        $this->assertGreaterThan(0, $this->accepts('request', self::request($order)));
    }

    public function test_binding_guard_refuses_malformed_and_out_of_order_rows(): void
    {
        $order = $this->accepts('order', self::order());
        $request = $this->accepts('request', self::request($order));
        $cases = self::common() + [
            'missing request' => ['request_id' => $request + 99],
            'before its request' => ['created_at' => '2026-10-07T12:00:00Z'],
            'other account' => ['account_id' => 'acct_OTHER'],
            'other funds mode' => ['funds_mode' => 'live', 'provider_session_id' => 'cs_live_SYNTHETICTAX'],
            'session mode differs from funds mode' => ['provider_session_id' => 'cs_live_SYNTHETICTAX'],
            'empty session id' => ['provider_session_id' => 'cs_test_'],
            'session punctuation' => ['provider_session_id' => 'cs_test_bad-id'],
            'not a checkout session' => ['provider_session_id' => 'pi_SYNTHETICTAX'],
        ];
        foreach ($cases as $label => $replace) {
            $this->refuses('binding', self::binding($request, $replace), $label);
        }
        $this->assertGreaterThan(0, $this->accepts('binding', self::binding($request)));
    }

    public function test_reviewed_guard_refuses_inconsistent_provider_amounts_and_out_of_order_rows(): void
    {
        $ids = ['order' => $this->accepts('order', self::order())];
        $ids['request'] = $this->accepts('request', self::request($ids['order']));
        $ids['binding'] = $this->accepts('binding', self::binding($ids['request']));
        $cases = self::common() + [
            'observed after recording' => ['observed_at' => '2026-10-07T12:00:05Z'],
            'observed before binding' => ['observed_at' => '2026-10-07T12:00:01Z'],
            'impossible observation' => ['observed_at' => '2026-02-30T12:00:03Z'],
            'other session' => ['provider_session_id' => 'cs_test_OTHER'],
            'other account' => ['account_id' => 'acct_OTHER'],
            'other order' => ['order_id' => $ids['order'] + 99],
            'other request' => ['request_id' => $ids['request'] + 99],
            'empty payment id' => ['provider_payment_id' => 'pi_'],
            'not a payment intent' => ['provider_payment_id' => 'ch_SYNTHETIC'],
            'foreign currency' => ['currency' => 'EUR'],
            'subtotal differs from request' => ['amount_subtotal_minor' => 4998, 'amount_total_minor' => 5435],
            'exclusive total not subtotal plus tax' => ['amount_total_minor' => 5435],
            'behavior differs from request' => ['tax_behavior' => 'inclusive', 'amount_total_minor' => 4999],
            'tax above approved ceiling' => ['amount_tax_minor' => 1250, 'amount_total_minor' => 6249],
        ] + self::affinity([
            'negative tax' => ['amount_tax_minor' => -1, 'amount_total_minor' => 4998],
            'non-integer text tax' => ['amount_tax_minor' => '437a'],
        ]);
        foreach ($cases as $label => $replace) {
            $this->refuses('reviewed', self::reviewed($ids, $replace), $label);
        }
        // The ceiling boundary itself is admitted: 1249 * 10000 <= 4999 * 2500.
        $this->assertGreaterThan(0, $this->accepts('reviewed', self::reviewed($ids, ['amount_tax_minor' => 1249, 'amount_total_minor' => 6248])));
        try {
            DB::table(TaxCheckoutSchema::TABLES['reviewed'])->insert(self::reviewed($ids, ['public_id' => '66666666-6666-4666-8666-666666666666']));
            $this->fail('A second reviewed session for one binding was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table(TaxCheckoutSchema::TABLES['reviewed'])->count());
        }
    }

    /**
     * Reviewer finding R-3: a provider identifier with a trailing line terminator must be refused on both drivers. MySQL's
     * ICU `$` matches before a final line feed, so the guard no longer relies on an end anchor. Each attempt is rolled back,
     * so a driver that wrongly admits one case still reports every case instead of stopping at the first stored row.
     */
    public function test_provider_identifiers_with_a_trailing_line_terminator_are_refused(): void
    {
        $ids = ['order' => $this->accepts('order', self::order())];
        $ids['request'] = $this->accepts('request', self::request($ids['order']));
        $ids['binding'] = $this->accepts('binding', self::binding($ids['request']));
        // The first binding exists for the reviewed case; the request and binding cases use parents of their own.
        $second = $this->accepts('order', self::order(['public_id' => '11111111-1111-4111-8111-111111111112', 'request_key' => str_repeat('d', 64)]));
        $third = $this->accepts('order', self::order(['public_id' => '11111111-1111-4111-8111-111111111113', 'request_key' => str_repeat('e', 64)]));
        $request = fn (int $order, string $id, array $replace = []): array => self::request($order, ['public_id' => $id,
            'idempotency_key' => TaxCheckoutSchema::IDEMPOTENCY_PREFIX.$id] + $replace);
        $freshRequest = $this->accepts('request', $request($third, '33333333-3333-4333-8333-333333333334'));
        $attempts = [];
        foreach (["\n" => 'line feed', "\r" => 'carriage return', "\r\n" => 'CR LF'] as $suffix => $name) {
            $attempts['request account_id, '.$name] = ['request', $request($second, '33333333-3333-4333-8333-333333333335', ['account_id' => 'acct_SYNTHETIC'.$suffix])];
            $attempts['binding provider_session_id, '.$name] = ['binding', self::binding($freshRequest, ['public_id' => '44444444-4444-4444-8444-444444444445',
                'provider_session_id' => 'cs_test_SYNTHETICTAX'.$suffix])];
            $attempts['reviewed provider_payment_id, '.$name] = ['reviewed', self::reviewed($ids, ['provider_payment_id' => 'pi_SYNTHETICTAX'.$suffix])];
        }
        $admitted = [];
        foreach ($attempts as $label => [$kind, $row]) {
            DB::beginTransaction();
            try {
                DB::table(TaxCheckoutSchema::TABLES[$kind])->insert($row);
                $admitted[] = $label;
            } catch (QueryException) {
                // Refused by the guard (or, on MySQL, by a typed-column error); either way nothing may be stored.
            } finally {
                DB::rollBack();
            }
        }
        $this->assertSame([], $admitted, 'These provider identifiers with a trailing line terminator were stored.');
        $this->assertSame(0, DB::table(TaxCheckoutSchema::TABLES['reviewed'])->count());
        // The same rows without the terminator are admitted, so the refusals above are caused by the terminator alone.
        $this->assertGreaterThan(0, $this->accepts('request', $request($second, '33333333-3333-4333-8333-333333333335')));
        $this->assertGreaterThan(0, $this->accepts('binding', self::binding($freshRequest, ['public_id' => '44444444-4444-4444-8444-444444444445',
            'provider_session_id' => 'cs_test_SYNTHETICSECOND'])));
        $this->assertGreaterThan(0, $this->accepts('reviewed', self::reviewed($ids)));
    }

    public function test_inclusive_reviewed_rows_keep_tax_inside_the_unchanged_total(): void
    {
        $ids = ['order' => $this->accepts('order', self::order())];
        $ids['request'] = $this->accepts('request', self::request($ids['order'], [], 'inclusive'));
        $ids['binding'] = $this->accepts('binding', self::binding($ids['request']));
        foreach ([
            'inclusive total grows by tax' => ['amount_total_minor' => 5436],
            'inclusive tax exceeds total' => ['amount_tax_minor' => 5000, 'amount_total_minor' => 4999],
            // Net 4999 - 1000 = 3999; 1000 * 10000 > 3999 * 2500.
            'inclusive tax above ceiling on the net' => ['amount_tax_minor' => 1000],
        ] as $label => $replace) {
            $this->refuses('reviewed', self::reviewed($ids, $replace, 'inclusive'), $label);
        }
        $this->assertGreaterThan(0, $this->accepts('reviewed', self::reviewed($ids, ['amount_tax_minor' => 999], 'inclusive')));
    }

    public function test_every_table_refuses_update_and_delete(): void
    {
        $ids = ['order' => $this->accepts('order', self::order())];
        $ids['request'] = $this->accepts('request', self::request($ids['order']));
        $ids['binding'] = $this->accepts('binding', self::binding($ids['request']));
        $this->accepts('reviewed', self::reviewed($ids));
        foreach (TaxCheckoutSchema::TABLES as $kind => $table) {
            $before = DB::table($table)->get()->toJson();
            foreach (['update' => fn () => DB::table($table)->update(['payload_hash' => str_repeat('f', 64)]), 'delete' => fn () => DB::table($table)->delete()] as $operation => $mutation) {
                try {
                    $mutation();
                    $this->fail($kind.' accepted '.$operation.'.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString(self::MESSAGE, $error->getMessage());
                }
            }
            $this->assertSame($before, DB::table($table)->get()->toJson());
        }
    }
}
