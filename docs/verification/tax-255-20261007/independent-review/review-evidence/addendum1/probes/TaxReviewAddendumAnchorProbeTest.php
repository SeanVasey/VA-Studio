<?php

namespace Tests\ReviewProbes;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Independent reviewer probe, addendum 1 (judgement call 3). Not part of the suite. Evaluates every generated
 * shape fragment (`NEW.v`) of the running driver against a valid value and line-terminator variants, through a
 * derived table aliased NEW, so the trigger's exact expression text is what runs. No schema is created.
 */
final class TaxReviewAddendumAnchorProbeTest extends TestCase
{
    private static function fragment(string $method, string $driver, array $extra = []): string
    {
        $m = new ReflectionMethod(TaxCheckoutSchema::class, $method);

        return $m->invoke(null, $driver, 'v', ...$extra);
    }

    private static function admits(string $expression, string $value): int
    {
        $driver = DB::getDriverName();
        $v = $driver === 'mysql' ? 'CONVERT(? USING ascii) COLLATE ascii_bin' : '?';

        return (int) DB::selectOne('SELECT COALESCE(('.$expression.'), 0) AS ok FROM (SELECT '.$v." AS v, 'test' AS funds_mode) AS NEW", [$value])->ok;
    }

    public function test_a1_shape_fragments_refuse_line_terminators_on_this_driver(): void
    {
        $driver = DB::getDriverName();
        $cases = [
            'uuidV4' => [self::fragment('uuidV4', $driver), '11111111-1111-4111-8111-111111111111'],
            'uuidAny' => [self::fragment('uuidAny', $driver), '22222222-2222-4222-8222-222222222222'],
            'hex' => [self::fragment('hex', $driver), str_repeat('a', 64)],
            'timestamp' => [self::fragment('timestamp', $driver), '2026-10-07T12:00:00Z'],
            'providerId acct_' => [self::fragment('providerId', $driver, ['acct_', 64]), 'acct_SYNTHETIC'],
            'providerId pi_' => [self::fragment('providerId', $driver, ['pi_', 120]), 'pi_SYNTHETICTAX'],
            'sessionId' => [self::fragment('sessionId', $driver), 'cs_test_SYNTHETICTAX'],
        ];
        if ($driver === 'mysql') {
            // Control: the pre-fix text at 9ec94d8c.
            $cases['OLD providerId acct_ (9ec94d8c)'] = ["NEW.v REGEXP '^acct_[A-Za-z0-9]{1,64}\$'", 'acct_SYNTHETIC'];
        }
        $out = [];
        foreach ($cases as $name => [$expression, $valid]) {
            $variants = ['valid' => $valid, '+LF' => $valid."\n", '+CR' => $valid."\r", '+CRLF' => $valid."\r\n",
                'last->LF' => substr($valid, 0, -1)."\n", 'drop1+LF' => substr($valid, 1)."\n", 'inner LF' => substr($valid, 0, 8)."\n".substr($valid, 9)];
            foreach ($variants as $label => $value) {
                $out[$name][$label] = self::admits($expression, $value);
            }
        }
        fwrite(STDERR, PHP_EOL.'DRIVER '.$driver.' '.json_encode($out).PHP_EOL);
        foreach ($out as $name => $results) {
            $expected = ['valid' => 1, '+LF' => 0, '+CR' => 0, '+CRLF' => 0, 'last->LF' => 0, 'drop1+LF' => 0, 'inner LF' => 0];
            if (str_starts_with($name, 'OLD')) {
                $this->assertSame(1, $results['+LF'], 'control: the old anchored text admitted a trailing LF');

                continue;
            }
            $this->assertSame($expected, $results, $name);
        }
    }
}
