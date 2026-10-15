<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\TaxPaidOriginalCommitDispatcher;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\PaidTax\PaidTaxReads;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxRendererProcess;
use App\Http\Tax\PaidTaxRequestContext;
use App\Http\Tax\PaidTaxResponseOwner;
use App\Http\Tax\PaidTaxStreamResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\PaidTaxRootHttpFixture;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/** Real root, loopback enrolled synthetic buyer and retained synthetic Tax payment; no merchant claim. */
final class PaidTaxHttpJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;
    use PaidTaxRootHttpFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->preparePaidTaxFixture();
        config(['paid-tax-grants.downloads_enabled' => true]);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function login(array $buyer): void
    {
        $this->actingAs($buyer['user'], 'customer')->withSession(['_token' => str_repeat('c', 40),
            '_production_customer_identity' => ['binding_digest' => $buyer['principal']->sessionBindingDigest()]]);
    }

    private function postTax(string $path, array $body = [], ?string $csrf = null)
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_CSRF_TOKEN' => $csrf ?? session()->token(), 'REQUEST_TIME_FLOAT' => microtime(true)],
            $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function complete(): array
    {
        $f = $this->paidTaxOrder();
        $this->login($f['buyer']);
        $f['batch'] = $this->postTax('/paid-tax-grants/orders/'.$f['order']['orderId'].'/finalize')->assertOk()->json('origin');
        $f['batch'] = $this->postTax('/paid-tax-grants/origins/'.$f['batch']['id'].'/document')->assertOk()->assertJsonPath('busy', false)->json('origin');

        return $f;
    }

    private function authorization(array $f, string $kind = 'master_wav'): array
    {
        return $this->postTax('/paid-tax-grants/origins/'.$f['batch']['id'].'/lines/'.$f['batch']['lines'][0]['id'].'/authorize',
            ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))])
            ->assertOk()->json('authorization');
    }

    private function redemptionPath(array $f, array $a): string
    {
        return '/paid-tax-grants/origins/'.$f['batch']['id'].'/authorizations/'.$a['id'].'/redeem';
    }

    public function test_actual_root_tax_checkout_then_paid_pdf_and_native_original_delivery(): void
    {
        $catalog = $this->taxCatalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $this->login($buyer);
        $transport = new RecordingTaxCheckoutTransport;
        $this->app->instance(TaxCheckoutTransport::class, $transport);
        $preview = $this->postTax('/production/tax-checkout/previews', ['candidateId' => $catalog['candidate']->id, 'items' => $catalog['items']])->assertOk()->json('checkout');
        $order = $this->postTax('/production/tax-checkout/orders', ['candidateId' => $catalog['candidate']->id, 'items' => $catalog['items'],
            'previewHash' => $preview['previewHash'], 'accepted' => true, 'buyer' => ['legalName' => 'Declared synthetic buyer'], 'requestKey' => 'synthetic-root-tax-http'])->assertOk()->json('checkout');
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $this->postTax('/production/tax-checkout/orders/'.$order['orderId'].'/hosted')->assertOk();
        $transport->paid = true;
        $this->postTax('/production/tax-checkout/orders/'.$order['orderId'].'/reconcile')->assertOk()->assertJsonPath('checkout.paymentStatus', 'verified');
        $batch = $this->postTax('/paid-tax-grants/orders/'.$order['orderId'].'/finalize')->assertOk()->json('origin');
        $this->assertFalse($batch['fulfilled']);
        $batch = $this->postTax('/paid-tax-grants/origins/'.$batch['id'].'/document')->assertOk()->assertJsonPath('busy', false)->json('origin');
        $this->assertTrue($batch['fulfilled']);
        $this->get('/paid-tax-grants/origins/'.$batch['id'])->assertOk()->assertJsonPath('origin.id', $batch['id']);
        $original = (array) DB::table('paid_tax_originals')->sole();
        $artifact = PaidTaxRecords::decode($original)['artifact'];
        $f = compact('batch', 'buyer', 'catalog');
        foreach (['contract', 'master_wav'] as $kind) {
            $a = $this->authorization($f, $kind);
            $path = $kind === 'contract' ? $artifact['storage_path'] : $catalog['media']['master_wav']->storage_path;
            $bytes = file_get_contents(Storage::disk('local')->path($path));
            if ($kind === 'contract') {
                $response = $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertOk();
            } else {
                $raw = http_build_query(['token' => $a['token'], '_token' => session()->token()], '', '&', PHP_QUERY_RFC3986);
                $response = $this->call('POST', $this->redemptionPath($f, $a), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'REQUEST_TIME_FLOAT' => microtime(true)], $raw)->assertOk();
            }
            $response->assertHeader('Content-Type', $a['mimeType'])->assertHeader('Content-Length', (string) strlen($bytes))->assertHeader('X-Paid-Tax-Sha256', hash('sha256', $bytes));
            $this->assertSame($bytes, $response->streamedContent());
            $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertStatus(409);
        }
        $this->get('/paid-tax-grants/origins/'.$batch['id'].'/downloads')->assertOk()->assertJsonPath('status.lines.0.attemptCount', 2)
            ->assertDontSee($a['token'], false)->assertDontSee($path, false);
        $this->assertSame($original, (array) DB::table('paid_tax_originals')->sole());
        $this->assertDatabaseCount('paid_tax_redemptions', 2);
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_real_csrf_comparison_and_rotation_protect_json_and_native_forms_without_spending(): void
    {
        $f = $this->complete();
        $a = $this->authorization($f);
        $old = session()->token();
        $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']], 'wrong')->assertStatus(419)->assertHeaderMissing('Location');
        session()->regenerateToken();
        $fresh = session()->token();
        $this->assertNotSame($old, $fresh);
        session()->save();
        $raw = http_build_query(['token' => $a['token'], '_token' => $old]);
        $this->call('POST', $this->redemptionPath($f, $a), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'REQUEST_TIME_FLOAT' => microtime(true)], $raw)->assertStatus(419);
        $this->assertDatabaseCount('paid_tax_redemptions', 0);
        $raw = http_build_query(['token' => $a['token'], '_token' => $fresh]);
        $response = $this->call('POST', $this->redemptionPath($f, $a), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'REQUEST_TIME_FLOAT' => microtime(true)], $raw)->assertOk();
        $this->assertNotSame('', $response->streamedContent());
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
        $this->assertNull(session()->get('_old_input.token'));
    }

    public function test_never_started_projection_and_transfer_responses_release_the_actual_original_observer(): void
    {
        $f = $this->complete();
        $delegate = DB::connection()->getEventDispatcher();
        $r = $this->get('/paid-tax-grants/origins/'.$f['batch']['id'])->assertOk();
        $this->assertInstanceOf(TaxPaidOriginalCommitDispatcher::class, DB::connection()->getEventDispatcher());
        $actual = $r->baseResponse;
        $this->assertInstanceOf(PaidTaxStreamResponse::class, $actual);
        $ref = \WeakReference::create($actual);
        unset($r, $actual);
        $this->assertNull($ref->get());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $a = $this->authorization($f);
        $r = $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertOk();
        $this->assertInstanceOf(TaxPaidOriginalCommitDispatcher::class, DB::connection()->getEventDispatcher());
        unset($r);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
        $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertStatus(409);
        $this->get('/paid-tax-grants/origins/'.$f['batch']['id'])->assertOk()->assertJsonPath('origin.id', $f['batch']['id']);
    }

    public function test_actual_captured_read_encoding_construction_and_clone_failures_preserve_cleanup_and_original_error(): void
    {
        $f = $this->complete();
        $delegate = DB::connection()->getEventDispatcher();
        foreach (['encoding', 'construction'] as $gap) {
            $owner = new PaidTaxResponseOwner;
            (new PaidTaxReads)->show($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'], $owner->read());
            $this->assertInstanceOf(TaxPaidOriginalCommitDispatcher::class, DB::connection()->getEventDispatcher());
            $original = new \RuntimeException('synthetic-response-header-callback');
            try {
                if ($gap === 'encoding') {
                    PaidTaxStreamResponse::projection(['bad' => "\xff"], $owner);
                } else {
                    $header = new class($original) implements \Stringable
                    {
                        public function __construct(private \RuntimeException $original) {}

                        public function __toString(): string
                        {
                            throw $this->original;
                        }
                    };
                    new PaidTaxStreamResponse($owner, static function (): void {}, ['Cache-Control' => [$header]]);
                }
                $this->fail('The response allocation gap was accepted.');
            } catch (\Throwable $error) {
                if ($gap === 'encoding') {
                    $this->assertInstanceOf(\JsonException::class, $error);
                } else {
                    $this->assertSame($original, $error);
                }
            }
            $this->assertSame($delegate, DB::connection()->getEventDispatcher());
            $this->get('/paid-tax-grants/origins/'.$f['batch']['id'])->assertOk()->assertJsonPath('origin.id', $f['batch']['id']);
        }
        $response = $this->get('/paid-tax-grants/origins/'.$f['batch']['id'])->assertOk();
        $observer = DB::connection()->getEventDispatcher();
        try {
            $copy = clone $response->baseResponse;
            $this->fail('Private response cloned.');
        } catch (\LogicException) {
            $this->assertSame($observer, DB::connection()->getEventDispatcher(), 'A refused copy cannot close the original owner.');
        }
        $response->assertJsonPath('origin.id', $f['batch']['id']);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function lateWithdrawal(): array
    {
        return [['policy'], ['credential'], ['source']];
    }

    #[DataProvider('lateWithdrawal')]
    public function test_late_withdrawal_suppresses_original_projection_bytes_and_releases_observer(string $kind): void
    {
        $f = $this->complete();
        $delegate = DB::connection()->getEventDispatcher();
        $response = $this->get('/paid-tax-grants/origins/'.$f['batch']['id'])->assertOk();
        match ($kind) {
            'policy' => config(['paid-tax-grants.rehearsal_enabled' => false]),
            'credential' => DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('synthetic-changed-credential')]),
            'source' => config(['production-tax-checkout.committed_read_receipts_enabled' => false]),
        };
        $body = $response->streamedContent();
        $this->assertStringNotContainsString($f['batch']['id'], $body);
        $this->assertStringNotContainsString('Declared synthetic buyer', $body);
        $this->assertSame('Paid Tax request unavailable.', json_decode($body, true, flags: JSON_THROW_ON_ERROR)['error']);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame(200, $response->getStatusCode(), 'Already prepared headers differ from late body refusal.');
    }

    public function test_identity_delay_cannot_replace_the_original_request_budget_and_expiry_prevents_attempt(): void
    {
        $f = $this->complete();
        $a = $this->authorization($f);
        $this->app->afterResolving(ProductionCustomerSessions::class, function (): void {
            $request = request();
            $map = (new \ReflectionProperty(PaidTaxRequestContext::class, 'requests'))->getValue();
            $original = $map[$request]['budget'];
            $request->server->set('REQUEST_TIME_FLOAT', microtime(true) + 3600);
            $request->attributes->set('_paid_tax_budget', new \stdClass);
            $original->shortenTo(hrtime(true) - 1);
        });
        $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertStatus(410);
        $this->assertDatabaseCount('paid_tax_redemptions', 0);
    }

    public function test_wrong_batch_wrong_owner_staff_and_current_credential_withdrawal_never_spend(): void
    {
        $f = $this->complete();
        $a = $this->authorization($f);
        $this->postTax('/paid-tax-grants/origins/'.Str::uuid().'/authorizations/'.$a['id'].'/redeem', ['token' => $a['token']])->assertNotFound();
        $other = $this->enrollThroughLocalSmtp('other-tax-http@example.test');
        $this->login($other);
        $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertNotFound();
        Auth::guard('customer')->logout();
        $this->actingAs($f['catalog']['actor'], 'web');
        $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertForbidden();
        $this->login($f['buyer']);
        DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('synthetic-current-withdrawal')]);
        $this->postTax($this->redemptionPath($f, $a), ['token' => $a['token']])->assertForbidden();
        $this->assertDatabaseCount('paid_tax_redemptions', 0);
    }

    public function test_two_line_physical_failure_retains_first_original_then_retries_whole_order(): void
    {
        $f = $this->paidTaxOrder(twoLines: true);
        $this->login($f['buyer']);
        $batch = $this->postTax('/paid-tax-grants/orders/'.$f['order']['orderId'].'/finalize')->assertOk()->json('origin');
        $calls = 0;
        $this->app->instance(PaidTaxRendererProcess::class, new PaidTaxRendererProcess(static function ($command, $directory, $environment, $input) use (&$calls): Process {
            if (++$calls === 2) {
                throw new ContractIssuanceException('render_failed');
            }

            return new Process($command, $directory, $environment, $input, 120);
        }));
        $this->postTax('/paid-tax-grants/origins/'.$batch['id'].'/document')->assertStatus(503);
        $this->assertDatabaseCount('paid_tax_originals', 1);
        $first = (array) DB::table('paid_tax_originals')->sole();
        $this->assertDatabaseCount('paid_tax_authorizations', 0);
        $this->app->instance(PaidTaxRendererProcess::class, new PaidTaxRendererProcess);
        $this->postTax('/paid-tax-grants/origins/'.$batch['id'].'/document')->assertOk()->assertJsonPath('origin.fulfilled', true);
        $this->assertSame($first, (array) DB::table('paid_tax_originals')->where('id', $first['id'])->sole());
        $this->assertDatabaseCount('paid_tax_originals', 2);
    }
}
