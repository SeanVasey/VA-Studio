<?php

use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\ReadGrantContract;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\ContractFixtures as F;
use Tests\Support\PaymentFixtures;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow('2026-10-07T00:00:00Z');
Carbon\CarbonImmutable::setTestNow('2026-10-07T00:00:00Z');
config(['filesystems.disks.local.root' => getenv('VA_REVIEW_STORAGE')]);
Storage::forgetDisk('local');
Http::preventStrayRequests();
Queue::fake();
F::configure();
$gateway = PaymentFixtures::gateway();
$app->instance(StripeCheckoutGateway::class, $gateway);
$app->instance(StripePaymentGateway::class, $gateway);
$app->instance(ContractRenderer::class, new IsolatedContractRenderer);

$snapshot = static function (): array {
    return ['business' => F::retained(), 'migrations' => DB::table('migrations')->orderBy('id')->get()->toArray(),
        'schema' => DB::select("SELECT name, type, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name, type")];
};
$phase = $argv[1];
if ($phase === 'old-create') {
    Assert::assertSame(0, $app->make(Illuminate\Contracts\Console\Kernel::class)->call('migrate:fresh', ['--force' => true]));
    Assert::assertSame('c383bd3ac09164c3fd1a6ac7da8f5460d19e47e3', Composer\InstalledVersions::getReference('tecnickcom/tc-lib-pdf'));
    $paid = F::paid($gateway);
    $profile = ContractRenderProfileRegistry::metadata('test-buyer-pdf-v1');
    $request = ContractRenderRequest::create(['public_id' => (string) Str::uuid(), 'license_grant_id' => $paid['grant']->id,
        'fulfillment_outbox_id' => FulfillmentOutbox::where('license_grant_id', $paid['grant']->id)->sole()->id,
        'input_hash' => $paid['grant']->render_input_hash, 'profile' => $profile, 'profile_hash' => CanonicalJson::hash($profile),
        'canonicalization_version' => CanonicalJson::VERSION, 'document_public_id' => (string) Str::uuid(), 'created_at' => now()]);
    ContractRenderWork::create(['contract_render_request_id' => $request->id, 'state' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $calls = $gateway->calls;
    Assert::assertSame('ready', app(RenderTestContract::class)->handle($request->id));
    $original = app(ReadGrantContract::class)->forRequest($request);
    $bytes = app(ContractFiles::class)->verify($original);
    Assert::assertStringContainsString('8.76.2', $bytes);
    Assert::assertSame($calls, $gateway->calls);
    file_put_contents(getenv('VA_REVIEW_SNAPSHOT'), serialize($snapshot()));
    echo json_encode(['phase' => $phase, 'result' => 'passed', 'real_isolated_v1_render' => true,
        'pdf_sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'profile_hash' => $original->profile_hash,
        'state' => ContractRenderWork::sole()->state, 'migrations' => DB::table('migrations')->count()], JSON_THROW_ON_ERROR), "\n";
} else {
    Assert::assertSame('d417129fad37d49dc9fc0d1e229e9740e1c774a3', Composer\InstalledVersions::getReference('tecnickcom/tc-lib-pdf'));
    $before = unserialize(file_get_contents(getenv('VA_REVIEW_SNAPSHOT')), ['allowed_classes' => ['stdClass']]);
    Assert::assertSame(serialize($before), serialize($snapshot()));
    $request = ContractRenderRequest::sole();
    $original = app(ReadGrantContract::class)->forRequest($request);
    $bytes = app(ContractFiles::class)->verify($original);
    Assert::assertStringContainsString('8.76.2', $bytes);
    Assert::assertSame($request->id, app(RequestTestContract::class)->handle($request->license_grant_id)->id);
    Assert::assertSame('ready', app(RenderTestContract::class)->handle($request->id));
    Assert::assertSame(serialize($before), serialize($snapshot()));
    Assert::assertSame($bytes, app(ContractFiles::class)->verify($original));
    $path = Storage::disk('local')->path($original->storage_path);
    chmod($path, 0600);
    unlink($path);
    Assert::assertSame('original_unavailable', app(RenderTestContract::class)->handle($request->id));
    Assert::assertSame(serialize($before), serialize($snapshot()));
    Assert::assertFileDoesNotExist($path);
    Assert::assertSame('completed', ContractRenderWork::sole()->state);
    Assert::assertSame(1, GrantContract::count());
    Assert::assertSame([], $gateway->calls);
    // Restore only those exact backup bytes; neither worker nor upgrade regenerates.
    file_put_contents($path, $bytes);
    chmod($path, 0400);
    Assert::assertSame('ready', app(RenderTestContract::class)->handle($request->id));
    Assert::assertSame($bytes, app(ContractFiles::class)->verify($original));
    Assert::assertSame(serialize($before), serialize($snapshot()));
    echo json_encode(['phase' => $phase, 'result' => 'passed', 'actual_old_pdf_read_under_new_runtime' => true,
        'missing_original_restore_only' => true, 'exact_backup_restore' => true, 'all_retained_schema_rows_migrations_unchanged' => true,
        'pdf_sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'new_provider_calls' => count($gateway->calls)], JSON_THROW_ON_ERROR), "\n";
}
