<?php

// Private native-test worker: actual server authentication and request capsule, never serialized principal authority.
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Memberships\Production\MembershipHttpBinding;
use App\Http\Membership\MembershipCustomerLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $directory, $worker, $attempts] = $argv;
config(json_decode(file_get_contents($directory.'/configuration.json'), true, 32, JSON_THROW_ON_ERROR));
$authenticated = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
if ($authenticated === null) {
    throw new LogicException('Synthetic native customer authentication refused.');
}
$request = Request::create('http://localhost/production-memberships/periods');
$app->instance('request', $request);
$session = $app['session.store'];
$session->start();
$request->setLaravelSession($session);
Auth::guard('customer')->setUser($authenticated['user']);
$request->setUserResolver(Auth::userResolver());
$session->put('_production_customer_identity', ['binding_digest' => $authenticated['principal']->sessionBindingDigest()]);
$identity = MembershipHttpBinding::forRequest($request);
file_put_contents($directory.'/ready-'.$worker, 'ready');
$deadline = hrtime(true) + 20_000_000_000;
while (! file_exists($directory.'/start') && hrtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($directory.'/start')) {
    throw new LogicException('Native admission start signal missing.');
}
$outcomes = [];
for ($i = 0; $i < (int) $attempts; $i++) {
    try {
        (new MembershipCustomerLimiter)->admit($identity, 'reads');
        $outcomes[] = 200;
    } catch (HttpExceptionInterface $error) {
        $outcomes[] = $error->getStatusCode();
    }
}
// A final sequential worker also proves successful admission has released its cache lock.
$principal = $identity->principal();
$key = 'production-memberships-customer:'.hash('sha256', json_encode([$principal->provenance, $principal->accountId, 'reads'], JSON_THROW_ON_ERROR));
$probe = Cache::store(config('cache.limiter'))->getStore()->lock($key.':admission', 5);
$released = $probe->get();
if ($released) {
    $probe->release();
}
echo json_encode(['outcomes' => $outcomes, 'lock_available_after' => $released], JSON_THROW_ON_ERROR), PHP_EOL;
