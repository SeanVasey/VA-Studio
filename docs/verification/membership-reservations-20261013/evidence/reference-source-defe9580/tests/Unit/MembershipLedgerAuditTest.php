<?php

namespace Tests\Unit;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipLedgerAudit as Audit;
use App\Domain\Memberships\Production\MembershipLicenseBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords as Period;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords as Reservation;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

/** Pure authenticated record fixtures. No rows here claim current invoice/catalog or writer authority. */
class MembershipLedgerAuditTest extends TestCase
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

    public function test_v1_award_projection_and_original_seal_purpose_stay_unchanged(): void
    {
        $f = $this->fixture(v2: false);
        $audit = $this->audit($f);
        $this->assertSame(['period_id' => $f['period']['id'], 'award_event_id' => $f['award']['id'], 'allowance' => 3,
            'period_start' => '2026-10-13 12:00:00', 'period_end' => '2026-10-13 13:00:00',
            'credit_expires_at' => '2026-10-13 13:00:00', 'provenance' => IdentityPolicy::REHEARSAL], $audit['award']);
        $this->assertSame($audit['award'], Period::verify($f['period'], [$f['award']], $f['invoice'], $f['policy'], 7));
        foreach ([Period::PERIODS => $f['period'], Period::EVENTS => $f['award']] as $table => $row) {
            $seal = $row['seal'];
            unset($row['seal']);
            $this->assertSame(hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'membership-award-record-v1',
                'table' => $table, 'row' => MembershipRecords::strings($row)]), config('app.key')), $seal);
        }
        $this->refuses(fn () => $this->redemption($f), 'unsupported_policy');
    }

    public function test_complete_ledger_sorts_sequence_and_preserves_award_after_reserve_release_and_expiry(): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f);
        $reserve = Reservation::reserveEvent($this->audit($f), $row, $f['buyer'], $row['created_at']);
        $reserved = $this->audit($f, [$f['award'], $reserve], [$row]);
        $this->assertSame(['available' => 2, 'reserved' => 1, 'consumed' => 0, 'expired' => 0], $reserved['balances']);
        $release = Reservation::releaseEvent($reserved, $row['id'], $f['policy'], $f['buyer'], $row['honor_deadline']);
        $released = $this->audit($f, [$release, $reserve, $f['award']], [$row]);
        $this->assertSame(['available' => 3, 'reserved' => 0, 'consumed' => 0, 'expired' => 0], $released['balances']);
        $expire = Reservation::expireEvent($released, $f['policy'], $f['buyer'], $f['period']['credit_expires_at']);
        $events = [$expire, $release, $reserve, $f['award']];
        $final = $this->audit($f, $events, [$row]);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 0, 'expired' => 3], $final['balances']);
        $this->assertSame($this->audit($f)['award'], Period::verify($f['period'], $events, $f['invoice'], $f['policy'], 7, [$row]));
        $this->assertSame($release, $final['reservations'][$row['id']]['terminal']);
        $this->refuses(fn () => Reservation::releaseEvent($final, $row['id'], $f['policy'], $f['buyer'], '2026-10-13 13:01:00'), 'reservation_closed');
        $this->refuses(fn () => Reservation::expireEvent($final, $f['policy'], $f['buyer'], '2026-10-13 13:01:00'), 'no_available_credit');
    }

    public function test_expiry_never_takes_reserved_credit_and_later_honor_release_cannot_resurrect_it(): void
    {
        $f = $this->fixture(honor: 7200, beyond: true);
        $row = $this->redemption($f);
        $reserve = Reservation::reserveEvent($this->audit($f), $row, $f['buyer'], $row['created_at']);
        $reserved = $this->audit($f, [$f['award'], $reserve], [$row]);
        $this->refuses(fn () => Reservation::releaseEvent($reserved, $row['id'], $f['policy'], $f['buyer'], '2026-10-13 13:00:00'), 'honor_current');
        $expire = Reservation::expireEvent($reserved, $f['policy'], $f['buyer'], '2026-10-13 13:00:00');
        $expired = $this->audit($f, [$f['award'], $reserve, $expire], [$row]);
        $this->assertSame(['available' => 0, 'reserved' => 1, 'consumed' => 0, 'expired' => 2], $expired['balances']);
        $release = Reservation::releaseEvent($expired, $row['id'], $f['policy'], $f['buyer'], $row['honor_deadline']);
        $final = $this->audit($f, [$f['award'], $reserve, $expire, $release], [$row]);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 0, 'expired' => 3], $final['balances']);
        $this->refuses(fn () => Reservation::create($f['period'], $f['invoice'], $f['policy'], $f['license'], 'late', $row['honor_deadline']), 'credit_expired');
    }

    public function test_release_at_exact_credit_expiry_moves_reserved_credit_to_expired(): void
    {
        $f = $this->fixture(honor: 3600);
        $row = $this->redemption($f);
        $reserve = Reservation::reserveEvent($this->audit($f), $row, $f['buyer'], $row['created_at']);
        $reserved = $this->audit($f, [$f['award'], $reserve], [$row]);
        $release = Reservation::releaseEvent($reserved, $row['id'], $f['policy'], $f['buyer'], '2026-10-13 13:00:00');
        $final = $this->audit($f, [$f['award'], $reserve, $release], [$row]);
        $this->assertSame(['available' => 2, 'reserved' => 0, 'consumed' => 0, 'expired' => 1], $final['balances']);
    }

    public function test_request_scope_prevents_cross_period_retargeting_and_retry_intent_does_not_renew_honor(): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f, 'same-request');
        $payload = Reservation::verify($row, $f['period'], $f['invoice'], $f['policy']);
        CarbonImmutable::setTestNow('2026-10-13 12:04:59 UTC');
        $this->assertSame($row['intent_hash'], CanonicalJson::hash(Reservation::intent($f['period'], $f['invoice'], $f['policy'], $f['license'])));
        $this->assertSame('2026-10-13 12:05:00', $payload['honor_deadline']);
        $other = $f['period'];
        $other['id'] = (string) Str::uuid();
        $other['seal'] = Period::seal(Period::PERIODS, $other);
        $retargeted = Reservation::create($other, $f['invoice'], $f['policy'], $f['license'], 'same-request', '2026-10-13 12:00:00');
        $this->assertSame($row['request_key_hash'], $retargeted['request_key_hash']);
        $this->assertNotSame($row['intent_hash'], $retargeted['intent_hash']);
        $this->assertSame(hash('sha256', 'same-request'), $payload['request_scope']['key_digest']);
        $this->assertStringNotContainsString('same-request', Crypt::decryptString($row['payload_ciphertext']));
        $this->refuses(fn () => Reservation::requestKey($f['invoice'], "bad\nkey"), 'invalid_request_key');
    }

    public function test_insufficient_credit_and_wrong_actor_have_no_buildable_effect(): void
    {
        $f = $this->fixture(cost: 3);
        $row = $this->redemption($f);
        $reserve = Reservation::reserveEvent($this->audit($f), $row, $f['buyer'], $row['created_at']);
        $reserved = $this->audit($f, [$f['award'], $reserve], [$row]);
        $second = $this->redemption($f, 'second');
        $this->refuses(fn () => Reservation::reserveEvent($reserved, $second, $f['buyer'], $second['created_at']), 'insufficient_credit');
        $other = [...$f['buyer'], 'user_id' => 99];
        $this->refuses(fn () => Reservation::reserveEvent($this->audit($f), $row, $other, $row['created_at']), 'owner_changed');
        $legacy = new MembershipLicenseBinding($f['license']->selectionHash, $f['license']->licenseManifestHash,
            $f['license']->assetManifestHash, $f['license']->originalTermsHash, 1);
        $this->refuses(fn () => Reservation::intent($f['period'], $f['invoice'], $f['policy'], $legacy), 'ineligible_license');
    }

    #[DataProvider('redemptionColumns')]
    public function test_every_retained_redemption_column_is_authenticated(string $column): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f);
        $row[$column] = $column === 'credit_amount' ? 2 : 'changed';
        $this->refuses(fn () => Reservation::verify($row, $f['period'], $f['invoice'], $f['policy']), 'tampered');
    }

    public static function redemptionColumns(): array
    {
        return array_map(fn ($column) => [$column], ['id', 'period_id', 'request_key_hash', 'owner_binding_hash', 'credit_amount',
            'selection_hash', 'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash', 'intent_hash',
            'honor_deadline', 'payload_ciphertext', 'seal', 'created_at']);
    }

    #[DataProvider('intentMutations')]
    public function test_resealed_semantic_forgery_still_refuses(string $field): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f);
        $payload = json_decode(Crypt::decryptString($row['payload_ciphertext']), true, 24, JSON_THROW_ON_ERROR);
        match ($field) {
            'cost' => $payload['intent']['credit_amount'] = 2,
            'rule' => $payload['intent']['rule']['rule_id'] = 'another-rule',
            'owner' => $payload['intent']['invoice']['buyer_binding']['user_id'] = 99,
            'period' => $payload['intent']['period_id'] = (string) Str::uuid(),
            'scope' => $payload['request_scope']['buyer_origin_id'] = (string) Str::uuid(),
            'purpose' => $payload['intent']['purpose'] = 'production-member-license-grant-v1',
            'honor' => $payload['honor_deadline'] = '2026-10-13 12:10:00',
        };
        $row['payload_ciphertext'] = Crypt::encryptString(CanonicalJson::encode($payload));
        $row['intent_hash'] = CanonicalJson::hash($payload['intent']);
        $row['seal'] = Reservation::seal($row);
        $this->refuses(fn () => Reservation::verify($row, $f['period'], $f['invoice'], $f['policy']), 'tampered');
    }

    public static function intentMutations(): array
    {
        return array_map(fn ($key) => [$key], ['cost', 'rule', 'owner', 'period', 'scope', 'purpose', 'honor']);
    }

    #[DataProvider('eventMutations')]
    public function test_resealed_broken_chain_or_balance_refuses(string $column, mixed $value): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f);
        $event = Reservation::reserveEvent($this->audit($f), $row, $f['buyer'], $row['created_at']);
        $event[$column] = $value;
        $event['seal'] = Period::seal(Period::EVENTS, $event);
        $this->refuses(fn () => $this->audit($f, [$f['award'], $event], [$row]));
    }

    public static function eventMutations(): array
    {
        return [['sequence', 3], ['sequence', '02'], ['prior_seal', str_repeat('f', 64)], ['amount', 2],
            ['before_available', 2], ['after_available', 3], ['after_reserved', 0], ['after_consumed', 1],
            ['request_hash', str_repeat('a', 64)], ['key_hash', str_repeat('b', 64)], ['created_at', '2026-10-13 11:59:59'],
            ['kind', 'consume'], ['grant_purpose', 'production-member-license-grant-v1']];
    }

    public function test_orphan_rows_duplicate_redemptions_and_duplicate_terminal_are_refused(): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f);
        $reserve = Reservation::reserveEvent($this->audit($f), $row, $f['buyer'], $row['created_at']);
        $this->refuses(fn () => $this->audit($f, [$f['award']], [$row]), 'orphan_redemption');
        $this->refuses(fn () => $this->audit($f, [$f['award'], $reserve], []), 'orphan_reservation');
        $this->refuses(fn () => $this->audit($f, [$f['award'], $reserve], [$row, $row]), 'duplicate_redemption');
        $reserved = $this->audit($f, [$f['award'], $reserve], [$row]);
        $release = Reservation::releaseEvent($reserved, $row['id'], $f['policy'], $f['buyer'], $row['honor_deadline']);
        $repeat = [...$release, 'id' => (string) Str::uuid(), 'sequence' => 4, 'prior_seal' => $release['seal']];
        $repeat['seal'] = Period::seal(Period::EVENTS, $repeat);
        $this->refuses(fn () => $this->audit($f, [$f['award'], $reserve, $release, $repeat], [$row]));
        $this->refuses(fn () => $this->audit($f, [$f['award'], $reserve, $reserve], [$row]), 'invalid_credit_chain');
    }

    public function test_expiry_before_boundary_and_release_before_honor_are_refused(): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f);
        $reserve = Reservation::reserveEvent($this->audit($f), $row, $f['buyer'], $row['created_at']);
        $reserved = $this->audit($f, [$f['award'], $reserve], [$row]);
        $this->refuses(fn () => Reservation::releaseEvent($reserved, $row['id'], $f['policy'], $f['buyer'], '2026-10-13 12:04:59'), 'honor_current');
        $this->refuses(fn () => Reservation::expireEvent($reserved, $f['policy'], $f['buyer'], '2026-10-13 12:59:59'), 'credit_current');
    }

    public function test_reserve_keeps_terminal_and_finite_expiry_slots_even_when_all_credit_is_reserved(): void
    {
        $f = $this->fixture(cost: 3);
        $row = $this->redemption($f);
        $audit = $this->audit($f);
        // Pure prospective-capacity boundary: these synthetic counters do not claim a retained 9,997-event chain.
        $audit['tail']['sequence'] = 9997;
        $reserve = Reservation::reserveEvent($audit, $row, $f['buyer'], $row['created_at']);
        $this->assertSame(9998, $reserve['sequence']);
        $this->assertSame(0, $reserve['after_available']);
        $audit['tail']['sequence'] = 9998;
        $this->refuses(fn () => Reservation::reserveEvent($audit, $row, $f['buyer'], $row['created_at']), 'ledger_capacity');
        $audit['tail'] = $reserve;
        $audit['balances'] = ['available' => 0, 'reserved' => 3, 'consumed' => 0, 'expired' => 0];
        $audit['reservations'][$row['id']] = ['record' => $row, 'reserve' => $reserve, 'terminal' => null];
        $release = Reservation::releaseEvent($audit, $row['id'], $f['policy'], $f['buyer'], $row['honor_deadline']);
        $this->assertSame(9999, $release['sequence']);
        $audit['tail'] = $release;
        $audit['balances'] = ['available' => 3, 'reserved' => 0, 'consumed' => 0, 'expired' => 0];
        $expire = Reservation::expireEvent($audit, $f['policy'], $f['buyer'], $f['period']['credit_expires_at']);
        $this->assertSame(10000, $expire['sequence']);
    }

    public function test_live_reservations_each_need_a_slot_and_no_expiry_period_needs_no_expiry_slot(): void
    {
        $f = $this->fixture(expiry: false);
        $row = $this->redemption($f);
        $audit = $this->audit($f);
        $audit['tail']['sequence'] = 9998;
        $reserve = Reservation::reserveEvent($audit, $row, $f['buyer'], $row['created_at']);
        $this->assertSame(9999, $reserve['sequence']);
        $audit['reservations']['synthetic-other-live-reservation'] = ['terminal' => null];
        $this->refuses(fn () => Reservation::reserveEvent($audit, $row, $f['buyer'], $row['created_at']), 'ledger_capacity');
        $this->refuses(fn () => $this->audit($f, array_fill(0, MembershipPolicy::MAX_EVENTS + 1, $f['award'])), 'technical_bound');
    }

    public function test_previous_record_key_is_accepted_and_unknown_key_refuses(): void
    {
        $f = $this->fixture();
        $row = $this->redemption($f);
        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('N', 32)), 'app.previous_keys' => [$old]]);
        $this->assertSame($row['id'], Reservation::verify($row, $f['period'], $f['invoice'], $f['policy'])['redemption_id']);
        $this->assertSame($f['period']['id'], $this->audit($f)['award']['period_id']);
        config(['app.previous_keys' => []]);
        $this->refuses(fn () => Reservation::verify($row, $f['period'], $f['invoice'], $f['policy']), 'tampered');
    }

    private function fixture(bool $v2 = true, int $cost = 1, int $honor = 300, bool $beyond = false, bool $expiry = true): array
    {
        $license = new MembershipLicenseBinding(hash('sha256', 'synthetic selection'), hash('sha256', 'synthetic license'),
            hash('sha256', 'synthetic assets'), hash('sha256', 'synthetic member original terms'), 1, 11, 12, 13, 14);
        $input = $this->policyInput(['award_terms' => ['expiry' => ['mode' => $expiry ? 'period_end' : 'none', 'seconds' => 0],
            'late_payment' => ['mode' => 'allow', 'seconds' => 0]]]);
        if ($v2) {
            $input = [...$input, 'schema_version' => MembershipPolicyDocument::CREDIT_VERSION, 'scope' => 'paid_period_credit_lifecycle',
                'redemption_terms' => ['eligibility_rules' => [['rule_id' => 'synthetic-wav', 'track_id' => 11, 'offer_id' => 12,
                    'offer_revision_id' => 13, 'license_version_id' => 14, 'license_manifest_hash' => $license->licenseManifestHash,
                    'asset_manifest_hash' => $license->assetManifestHash, 'original_terms_hash' => $license->originalTermsHash, 'credit_amount' => $cost]],
                    'honor' => ['duration_seconds' => $honor, 'expiry_mode' => $beyond ? 'allow_beyond_credit_expiry' : 'cap_at_credit_expiry'],
                    'release' => ['before_credit_expiry' => 'available', 'at_or_after_credit_expiry' => 'expired'],
                    'available_expiry' => ['mode' => 'expire_available_at_credit_expiry']]];
        }
        $policy = MembershipPolicyDocument::from($input)->binding((string) Str::uuid());
        $buyer = ['schema_version' => 1, 'account_id' => 1, 'user_id' => 2, 'origin_id' => (string) Str::uuid(),
            'account_public_id' => (string) Str::uuid(), 'verification_observation_id' => (string) Str::uuid(),
            'verification_observation_hash' => hash('sha256', 'synthetic verification'), 'identity_policy_hash' => hash('sha256', 'synthetic identity'),
            'identity_policy_version' => IdentityPolicy::VERSION, 'provenance' => IdentityPolicy::REHEARSAL];
        $invoice = new MembershipInvoiceBinding(hash('sha256', 'synthetic invoice'), hash('sha256', 'synthetic invoice graph'),
            hash('sha256', 'synthetic provider scope'), 1700, 'USD', '2026-10-13 12:00:00', '2026-10-13 13:00:00',
            $policy->policyHash, $policy->originalTermsHash, $buyer, IdentityPolicy::REHEARSAL, '2026-10-13 12:00:00', $policy->planVersionId);
        [$period, $award] = Period::create($invoice, $policy, 7, $buyer);

        return compact('license', 'policy', 'buyer', 'invoice', 'period', 'award');
    }

    private function redemption(array $fixture, string $key = 'synthetic-request'): array
    {
        return Reservation::create($fixture['period'], $fixture['invoice'], $fixture['policy'], $fixture['license'], $key, '2026-10-13 12:00:00');
    }

    private function audit(array $fixture, ?array $events = null, array $redemptions = []): array
    {
        return Audit::verify($fixture['period'], $events ?? [$fixture['award']], $redemptions, $fixture['invoice'], $fixture['policy'], 7);
    }

    private function refuses(callable $operation, ?string $reason = null): void
    {
        try {
            $operation();
            $this->fail('Malformed or unsupported retained records must refuse.');
        } catch (MembershipException $error) {
            if ($reason !== null) {
                $this->assertSame($reason, $error->reason);
            } else {
                $this->assertNotSame('', $error->reason);
            }
        }
    }
}
