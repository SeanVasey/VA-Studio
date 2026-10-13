<?php

namespace Tests\Unit;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords as Period;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

/** Pure sealed synthetic records. No current provider, account, catalog or DB-absence proof. */
class MembershipHistoricalInvoiceTest extends TestCase
{
    use MembershipPolicyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policySetup();
        CarbonImmutable::setTestNow('2026-10-13 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('versions')]
    public function test_retained_semantics_preserve_original_t23_values_without_live_paid_evidence(int $version): void
    {
        $f = $this->fixture($version);
        $before = CanonicalJson::encode([$f['period'], $f['award']]);
        // Historical decoding does not consult today's time, commercial approval or provider status.
        CarbonImmutable::setTestNow('2036-10-13 12:00:00 UTC');
        $historical = Period::historicalInvoice($f['period'], $f['award'], $f['policy'], 7);
        $this->assertSame(CanonicalJson::encode(Period::semantic($f['invoice'])), CanonicalJson::encode(Period::semantic($historical)));
        $this->assertSame(CanonicalJson::encode($f['invoice']->originalBuyerBinding), CanonicalJson::encode($historical->originalBuyerBinding));
        $this->assertNotNull($f['invoice']->invoiceId);
        $this->assertNull($historical->invoiceId);
        $this->assertNull($historical->observationId);
        $this->assertNull($historical->observationSeal);
        $this->assertNull($historical->evidenceDeadline);
        $this->assertSame($before, CanonicalJson::encode([$f['period'], $f['award']]));
        $this->assertSame(Period::verifyFirstAward($f['period'], $f['award'], $f['invoice'], $f['policy'], 7),
            Period::verifyFirstAward($f['period'], $f['award'], $historical, $f['policy'], 7));
    }

    public static function versions(): array
    {
        return [[1], [2], [3]];
    }

    #[DataProvider('physicalMutations')]
    public function test_changed_physical_records_never_decode(string $field): void
    {
        $f = $this->fixture(3);
        if ($field === 'extra_column') {
            $f['period']['unrecognized'] = 'extra';
            $f['period']['seal'] = Period::seal(Period::PERIODS, $f['period']);
        } elseif ($field === 'award_seal') {
            $f['award']['seal'] = str_repeat('a', 64);
        } elseif ($field === 'allowance') {
            $f['period']['allowance']++;
        } else {
            $f['period']['payload_ciphertext'] = 'invalid synthetic envelope';
            $f['period']['seal'] = Period::seal(Period::PERIODS, $f['period']);
        }
        $this->refuses(fn () => Period::historicalInvoice($f['period'], $f['award'], $f['policy'], 7));
    }

    public static function physicalMutations(): array
    {
        return array_map(fn (string $key): array => [$key], ['extra_column', 'award_seal', 'allowance', 'ciphertext']);
    }

    #[DataProvider('semanticMutations')]
    public function test_resealed_but_inconsistent_semantic_facts_and_first_award_joins_refuse(string $field): void
    {
        $f = $this->fixture(3);
        $payload = json_decode(Crypt::decryptString($f['period']['payload_ciphertext']), true, 24, JSON_THROW_ON_ERROR);
        if ($field === 'extra_payload') {
            $payload['extra'] = true;
        } elseif ($field === 'extra_invoice') {
            $payload['invoice']['observation_id'] = (string) Str::uuid();
        } elseif ($field === 'missing_paid_at') {
            unset($payload['invoice']['paid_at']);
        } elseif ($field === 'numeric_amount_string') {
            $payload['invoice']['amount_minor'] = '1700';
        } elseif ($field === 'price') {
            $payload['invoice']['amount_minor'] = 1800;
        } elseif ($field === 'policy') {
            $payload['invoice']['policy_hash'] = str_repeat('e', 64);
        } elseif ($field === 'terms') {
            $payload['invoice']['original_terms_hash'] = str_repeat('e', 64);
        } elseif ($field === 'plan') {
            $payload['invoice']['plan_version_id'] = (string) Str::uuid();
        } elseif ($field === 'verification') {
            $payload['invoice']['buyer_binding']['verification_observation_id'] = (string) Str::uuid();
        } else {
            $f['award']['period_id'] = (string) Str::uuid();
        }
        $f['period']['payload_ciphertext'] = Crypt::encryptString(CanonicalJson::encode($payload));
        $f['period']['seal'] = Period::seal(Period::PERIODS, $f['period']);
        // Rebind the award's semantic digest: a valid seal alone cannot bypass the policy/owner joins.
        $f['award']['request_hash'] = CanonicalJson::hash($payload['invoice']);
        $f['award']['seal'] = Period::seal(Period::EVENTS, $f['award']);
        $this->refuses(fn () => Period::historicalInvoice($f['period'], $f['award'], $f['policy'], 7));
    }

    public static function semanticMutations(): array
    {
        return array_map(fn (string $key): array => [$key], ['extra_payload', 'extra_invoice', 'missing_paid_at',
            'numeric_amount_string', 'price', 'policy', 'terms', 'plan', 'verification', 'award_period']);
    }

    public function test_wrong_origin_and_different_retained_policy_refuse_without_rewriting_history(): void
    {
        $f = $this->fixture(3);
        $this->refuses(fn () => Period::historicalInvoice($f['period'], $f['award'], $f['policy'], 8));
        $other = MembershipPolicyDocument::from($this->consumptionPolicyInput(['credit_allowance' => 4]))->binding($f['policy']->planVersionId);
        $this->refuses(fn () => Period::historicalInvoice($f['period'], $f['award'], $other, 7));
    }

    public function test_original_record_seal_key_rotation_remains_supported(): void
    {
        $f = $this->fixture(3);
        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('N', 32)), 'app.previous_keys' => [$old]]);
        $this->assertSame($f['invoice']->sourceInvoiceHash, Period::historicalInvoice($f['period'], $f['award'], $f['policy'], 7)->sourceInvoiceHash);
        config(['app.previous_keys' => []]);
        $this->refuses(fn () => Period::historicalInvoice($f['period'], $f['award'], $f['policy'], 7));
    }

    private function fixture(int $version): array
    {
        $input = $this->consumptionPolicyInput();
        if ($version < 3) {
            unset($input['consumption_terms']);
            $input['schema_version'] = MembershipPolicyDocument::CREDIT_VERSION;
            $input['scope'] = 'paid_period_credit_lifecycle';
        }
        if ($version === 1) {
            unset($input['redemption_terms']);
            $input['schema_version'] = MembershipPolicyDocument::VERSION;
            $input['scope'] = 'paid_period_award';
        }
        $policy = MembershipPolicyDocument::from($input)->binding((string) Str::uuid());
        $buyer = ['schema_version' => 1, 'account_id' => 1, 'user_id' => 2, 'origin_id' => (string) Str::uuid(),
            'account_public_id' => (string) Str::uuid(), 'verification_observation_id' => (string) Str::uuid(),
            'verification_observation_hash' => hash('sha256', 'synthetic original verification'),
            'identity_policy_hash' => hash('sha256', 'synthetic original identity'),
            'identity_policy_version' => IdentityPolicy::VERSION, 'provenance' => IdentityPolicy::REHEARSAL];
        $invoice = new MembershipInvoiceBinding(hash('sha256', 'synthetic invoice'), hash('sha256', 'synthetic invoice graph'),
            hash('sha256', 'synthetic provider scope'), 1700, 'USD', '2026-10-13 12:00:00', '2026-10-13 13:00:00',
            $policy->policyHash, $policy->originalTermsHash, $buyer, IdentityPolicy::REHEARSAL, '2026-10-13 12:00:00', $policy->planVersionId,
            (string) Str::uuid(), '2026-10-13 12:10:00', (string) Str::uuid(), hash('sha256', 'synthetic live observation'));
        [$period, $award] = Period::create($invoice, $policy, 7, $buyer);

        return compact('policy', 'invoice', 'period', 'award');
    }

    private function refuses(callable $call): void
    {
        try {
            $call();
            $this->fail('Changed or inconsistent retained records must refuse.');
        } catch (MembershipException $error) {
            $this->assertNotSame('', $error->reason);
        }
    }
}
