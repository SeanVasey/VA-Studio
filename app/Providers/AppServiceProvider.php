<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Domain\Commerce\Payments\StripeCheckoutGateway::class,
            \App\Domain\Commerce\Payments\StripeSdkCheckoutGateway::class);
        $this->app->bind(\App\Domain\Commerce\Payments\StripePaymentGateway::class,
            \App\Domain\Commerce\Payments\StripeSdkCheckoutGateway::class);
    }

    public function boot(): void
    {
        Gate::define('administer-catalog', fn (User $user) => $user->is_admin && $user->email_verified_at !== null);
        Event::listen([Login::class, Logout::class], function (): void {
            // Invalidate old quote ownership even if no quote route was visited
            // between signing in and signing out.
            if (request()->hasSession()) {
                request()->session()->forget('_quote_owner');
            }
        });
    }
}
