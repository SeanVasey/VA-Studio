<?php

namespace Tests\Feature\ProductionFeatures;

use App\Http\AccountFeatures\AccountFeatureController;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionAccountFeaturesDefaultMountTest extends TestCase
{
    public function test_shipped_default_has_no_registered_feature_routes_or_identity_queries(): void
    {
        $this->assertFalse(config('production-account-features.http_mounted'));
        $this->assertNull(Route::getRoutes()->getByAction(AccountFeatureController::class.'@listeningRead'));
        $queries = 0;
        Event::listen(QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });
        $test = $this->get('/customer/features/listening', ['Accept' => 'text/html']);
        $test->assertStatus(503)->assertExactJson(['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']);
        $this->assertSame(0, $queries);
        $this->assertFalse($test->headers->has('Location'));
    }
}
