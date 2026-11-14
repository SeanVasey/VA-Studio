<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\Free\FreeGrantDocuments;
use App\Domain\Grants\Free\FreeGrantRecords;
use App\Domain\Grants\Free\FreeGrantRendererProcess;
use Closure;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\Support\OpenBasedirProbe;
use Tests\Support\PhpCliWrapperFixture;
use Tests\TestCase;

/** M-16: outside the CLI, the pinned free renderer's child runs through the validated CLI binary, unchanged otherwise. */
final class FreeGrantCliRendererTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PhpCliWrapperFixture;

    public function test_the_cli_builds_the_pinned_renderer_without_a_process_factory(): void
    {
        $this->assertNull($this->factoryOf(app(FreeGrantRendererProcess::class)));
        $this->simulateFpm(PHP_BINARY);
        $this->assertInstanceOf(Closure::class, $this->factoryOf(app(FreeGrantRendererProcess::class)));
    }

    public function test_outside_the_cli_the_document_renders_through_the_configured_cli_with_the_pinned_arguments(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $accepted = FreeGrantFixtures::accept($f, FreeGrantFixtures::publish($f))['origin'];
        $wrapper = $this->cliWrapper();
        $this->simulateFpm($wrapper);
        $issued = (new FreeGrantDocuments)->issue($accepted['id'], $accepted['originHash'], $f['customer']['principal'], $f['customer']['user']);
        $this->assertSame('complete', $issued['documentStatus']);
        $this->assertSame(1, $issued['renderAttempts']);
        $bytes = (new ContractFiles)->verify(FreeGrantRecords::decode((array) DB::table('free_originals')->sole())['artifact']);
        $this->assertStringStartsWith('%PDF-', $bytes);

        // One validation probe, then the render: the pinned argument vector with only the executable replaced.
        [$probe, $render] = $this->wrapperRuns($wrapper);
        $this->assertSame([$wrapper, '-n', '-r'], array_slice($probe, 0, 3));
        $this->assertSame($wrapper, $render[0]);
        $this->assertSame('-n', $render[1]);
        $this->assertSame(base_path('scripts/render-free-grant.php'), end($render));
        $this->assertContains('disable_functions=curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,exec,passthru,shell_exec,system,popen,proc_open', $render);
        $this->assertCount(2, $this->wrapperRuns($wrapper));
    }

    public function test_outside_the_cli_without_a_configured_cli_the_render_fails_closed_before_any_child(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $accepted = FreeGrantFixtures::accept($f, FreeGrantFixtures::publish($f))['origin'];
        $this->simulateFpm(null);
        try {
            (new FreeGrantDocuments)->issue($accepted['id'], $accepted['originHash'], $f['customer']['principal'], $f['customer']['user']);
            $this->fail('An unconfigured CLI binary cannot render.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('render_failed', $error->reason);
        }
        $this->assertDatabaseCount('free_originals', 0);
        $this->assertSame('failed', DB::table('free_document_work')->value('state'));
    }

    /**
     * Follow-up of #251: each open_basedir directory ends with the separator and a prefix-sharing sibling is unreadable.
     * (PHP 8.4 already checks a component boundary, so the entry-format assertion is what pins the separator.)
     */
    public function test_the_child_open_basedir_admits_no_prefix_sharing_sibling_and_still_renders(): void
    {
        $this->fakePrivateMediaStorage();
        $probe = $workspace = null;
        // A plain closure: an arrow function would capture copies of the probe variables.
        $this->app->instance(FreeGrantRendererProcess::class, new FreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload) use (&$probe, &$workspace): Process {
                $workspace = $environment['TMPDIR'];
                $probe = OpenBasedirProbe::run($command, $cwd, $environment, $payload);

                return new Process($command, $cwd, $environment, $payload, 60);
            }));
        $f = FreeGrantFixtures::source();
        $accepted = FreeGrantFixtures::accept($f, FreeGrantFixtures::publish($f))['origin'];
        $issued = (new FreeGrantDocuments)->issue($accepted['id'], $accepted['originHash'], $f['customer']['principal'], $f['customer']['user']);
        $this->assertSame('complete', $issued['documentStatus']);
        $this->assertSame([false, true, true], [$probe['sibling_read'], $probe['workspace_list'], $probe['app_read']]);
        $entries = explode(PATH_SEPARATOR, $probe['basedir']);
        $this->assertSame([base_path().'/', $workspace.'/'], array_slice($entries, 0, 2));
        foreach ($entries as $entry) {
            $this->assertStringEndsWith('/', $entry);
        }
    }

    private function factoryOf(FreeGrantRendererProcess $renderer): ?Closure
    {
        return (new ReflectionProperty($renderer, 'processFactory'))->getValue($renderer);
    }
}
