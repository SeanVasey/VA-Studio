<?php

use App\Http\Controllers\EditorialController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\PublicSiteImageController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\TestCheckoutController;
use App\Http\Controllers\TestExceptionResolutionController;
use App\Http\Controllers\TestOwnerDeliveryController;
use App\Http\Middleware\CustomerCommerceAccess;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

require __DIR__.'/production-customer-identity.php';

Route::get('/', [StorefrontController::class, 'index'])->middleware('throttle:120,1')->name('home');
Route::get('/{section}/{slug?}', EditorialController::class)
    ->where('section', 'about|contact|blog|videos')->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:120,1')->name('editorial.show');
Route::get('/tracks/{slug}', [StorefrontController::class, 'index'])->middleware('throttle:120,1')->name('tracks.show');
Route::get('/api/catalog', [StorefrontController::class, 'json'])->middleware('throttle:120,1')->name('catalog.index');
Route::get('/media/{asset}', PublicMediaController::class)->middleware('throttle:240,1')->name('media.public');
// Immutable public files: no session, cookies or Inertia, so a cached response never carries a visitor's state. There is
// no route pattern: the controller checks every name itself and answers each bad one with the same empty no-store 404.
// A shared cache may keep the throttle's X-RateLimit-* counters with a cached image; stale counts are harmless advice. The
// counter is the route's own: unprefixed throttles share one per client, so images would otherwise spend the pages' budget.
Route::get('/site-images/{file}', PublicSiteImageController::class)
    ->withoutMiddleware('web')->middleware('throttle:600,1,site-images')->name('site-images.show');
// JSON quote boundaries retain web sessions/CSRF but do not pass through Inertia,
// whose response negotiation replaces the Cookie Vary header.
Route::withoutMiddleware(HandleInertiaRequests::class)->middleware(CustomerCommerceAccess::class)->group(function (): void {
    Route::post('/contact/inquiries', InquiryController::class)->middleware('throttle:customer-inquiries')->block(20, 5)->name('contact.inquiries');
    Route::get('/tracks/{slug}/offers/{revision}/license', [StorefrontController::class, 'license'])->whereNumber('revision')->middleware('throttle:60,1')->name('tracks.license');
    Route::post('/catalog/selections', [StorefrontController::class, 'selections'])->middleware('throttle:60,1')->name('catalog.selections');
    Route::post('/quotes', [QuoteController::class, 'store'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('quotes.store');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('quotes.show');
    Route::get('/quotes/{quote}/offers/{revision}/license', [QuoteController::class, 'license'])->whereNumber('revision')->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('quotes.license');
    Route::post('/quotes/{quote}/pricing', [QuoteController::class, 'price'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('quotes.price');
    Route::post('/quotes/{quote}/pricing/promotions', [QuoteController::class, 'promotionPrice'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('quotes.promotion-price');
    Route::get('/quotes/{quote}/pricing', [QuoteController::class, 'pricing'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('quotes.pricing');
    Route::get('/quotes/{quote}/order-review', [OrderController::class, 'review'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.review');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('orders.store');
    Route::get('/orders/history', [OrderController::class, 'history'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.history');
    Route::get('/orders/{order}/status', [OrderController::class, 'status'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.status');
    Route::get('/orders/{order}/items', [OrderController::class, 'items'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.items');
    Route::get('/orders/{order}/exception-resolution', TestExceptionResolutionController::class)->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.exception-resolution');
    Route::get('/quotes/{quote}/order', [OrderController::class, 'forQuote'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.for-quote');
    Route::post('/orders/{order}/checkout', [TestCheckoutController::class, 'start'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('orders.checkout');
    Route::get('/orders/{order}/checkout', [TestCheckoutController::class, 'status'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.checkout-status');
    Route::post('/orders/{order}/checkout/reconcile', [TestCheckoutController::class, 'reconcile'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('orders.checkout-reconcile');
    Route::get('/orders/{order}/delivery', [TestOwnerDeliveryController::class, 'show'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('orders.delivery');
    Route::post('/orders/{order}/delivery/authorizations', [TestOwnerDeliveryController::class, 'issue'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('orders.delivery-authorizations');
    Route::post('/orders/{order}/delivery/download', [TestOwnerDeliveryController::class, 'download'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('orders.delivery-download');
});
Route::get('/orders/{order}/checkout/return', [TestCheckoutController::class, 'returned'])->middleware([CustomerCommerceAccess::class, 'throttle:60,1,quotes-read'])->block(120, 10)->name('orders.checkout-return');
Route::post('/checkout', fn () => response()->json([
    'code' => 'COMMERCE_NOT_ENABLED',
    'message' => 'Checkout is being prepared. No payment has been taken.',
], 503))->middleware('throttle:10,1')->name('checkout.store');

require __DIR__.'/customer.php';
require __DIR__.'/services.php';
require __DIR__.'/inquiry-conversations.php';

require __DIR__.'/public-discovery.php';

require __DIR__.'/free-grants.php';
