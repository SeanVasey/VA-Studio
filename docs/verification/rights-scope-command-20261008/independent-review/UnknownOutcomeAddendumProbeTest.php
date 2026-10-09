<?php

namespace Tests\IndependentReview;

use App\Console\Commands\ManageRightsScopes;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Support\Audit\AuditEvent;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Explicit review-only probes; no enclosing transaction can hide a real commit. */
class UnknownOutcomeAddendumProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['rights_scopes', 'rights_scope_offers', 'audit_events']);
    }

    private function runCommand(array $arguments, ?BufferedOutput $buffer = null): array
    {
        $command = app(ManageRightsScopes::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput($arguments, $command->getDefinition());
        $input->setInteractive(true);
        $buffer ??= new BufferedOutput;
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [$input, $buffer]);
        $output->shouldReceive('askQuestion')->times($arguments['action'] === 'list' ? 0 : 1)->andReturn('password');

        return [$command->run($input, $output), $buffer->fetch()];
    }

    private function assertUnconfirmed(array $result, string $reference): void
    {
        [$status, $text] = $result;
        $this->assertSame(1, $status);
        $this->assertStringContainsString('The result is unconfirmed.', $text);
        $this->assertStringContainsString('exact original request', $text);
        $this->assertStringNotContainsString('No changes were made', $text);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC-INTERRUPTION', $text);
        $this->assertStringNotContainsString($reference, $text);
    }

    public function test_post_commit_interruptions_for_register_and_link_retain_each_real_effect_and_exact_retry_creates_no_duplicate(): void
    {
        $fixture = QuoteFixtures::selection();
        $this->assertSame(0, DB::transactionLevel());
        $armed = false;
        $levels = [];
        Event::listen(TransactionCommitted::class, function () use (&$armed, &$levels): void {
            if ($armed) {
                $armed = false;
                $levels[] = DB::transactionLevel();
                throw new RuntimeException('PRIVATE-SYNTHETIC-INTERRUPTION-AFTER-COMMIT');
            }
        });

        foreach (['register', 'link'] as $action) {
            $arguments = ['action' => $action, '--actor-id' => (string) $fixture['actor']->id,
                '--scope' => 'synthetic-unknown-outcome', '--reference' => 'SYNTHETIC-'.strtoupper($action).'-REFERENCE'];
            if ($action === 'link') {
                $arguments['--revision'] = (string) $fixture['revision']->id;
            }
            $beforeAudits = DB::table('audit_events')->count();
            $armed = true;
            $this->assertUnconfirmed($this->runCommand($arguments), $arguments['--reference']);
            $this->assertFalse($armed, 'The exception must follow a real service commit.');
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('rights_scopes', 1);
            $this->assertDatabaseCount('rights_scope_offers', $action === 'link' ? 1 : 0);
            $this->assertSame($beforeAudits + 1, DB::table('audit_events')->count());
            $retained = $this->evidence();

            [$retryStatus, $retryText] = $this->runCommand($arguments);
            $this->assertSame(0, $retryStatus);
            $this->assertStringContainsString('status=unchanged', $retryText);
            $this->assertStringNotContainsString($arguments['--reference'], $retryText);
            $this->assertSame($retained, $this->evidence());
        }
        $this->assertSame([0, 0], $levels, 'Both exceptions must occur after the PDO commit, outside any transaction.');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.scope_registered')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.offer_linked')->count());
    }

    public function test_pre_commit_audit_failure_keeps_state_unchanged_but_reports_conservative_uncertainty_and_can_be_retried(): void
    {
        $actor = LicenseFixtures::admin();
        $before = $this->evidence();
        $armed = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$armed): void {
            if ($armed && $event->action === 'commerce.inventory.scope_registered') {
                $armed = false;
                throw new RuntimeException('PRIVATE-SYNTHETIC-INTERRUPTION-BEFORE-COMMIT');
            }
        });
        $arguments = ['action' => 'register', '--actor-id' => (string) $actor->id,
            '--scope' => 'synthetic-audit-interruption', '--reference' => 'SYNTHETIC-AUDIT-REFERENCE'];

        $this->assertUnconfirmed($this->runCommand($arguments), $arguments['--reference']);
        $this->assertFalse($armed);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->evidence());
        [$retryStatus, $retryText] = $this->runCommand($arguments);
        $this->assertSame(0, $retryStatus);
        $this->assertStringContainsString('status=registered', $retryText);
        $this->assertDatabaseCount('rights_scopes', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.scope_registered')->count());
    }

    public function test_list_output_failure_keeps_state_unchanged_and_uses_a_read_only_error_without_retry_or_rollback_claims(): void
    {
        app(ManageRightsScope::class)->register('synthetic-listed-scope', 'SYNTHETIC-LIST-REFERENCE', LicenseFixtures::admin());
        $before = $this->evidence();
        $buffer = new class extends BufferedOutput
        {
            protected function doWrite(string $message, bool $newline): void
            {
                if (str_contains($message, 'scope=synthetic-listed-scope')) {
                    throw new RuntimeException('PRIVATE-SYNTHETIC-INTERRUPTION-READ-ONLY');
                }
                parent::doWrite($message, $newline);
            }
        };

        [$status, $text] = $this->runCommand(['action' => 'list'], $buffer);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('Rights scope listing is unavailable.', $text);
        $this->assertStringNotContainsString('unconfirmed', $text);
        $this->assertStringNotContainsString('No changes were made', $text);
        $this->assertStringNotContainsString('original request', $text);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC-INTERRUPTION', $text);
        $this->assertStringNotContainsString('SYNTHETIC-LIST-REFERENCE', $text);
        $this->assertSame($before, $this->evidence());
    }
}
