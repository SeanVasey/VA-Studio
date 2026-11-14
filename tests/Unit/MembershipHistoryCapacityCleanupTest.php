<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberActivationHistory;
use App\Domain\Grants\Member\MemberActivationReadiness;
use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Domain\Grants\Member\MemberLicenseAuthorizationRecords;
use App\Domain\Grants\Member\MemberOriginalArtifactManifest;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipLedgerAudit as Audit;
use App\Domain\Memberships\Production\MembershipLicenseBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords as Period;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords as Reservation;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Support\MemberLicenseAuthorizationValues;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

/** Actual pure codecs and complete synthetic ledgers; no DB writes, live confirmation or file claims. */
final class MembershipHistoryCapacityCleanupTest extends TestCase
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

    public function test_valid_near_capacity_history_allows_credit_cleanup_but_refuses_one_more_planned_effect(): void
    {
        $f = $this->fixture();
        $sets = ['origins' => [], 'artifacts' => [], 'activations' => [],
            'profiles' => [$f['profile']], 'definitions' => [$f['definition']], 'plans' => [$f['plan']]];
        $bytes = $this->rawBytes($sets);
        $events = [$f['award']];
        $redemptions = [];
        $prospective = Audit::verify($f['period'], $events, [], $f['invoice'], $f['policy'], 17, MemberActivationHistory::empty());
        $overflow = null;
        // Build exact real rows once, not an O(n^2) series of repeated history decryptions. The
        // prospective builder views below confer no admission; the complete ledger is audited next.
        for ($index = 0; $index < 512; $index++) {
            $candidate = $this->candidate($f, $prospective, $index);
            $added = $this->rawBytes($candidate['sets']);
            if ($bytes + $added > MemberActivationHistory::MAX_BYTES) {
                $overflow = $candidate;
                break;
            }
            foreach (['origins', 'artifacts', 'activations'] as $kind) {
                $sets[$kind][] = $candidate['sets'][$kind][0];
            }
            $bytes += $added;
            $events[] = $candidate['reserve'];
            $events[] = $candidate['consume'];
            $redemptions[] = $candidate['redemption'];
            $prospective = $candidate['prospective'];
        }
        $this->assertNotNull($overflow, 'The complete valid fixture must actually reach the member byte bound.');
        $this->assertGreaterThan((int) (MemberActivationHistory::MAX_BYTES * 0.99), $bytes);
        $this->assertLessThanOrEqual(MemberActivationHistory::MAX_BYTES, $bytes);
        $this->assertSame($bytes, $this->rawBytes($sets));
        $history = $this->history($sets, count($redemptions));
        $audit = Audit::verify($f['period'], $events, $redemptions, $f['invoice'], $f['policy'], 17, $history);
        $this->assertSame(count($redemptions), $audit['balances']['consumed']);
        $this->assertSame(0, $audit['balances']['reserved']);
        $this->assertGreaterThan(1, $audit['balances']['available']);
        $memberHash = $this->rowDigest($history->rows());
        $decoded = $this->decodedBytes($sets);
        $this->assertLessThanOrEqual(MemberActivationHistory::MAX_BYTES, $decoded);

        // Each overflow row was independently built/read by its actual codec; the whole planned
        // graph exceeds only the aggregate raw limit. No SQL writer is invoked by this pure test.
        $tooLarge = $sets;
        foreach (['origins', 'artifacts', 'activations'] as $kind) {
            $tooLarge[$kind][] = $overflow['sets'][$kind][0];
        }
        $overflowBytes = $this->rawBytes($tooLarge);
        $this->assertGreaterThan(MemberActivationHistory::MAX_BYTES, $overflowBytes);
        try {
            $this->history($tooLarge, count($redemptions) + 1);
            $this->fail('A new planned graph exceeding the member history budget must refuse.');
        } catch (MemberGrantException $error) {
            $this->assertSame('history_capacity', $error->reason);
        }
        unset($tooLarge, $overflow, $prospective);

        $beforeEvents = count($events);
        $later = Reservation::create($f['period'], $f['invoice'], $f['policy'], $f['license'],
            'NONBINDING cleanup after near-capacity history', '2026-10-13 00:03:00');
        $reserve = Reservation::reserveEvent($audit, $later, $f['actor'], $later['created_at']);
        $events[] = $reserve;
        $redemptions[] = $later;
        $cleanup = $this->advance($audit, $reserve, $later);
        $expiry = Reservation::expireEvent($cleanup, $f['policy'], $f['actor'], $f['period']['credit_expires_at']);
        $events[] = $expiry;
        $cleanup = $this->advance($cleanup, $expiry);
        $release = Reservation::releaseEvent($cleanup, $later['id'], $f['policy'], $f['actor'], $f['period']['credit_expires_at']);
        $events[] = $release;
        $final = Audit::verify($f['period'], $events, $redemptions, $f['invoice'], $f['policy'], 17, $history);
        $this->assertSame($beforeEvents + 3, count($events));
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => count($redemptions) - 1,
            'expired' => $f['policy']->creditAllowance - count($redemptions) + 1], $final['balances']);
        $this->assertSame('release', $final['reservations'][$later['id']]['terminal']['kind']);
        $this->assertSame($memberHash, $this->rowDigest($history->rows()));
        $this->assertSame($bytes, $this->rawBytes($history->rows()));
        fwrite(STDOUT, "\n".json_encode(['capacity_fixture' => 'synthetic authenticated pure rows',
            'complete_graphs' => count($redemptions) - 1, 'member_raw_bytes' => $bytes, 'member_decoded_bytes' => $decoded,
            'member_limit_bytes' => MemberActivationHistory::MAX_BYTES, 'overcapacity_planned_raw_bytes' => $overflowBytes,
            'credit_events_before_cleanup' => $beforeEvents, 'credit_events_after_cleanup' => count($events),
            'member_rows_unchanged' => true], JSON_THROW_ON_ERROR)."\n");
    }

    private function fixture(): array
    {
        $f = MemberLicenseAuthorizationValues::fixture();
        $s = $f['source']->document();
        $terms = $s['profile']['document']['original_terms'];
        $terms['authored_source'] = str_pad('NONBINDING SYNTHETIC capacity-only original text. ', 65536, 'x');
        $s['profile']['document']['original_terms'] = $terms;
        $s['definition']['document']['profile_hash'] = CanonicalJson::hash($s['profile']['document']);
        $s['definition']['document']['license_manifest']['authored_source'] = $terms['authored_source'];
        $s['definition']['document']['license_manifest']['source_hash'] = hash('sha256', $terms['authored_source']);
        $s['definition']['document']['original_terms_hash'] = CanonicalJson::hash($terms);
        $s['definition']['document']['retention_policy']['text'] = str_pad('NONBINDING SYNTHETIC capacity-only retention text. ', 65536, 'r');
        $s['definition']['document']['storage_policy']['retention_policy_hash'] = CanonicalJson::hash($s['definition']['document']['retention_policy']);
        $s['selection']['license_manifest_hash'] = CanonicalJson::hash($s['definition']['document']['license_manifest']);
        $s['selection']['original_terms_hash'] = CanonicalJson::hash($terms);
        $input = $f['policy_document']->facts;
        $input['credit_allowance'] = 1000;
        $input['consumption_terms']['retention_policy'] = $s['definition']['document']['retention_policy'];
        $input['redemption_terms']['eligibility_rules'][0]['license_manifest_hash'] = $s['selection']['license_manifest_hash'];
        $input['redemption_terms']['eligibility_rules'][0]['original_terms_hash'] = $s['selection']['original_terms_hash'];
        $document = MembershipPolicyDocument::from($input);
        $policy = $document->binding($f['policy']->planVersionId);
        $s['invoice']['policy_hash'] = $policy->policyHash;
        $s['invoice']['original_terms_hash'] = $policy->originalTermsHash;
        $s['definition']['document']['policy_hash'] = $policy->policyHash;
        $author = hash('sha256', 'NONBINDING synthetic author');
        $profile = MemberGrantRecords::profile(MemberGrantProfileDocument::from($s['profile']['document']), $s['profile']['id'], 1, $author, '2026-10-12 00:00:00');
        $definition = MemberGrantRecords::definition(MemberGrantDefinitionDocument::from($s['definition']['document']),
            $s['definition']['id'], $s['profile']['id'], 1, $author, '2026-10-12 00:00:00');
        $s['profile']['seal'] = $profile['seal'];
        $s['definition']['seal'] = $definition['seal'];
        $plan = ['id' => $policy->planVersionId, 'policy_hash' => $policy->policyHash, 'original_terms_hash' => $policy->originalTermsHash,
            'provenance' => 'synthetic_rehearsal', 'created_at' => '2026-10-12 00:00:00'];
        $plan['payload_ciphertext'] = MembershipRecords::encrypt(['schema_version' => 'membership-policy-record-v1',
            'event' => 'policy_version_authored', 'plan_version_id' => $plan['id'], 'document' => $document->facts,
            'author_user_id' => 1, 'author_binding_hash' => $author, 'created_at' => $plan['created_at']]);
        $plan['seal'] = MembershipRecords::seal($plan);
        $i = $s['invoice'];
        $invoice = new MembershipInvoiceBinding($i['source_invoice_hash'], $i['invoice_graph_hash'], $i['provider_scope_hash'],
            $i['amount_minor'], $i['currency'], $i['period_start'], $i['period_end'], $i['policy_hash'], $i['original_terms_hash'],
            $i['buyer_binding'], $i['provenance'], $i['paid_at'], $i['plan_version_id']);
        [$period, $award] = Period::create($invoice, $policy, 17, $f['actor']);
        $selected = $s['selection'];
        $license = new MembershipLicenseBinding($selected['selection_hash'], $selected['license_manifest_hash'], $selected['asset_manifest_hash'],
            $selected['original_terms_hash'], 1, $selected['track_id'], $selected['offer_id'], $selected['offer_revision_id'], $selected['license_version_id']);

        return [...$f, ...compact('policy', 'profile', 'definition', 'plan', 'invoice', 'period', 'award', 'license'), 'source_template' => $s];
    }

    private function candidate(array $f, array $audit, int $index): array
    {
        $at = '2026-10-13 00:02:00';
        $redemption = Reservation::create($f['period'], $f['invoice'], $f['policy'], $f['license'], 'NONBINDING capacity '.$index, $at);
        $reserve = Reservation::reserveEvent($audit, $redemption, $f['actor'], $at);
        $prospective = $this->advance($audit, $reserve, $redemption);
        $s = $f['source_template'];
        $s['origin_id'] = $redemption['id'];
        $s['period'] = ['id' => $f['period']['id'], 'seal' => $f['period']['seal']];
        $s['redemption'] = array_intersect_key($redemption, array_flip(['id', 'seal', 'intent_hash', 'credit_amount', 'created_at', 'honor_deadline']));
        $s['reserve_event'] = ['id' => $reserve['id'], 'seal' => $reserve['seal']];
        $source = MemberOriginalSourceValues::from($s);
        $a = MemberLicenseAuthorizationRecords::create($source, $f['policy'], $f['actor'], (string) Str::uuid(), $at);
        $originals = [['role' => 'license_pdf', 'sha256' => hash('sha256', 'NONBINDING capacity artifact '.$index), 'bytes' => 128,
            'storage_policy_hash' => CanonicalJson::hash($s['definition']['document']['storage_policy'])]];
        $m = ['schema_version' => 'member-original-artifact-manifest-v1', 'family' => $s['family'], 'purpose' => $s['purpose'],
            'origin_id' => $s['origin_id'], 'originals' => $originals];
        $manifest = new MemberOriginalArtifactManifest($s['origin_id'], CanonicalJson::hash($m), $originals);
        $r = MemberActivationReadiness::forSource($source, $a, $f['policy'], $manifest);
        $origin = MemberActivationRecords::origin($source, $a, $f['policy'], $manifest, 17, $f['actor'], $at);
        $artifact = MemberActivationRecords::artifact($origin, $f['policy'], (string) Str::uuid(), $at);
        $consume = Reservation::consumeEvent($prospective, $redemption['id'], $f['policy'], $r->value(), $f['actor'], $at);
        $activation = MemberActivationRecords::activation($origin, $artifact, $consume, $f['policy'], (string) Str::uuid(), $f['actor'], $at);
        $prospective = $this->advance($prospective, $consume);

        return compact('redemption', 'reserve', 'consume', 'prospective') + ['sets' => ['origins' => [$origin], 'artifacts' => [$artifact], 'activations' => [$activation]]];
    }

    /** Prospective pure builder values only; every row is authenticated by the full audit above. */
    private function advance(array $audit, array $event, ?array $redemption = null): array
    {
        foreach (['available', 'reserved', 'consumed', 'expired'] as $kind) {
            $audit['balances'][$kind] = $event['after_'.$kind];
        }
        $audit['tail'] = $event;
        if ($redemption !== null) {
            $audit['reservations'][$redemption['id']] = ['record' => $redemption, 'reserve' => $event, 'terminal' => null];
        } elseif ($event['redemption_id'] !== null) {
            $audit['reservations'][$event['redemption_id']]['terminal'] = $event;
        }

        return $audit;
    }

    private function history(array $sets, int $redemptions): MemberActivationHistory
    {
        return MemberActivationHistory::fromRows($sets['origins'], $sets['artifacts'], $sets['activations'],
            $sets['profiles'], $sets['definitions'], $sets['plans'], $redemptions);
    }

    private function rawBytes(array $sets): int
    {
        $total = 0;
        foreach ($sets as $rows) {
            foreach ($rows as $row) {
                $total += strlen(CanonicalJson::encode(MemberGrantRecords::strings($row)));
            }
        }

        return $total;
    }

    private function decodedBytes(array $sets): int
    {
        $total = 0;
        foreach ($sets as $rows) {
            foreach ($rows as $row) {
                $total += strlen(Crypt::decryptString($row['payload_ciphertext']));
            }
        }

        return $total;
    }

    private function rowDigest(array $sets): string
    {
        $hash = hash_init('sha256');
        foreach ($sets as $kind => $rows) {
            hash_update($hash, $kind);
            foreach ($rows as $row) {
                hash_update($hash, CanonicalJson::encode(MemberGrantRecords::strings($row)));
            }
        }

        return hash_final($hash);
    }
}
