<?php

namespace App\Providers;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentitySmtpFactory;
use App\Domain\Media\MediaWorkflowBudget;
use App\Models\User;
use App\Support\PhpCliBinary;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityNoticeTransport::class,
            fn ($app) => IdentitySmtpFactory::make($app, $app['config']));
        $this->app->scoped(MediaWorkflowBudget::class);
        $this->app->bind(ContractRenderer::class,
            IsolatedContractRenderer::class);
        $this->app->bind(PhpCliBinary::class,
            fn ($app) => new PhpCliBinary($app['config']->get('app.php_cli_binary')));
        $this->app->bind(StripeCheckoutGateway::class,
            StripeSdkCheckoutGateway::class);
        $this->app->bind(StripePaymentGateway::class,
            StripeSdkCheckoutGateway::class);
        $this->app->bind(StripeFinancialInspectionGateway::class,
            StripeSdkCheckoutGateway::class);
    }

    public function boot(): void
    {
        // Route files are not executed when a cached route collection is loaded.
        // Keep these IP-only named budgets available on every application boot.
        RateLimiter::for('public-track-embed', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('public-track-embed-audio', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
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
        Event::listen([Login::class, Logout::class], function (): void {
            // Invalidate old quote ownership even if no quote route was visited
            // between signing in and signing out.
            if (request()->hasSession()) {
                request()->session()->forget(['_quote_owner', '_inquiry_owner']);
            }
        });
    }
}
