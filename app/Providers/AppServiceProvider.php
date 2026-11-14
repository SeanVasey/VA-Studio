<?php

namespace App\Providers;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Contracts\Production\VaseyLicenseV2RendererProcess;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentitySmtpFactory;
use App\Domain\Grants\Free\FreeGrantRendererProcess;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use App\Domain\Grants\PaidTax\PaidTaxRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Inquiries\Notifications\MailInquiryAlertTransport;
use App\Domain\Media\MediaWorkflowBudget;
use App\Domain\Merch\Fulfillment\Printful\HttpPrintfulClient;
use App\Domain\Merch\Fulfillment\Printful\PrintfulClient;
use App\Domain\Services\Invoicing\StripeInvoiceGateway;
use App\Domain\Services\Invoicing\StripeSdkInvoiceGateway;
use App\Http\ProductionCheckout\ProductionCheckoutMount;
use App\Http\Tax\TaxHttpMount;
use App\Models\User;
use App\Support\PhpCliBinary;
use App\Support\PhpCliProcess;
use App\Support\PublicReadMemo;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\Stripe;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (TaxHttpMount::admitted('production-tax-checkout.http_mounted')) {
            $this->app->register(ProductionTaxCheckoutServiceProvider::class);
        }
        // Lane PCC: production checkout's provider binding exists only behind its literal-false composition gate.
        if (ProductionCheckoutMount::admitted()) {
            $this->app->register(ProductionCheckoutServiceProvider::class);
        }
        // FP-013: the inquiry operator alert transport exists only behind its default-off flag and a configured operator.
        MailInquiryAlertTransport::bindWhenConfigured($this->app);
        $this->app->bind(IdentityNoticeTransport::class,
            fn ($app) => IdentitySmtpFactory::make($app, $app['config']));
        $this->app->scoped(MediaWorkflowBudget::class);
        $this->app->bind(ContractRenderer::class,
            IsolatedContractRenderer::class);
        $this->app->bind(PhpCliBinary::class,
            fn ($app) => new PhpCliBinary($app['config']->get('app.php_cli_binary')));
        // The pinned grant renderers spawn PHP_BINARY, which is the FPM daemon outside the CLI; there they get a
        // factory that runs the validated CLI binary instead (PhpCliProcess). In the CLI they are built as before.
        // Family 256 is included now that its HTTP mount exists. The binding is inert while that mount and the family's
        // policy stay off: nothing resolves the renderer unless an admitted render runs. The v2 license child
        // (VaseyLicenseV2RendererProcess) gets the same factory; it renders only recorded v2 selections.
        foreach ([FreeGrantRendererProcess::class, PaidGrantRendererProcess::class, PaidTaxRendererProcess::class, ProductionFreeGrantRendererProcess::class,
            VaseyLicenseV2RendererProcess::class] as $renderer) {
            $this->app->bind($renderer, function ($app) use ($renderer) {
                $binary = $app->make(PhpCliBinary::class);

                return $binary->isCli() ? new $renderer : new $renderer(PhpCliProcess::factory($binary));
            });
        }
        $this->app->bind(StripeCheckoutGateway::class,
            StripeSdkCheckoutGateway::class);
        $this->app->bind(StripePaymentGateway::class,
            StripeSdkCheckoutGateway::class);
        $this->app->bind(StripeFinancialInspectionGateway::class,
            StripeSdkCheckoutGateway::class);
        $this->app->bind(StripeInvoiceGateway::class, StripeSdkInvoiceGateway::class);
        // BL-63: the only Printful transport. Tests bind a fake over it; it refuses without a configured token.
        $this->app->bind(PrintfulClient::class, fn () => new HttpPrintfulClient(
            config('merch.printful.api_key'), config('merch.printful.store_id')));
    }

    public function boot(): void
    {
        // stripe-php 21.3.2 (pinned with API 2026-08-26.dahlia) bundles roots Stripe no longer uses, including emSign ECC
        // Root CA - G3, whose intermediates were compromised. Every Stripe request's CURLOPT_CAINFO comes from this path, so
        // the bundle the SDK adds is the one stripe-php 21.3.3 and 22.0.1 ship (byte-identical copy, see StripeTrustedRootsTest).
        // The SDK sets no CURLOPT_CAPATH, so libcurl also trusts the host's system store (/etc/ssl/certs on Ubuntu): keep the
        // hosts' ca-certificates package current. Remove once the pinned SDK itself is 21.3.3 or later.
        Stripe::setCABundlePath(resource_path('stripe/ca-certificates.crt'));

        // Route files are not executed when a cached route collection is loaded.
        // Keep these IP-only named budgets available on every application boot.
        RateLimiter::for('public-track-embed', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('public-track-embed-audio', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
        // Printful's sender addresses are not published, so the budget is per client address and generous for its retry schedule.
        RateLimiter::for('printful-webhook', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('customer-inquiries', function (Request $request): array {
            $key = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

            return [Limit::perMinute(5)->by($key.':minute'), Limit::perHour(20)->by($key.':hour')];
        });
        Gate::define('administer-catalog', function (User $user, bool $lockForUpdate = false): bool {
            // Only transactional domain callers request a current locking authority read.
            if ($lockForUpdate && DB::transactionLevel() === 0) {
                return false;
            }
            // Editors and background callers may retain a model after its authority changes.
            $current = $user->exists ? ($lockForUpdate
                ? User::query()->lockForUpdate()->find($user->getKey())
                : User::find($user->getKey())) : null;

            return $current !== null && $current->is_admin && $current->email_verified_at !== null;
        });
        // A write in the same request empties the public read memo, so a read after it always reaches the database.
        Event::listen(QueryExecuted::class, [PublicReadMemo::class, 'observe']);
        Event::listen([Login::class, Logout::class], function (): void {
            // Invalidate old quote ownership even if no quote route was visited
            // between signing in and signing out.
            if (request()->hasSession()) {
                request()->session()->forget(['_quote_owner', '_inquiry_owner']);
            }
        });
    }
}
