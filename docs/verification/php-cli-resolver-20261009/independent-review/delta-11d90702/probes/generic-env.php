<?php
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app()->instance(App\Support\PhpCliBinary::class, new App\Support\PhpCliBinary($argv[2], 'fpm-fcgi'));
require $root.'/tests/Support/ContractRendererFixtures.php';
try { $r = app(App\Domain\Contracts\ContractRenderer::class)->render(Tests\Support\ContractRendererFixtures::input(), App\Domain\Contracts\ContractRenderProfile::current()); echo get_class(app(App\Domain\Contracts\ContractRenderer::class)), " ok ", substr($r->sha256, 0, 12), "\n"; }
catch (App\Domain\Contracts\ContractIssuanceException $e) { echo get_class(app(App\Domain\Contracts\ContractRenderer::class)), " reason=", $e->reason, "\n"; }
