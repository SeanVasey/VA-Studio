<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingDatabaseInstant;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingSettlement;
use App\Domain\Memberships\Billing\BillingSnapshots;
use App\Domain\Memberships\Production\CurrentEligibleLicenses;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipCatalogEvidence;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipLedgerAudit;
use App\Domain\Memberships\Production\MembershipLicenseBinding;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Domain\Memberships\Production\MembershipRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Explicit synthetic terms over real catalog, identity, policy, billing and award writers. Never runtime registration. */
trait MembershipReservationFixtures
{
    use MembershipAwardFixtures;

    protected function reservationSetup(): void
    {
        $this->awardSetup();
        $this->fakePrivateMediaStorage();
    }

    protected function reservationFixture(array $policyOverrides = [], array $honorOverrides = [], bool $award = true,
        int $selectionCount = 1, int $creditAmount = 1): array
    {
        $catalogs = [];
        $rules = [];
        for ($index = 0; $index < $selectionCount; $index++) {
            $catalog = QuoteFixtures::selection();
            $catalog['binding'] = DB::transaction(function () use ($catalog): MembershipLicenseBinding {
                $rows = new MembershipRows;
                $started = hrtime(true);
                $at = CarbonImmutable::now('UTC');

                // Current real catalog values for authoring, without minting membership authority.
                return MembershipCatalogEvidence::lock($rows, (string) $catalog['offer']->id,
                    (int) $catalog['revision']->id, $at, $started + 60_000_000_000, $started)->binding;
            });
            $binding = $catalog['binding'];
            $rules[] = ['rule_id' => 'synthetic-selection-'.$index, 'track_id' => $binding->trackId,
                'offer_id' => $binding->offerId, 'offer_revision_id' => $binding->offerRevisionId,
                'license_version_id' => $binding->licenseVersionId, 'license_manifest_hash' => $binding->licenseManifestHash,
                'asset_manifest_hash' => $binding->assetManifestHash, 'original_terms_hash' => $binding->originalTermsHash,
                'credit_amount' => $creditAmount];
            $catalogs[] = $catalog;
        }
        $terms = ['schema_version' => MembershipPolicyDocument::CREDIT_VERSION, 'scope' => 'paid_period_credit_lifecycle',
            'redemption_terms' => ['eligibility_rules' => $rules,
                'honor' => ['duration_seconds' => 60, 'expiry_mode' => 'cap_at_credit_expiry', ...$honorOverrides],
                'release' => ['before_credit_expiry' => 'available', 'at_or_after_credit_expiry' => 'expired'],
                'available_expiry' => ['mode' => 'expire_available_at_credit_expiry']], ...$policyOverrides];
        $fixture = $this->awardFixture($terms);
        $this->reservationTestCapabilities();
        // The actual registered producer must independently admit every catalog after real policy/owner authoring.
        foreach ($catalogs as $catalog) {
            DB::transaction(function () use ($fixture, $catalog): void {
                $rows = new MembershipRows;
                $authority = app(MembershipEligibleLicenseAuthority::class);
                $proof = $authority->lock((string) $catalog['offer']->id, (int) $catalog['revision']->id,
                    $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
                $authority->proveCurrent($proof, $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
                $actual = $proof->binding();
                $expected = $catalog['binding'];
                foreach (['trackId', 'offerId', 'offerRevisionId', 'licenseVersionId', 'selectionHash',
                    'licenseManifestHash', 'assetManifestHash', 'originalTermsHash'] as $field) {
                    $this->assertSame($expected->$field, $actual->$field);
                }
            });
        }
        $catalog = $catalogs[0];

        return [...$fixture, 'catalog' => $catalog, 'catalogs' => $catalogs,
            'selection' => (string) $catalog['offer']->id, 'expected_revision' => (int) $catalog['revision']->id,
            'license_binding' => $catalog['binding'], 'provider_invoice_ref' => BillingStripeFixtures::INVOICE,
            'provider_graph' => BillingStripeFixtures::graph(),
            'award' => $award ? (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']) : null];
    }

    protected function reservationTestCapabilities(): void
    {
        $this->awardTestCapabilities();
        // The grant capability remains the deliberately unusable test-only double supplied above.
        $this->app->instance(MembershipEligibleLicenseAuthority::class, new CurrentEligibleLicenses);
    }

    protected function reserveFixture(array $fixture, string $requestKey = 'synthetic-reservation-request'): array
    {
        return (new MembershipReservationOperations)->reserve($fixture['invoice']['id'], $fixture['selection'],
            $fixture['expected_revision'], $requestKey, $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    protected function refreshReservationObservation(array $fixture): array
    {
        $ledger = new BillingLedger;
        $start = $ledger->startRetrieval();
        $providerGraph = $fixture['provider_graph'];
        $interval = $ledger->endRetrieval($start);
        $retrievedAt = BillingDatabaseInstant::fromNativeValue($interval->databaseAt, 0)->seconds();
        $projected = BillingStripeFixtures::snapshots($providerGraph, $retrievedAt);
        $snapshots = new BillingSnapshots($fixture['provider_invoice_ref'], $projected->account, $projected->invoice,
            $projected->lines, $projected->payments, $projected->paymentIntents, $projected->charges,
            $projected->balanceTransactions, $projected->subscription, $retrievedAt, IdentityPolicy::REHEARSAL);
        $verdict = BillingSettlement::evaluate($snapshots, $fixture['approval']['expectation']);

        return $ledger->append($fixture['invoice'], $verdict, IdentityPolicy::REHEARSAL, $interval);
    }

    /** A second actual invoice claims an advance-paid adjacent, nonoverlapping synthetic service period. */
    protected function reservationNextPeriod(array $fixture): array
    {
        $references = [BillingStripeFixtures::INVOICE => 'in_SYNTHETICRESERVATION2',
            BillingStripeFixtures::INVOICE_PAYMENT => 'inpay_SYNTHETICRESERVATION2',
            BillingStripeFixtures::PAYMENT_INTENT => 'pi_SYNTHETICRESERVATION2',
            BillingStripeFixtures::CHARGE => 'ch_SYNTHETICRESERVATION2',
            BillingStripeFixtures::BALANCE_TRANSACTION => 'txn_SYNTHETICRESERVATION2'];
        $replace = function (mixed $value) use (&$replace, $references): mixed {
            if (! is_array($value)) {
                return is_string($value) ? ($references[$value] ?? $value) : $value;
            }
            $result = [];
            foreach ($value as $key => $item) {
                $result[is_string($key) ? ($references[$key] ?? $key) : $key] = $replace($item);
            }

            return $result;
        };
        $start = BillingStripeFixtures::PERIOD_END;
        // Retain the original elapsed provider payment dates. Only the synthetic service period
        // and business wall boundary move forward; the database retrieval clock stays native.
        $graph = $replace(BillingStripeFixtures::graph(['line' => ['id' => 'il_SYNTHETICRESERVATION2',
            'period' => ['start' => $start, 'end' => $start + 2678400]],
            'invoice' => ['period_start' => BillingStripeFixtures::PERIOD_START, 'period_end' => $start]]));
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($start + 1));
        $fixture['provider_invoice_ref'] = $references[BillingStripeFixtures::INVOICE];
        $fixture['provider_graph'] = $graph;
        $fixture['invoice'] = (new BillingLedger)->invoice($fixture['approval'], $fixture['provider_invoice_ref']);
        $fixture['observation'] = $this->refreshReservationObservation($fixture);
        $fixture['award'] = (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);

        return $fixture;
    }

    protected function ledgerSnapshot(array $fixture): array
    {
        return DB::transaction(function () use ($fixture): array {
            $rows = new MembershipRows;
            $access = new ProductionCustomerAccess;
            $access->lock($fixture['buyer']['principal'], $fixture['buyer']['user'], $rows->current());
            $invoice = app(MembershipPaidInvoiceAuthority::class)->lock($fixture['invoice']['id'],
                $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows)->binding();
            $facts = app(MembershipPolicyFactsAuthority::class)->lock($invoice->planVersionId, $rows)->binding();
            $historical = $access->verifyHistoricalBinding($invoice->originalBuyerBinding, $rows->current());
            $period = $rows->one(MembershipPeriodRecords::PERIODS, 'source_invoice_hash = ?', [$invoice->sourceInvoiceHash]);
            $events = $rows->rows(MembershipPeriodRecords::EVENTS, 'period_id = ?', [$period['id']], MembershipPolicy::MAX_EVENTS + 1);
            $redemptions = $rows->rows(MembershipReservationRecords::TABLE, 'period_id = ?', [$period['id']], MembershipPolicy::MAX_EVENTS + 1);

            return MembershipLedgerAudit::verify($period, $events, $redemptions, $invoice, $facts, (int) $historical['origin']['id']);
        });
    }
}
