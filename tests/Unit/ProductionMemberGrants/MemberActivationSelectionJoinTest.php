<?php

namespace Tests\Unit\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberActivationHistory;
use App\Domain\Grants\Member\MemberActivationReadiness;
use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberLicenseAuthorizationRecords;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Domain\Memberships\Production\MembershipLedgerAudit;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;
use Tests\Unit\MembershipConsumeLedgerAuditTest;

/** Real pure record builders and complete synthetic ledger; no database or issuance authority. */
class MemberActivationSelectionJoinTest extends TestCase
{
    use MembershipPolicyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policySetup();
        CarbonImmutable::setTestNow('2026-10-13 00:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('independentlyChangedSelectionFields')]
    public function test_complete_rebuilt_effect_graph_must_match_the_unchanged_reservation_selection(string $field): void
    {
        // Reuse the released genuine complete-ledger fixture without altering its source.
        $f = (new ReflectionMethod(MembershipConsumeLedgerAuditTest::class, 'fixture'))->invoke(
            new MembershipConsumeLedgerAuditTest('test_complete_graph_consumes_exact_reserved_credit_and_retains_the_original_award_projection'));
        $this->assertSame(1, $this->audit($f)['balances']['consumed']);
        $unchangedReservation = CanonicalJson::encode([$f['period'], $f['redemption'], $f['reserve']]);
        $selected = MembershipReservationRecords::verify($f['redemption'], $f['period'], $f['invoice'], $f['policy'])['intent']['selection'];
        $s = $f['source']->document();
        $s['selection'][$field] = $field === 'selection_hash'
            ? hash('sha256', 'NONBINDING different synthetic selection') : $s['selection'][$field] + 1;
        $source = MemberOriginalSourceValues::from($s);
        $this->assertNotSame(CanonicalJson::encode($selected), CanonicalJson::encode($source->document()['selection']));
        $authorization = MemberLicenseAuthorizationRecords::create($source, $f['policy'], $f['actor'], $f['authorization_id'], $f['confirmed_at']);
        $readiness = MemberActivationReadiness::forSource($source, $authorization, $f['policy'], $f['manifest']);
        $origin = MemberActivationRecords::origin($source, $authorization, $f['policy'], $f['manifest'], 17, $f['actor'], $f['effect_at']);
        $artifact = MemberActivationRecords::artifact($origin, $f['policy'], $f['artifact']['id'], $f['effect_at']);
        $consume = MembershipReservationRecords::consumeEvent($f['reserved'], $f['redemption']['id'], $f['policy'],
            $readiness->value(), $f['actor'], $f['effect_at']);
        $activation = MemberActivationRecords::activation($origin, $artifact, $consume, $f['policy'],
            $f['activation']['id'], $f['actor'], $f['effect_at']);
        $f['history'] = MemberActivationHistory::fromRows([$origin], [$artifact], [$activation],
            [$f['profile']], [$f['definition']], [$f['plan']], 1);
        $f['events'] = [$f['award'], $f['reserve'], $consume];
        $this->assertSame($unchangedReservation, CanonicalJson::encode([$f['period'], $f['redemption'], $f['reserve']]));
        $this->assertSame($source->hash(), $f['history']->original($f['redemption']['id'])->source()->hash());

        $this->expectException(MemberGrantException::class);
        try {
            $this->audit($f);
        } catch (MemberGrantException $error) {
            $this->assertSame('mislinked_history', $error->reason);
            throw $error;
        }
    }

    public static function independentlyChangedSelectionFields(): array
    {
        return array_combine($fields = ['selection_hash', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id'],
            array_map(static fn (string $field): array => [$field], $fields));
    }

    private function audit(array $f): array
    {
        return MembershipLedgerAudit::verify($f['period'], $f['events'], [$f['redemption']],
            $f['invoice'], $f['policy'], 17, $f['history']);
    }
}
