<?php

namespace Tests\Feature;

use App\Console\Commands\ManageRightsScopes;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RightsScopeCommandUnknownOutcomeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    public static function actions(): array
    {
        return ['register' => ['register'], 'link' => ['link']];
    }

    #[DataProvider('actions')]
    public function test_output_failure_after_a_committed_write_reports_uncertainty_and_exact_retry_is_safe(string $action): void
    {
        $f = QuoteFixtures::selection();
        $scope = 'synthetic-output-failure';
        $arguments = ['action' => $action, '--actor-id' => (string) $f['actor']->id,
            '--scope' => $scope, '--reference' => 'SYNTHETIC-NONSECRET-REFERENCE'];
        if ($action === 'link') {
            $this->artisan('vasey:rights-scope', ['action' => 'register', '--actor-id' => (string) $f['actor']->id,
                '--scope' => $scope, '--reference' => 'SYNTHETIC-SCOPE-REFERENCE'])
                ->expectsQuestion('Staff password (hidden)', 'password')->assertExitCode(0);
            $arguments['--revision'] = (string) $f['revision']->id;
        }
        $this->assertSame(0, DB::transactionLevel());
        $beforeAudits = DB::table('audit_events')->count();
        $command = app(ManageRightsScopes::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput($arguments, $command->getDefinition());
        $input->setInteractive(true);
        $buffer = new class extends BufferedOutput
        {
            protected function doWrite(string $message, bool $newline): void
            {
                if (str_contains($message, 'status=registered') || str_contains($message, 'status=linked')) {
                    throw new RuntimeException('SYNTHETIC-PRIVATE-OUTPUT-FAILURE');
                }
                parent::doWrite($message, $newline);
            }
        };
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [$input, $buffer]);
        $output->shouldReceive('askQuestion')->once()->andReturn('password');

        $this->assertSame(1, $command->run($input, $output));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('rights_scopes', 1);
        $this->assertDatabaseCount('rights_scope_offers', $action === 'link' ? 1 : 0);
        $this->assertSame($beforeAudits + 1, DB::table('audit_events')->count());
        $text = $buffer->fetch();
        $this->assertStringNotContainsString('No changes were made', $text);
        $this->assertStringContainsString('unconfirmed', $text);
        $this->assertStringContainsString('exact original request', $text);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-OUTPUT-FAILURE', $text);
        $this->assertStringNotContainsString($arguments['--reference'], $text);

        $this->artisan('vasey:rights-scope', $arguments)
            ->expectsQuestion('Staff password (hidden)', 'password')
            ->expectsOutputToContain('status=unchanged')->assertExitCode(0);
        $this->assertDatabaseCount('rights_scopes', 1);
        $this->assertDatabaseCount('rights_scope_offers', $action === 'link' ? 1 : 0);
        $this->assertSame($beforeAudits + 1, DB::table('audit_events')->count());
    }
}
