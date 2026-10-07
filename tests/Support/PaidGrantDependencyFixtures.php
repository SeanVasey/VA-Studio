<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderCommittedReadReceiptV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use App\Providers\ProductionCheckoutServiceProvider;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/** Integrated Composer code is primary. A snapshot is an explicitly selected development fixture only. */
trait PaidGrantDependencyFixtures
{
    private string $paidDependencyRoot;

    protected function preparePaidDependencies(): void
    {
        $this->assertTrue(app()->environment('testing'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame(getenv('DB_DATABASE'), DB::getDatabaseName(), 'The externally selected disposable testing schema is required.');
        }
        $source = 'app/Domain/Commerce/ProductionCheckout/ProductionPaidOrderSourceV1.php';
        $canonical = is_file(base_path($source));
        if ($canonical) {
            $root = base_path();
        } else {
            $root = getenv('VA_PAID_DEVELOPMENT_DEPENDENCY');
            $this->assertTrue(is_string($root) && $root !== '', 'Integrate the producer dependency or explicitly select an attributed development snapshot.');
            $this->assertFileExists($root.'/source-map.json');
            $manifest = json_decode(file_get_contents($root.'/source-map.json'), true, 16, JSON_THROW_ON_ERROR);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $manifest['producer_source'] ?? '');
            $this->assertNotEmpty($manifest['files'] ?? []);
            $exact = true;
            foreach ($manifest['files'] as $file) {
                $exact = $exact && is_file($root.'/'.$file['path']) && $file['sha256'] === hash_file('sha256', $root.'/'.$file['path']);
            }
            $this->assertTrue($exact, 'Development snapshot differs from its attributed source map.');
            app('migrator')->path($root.'/database/migrations');
        }
        $this->paidDependencyRoot = $root;
        foreach ([ProductionPaidOrderSourceV1::class => $source,
            ProductionPaidOrderCommittedReadReceiptV1::class => 'app/Domain/Commerce/ProductionCheckout/ProductionPaidOrderCommittedReadReceiptV1.php',
            IdentityOriginalCommitWitness::class => 'app/Domain/Customers/ProductionIdentity/IdentityOriginalCommitWitness.php',
            ProductionCheckoutJourneyFixture::class => 'tests/Support/ProductionCheckoutJourneyFixture.php'] as $class => $path) {
            $this->assertFileExists($root.'/'.$path);
            $this->assertSame(realpath($root.'/'.$path), (new ReflectionClass($class))->getFileName(),
                $canonical ? 'Canonical Composer source must take priority over a development snapshot.' : 'The development dependency must be explicitly bootstrapped before test discovery.');
        }
        $this->assertFileExists($this->paidDependencyPath('tests/Support/production_identity_smtp_sink.py'));
        app()->register(ProductionCheckoutServiceProvider::class);
    }

    protected function paidDependencyPath(string $relative): string
    {
        return $this->paidDependencyRoot.'/'.$relative;
    }
}
