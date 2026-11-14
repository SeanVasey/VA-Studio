<?php

namespace Tests\Feature\ProductionCheckoutComposition;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\ExclusiveInventoryGate;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Migration\History\ExclusivityAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Production checkout consults the exclusivity authority (PR #171 ledger) before refusing exclusive or scoped inventory
 * (lane PCC). The chain sells non-exclusive v1 only, so the selection is refused in every case; the authority decides the
 * reason, and an unreadable ledger counts as "no".
 */
final class ProductionCheckoutExclusivityTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    /** Synthetic append-only ledger history, ending at the given authority. */
    private function ledger(array $authorities, int $actor): void
    {
        $transitions = ['beatstars' => 'declare', 'frozen' => 'freeze', 'vasey_store' => 'handover'];
        foreach ($authorities as $index => $authority) {
            DB::table(ExclusivityAuthority::TABLE)->insert(['sequence' => $index + 1, 'authority' => $authority,
                'transition' => $transitions[$authority], 'reference_hmac' => str_repeat('a', 64), 'actor_id' => $actor]);
        }
    }

    /** Scope the catalog's offered revision: rights-scoped (exclusive) inventory. */
    private function scope(array $f): void
    {
        $scope = DB::table('rights_scopes')->insertGetId(['public_id' => (string) Str::uuid(), 'scope_key' => 'synthetic-exclusive-scope',
            'evidence_reference' => 'synthetic', 'created_by' => $f['catalog']['actor']->id, 'created_at' => now()]);
        DB::table('rights_scope_offers')->insert(['rights_scope_id' => $scope, 'offer_revision_id' => $f['catalog']['revision']->id,
            'evidence_reference' => 'synthetic', 'linked_by' => $f['catalog']['actor']->id, 'created_at' => now()]);
    }

    private function refusal(\Closure $command): string
    {
        try {
            $command();
        } catch (CheckoutException $error) {
            return $error->reason;
        }
        $this->fail('Exclusive inventory was admitted.');
    }

    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function ledgers(): array
    {
        return ['empty ledger' => [[], 'exclusivity_authority'], 'BeatStars sells' => [['beatstars'], 'exclusivity_authority'],
            'frozen' => [['beatstars', 'frozen'], 'exclusivity_authority'],
            'reverted to frozen' => [['beatstars', 'frozen', 'vasey_store', 'frozen'], 'exclusivity_authority'],
            'store is the authority' => [['beatstars', 'frozen', 'vasey_store'], 'unsupported_inventory']];
    }

    #[DataProvider('ledgers')]
    public function test_an_exclusive_order_is_refused_and_the_authority_decides_the_reason(array $ledger, string $reason): void
    {
        $f = $this->payable(false);
        $this->ledger($ledger, $f['catalog']['actor']->id);
        $this->scope($f);
        $this->assertSame($reason, $this->refusal(fn () => $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'],
            $f['review']['reviewId'], $f['review']['reviewHash'], true, 'synthetic-exclusive-accept')));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 0);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['attempt'], 0);
    }

    public function test_the_hosted_request_re_checks_before_any_provider_call(): void
    {
        $f = $this->payable();
        $this->scope($f);
        $this->assertSame('exclusivity_authority', $this->refusal(fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
    }

    public function test_an_unreadable_ledger_counts_as_no(): void
    {
        $f = $this->payable(false);
        $this->scope($f);
        // The ledger table is gone (a missing migration or a renamed table): reading it throws, and that is a refusal.
        DB::statement('ALTER TABLE '.ExclusivityAuthority::TABLE.' RENAME TO legacy_exclusivity_authority_unreadable');
        $this->assertSame('exclusivity_authority', $this->refusal(fn () => $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'],
            $f['review']['reviewId'], $f['review']['reviewHash'], true, 'synthetic-exclusive-accept')));
    }

    public function test_non_exclusive_inventory_never_reads_the_ledger_and_still_sells(): void
    {
        $this->app->bind(ExclusivityAuthority::class, fn () => throw new \LogicException('The ledger must not be read.'));
        $f = $this->payable();
        $this->assertTrue($f['order']['assentAccepted']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 1);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function graphs(): array
    {
        $snapshot = fn (array $snapshot): string => json_encode($snapshot, JSON_THROW_ON_ERROR);
        $graph = fn (int $active, string $type, string $template) => ['offers' => [['id' => 7, 'is_active' => $active, 'current_revision_id' => 70]],
            'revisions' => [['id' => 70, 'license_version_id' => 700, 'snapshot' => $snapshot(['commercial' => ['type' => $type]])]],
            'licenses' => [['id' => 700, 'license_template_id' => 7000]], 'templates' => [['id' => 7000, 'type' => $template]]];

        return ['non-exclusive' => [$graph(1, 'non-exclusive', 'non-exclusive'), false],
            'free template' => [$graph(1, 'non-exclusive', 'free'), false],
            'inactive exclusive offer' => [$graph(0, 'exclusive', 'exclusive'), false],
            'exclusive snapshot' => [$graph(1, 'exclusive', 'non-exclusive'), true],
            'exclusive template' => [$graph(1, 'non-exclusive', 'exclusive'), true],
            'test exclusive preparation' => [['offers' => [['id' => 7, 'is_active' => 1, 'current_revision_id' => 70]],
                'revisions' => [['id' => 70, 'license_version_id' => 700, 'snapshot' => $snapshot(['purpose' => 'test_exclusive_preparation'])]],
                'licenses' => [], 'templates' => []], true]];
    }

    #[DataProvider('graphs')]
    public function test_detection_follows_the_active_offer_composition_rule(array $graph, bool $exclusive): void
    {
        $reason = null;
        try {
            ExclusiveInventoryGate::refuse($graph, []);
        } catch (CheckoutException $error) {
            $reason = $error->reason;
        }
        $this->assertSame($exclusive ? 'exclusivity_authority' : null, $reason);
        // Any rights-scope link is scoped inventory whatever the offers say.
        $this->assertSame('exclusivity_authority', $this->refusal(fn () => ExclusiveInventoryGate::refuse($graph, [['id' => 1]])));
    }
}
