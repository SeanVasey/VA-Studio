<?php

namespace Tests\Feature;

use App\Console\Commands\ManageRightsScopes;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Media\MalwareScanner;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RightsScopeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PROMPT = 'Staff password (hidden)';

    private const SCOPE_REFERENCE = 'PRIVATE-SCOPE-EVIDENCE-MARKER';

    private const LINK_REFERENCE = 'PRIVATE-LINK-EVIDENCE-MARKER';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        OrderFixtures::configure();
    }

    /** Runs a write action as the given actor, answering the hidden password prompt. */
    private function write(array $arguments, User $actor, string $password = 'password'): PendingCommand
    {
        return $this->artisan('vasey:rights-scope', $arguments + ['--actor-id' => (string) $actor->id])
            ->expectsQuestion(self::PROMPT, $password)
            ->doesntExpectOutputToContain(self::SCOPE_REFERENCE)
            ->doesntExpectOutputToContain(self::LINK_REFERENCE)
            ->doesntExpectOutputToContain($password);
    }

    private function register(User $actor, string $scope = 'synthetic-track-scope', string $reference = self::SCOPE_REFERENCE): PendingCommand
    {
        return $this->write(['action' => 'register', '--scope' => $scope, '--reference' => $reference], $actor);
    }

    private function link(User $actor, int $revisionId, string $scope = 'synthetic-track-scope', string $reference = self::LINK_REFERENCE): PendingCommand
    {
        return $this->write(['action' => 'link', '--scope' => $scope, '--revision' => (string) $revisionId,
            '--reference' => $reference], $actor);
    }

    /** A published, priced, unlinked non-exclusive selection exactly as Studio leaves it today. */
    private function pricedUnlinked(?MalwareScanner $scanner = null): array
    {
        $f = QuoteFixtures::selection(scanner: $scanner);
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);

        return $f + ['quote' => $quote];
    }

    private function prepare(Quote $quote): Order
    {
        return app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['rights_scopes', 'rights_scope_offers', 'audit_events', 'orders']);
    }

    public function test_command_link_turns_an_unlinked_revision_order_failure_into_a_prepared_order(): void
    {
        $this->linkTurnsAnUnlinkedOrderFailureIntoAPreparedOrder();
    }

    public function test_staging_admits_the_same_link_and_order_preparation(): void
    {
        // B2: a staging host is where operators link real offers before the first test purchase. The synthetic
        // fixtures are testing-only, so only the command and order preparation run under staging. Staging accepts
        // only ClamAV scan evidence (ScanEngines), so the fixture media carry a synthetic stand-in for a host scan.
        try {
            $this->linkTurnsAnUnlinkedOrderFailureIntoAPreparedOrder('staging');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    private function linkTurnsAnUnlinkedOrderFailureIntoAPreparedOrder(string $environment = 'testing'): void
    {
        $f = $this->pricedUnlinked($environment === 'testing' ? null : new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'synthetic-host-scan-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
        $this->app['env'] = $environment;
        try {
            $this->prepare($f['quote']);
            $this->fail('An unlinked offer revision prepared an order.');
        } catch (QuoteException $error) {
            $this->assertSame('INVENTORY_SCOPE_UNAVAILABLE', $error->errorCode);
            $this->assertSame(409, $error->status);
        }
        $this->assertDatabaseCount('orders', 0);

        $this->artisan('vasey:rights-scope', ['action' => 'list'])
            ->expectsOutputToContain('unlinked revision='.$f['revision']->id.' offer='.$f['offer']->id.' track='.$f['track']->slug)
            ->assertExitCode(0);

        $this->register($f['actor'])->expectsOutputToContain('scope=synthetic-track-scope id=')->assertExitCode(0);
        $this->assertSame('synthetic-track-scope', DB::table('rights_scopes')->value('scope_key'));
        $this->link($f['actor'], $f['revision']->id)
            ->expectsOutputToContain('revision='.$f['revision']->id.' scope=synthetic-track-scope status=linked')->assertExitCode(0);

        $order = $this->prepare($f['quote']);
        $this->assertTrue($order->exists);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame([$f['actor']->id, $f['actor']->id], DB::table('audit_events')
            ->whereIn('action', ['commerce.inventory.scope_registered', 'commerce.inventory.offer_linked'])
            ->orderBy('id')->pluck('actor_id')->map(fn ($id) => (int) $id)->all());

        $this->artisan('vasey:rights-scope', ['action' => 'list'])
            ->expectsOutputToContain('scope=synthetic-track-scope')
            ->doesntExpectOutputToContain('unlinked revision='.$f['revision']->id)
            ->doesntExpectOutputToContain(self::SCOPE_REFERENCE)->assertExitCode(0);
    }

    public function test_repeat_register_and_link_are_idempotent_without_duplicate_rows_or_audits(): void
    {
        $f = QuoteFixtures::selection();
        $this->register($f['actor'])->expectsOutputToContain('status=registered')->assertExitCode(0);
        $this->register($f['actor'])->expectsOutputToContain('status=unchanged')->assertExitCode(0);
        $this->link($f['actor'], $f['revision']->id)->expectsOutputToContain('status=linked')->assertExitCode(0);
        $this->link($f['actor'], $f['revision']->id)->expectsOutputToContain('status=unchanged')->assertExitCode(0);

        $this->assertDatabaseCount('rights_scopes', 1);
        $this->assertDatabaseCount('rights_scope_offers', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.scope_registered')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.offer_linked')->count());
    }

    public function test_revision_linked_to_another_scope_or_reference_conflicts_without_change(): void
    {
        $f = QuoteFixtures::selection();
        $this->register($f['actor'])->assertExitCode(0);
        $this->register($f['actor'], 'synthetic-other-scope')->assertExitCode(0);
        $this->link($f['actor'], $f['revision']->id)->assertExitCode(0);
        $before = $this->evidence();

        $this->link($f['actor'], $f['revision']->id, 'synthetic-other-scope')
            ->expectsOutputToContain('Refused (INVENTORY_SCOPE_CONFLICT): offer revision '.$f['revision']->id
                .' is already linked to scope synthetic-track-scope.')->assertExitCode(1);
        $this->link($f['actor'], $f['revision']->id, reference: 'PRIVATE-DIFFERENT-LINK-MARKER')
            ->expectsOutputToContain('INVENTORY_SCOPE_CONFLICT')
            ->doesntExpectOutputToContain('PRIVATE-DIFFERENT-LINK-MARKER')->assertExitCode(1);
        $this->register($f['actor'], reference: 'PRIVATE-DIFFERENT-SCOPE-MARKER')
            ->expectsOutputToContain('INVENTORY_SCOPE_CONFLICT')
            ->doesntExpectOutputToContain('PRIVATE-DIFFERENT-SCOPE-MARKER')->assertExitCode(1);

        $this->assertSame($before, $this->evidence());
    }

    public function test_unauthenticated_or_unauthorized_actors_are_refused_without_effect(): void
    {
        $f = QuoteFixtures::selection();
        $customer = User::factory()->create();
        $unverified = LicenseFixtures::admin();
        DB::table('users')->where('id', $unverified->id)->update(['email_verified_at' => null]);
        $before = $this->evidence();

        foreach ([$customer, $unverified] as $actor) {
            $this->register($actor)->expectsOutputToContain('Operator authority was refused')->assertExitCode(1);
        }
        $this->write(['action' => 'register', '--scope' => 'synthetic-track-scope', '--reference' => self::SCOPE_REFERENCE],
            $f['actor'], 'wrong-password')->expectsOutputToContain('Operator authority was refused')->assertExitCode(1);
        $this->artisan('vasey:rights-scope', ['action' => 'register', '--actor-id' => '999999',
            '--scope' => 'synthetic-track-scope', '--reference' => self::SCOPE_REFERENCE])
            ->expectsQuestion(self::PROMPT, 'password')
            ->expectsOutputToContain('Operator authority was refused')->assertExitCode(1);
        $this->artisan('vasey:rights-scope', ['action' => 'register', '--actor-id' => (string) $f['actor']->id,
            '--scope' => 'synthetic-track-scope', '--reference' => self::SCOPE_REFERENCE, '--no-interaction' => true])
            ->expectsOutputToContain('interactive')->assertExitCode(1);

        $this->assertSame($before, $this->evidence());
    }

    public function test_writes_are_refused_outside_local_testing_and_staging_environments(): void
    {
        $f = QuoteFixtures::selection();
        $before = $this->evidence();
        $this->app['env'] = 'production';
        try {
            $this->register($f['actor'])->expectsOutputToContain('INVENTORY_UNAVAILABLE')->assertExitCode(1);
            $this->artisan('vasey:rights-scope', ['action' => 'list'])->expectsOutputToContain('INVENTORY_UNAVAILABLE')->assertExitCode(1);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_invalid_or_ambiguous_input_is_refused_before_authentication(): void
    {
        $f = QuoteFixtures::selection();
        $actor = (string) $f['actor']->id;
        $before = $this->evidence();
        foreach ([
            ['action' => 'delete', '--actor-id' => $actor],
            ['action' => 'register', '--scope' => 'synthetic-track-scope', '--reference' => 'REF'],
            ['action' => 'register', '--actor-id' => 'sean', '--scope' => 'synthetic-track-scope', '--reference' => 'REF'],
            ['action' => 'register', '--actor-id' => '0', '--scope' => 'synthetic-track-scope', '--reference' => 'REF'],
            ['action' => 'register', '--actor-id' => $actor, '--reference' => 'REF'],
            ['action' => 'register', '--actor-id' => $actor, '--scope' => 'synthetic-track-scope'],
            ['action' => 'register', '--actor-id' => $actor, '--scope' => 'synthetic-track-scope', '--reference' => 'REF', '--revision' => '1'],
            ['action' => 'link', '--actor-id' => $actor, '--revision' => (string) $f['revision']->id, '--reference' => 'REF'],
            ['action' => 'link', '--actor-id' => $actor, '--scope' => 'synthetic-track-scope', '--reference' => 'REF'],
            ['action' => 'link', '--actor-id' => $actor, '--scope' => 'synthetic-track-scope', '--revision' => 'abc', '--reference' => 'REF'],
            ['action' => 'link', '--actor-id' => $actor, '--scope' => 'synthetic-track-scope', '--revision' => '-1', '--reference' => 'REF'],
            ['action' => 'list', '--actor-id' => $actor],
            ['action' => 'list', '--scope' => 'synthetic-track-scope'],
        ] as $arguments) {
            $this->artisan('vasey:rights-scope', $arguments)->expectsOutputToContain('Invalid input')->assertExitCode(2);
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_staff_password_prompt_disables_visible_fallback_and_refuses_when_hiding_fails(): void
    {
        $command = app(ManageRightsScopes::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['action' => 'register', '--actor-id' => '1',
            '--scope' => 'synthetic-review-scope', '--reference' => 'SYNTHETIC-NONSECRET-REFERENCE'], $command->getDefinition());
        $input->setInteractive(true);
        $buffer = new BufferedOutput;
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [$input, $buffer]);
        $observed = null;
        $output->shouldReceive('askQuestion')->once()->andReturnUsing(function (Question $question) use (&$observed): void {
            $observed = ['hidden' => $question->isHidden(), 'fallback' => $question->isHiddenFallback()];
            throw new RuntimeException('Unable to hide response.');
        });
        $before = $this->evidence();

        $this->assertSame(1, $command->run($input, $output));
        $this->assertSame(['hidden' => true, 'fallback' => false], $observed,
            'Unsupported hidden input must never fall back to visible password entry.');
        $this->assertSame($before, $this->evidence());
        $text = $buffer->fetch();
        $this->assertStringContainsString('Rights scope management is unavailable. The result is unconfirmed.', $text);
        $this->assertStringNotContainsString('Unable to hide response.', $text);
    }

    public function test_domain_rejected_formats_and_unknown_records_fail_clearly_after_authentication(): void
    {
        $f = QuoteFixtures::selection();
        $before = $this->evidence();
        $this->register($f['actor'], 'Upper Case Scope')->expectsOutputToContain('Invalid input')->assertExitCode(2);
        $this->register($f['actor'], reference: 'has spaces')->expectsOutputToContain('Invalid input')->assertExitCode(2);
        $this->link($f['actor'], $f['revision']->id, 'unregistered-scope')
            ->expectsOutputToContain('Rights scope unregistered-scope is not registered')->assertExitCode(1);
        $this->assertSame($before, $this->evidence());

        $this->register($f['actor'])->assertExitCode(0);
        $before = $this->evidence();
        $this->link($f['actor'], 999999)->expectsOutputToContain('Offer revision 999999 was not found')->assertExitCode(1);
        $this->assertSame($before, $this->evidence());
    }

    public function test_superseded_revision_is_refused_by_the_domain_readiness_check(): void
    {
        $f = QuoteFixtures::selection();
        $this->register($f['actor'])->assertExitCode(0);
        DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false]);
        $before = $this->evidence();
        $this->link($f['actor'], $f['revision']->id)->expectsOutputToContain('SELECTION_CHANGED')->assertExitCode(1);
        $this->assertSame($before, $this->evidence());
    }
}
