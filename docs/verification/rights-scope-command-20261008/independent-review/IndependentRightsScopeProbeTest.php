<?php

namespace Tests\IndependentReview;

use App\Console\Commands\ManageRightsScopes;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Support\Audit\AuditEvent;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Review-only adversarial probes, executed explicitly; not part of the default suite. */
class IndependentRightsScopeProbeTest extends TestCase
{
    use RefreshDatabase;

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

    private function command(array $fixture, string $action, string $reference = 'SYNTHETIC-REVIEW-REFERENCE'): PendingCommand
    {
        $arguments = ['action' => $action, '--actor-id' => (string) $fixture['actor']->id,
            '--scope' => 'synthetic-review-scope', '--reference' => $reference];
        if ($action === 'link') {
            $arguments['--revision'] = (string) $fixture['revision']->id;
        }

        return $this->artisan('vasey:rights-scope', $arguments)
            ->expectsQuestion('Staff password (hidden)', 'password')
            ->doesntExpectOutputToContain($reference);
    }

    public static function authorityWithdrawals(): array
    {
        return [
            'register / role' => ['register', 'is_admin', false],
            'link / role' => ['link', 'is_admin', false],
            'register / verification' => ['register', 'email_verified_at', null],
            'link / verification' => ['link', 'email_verified_at', null],
        ];
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_post_password_authority_withdrawal_is_rechecked_before_the_mutation(string $action, string $field, mixed $value): void
    {
        $fixture = QuoteFixtures::selection();
        if ($action === 'link') {
            app(ManageRightsScope::class)->register('synthetic-review-scope', 'SYNTHETIC-SCOPE', $fixture['actor']);
        }
        $before = $this->evidence();
        $hasher = Hash::getFacadeRoot();
        Hash::partialMock()->shouldReceive('check')->once()->andReturnUsing(function ($password, $hash) use ($fixture, $field, $value, $hasher) {
            $valid = $hasher->check($password, $hash);
            $this->assertTrue($valid, 'The synthetic password must authenticate before authority is withdrawn.');
            DB::table('users')->where('id', $fixture['actor']->id)->update([$field => $value]);

            return $valid;
        });

        $this->command($fixture, $action)->expectsOutputToContain('Operator authority was refused')->assertExitCode(1);
        $this->assertSame($before, $this->evidence());
    }

    public static function writeActions(): array
    {
        return ['register' => ['register'], 'link' => ['link']];
    }

    #[DataProvider('writeActions')]
    public function test_audit_failure_rolls_back_scope_or_link_and_never_prints_private_error_details(string $action): void
    {
        $fixture = QuoteFixtures::selection();
        if ($action === 'link') {
            app(ManageRightsScope::class)->register('synthetic-review-scope', 'SYNTHETIC-SCOPE', $fixture['actor']);
        }
        $before = $this->evidence();
        AuditEvent::creating(function (AuditEvent $event): void {
            if (str_starts_with($event->action, 'commerce.inventory.')) {
                throw new RuntimeException('PRIVATE-SYNTHETIC-ERROR-DETAILS');
            }
        });

        $this->command($fixture, $action)
            ->expectsOutputToContain('Rights scope management is unavailable. No changes were made.')
            ->doesntExpectOutputToContain('PRIVATE-SYNTHETIC-ERROR-DETAILS')->assertExitCode(1);
        $this->assertSame($before, $this->evidence());
    }

    public function test_a_real_successor_revision_refuses_the_old_revision_and_lists_only_the_new_unlinked_revision(): void
    {
        $fixture = QuoteFixtures::selection();
        app(ManageRightsScope::class)->register('synthetic-review-scope', 'SYNTHETIC-SCOPE', $fixture['actor']);
        $draft = app(SaveOfferDraft::class)->handle($fixture['offer'], ['price_minor' => 5000], $fixture['actor']);
        $successor = app(PublishOffer::class)->handle($draft, $fixture['actor']);
        $previousRevisionId = $fixture['revision']->id;
        $this->assertNotSame($fixture['revision']->id, $successor->id);
        $before = $this->evidence();

        $this->command($fixture, 'link')->expectsOutputToContain('SELECTION_CHANGED')->assertExitCode(1);
        $this->assertSame($before, $this->evidence());
        $this->artisan('vasey:rights-scope', ['action' => 'list'])
            ->expectsOutputToContain('unlinked revision='.$successor->id.' offer='.$fixture['offer']->id)
            ->doesntExpectOutputToContain('unlinked revision='.$fixture['revision']->id.' offer=')->assertExitCode(0);
        $fixture['revision'] = $successor;
        $this->command($fixture, 'link')->expectsOutputToContain('status=linked')->assertExitCode(0);
        $this->assertDatabaseHas('rights_scope_offers', ['offer_revision_id' => $successor->id]);
        $this->assertDatabaseMissing('rights_scope_offers', ['offer_revision_id' => $previousRevisionId]);
    }

    public function test_link_reference_format_is_rejected_by_the_domain_without_output_or_state_leakage(): void
    {
        $fixture = QuoteFixtures::selection();
        app(ManageRightsScope::class)->register('synthetic-review-scope', 'SYNTHETIC-SCOPE', $fixture['actor']);
        $before = $this->evidence();

        $this->command($fixture, 'link', 'PRIVATE INVALID REFERENCE')
            ->expectsOutputToContain('Invalid input')->assertExitCode(2);
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
        $this->assertStringContainsString('Rights scope management is unavailable. No changes were made.', $text);
        $this->assertStringNotContainsString('Unable to hide response.', $text);
    }
}
