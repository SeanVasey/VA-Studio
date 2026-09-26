<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\IssuedTestDelivery;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\ReadTestDeliveryAuthorization;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Domain\Media\Models\MediaAsset;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestOwnerDeliveryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;
    private PrepareTestDeliveryStream $streams;

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $this->gateway = PaymentFixtures::gateway(); $this->streams = F::observingStreams();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway); $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer()); $this->app->instance(PrepareTestDeliveryStream::class, $this->streams);
    }

    private function issue(array $f, string $kind = 'contract', ?string $key = null): IssuedTestDelivery
    {
        return app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grant']->public_id, $kind, $key ?? (string) Str::uuid());
    }

    private function redeem(array $f, IssuedTestDelivery $issued): \App\Domain\Delivery\PreparedDeliveryStream
    {
        return app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token());
    }

    private function fails(string $reason, callable $action): void
    {
        try { $action(); $this->fail('Delivery operation unexpectedly succeeded.'); }
        catch (DeliveryException $error) { $this->assertSame($reason, $error->reason); }
    }

    public function test_each_retained_kind_delivers_exact_selected_bytes_once_without_changing_rights(): void
    {
        $f = F::allRoles($this->gateway); $before = F::retained(); $providerCalls = $this->gateway->calls;
        $kinds = ['contract' => ['pdf', 'application/pdf'], 'master_wav' => ['wav', 'audio/wav'],
            'download_mp3' => ['mp3', 'audio/mpeg'], 'stems_zip' => ['zip', 'application/zip']];
        foreach ($kinds as $kind => [$extension, $mime]) {
            if ($kind === 'stems_zip') { $this->travelTo(now()->addSeconds(60)); }
            $record = $kind === 'contract' ? GrantContract::sole() : MediaAsset::findOrFail(PendingEntitlement::where('role', $kind)->sole()->media_asset_id);
            $bytes = Storage::disk($record->disk)->get($record->storage_path); $issued = $this->issue($f, $kind);
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $issued->token());
            $this->assertSame($f['grant']->public_id.'-'.$kind.'.'.$extension, $issued->filename); $this->assertSame($mime, $issued->mimeType);
            $this->assertTrue($issued->expiresAt->equalTo(now()->addSeconds(60))); $this->assertFalse(is_resource($this->streams->resources[array_key_last($this->streams->resources)]));
            $auth = TestDeliveryAuthorization::where('public_id', $issued->authorizationId)->sole();
            $this->assertSame(hash('sha256', $issued->token()), $auth->token_hash); $this->assertSame(hash('sha256', $auth->evidence_ciphertext), $auth->evidence_hash);
            $plain = Crypt::decryptString($auth->evidence_ciphertext); $this->assertSame($plain, CanonicalJson::encode(json_decode($plain, true, 128, JSON_THROW_ON_ERROR)));
            $this->assertStringNotContainsString($issued->token(), $plain); $this->assertStringNotContainsString($issued->token(), json_encode($auth->getAttributes(), JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($issued->token(), json_encode($issued, JSON_THROW_ON_ERROR));
            ob_start(); var_dump($issued); $debug = ob_get_clean(); $this->assertStringNotContainsString($issued->token(), $debug);
            $stream = $this->redeem($f, $issued); $this->assertSame(hash('sha256', $bytes), $stream->sha256); $this->assertSame(strlen($bytes), $stream->sizeBytes);
            $body = ''; $stream->writeTo(function ($chunk) use (&$body): void { $body .= $chunk; }); $this->assertSame($bytes, $body);
            $this->assertFalse(is_resource($this->streams->resources[array_key_last($this->streams->resources)]));
            $this->fails('redeemed', fn () => $this->redeem($f, $issued));
            $read = app(ReadTestDeliveryAuthorization::class)->forOwner($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token());
            $this->assertSame($kind, $read['target']['kind']); $this->assertSame($auth->id, $read['redemption']->test_delivery_authorization_id);
        }
        $this->assertSame(array_fill(0, 8, 0), $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 4);
        $this->assertDatabaseCount('test_delivery_redemptions', 4); $this->assertSame($before, F::retained()); $this->assertSame($providerCalls, $this->gateway->calls);
        $this->assertSame(['pending'], PendingEntitlement::pluck('state')->unique()->values()->all());
        $this->assertSame(4, AuditEvent::where('action', 'commerce.delivery.authorized')->count()); $this->assertSame(4, AuditEvent::where('action', 'commerce.delivery.redeemed')->count());
        $audit = json_encode(DB::table('audit_events')->where('action', 'like', 'commerce.delivery.%')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(InventoryFixtures::OWNER, $audit); $this->assertStringNotContainsString($record->storage_path, $audit);
        try { serialize($issued); $this->fail('A delivery secret was serialized.'); } catch (\LogicException) { $this->addToAssertionCount(1); }
    }

    public function test_owner_selector_and_token_substitution_fail_without_file_access_or_effects(): void
    {
        $f = F::ready($this->gateway); $before = F::retained();
        foreach ([[str_repeat('b', 64), $f['grant']->public_id, 'contract'], [InventoryFixtures::OWNER, (string) Str::uuid(), 'contract'],
            [InventoryFixtures::OWNER, $f['grant']->public_id, 'stems_zip'], [InventoryFixtures::OWNER, $f['grant']->public_id, 'preview_tagged']] as [$owner, $grant, $kind]) {
            $this->fails('not_found', fn () => app(IssueTestDelivery::class)->handle($f['order']->public_id, $owner, $grant, $kind, (string) Str::uuid()));
        }
        $this->assertSame([], $this->streams->transactionLevels); $issued = $this->issue($f); $calls = $this->streams->transactionLevels;
        foreach ([[str_repeat('b', 64), $issued->authorizationId, $issued->token()], [InventoryFixtures::OWNER, (string) Str::uuid(), $issued->token()],
            [InventoryFixtures::OWNER, $issued->authorizationId, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')],
            [InventoryFixtures::OWNER, $issued->authorizationId, 'malformed']] as [$owner, $id, $token]) {
            $this->fails('not_found', fn () => app(RedeemTestDelivery::class)->handle($f['order']->public_id, $owner, $id, $token));
        }
        $this->fails('not_found', fn () => app(RedeemTestDelivery::class)->handle((string) Str::uuid(), InventoryFixtures::OWNER, $issued->authorizationId, $issued->token()));
        $this->assertSame($calls, $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0); $this->assertSame($before, F::retained());
    }

    public function test_existing_order_authorization_and_grant_cannot_be_substituted_into_another_order(): void
    {
        $first = F::ready($this->gateway); $issued = $this->issue($first);
        $this->gateway->onCreate = fn ($params) => \Tests\Support\CheckoutFixtures::session($params, 'cs_test_SECONDDELIVERY');
        $second = PaymentFixtures::started($this->gateway);
        $this->gateway->session['payment_intent'] = 'pi_SECONDDELIVERY'; $this->gateway->payment['id'] = 'pi_SECONDDELIVERY';
        $second = F::activate(ActivationFixtures::issue(ContractFixtures::finalize(\Tests\Support\FinalizationFixtures::confirm($second))));
        $before = F::retained();
        $this->fails('not_found', fn () => app(IssueTestDelivery::class)->handle($second['order']->public_id, InventoryFixtures::OWNER,
            $first['grant']->public_id, 'contract', (string) Str::uuid()));
        $this->fails('not_found', fn () => app(RedeemTestDelivery::class)->handle($second['order']->public_id, InventoryFixtures::OWNER,
            $issued->authorizationId, $issued->token()));
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0); $this->assertSame($before, F::retained());
    }

    public function test_replay_does_not_recover_a_secret_or_spend_budget_and_other_selector_conflicts(): void
    {
        $f = F::ready($this->gateway); $key = (string) Str::uuid(); $issued = $this->issue($f, key: $key); $auth = TestDeliveryAuthorization::sole()->getAttributes();
        $this->fails('already_issued', fn () => $this->issue($f, key: $key)); $this->fails('conflict', fn () => $this->issue($f, 'master_wav', $key));
        $this->assertSame($auth, TestDeliveryAuthorization::sole()->getAttributes()); $this->assertSame([0], $this->streams->transactionLevels);
        $this->assertSame(1, AuditEvent::where('action', 'commerce.delivery.authorized')->count()); $this->redeem($f, $issued)->close();
    }

    public function test_three_authorizations_share_one_rolling_order_budget_across_grants_and_targets(): void
    {
        $f = F::ready($this->gateway, true); $start = now()->toImmutable(); $this->issue($f); $this->issue($f, 'master_wav');
        $other = $f; $other['grant'] = $f['grants'][1]; $this->issue($other);
        $this->fails('budget_exhausted', fn () => $this->issue($other, 'master_wav')); $this->travelTo($start->addSeconds(59));
        $this->fails('budget_exhausted', fn () => $this->issue($f)); $this->assertSame([0, 0, 0], $this->streams->transactionLevels);
        $this->travelTo($start->addSeconds(60)); $this->issue($other); $this->assertDatabaseCount('test_delivery_authorizations', 4);
        $this->assertDatabaseCount('test_delivery_redemptions', 0); $this->assertSame([0, 0, 0, 0], $this->streams->transactionLevels);
    }

    public static function timeBoundaries(): array { return [[59, null], [60, 'expired'], [-1, 'retry']]; }
    #[DataProvider('timeBoundaries')]
    public function test_redemption_checks_exact_expiry_and_clock_rollback(int $seconds, ?string $reason): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $this->travelTo(now()->addSeconds($seconds));
        if ($reason) { $this->fails($reason, fn () => $this->redeem($f, $issued)); $this->assertDatabaseCount('test_delivery_redemptions', 0); $this->assertSame([0], $this->streams->transactionLevels); }
        else { $this->redeem($f, $issued)->close(); $this->assertDatabaseCount('test_delivery_redemptions', 1); }
    }

    public static function postPrepareChanges(): array { return [['clock_back', 'retry'], ['expired', 'expired'], ['disabled', 'unavailable'], ['blocked', 'blocked'], ['source', 'changed']]; }
    #[DataProvider('postPrepareChanges')]
    public function test_changes_after_spooling_prevent_consumption_and_close_private_descriptor(string $change, string $reason): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $before = F::retained();
        $this->streams->afterPrepare = function () use ($change, $f): void {
            match ($change) {
                'clock_back' => $this->travelTo(now()->subSecond()), 'expired' => $this->travelTo(now()->addSeconds(60)),
                'disabled' => config(['delivery.test_access_enabled' => false]),
                'blocked' => app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-BLOCK'),
                'source' => Event::listen('eloquent.retrieved: '.GrantContract::class, function ($record): void { $record->input_hash = str_repeat('0', 64); }),
            };
        };
        $this->fails($reason, fn () => $this->redeem($f, $issued)); $this->assertDatabaseCount('test_delivery_redemptions', 0);
        $this->assertSame(0, AuditEvent::where('action', 'commerce.delivery.redeemed')->count()); $this->assertFalse(is_resource($this->streams->resources[1]));
        $this->assertSame($before, F::retained());
    }

    public static function issuancePreflightChanges(): array { return [['clock_back', 'retry'], ['disabled', 'unavailable'], ['blocked', 'blocked'], ['source', 'changed']]; }
    #[DataProvider('issuancePreflightChanges')]
    public function test_issuance_rechecks_control_source_and_policy_after_private_preflight(string $change, string $reason): void
    {
        $f = F::ready($this->gateway); $before = F::retained();
        $this->streams->afterPrepare = function () use ($change, $f): void {
            match ($change) {
                'clock_back' => $this->travelTo(now()->subSecond()), 'disabled' => config(['delivery.test_access_enabled' => false]),
                'blocked' => app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-BLOCK'),
                'source' => Event::listen('eloquent.retrieved: '.GrantContract::class, function ($record): void { $record->input_hash = str_repeat('0', 64); }),
            };
        };
        $this->fails($reason, fn () => $this->issue($f)); $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertSame(0, AuditEvent::where('action', 'commerce.delivery.authorized')->count()); $this->assertFalse(is_resource($this->streams->resources[0]));
        $this->assertSame($before, F::retained());
    }

    public static function auditChanges(): array
    {
        $cases = []; foreach (['issue', 'redeem'] as $operation) { foreach (['expiry' => 'expired', 'clock_back' => 'retry', 'disabled' => 'unavailable', 'failure' => 'runtime'] as $change => $reason) { $cases[] = [$operation, $change, $reason]; } } return $cases;
    }
    #[DataProvider('auditChanges')]
    public function test_final_audit_boundary_rolls_back_proof_and_audit_together(string $operation, string $change, string $reason): void
    {
        $f = F::ready($this->gateway); $issued = $operation === 'redeem' ? $this->issue($f) : null; $before = F::retained();
        $auditCount = AuditEvent::count(); $armed = true;
        Event::listen('eloquent.created: '.AuditEvent::class, function ($event) use ($operation, $change, &$armed): void {
            if (! $armed || $event->action !== 'commerce.delivery.'.($operation === 'issue' ? 'authorized' : 'redeemed')) { return; }
            $armed = false;
            match ($change) { 'expiry' => $this->travelTo(now()->addSeconds(60)), 'clock_back' => $this->travelTo(now()->subSecond()),
                'disabled' => config(['delivery.test_access_enabled' => false]), 'failure' => throw new RuntimeException('SYNTHETIC-DELIVERY-AUDIT-FAILURE') };
        });
        $action = fn () => $operation === 'issue' ? $this->issue($f) : $this->redeem($f, $issued);
        if ($reason === 'runtime') { try { $action(); $this->fail('Audit failure was ignored.'); } catch (RuntimeException $error) { $this->assertSame('SYNTHETIC-DELIVERY-AUDIT-FAILURE', $error->getMessage()); } }
        else { $this->fails($reason, $action); }
        $this->assertFalse($armed); $this->assertDatabaseCount('test_delivery_authorizations', $operation === 'issue' ? 0 : 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0); $this->assertSame($auditCount, AuditEvent::count()); $this->assertSame($before, F::retained());
        foreach ($this->streams->resources as $resource) { $this->assertFalse(is_resource($resource)); }
    }

    public function test_missing_corrupt_files_require_exact_restore_and_unselected_file_loss_does_not_change_target(): void
    {
        $f = F::ready($this->gateway); $contract = GrantContract::sole(); $path = Storage::disk('local')->path($contract->storage_path);
        $bytes = file_get_contents($path); $mode = fileperms($path) & 0777; unlink($path);
        $this->fails('target_unavailable', fn () => $this->issue($f)); $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $media = $this->issue($f, 'master_wav'); $this->redeem($f, $media)->close();
        file_put_contents($path, $bytes); chmod($path, $mode); $issued = $this->issue($f);
        $changed = $bytes; $changed[12] = $changed[12] === 'X' ? 'Y' : 'X'; chmod($path, 0600); file_put_contents($path, $changed); chmod($path, $mode);
        $this->fails('target_unavailable', fn () => $this->redeem($f, $issued)); $this->assertDatabaseCount('test_delivery_redemptions', 1);
        chmod($path, 0600); file_put_contents($path, $bytes); chmod($path, $mode); $stream = $this->redeem($f, $issued);
        $this->assertSame($bytes, stream_get_contents($stream->stream())); $stream->close(); $this->assertDatabaseCount('test_delivery_redemptions', 2);
    }

    public function test_controls_start_blocked_require_expected_version_and_invalidate_old_tokens_forever(): void
    {
        $f = F::ready($this->gateway, enable: false); $manage = app(ManageTestDeliveryControl::class);
        $this->fails('blocked', fn () => $this->issue($f)); $this->fails('blocked', fn () => $manage->handle($f['order']->public_id, false, 0, 'SYNTHETIC-ENABLE'));
        $this->assertDatabaseCount('test_delivery_controls', 0); $control = $manage->handle($f['order']->public_id, true, 0, 'SYNTHETIC-PROVISION');
        $this->assertTrue($control->blocked); $this->assertSame(0, $control->control_version); $this->fails('blocked', fn () => $this->issue($f));
        $manage->handle($f['order']->public_id, false, 0, 'SYNTHETIC-ENABLE'); $issued = $this->issue($f); $audits = AuditEvent::count();
        $this->assertSame(1, $manage->handle($f['order']->public_id, false, 1, 'SYNTHETIC-IDEMPOTENT')->control_version); $this->assertSame($audits, AuditEvent::count());
        $this->fails('conflict', fn () => $manage->handle($f['order']->public_id, true, 0, 'SYNTHETIC-STALE'));
        config(['delivery.test_access_enabled' => false]); $this->assertSame(2, $manage->handle($f['order']->public_id, true, 1, 'SYNTHETIC-BLOCK')->control_version);
        $this->fails('unavailable', fn () => $manage->handle($f['order']->public_id, false, 2, 'SYNTHETIC-ENABLE'));
        config(['delivery.test_access_enabled' => true]); $this->assertSame(3, $manage->handle($f['order']->public_id, false, 2, 'SYNTHETIC-REENABLE')->control_version);
        $this->fails('blocked', fn () => $this->redeem($f, $issued)); $new = $this->issue($f); $this->redeem($f, $new)->close();
        $this->assertSame(1, TestDeliveryAuthorization::where('public_id', $issued->authorizationId)->sole()->control_version);
    }

    public static function deniedPolicies(): array { return [['disabled'], ['string_flag'], ['policy'], ['live'], ['foreign_account'], ['production']]; }
    #[DataProvider('deniedPolicies')]
    public function test_current_policy_and_account_denial_has_no_effect_or_file_access(string $scenario): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $before = F::retained(); $environment = $this->app->environment();
        match ($scenario) { 'disabled' => config(['delivery.test_access_enabled' => false]), 'string_flag' => config(['delivery.test_access_enabled' => 'true']),
            'policy' => config(['delivery.test_access_policy' => json_encode(F::policy() + ['extra' => true])]), 'live' => config(['payments.stripe.mode' => 'live']),
            'foreign_account' => config(['payments.stripe.account_id' => 'acct_FOREIGN']), 'production' => $this->app->instance('env', 'production') };
        try { $this->fails('unavailable', fn () => $this->issue($f)); $this->fails('unavailable', fn () => $this->redeem($f, $issued)); }
        finally { $this->app->instance('env', $environment); }
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0); $this->assertSame($before, F::retained());
    }

    public function test_missing_activation_does_not_implicitly_activate_or_issue_control(): void
    {
        $f = ActivationFixtures::issued($this->gateway); $before = F::retained(); $this->fails('blocked', fn () => $this->issue($f));
        $this->assertDatabaseCount('test_delivery_authorizations', 0); $this->assertDatabaseCount('test_delivery_controls', 0);
        $this->assertSame([], $this->streams->transactionLevels); $this->assertSame($before, F::retained());
    }

    public function test_historical_reader_survives_flag_withdrawal_file_loss_expiry_and_control_changes_without_consuming(): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $auth = TestDeliveryAuthorization::sole()->getAttributes();
        app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-BLOCK');
        config(['delivery.test_access_enabled' => false, 'delivery.test_access_policy' => null]); $this->travelTo(now()->addDay());
        $contract = GrantContract::sole(); unlink(Storage::disk('local')->path($contract->storage_path));
        $read = app(ReadTestDeliveryAuthorization::class)->forOwner($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token());
        $this->assertSame($auth, $read['authorization']->getAttributes()); $this->assertNull($read['redemption']);
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public static function proofKinds(): array { return [['authorization'], ['redemption']]; }
    #[DataProvider('proofKinds')]
    public function test_reencrypted_changed_evidence_fails_exact_historical_verification_without_repair(string $kind): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); if ($kind === 'redemption') { $this->redeem($f, $issued)->close(); }
        $model = $kind === 'authorization' ? TestDeliveryAuthorization::class : TestDeliveryRedemption::class; $original = $model::sole()->getAttributes();
        Event::listen('eloquent.retrieved: '.$model, function ($record): void {
            $payload = json_decode(Crypt::decryptString($record->evidence_ciphertext), true, 128, JSON_THROW_ON_ERROR); $payload['unexpected'] = true;
            $record->evidence_ciphertext = Crypt::encryptString(CanonicalJson::encode($payload)); $record->evidence_hash = hash('sha256', $record->evidence_ciphertext);
        });
        $this->fails('changed', fn () => app(ReadTestDeliveryAuthorization::class)->forOwner($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token()));
        $this->assertSame($original, (array) DB::table((new $model)->getTable())->sole());
    }

    public function test_retained_authorization_cannot_hide_missing_original_metadata(): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $before = F::retained();
        GrantContract::addGlobalScope('synthetic_missing_original', fn ($query) => $query->whereRaw('1 = 0'));
        $this->fails('changed', fn () => app(ReadTestDeliveryAuthorization::class)->forOwner($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token()));
        $this->fails('changed', fn () => $this->redeem($f, $issued)); $this->assertDatabaseCount('test_delivery_redemptions', 0);
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertSame($before, F::retained());
    }

    public static function mismatchedPreparedProofs(): array { return [['issue', 'hash'], ['issue', 'size'], ['redeem', 'hash'], ['redeem', 'size']]; }
    #[DataProvider('mismatchedPreparedProofs')]
    public function test_mismatched_prepared_proof_never_commits_and_closes_its_descriptor(string $operation, string $field): void
    {
        $f = F::ready($this->gateway); $issued = $operation === 'redeem' ? $this->issue($f) : null;
        $adapter = new class($field) extends PrepareTestDeliveryStream {
            public mixed $descriptor;
            public function __construct(private string $field) {}
            public function handle(array $target): \App\Domain\Delivery\PreparedDeliveryStream
            {
                $this->descriptor = fopen('php://temp', 'w+b');
                return new \App\Domain\Delivery\PreparedDeliveryStream($this->descriptor,
                    $this->field === 'hash' ? str_repeat('0', 64) : $target['sha256'],
                    $this->field === 'size' ? $target['size_bytes'] + 1 : $target['size_bytes']);
            }
        };
        $this->app->instance(PrepareTestDeliveryStream::class, $adapter);
        $this->fails('target_unavailable', fn () => $operation === 'issue' ? $this->issue($f) : $this->redeem($f, $issued));
        $this->assertFalse(is_resource($adapter->descriptor)); $this->assertDatabaseCount('test_delivery_authorizations', $operation === 'issue' ? 0 : 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_open_secondary_transaction_denies_issue_and_redeem_before_private_io(): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f);
        config(['database.connections.delivery_guard' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('delivery_guard'); $connection->beginTransaction();
        try { $this->fails('unavailable', fn () => $this->issue($f)); $this->fails('unavailable', fn () => $this->redeem($f, $issued)); }
        finally { $connection->rollBack(); DB::purge('delivery_guard'); }
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_failed_transport_consumes_only_committed_attempt_and_closes_the_descriptor(): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $stream = $this->redeem($f, $issued);
        try { $stream->writeTo(fn () => throw new RuntimeException('SYNTHETIC-TRANSPORT-FAILURE')); $this->fail('Transport callback did not fail.'); }
        catch (RuntimeException $error) { $this->assertSame('SYNTHETIC-TRANSPORT-FAILURE', $error->getMessage()); }
        $this->assertFalse(is_resource($this->streams->resources[1])); $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->fails('redeemed', fn () => $this->redeem($f, $issued));
    }

    public function test_control_command_reports_only_bounded_public_status_and_requires_valid_reference(): void
    {
        $f = F::ready($this->gateway, enable: false); $reference = 'SYNTHETIC-PRIVATE-REFERENCE';
        $this->assertSame(0, Artisan::call('vasey:control-test-delivery', ['order' => $f['order']->public_id, 'action' => 'block', '--expected-version' => '0', '--reference' => $reference]));
        $output = Artisan::output(); $this->assertStringContainsString(TestDeliveryControl::sole()->public_id, $output); $this->assertStringNotContainsString($reference, $output);
        $this->assertStringNotContainsString(InventoryFixtures::OWNER, $output); $this->assertSame(0, TestDeliveryControl::sole()->control_version);
        $this->assertSame(1, Artisan::call('vasey:control-test-delivery', ['order' => $f['order']->public_id, 'action' => 'enable', '--expected-version' => '0', '--reference' => 'invalid reference']));
        $output = Artisan::output(); $this->assertStringNotContainsString('invalid reference', $output); $this->assertTrue(TestDeliveryControl::sole()->blocked);
    }
}
