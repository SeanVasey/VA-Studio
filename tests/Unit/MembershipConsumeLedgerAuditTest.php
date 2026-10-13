<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberActivationHistory;
use App\Domain\Grants\Member\MemberActivationReadiness;
use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Domain\Grants\Member\MemberLicenseAuthorizationRecords;
use App\Domain\Grants\Member\MemberOriginalArtifactManifest;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipLedgerAudit as Audit;
use App\Domain\Memberships\Production\MembershipLicenseBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords as Period;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords as Reservation;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberActivationRecordValues;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

/** Real pure codecs and complete synthetic sealed ledgers; no DB producer or confirmation authority. */
class MembershipConsumeLedgerAuditTest extends TestCase
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

    public function test_complete_graph_consumes_exact_reserved_credit_and_retains_the_original_award_projection(): void
    {
        $f = $this->fixture();
        $audit = $this->audit($f);
        $this->assertSame(['available' => 2, 'reserved' => 0, 'consumed' => 1, 'expired' => 0], $audit['balances']);
        $this->assertSame($f['consume'], $audit['reservations'][$f['redemption']['id']]['terminal']);
        $this->assertSame($f['reserve']['id'], $f['consume']['reservation_event_id']);
        $this->assertSame($f['reserve']['seal'], $f['consume']['prior_seal']);
        $this->assertSame($f['readiness']->hash(), $f['consume']['grant_receipt_hash']);
        $this->assertSame(3, $f['consume']['sequence']);
        $this->assertSame($f['reserved']['award'], Period::verify($f['period'], $f['events'], $f['invoice'], $f['policy'],
            17, [$f['redemption']], $f['history']));
        $this->assertSame($audit, $this->audit($f));
        $f['history']->assertComplete([$f['consume']['id']]);
        $f['history']->assertComplete([$f['consume']['id']]);
    }

    public function test_keys_bind_the_retained_authorization_specific_readiness_and_stable_redemption(): void
    {
        $f = $this->fixture();
        $r = $f['readiness']->value();
        $this->assertSame(CanonicalJson::hash(['purpose' => 'membership-credit-consume-event-v1',
            'redemption_id' => $f['redemption']['id']]), $f['consume']['key_hash']);
        $expected = ['purpose' => 'membership-credit-consume-v1', 'period_id' => $f['period']['id'],
            'period_seal' => $f['period']['seal'], 'redemption_id' => $f['redemption']['id'],
            'redemption_seal' => $f['redemption']['seal'], 'intent_hash' => $f['redemption']['intent_hash'],
            'reservation_event_id' => $f['reserve']['id'], 'reservation_event_seal' => $f['reserve']['seal'],
            'grant_origin_id' => $r['origin_id'], 'readiness_receipt_hash' => CanonicalJson::hash($r),
            'authorization_intent_hash' => $r['authorization_intent_hash'], 'honor_deadline' => $f['redemption']['honor_deadline']];
        $this->assertSame(CanonicalJson::hash($expected), $f['consume']['request_hash']);
        $r['authorization_document_hash'] = hash('sha256', 'different synthetic confirmation');
        $this->assertNotSame($f['consume']['request_hash'], Reservation::consumeRequest($f['period'], $f['redemption'], $f['reserve'], $r));
        $this->assertSame($f['consume']['key_hash'], Reservation::consumeKey($f['redemption']['id']));
    }

    public function test_consumed_history_remains_verifiable_after_honor_and_period_expiry_without_current_producers(): void
    {
        $f = $this->fixture();
        $rows = CanonicalJson::encode([$f['period'], $f['events'], $f['redemption'], $f['history']->rows()]);
        CarbonImmutable::setTestNow('2036-10-13 00:00:00 UTC');
        config(['production-memberships.enabled' => false, 'production-member-grants.enabled' => false]);
        $f['invoice'] = Period::historicalInvoice($f['period'], $f['award'], $f['policy'], 17);
        $this->assertSame(1, $this->audit($f)['balances']['consumed']);
        $this->assertNull($f['invoice']->observationId);
        $this->assertNull($f['invoice']->evidenceDeadline);
        $this->assertSame($rows, CanonicalJson::encode([$f['period'], $f['events'], $f['redemption'], $f['history']->rows()]));
    }

    public function test_honor_equality_refuses_but_authored_beyond_credit_expiry_can_consume_before_honor(): void
    {
        $f = $this->fixture(beyond: true, effectAt: '2026-10-13 00:15:00');
        $this->assertSame('2026-10-13 00:10:00', $f['period']['credit_expires_at']);
        $this->assertSame('2026-10-13 00:30:00', $f['redemption']['honor_deadline']);
        $this->assertSame(1, $this->audit($f)['balances']['consumed']);
        $this->refuses(fn () => Reservation::consumeEvent($f['reserved'], $f['redemption']['id'], $f['policy'],
            $f['readiness']->value(), $f['actor'], $f['redemption']['honor_deadline']), 'honor_expired');
        $this->refuses(fn () => Reservation::consumeEvent($f['reserved'], $f['redemption']['id'], $f['policy'],
            $f['readiness']->value(), $f['actor'], '2026-10-12 23:59:59'), 'invalid_record_time');
    }

    public function test_consume_spends_the_reserved_terminal_slot_without_stealing_the_final_available_expiry_slot(): void
    {
        $f = $this->fixture();
        $audit = $f['reserved'];
        // Prospective capacity only; this does not claim an authenticated 9,998-event history.
        $audit['tail']['sequence'] = 9998;
        $consume = Reservation::consumeEvent($audit, $f['redemption']['id'], $f['policy'], $f['readiness']->value(), $f['actor'], $f['effect_at']);
        $this->assertSame(9999, $consume['sequence']);
        $audit['tail'] = $consume;
        $audit['balances'] = ['available' => 2, 'reserved' => 0, 'consumed' => 1, 'expired' => 0];
        $expire = Reservation::expireEvent($audit, $f['policy'], $f['actor'], $f['period']['credit_expires_at']);
        $this->assertSame(10000, $expire['sequence']);
        $this->assertSame(1, $expire['after_consumed']);
        $this->assertSame(2, $expire['after_expired']);
        $audit = $f['reserved'];
        $audit['tail']['sequence'] = 10000;
        $this->refuses(fn () => Reservation::consumeEvent($audit, $f['redemption']['id'], $f['policy'],
            $f['readiness']->value(), $f['actor'], $f['effect_at']), 'technical_bound');
    }

    #[DataProvider('readinessJoins')]
    public function test_validly_shaped_readiness_cannot_retarget_retained_source_joins(string $field): void
    {
        $f = $this->fixture();
        $r = $f['readiness']->value();
        $r[$field] = str_ends_with($field, '_id') ? (string) Str::uuid() : hash('sha256', 'changed synthetic commitment');
        $this->refuses(fn () => Reservation::consumeEvent($f['reserved'], $f['redemption']['id'], $f['policy'],
            $r, $f['actor'], $f['effect_at']));
    }

    public static function readinessJoins(): array
    {
        return array_map(fn (string $key): array => [$key], ['period_id', 'period_seal', 'redemption_seal',
            'reservation_event_id', 'reservation_event_seal', 'source_invoice_hash', 'policy_hash',
            'consumption_terms_hash', 'retention_policy_hash']);
    }

    public function test_insufficient_reserved_credit_and_changed_actor_cannot_build_consume(): void
    {
        $f = $this->fixture();
        $audit = $f['reserved'];
        $audit['balances']['reserved'] = 0;
        $this->refuses(fn () => Reservation::consumeEvent($audit, $f['redemption']['id'], $f['policy'],
            $f['readiness']->value(), $f['actor'], $f['effect_at']), 'invalid_credit_balance');
        $actor = [...$f['actor'], 'user_id' => 99];
        $this->refuses(fn () => Reservation::consumeEvent($f['reserved'], $f['redemption']['id'], $f['policy'],
            $f['readiness']->value(), $actor, $f['effect_at']), 'owner_changed');
    }

    public function test_closed_reservation_cannot_consume_or_release_again_and_expiry_preserves_consumed_credit(): void
    {
        $f = $this->fixture();
        $audit = $this->audit($f);
        $this->refuses(fn () => Reservation::consumeEvent($audit, $f['redemption']['id'], $f['policy'],
            $f['readiness']->value(), $f['actor'], $f['effect_at']), 'reservation_closed');
        $this->refuses(fn () => Reservation::releaseEvent($audit, $f['redemption']['id'], $f['policy'],
            $f['actor'], $f['redemption']['honor_deadline']), 'reservation_closed');
        $expire = Reservation::expireEvent($audit, $f['policy'], $f['actor'], $f['period']['credit_expires_at']);
        $f['events'][] = $expire;
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 1, 'expired' => 2], $this->audit($f)['balances']);
    }

    public function test_consume_requires_complete_history_and_history_requires_its_exact_consume_even_on_empty_path(): void
    {
        $f = $this->fixture();
        $this->refuses(fn () => $this->audit($f, MemberActivationHistory::empty()));
        $before = $f;
        $before['events'] = [$f['award'], $f['reserve']];
        $this->refuses(fn () => $this->audit($before));
        $rows = $f['history']->rows();
        $this->refuses(fn () => MemberActivationHistory::fromRows($rows['origins'], [], $rows['activations'],
            $rows['profiles'], $rows['definitions'], $rows['plans'], 1));
        $this->assertSame(1, $this->audit($f)['balances']['consumed']);
    }

    #[DataProvider('consumeMutations')]
    public function test_resealed_consume_changes_cannot_escape_complete_history_and_conservation(string $field): void
    {
        $f = $this->fixture();
        $event = $f['consume'];
        $event[$field] = match ($field) {
            'amount', 'after_consumed', 'after_available', 'before_reserved' => $event[$field] + 1,
            'sequence' => 4,
            'created_at' => $f['redemption']['honor_deadline'],
            'grant_purpose' => 'different-purpose',
            'reservation_event_id', 'grant_origin_id' => (string) Str::uuid(),
            default => hash('sha256', 'changed retained consume'),
        };
        $event['seal'] = Period::seal(Period::EVENTS, $event);
        $f['events'][2] = $event;
        $this->refuses(fn () => $this->audit($f));
    }

    public static function consumeMutations(): array
    {
        return array_map(fn (string $key): array => [$key], ['amount', 'after_consumed', 'after_available', 'before_reserved',
            'sequence', 'created_at', 'grant_purpose', 'reservation_event_id', 'grant_origin_id', 'grant_receipt_hash', 'request_hash', 'prior_seal']);
    }

    public function test_legacy_policy_never_gains_consume_from_shaped_or_genuine_foreign_history(): void
    {
        $f = $this->fixture();
        foreach ([1, 2] as $version) {
            $input = $f['policy_document']->facts;
            unset($input['consumption_terms']);
            $input['schema_version'] = MembershipPolicyDocument::CREDIT_VERSION;
            $input['scope'] = 'paid_period_credit_lifecycle';
            if ($version === 1) {
                unset($input['redemption_terms']);
                $input['schema_version'] = MembershipPolicyDocument::VERSION;
                $input['scope'] = 'paid_period_award';
            }
            $legacy = MembershipPolicyDocument::from($input)->binding($f['policy']->planVersionId);
            $this->refuses(fn () => Reservation::consumeEvent($f['reserved'], $f['redemption']['id'], $legacy,
                $f['readiness']->value(), $f['actor'], $f['effect_at']), 'unsupported_policy');
            $this->refuses(fn () => $f['history']->verifyConsume($f['period'], $f['redemption'], $f['reserve'],
                $f['consume'], $f['invoice'], $legacy, 17), 'unsupported_credit_history');
        }
    }

    private function fixture(bool $beyond = false, string $effectAt = '2026-10-13 00:02:00'): array
    {
        $f = MemberActivationRecordValues::fixture();
        $s = $f['source']->document();
        if ($beyond) {
            $input = $f['policy_document']->facts;
            $input['redemption_terms']['honor']['expiry_mode'] = 'allow_beyond_credit_expiry';
            $f['policy_document'] = MembershipPolicyDocument::from($input);
            $f['policy'] = $f['policy_document']->binding($f['policy']->planVersionId);
            $s['invoice']['period_end'] = '2026-10-13 00:10:00';
            $s['invoice']['policy_hash'] = $f['policy']->policyHash;
            $s['invoice']['original_terms_hash'] = $f['policy']->originalTermsHash;
            $s['definition']['document']['policy_hash'] = $f['policy']->policyHash;
            $f['definition'] = MemberGrantRecords::definition(MemberGrantDefinitionDocument::from($s['definition']['document']),
                $s['definition']['id'], $s['profile']['id'], 1, hash('sha256', 'NONBINDING author'), '2026-10-12 00:00:00');
            $s['definition']['seal'] = $f['definition']['seal'];
            $f['plan']['policy_hash'] = $f['policy']->policyHash;
            $f['plan']['original_terms_hash'] = $f['policy']->originalTermsHash;
            $f['plan']['payload_ciphertext'] = MembershipRecords::encrypt(['schema_version' => 'membership-policy-record-v1',
                'event' => 'policy_version_authored', 'plan_version_id' => $f['plan']['id'], 'document' => $f['policy_document']->facts,
                'author_user_id' => 1, 'author_binding_hash' => hash('sha256', 'NONBINDING author'), 'created_at' => $f['plan']['created_at']]);
            $f['plan']['seal'] = MembershipRecords::seal($f['plan']);
        }
        $i = $s['invoice'];
        $invoice = new MembershipInvoiceBinding($i['source_invoice_hash'], $i['invoice_graph_hash'], $i['provider_scope_hash'],
            $i['amount_minor'], $i['currency'], $i['period_start'], $i['period_end'], $i['policy_hash'], $i['original_terms_hash'],
            $i['buyer_binding'], $i['provenance'], $i['paid_at'], $i['plan_version_id']);
        [$period, $award] = Period::create($invoice, $f['policy'], 17, $f['actor']);
        $selected = $s['selection'];
        $license = new MembershipLicenseBinding($selected['selection_hash'], $selected['license_manifest_hash'], $selected['asset_manifest_hash'],
            $selected['original_terms_hash'], 1, $selected['track_id'], $selected['offer_id'], $selected['offer_revision_id'], $selected['license_version_id']);
        $redemption = Reservation::create($period, $invoice, $f['policy'], $license, 'NONBINDING pure ledger request', '2026-10-13 00:00:00');
        $initial = Audit::verify($period, [$award], [], $invoice, $f['policy'], 17, MemberActivationHistory::empty());
        $reserve = Reservation::reserveEvent($initial, $redemption, $f['actor'], $redemption['created_at']);
        $reserved = Audit::verify($period, [$award, $reserve], [$redemption], $invoice, $f['policy'], 17, MemberActivationHistory::empty());
        $s['origin_id'] = $redemption['id'];
        $s['period'] = ['id' => $period['id'], 'seal' => $period['seal']];
        $s['redemption'] = array_intersect_key($redemption, array_flip(['id', 'seal', 'intent_hash', 'credit_amount', 'created_at', 'honor_deadline']));
        $s['reserve_event'] = ['id' => $reserve['id'], 'seal' => $reserve['seal']];
        $source = MemberOriginalSourceValues::from($s);
        $a = MemberLicenseAuthorizationRecords::create($source, $f['policy'], $f['actor'], $f['authorization_id'], $f['confirmed_at']);
        $m = ['schema_version' => 'member-original-artifact-manifest-v1', 'family' => $s['family'], 'purpose' => $s['purpose'],
            'origin_id' => $s['origin_id'], 'originals' => $f['manifest']->originals];
        $manifest = new MemberOriginalArtifactManifest($s['origin_id'], CanonicalJson::hash($m), $m['originals']);
        $readiness = MemberActivationReadiness::forSource($source, $a, $f['policy'], $manifest);
        $origin = MemberActivationRecords::origin($source, $a, $f['policy'], $manifest, 17, $f['actor'], $effectAt);
        $artifact = MemberActivationRecords::artifact($origin, $f['policy'], $f['artifact']['id'], $effectAt);
        $consume = Reservation::consumeEvent($reserved, $redemption['id'], $f['policy'], $readiness->value(), $f['actor'], $effectAt);
        $activation = MemberActivationRecords::activation($origin, $artifact, $consume, $f['policy'], $f['activation']['id'], $f['actor'], $effectAt);
        $history = MemberActivationHistory::fromRows([$origin], [$artifact], [$activation], [$f['profile']], [$f['definition']], [$f['plan']], 1);

        return [...$f, ...compact('invoice', 'period', 'award', 'redemption', 'reserve', 'reserved', 'source', 'manifest',
            'readiness', 'origin', 'artifact', 'consume', 'activation', 'history'), 'authorization' => $a,
            'events' => [$award, $reserve, $consume], 'effect_at' => $effectAt];
    }

    private function audit(array $f, ?MemberActivationHistory $history = null): array
    {
        return Audit::verify($f['period'], $f['events'], [$f['redemption']], $f['invoice'], $f['policy'], 17, $history ?? $f['history']);
    }

    private function refuses(callable $call, ?string $reason = null): void
    {
        try {
            $call();
            $this->fail('Unauthenticated, inconsistent or unsupported consumption must refuse.');
        } catch (MembershipException|MemberGrantException $error) {
            $reason === null ? $this->assertNotSame('', $error->reason) : $this->assertSame($reason, $error->reason);
        }
    }
}
