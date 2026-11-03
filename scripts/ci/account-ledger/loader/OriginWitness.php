<?php
declare(strict_types=1);

namespace AccountIntegratedHarness;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Throwable;

/** Observe the real test application's origin after its original setup; no test assertions. */
final class OriginWitness implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new PreparedOrigin);
    }
}

final class PreparedOrigin implements PreparedSubscriber
{
    private int $count = 0;

    public function notify(Prepared $event): void
    {
        $context = Bootstrap::$context;
        $facts = ['candidate_sha' => $context['sha'], 'selected_case' => false, 'prepared_events' => ++$this->count,
            'base_path' => false, 'source_origins' => false, 'source_includes' => false,
            'vendor_binding' => false, 'private_storage' => false, 'representatives' => false];
        try {
            $selected = $context['selection'];
            $test = $event->test();
            Bootstrap::require($test->isTestMethod() && $test->className() === $selected['class']
                && $test->name() === $selected['case_name'] && $test->file() === $context['candidate'].'/'.$selected['test_path']);
            $facts['selected_case'] = true;
            $app = Container::getInstance();
            Bootstrap::require($app instanceof Application && $app->basePath() === $context['candidate']
                && $app->path() === $context['candidate'].'/app' && $app->configPath() === $context['candidate'].'/config'
                && $app->databasePath() === $context['candidate'].'/database' && $app->bootstrapPath() === $context['candidate'].'/bootstrap');
            $facts['base_path'] = true;
            Bootstrap::origins();
            $facts['source_origins'] = $facts['source_includes'] = true;
            Bootstrap::require(realpath($context['candidate'].'/vendor') === $context['vendor']);
            $facts['vendor_binding'] = true;
            Bootstrap::require($app->storagePath() === $context['directory'].'/runtime/storage'
                && $app->make('config')->get('view.compiled') === $context['directory'].'/runtime/storage/framework/views');
            $facts['private_storage'] = true;
            Bootstrap::representatives();
            $facts['representatives'] = true;
        } catch (Throwable) {
            // Closed false facts deny adapter acceptance without exposing native/source exception chains.
        }
        $path = $context['directory'].'/origins.json';
        $bytes = json_encode($facts, JSON_THROW_ON_ERROR)."\n";
        $stream = fopen($path, 'x+b');
        Bootstrap::require(is_resource($stream) && chmod($path, 0600));
        try {
            Bootstrap::require(fwrite($stream, $bytes) === strlen($bytes) && fflush($stream) && fsync($stream));
            rewind($stream);
            Bootstrap::require(stream_get_contents($stream, 65537) === $bytes);
        } finally {
            fclose($stream);
        }
    }
}
