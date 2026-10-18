<?php

namespace Tests\Feature;

use App\Console\Commands\TestIdentityLinks;
use App\Domain\Customers\Models\CustomerIdentityChallenge;
use App\Domain\Customers\TestIdentityLinks\TestIdentityLinkRelay;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\Support\CustomerFixtures;
use Tests\Support\CustomerIdentityFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * `vasey:test-identity-links`: the read-only staging relay for captured test identity messages. Admission refuses
 * before any read; a named staff account confirms its password at a hidden prompt (and attests on staging); only
 * verified captures for reserved synthetic addresses are listed or printed; nothing but append-only audit rows is
 * written. Captures are produced by the real identity request service, then tampered with where a case needs it.
 */
class TestIdentityLinkRelayCommandTest extends TestCase
{
    // The identity request service owns a standalone transaction, so no test transaction may wrap it.
    use FinalizationDatabaseMigrations;

    private const OPERATOR = 'relay-operator@example.test';

    private const PASSWORD = 'Relay-operator-password-42';

    private const SYNTHETIC = 'tester-one@example.test';

    private const NON_SYNTHETIC = 'buyer@nonreserved-customer-domain.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        F::configure();
        Mail::fake();
    }

    private function operator(array $attributes = []): User
    {
        return User::factory()->create(['name' => 'Relay Operator', 'email' => self::OPERATOR, 'is_admin' => true,
            'email_verified_at' => now(), 'password' => self::PASSWORD, ...$attributes]);
    }

    private function enroll(User $account): void
    {
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        $account->saveAppAuthenticationSecret($provider->generateSecret());
    }

    /**
     * Runs the command on a real console input whose only stubbed call is the hidden password question, and returns
     * the exit code, the complete output and how many questions were asked.
     *
     * @return array{code: int, text: string, asked: int}
     */
    private function console(array $arguments, ?string $password = self::PASSWORD, bool $interactive = true): array
    {
        $command = app(TestIdentityLinks::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput($arguments + ['--operator' => self::OPERATOR], $command->getDefinition());
        $input->setInteractive($interactive);
        $buffer = new BufferedOutput;
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [$input, $buffer]);
        $asked = 0;
        $output->shouldReceive('askQuestion')->andReturnUsing(function (Question $question) use ($password, &$asked): ?string {
            $asked++;
            $this->assertSame(TestIdentityLinks::PASSWORD_PROMPT, $question->getQuestion());
            $this->assertTrue($question->isHidden());
            $this->assertFalse($question->isHiddenFallback());

            return $password;
        });
        $code = $command->run($input, $output);

        return ['code' => $code, 'text' => $buffer->fetch(), 'asked' => $asked];
    }

    /** Runs the probe under one environment, configured and detected alike unless told otherwise, and always restores testing. */
    private function inEnvironment(string $detected, Closure $probe, ?string $configured = null): void
    {
        $this->app->detectEnvironment(fn (): string => $detected);
        config(['app.env' => $configured ?? $detected]);
        try {
            $probe();
        } finally {
            // The disposable database teardown would ask for confirmation in production.
            $this->app->detectEnvironment(fn (): string => 'testing');
            config(['app.env' => 'testing']);
        }
    }

    private function directory(): string
    {
        return Storage::disk('local')->path('customer-identity-capture');
    }

    private function capturePath(string $id): string
    {
        return $this->directory().'/'.$id.'.json';
    }

    private function capture(string $id): array
    {
        return json_decode(file_get_contents($this->capturePath($id)), true, 2, JSON_THROW_ON_ERROR);
    }

    /** Every row the relay could touch and every capture file's bytes, mode and modification time. */
    private function state(): array
    {
        $rows = [];
        foreach (['users', 'customer_accounts', 'customer_identity_challenges', 'customer_identity_addresses', 'quote_owners'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn (object $row): string => json_encode($row))->sort()->values()->all();
        }
        clearstatcache();
        $files = [];
        foreach (is_dir($this->directory()) ? scandir($this->directory()) : [] as $name) {
            $path = $this->directory().'/'.$name;
            if (! in_array($name, ['.', '..'], true)) {
                $files[$name] = [is_link($path) ? 'link:'.readlink($path) : (is_dir($path) ? 'directory' : hash_file('sha256', $path)),
                    lstat($path)['mtime'], lstat($path)['mode']];
            }
        }

        return compact('rows', 'files');
    }

    /** Text that must never reach the console: storage paths, raw capture JSON, secrets and every captured link or proof. */
    private function assertNothingPrivate(string $text, array $bodies, array $except = []): void
    {
        foreach ([Storage::disk('local')->path(''), 'customer-identity-capture', '.json', '{"', '"testOnly"', 'testOnly',
            (string) config('app.key'), self::PASSWORD] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
        foreach ($bodies as $body) {
            if (! in_array($body['id'], $except, true)) {
                $this->assertStringNotContainsString($body['proof'], $text);
            }
        }
    }

    private function assertRefused(array $result, string $message, int $asked = 0): void
    {
        $this->assertSame(1, $result['code'], $result['text']);
        $text = str_replace(PHP_EOL, ' ', $result['text']);
        $this->assertStringStartsWith('Refused: ', $text);
        $this->assertStringContainsString($message, $text);
        $this->assertStringNotContainsString('Stopped:', $result['text']);
        $this->assertSame($asked, $result['asked']);
    }

    private function audits(string $action): array
    {
        return AuditEvent::where('action', $action)->orderBy('id')->get()->all();
    }

    public function test_production_is_refused_before_any_prompt_or_read(): void
    {
        $this->operator();
        $body = F::request('enroll', self::SYNTHETIC);
        $before = [$this->state(), AuditEvent::count()];
        $this->inEnvironment('production', function () use ($body): void {
            foreach ([['action' => 'list'], ['action' => 'print', 'id' => $body['id']]] as $arguments) {
                $result = $this->console($arguments);
                $this->assertRefused($result, 'The test identity link relay is refused in production.');
                $this->assertStringNotContainsString(self::SYNTHETIC, $result['text']);
                $this->assertNothingPrivate($result['text'], [$body]);
            }
        });
        // A cached or loaded APP_ENV=production refuses even when the running environment (for example --env) says staging.
        $this->inEnvironment('staging', function () use ($body): void {
            $this->assertRefused($this->console(['action' => 'list', '--attest' => true]), 'refused in production');
            $this->assertRefused($this->console(['action' => 'print', 'id' => $body['id'], '--attest' => true]), 'refused in production');
        }, 'production');
        $this->assertSame($before, [$this->state(), AuditEvent::count()]);
    }

    public function test_a_running_environment_that_differs_from_the_configuration_is_refused(): void
    {
        $this->operator();
        F::request('enroll', self::SYNTHETIC);
        $before = [$this->state(), AuditEvent::count()];
        foreach ([['staging', 'testing'], ['testing', 'staging'], ['local', 'staging'], ['staging', 'local']] as [$detected, $configured]) {
            $this->inEnvironment($detected, function (): void {
                $this->assertRefused($this->console(['action' => 'list', '--attest' => true]), 'The configured environment (APP_ENV) and the running environment differ');
            }, $configured);
        }
        $this->assertSame($before, [$this->state(), AuditEvent::count()]);
    }

    public function test_environments_outside_local_testing_and_staging_are_refused(): void
    {
        $this->operator();
        F::request('enroll', self::SYNTHETIC);
        $before = [$this->state(), AuditEvent::count()];
        foreach (['development', 'Staging', 'staging-eu', 'prod', ''] as $environment) {
            $this->inEnvironment($environment, function (): void {
                $this->assertRefused($this->console(['action' => 'list']), 'runs only when APP_ENV is local, testing or staging');
            });
        }
        $this->assertSame($before, [$this->state(), AuditEvent::count()]);
    }

    public function test_the_existing_capture_policy_gates_the_relay_before_any_read(): void
    {
        $this->operator();
        $body = F::request('enroll', self::SYNTHETIC);
        $before = [$this->state(), AuditEvent::count()];
        $variants = [['customer.test_identity_enabled' => false], ['customer.test_identity_enabled' => 'true'], ['customer.test_identity_enabled' => null],
            ['customer.identity_transport' => 'smtp'], ['customer.identity_transport' => null], ['customer.test_accounts_enabled' => false]];
        foreach ($variants as $override) {
            config($override);
            foreach ([['action' => 'list'], ['action' => 'print', 'id' => $body['id']]] as $arguments) {
                $result = $this->console($arguments);
                $this->assertRefused($result, 'Test identity capture is not enabled.');
                $this->assertStringNotContainsString(self::SYNTHETIC, $result['text']);
            }
            F::configure();
        }
        config(['filesystems.disks.local.driver' => 's3']);
        $this->assertRefused($this->console(['action' => 'list']), 'Test identity captures are read only from the private local disk.');
        config(['filesystems.disks.local.driver' => 'local']);
        $this->assertSame($before, [$this->state(), AuditEvent::count()]);
    }

    public function test_operator_identity_requires_a_staff_account_its_password_and_an_interactive_console(): void
    {
        F::request('enroll', self::SYNTHETIC);
        $before = [$this->state(), AuditEvent::count()];
        $this->assertRefused($this->console(['action' => 'list', '--operator' => null]), 'Name the staff account relaying links with --operator=EMAIL.');
        $this->assertRefused($this->console(['action' => 'list']), 'No staff account has the operator email.');
        $operator = $this->operator(['is_admin' => false]);
        $this->assertRefused($this->console(['action' => 'list']), 'The operator account lacks staff authority');
        $operator->forceFill(['is_admin' => true, 'email_verified_at' => null])->save();
        $this->assertRefused($this->console(['action' => 'list']), 'The operator account lacks staff authority');
        $operator->forceFill(['email_verified_at' => now()])->save();
        $before = [$this->state(), AuditEvent::count()];

        $this->assertRefused($this->console(['action' => 'list'], interactive: false), 'The relay requires an interactive trusted console');
        foreach (['wrong-password', '', null, CustomerFixtures::PASSWORD] as $password) {
            $result = $this->console(['action' => 'list'], $password);
            $this->assertRefused($result, 'Operator authority was refused: the staff password was not confirmed. No link was printed and nothing was recorded.', 1);
            $this->assertStringNotContainsString(self::SYNTHETIC, $result['text']);
        }
        $this->assertSame($before, [$this->state(), AuditEvent::count()]);
    }

    public function test_invalid_input_is_refused_before_any_read_including_a_traversal_id(): void
    {
        $this->operator();
        F::request('enroll', self::SYNTHETIC);
        $before = [$this->state(), AuditEvent::count()];
        foreach ([[['action' => 'show'], 'the action must be list or print.'], [['action' => 'list', 'id' => (string) Str::uuid()], 'list takes no message id.'],
            [['action' => 'print'], 'print needs the message id'], [['action' => 'print', 'id' => '../../../../etc/passwd'], 'print needs the message id'],
            [['action' => 'print', 'id' => strtoupper((string) Str::uuid())], 'print needs the message id'],
            [['action' => 'print', 'id' => 'customer-identity-capture/x'], 'print needs the message id']] as [$arguments, $message]) {
            $result = $this->console($arguments);
            $this->assertSame(2, $result['code']);
            $this->assertStringContainsString('Invalid input: '.$message, $result['text']);
            $this->assertStringContainsString('Nothing was read.', $result['text']);
            $this->assertSame(0, $result['asked']);
        }
        $this->assertSame($before, [$this->state(), AuditEvent::count()]);
    }

    public function test_staging_requires_an_enrolled_operator_and_an_attestation_then_lists_and_relays(): void
    {
        $operator = $this->operator();
        $body = F::request('enroll', self::SYNTHETIC);
        $this->inEnvironment('staging', function () use ($operator, $body): void {
            $audits = AuditEvent::count();
            $this->assertRefused($this->console(['action' => 'list', '--attest' => true]), "The operator account does not satisfy the admin panel's MFA rule here.");
            $this->enroll($operator);
            $before = [$this->state(), $audits];
            $result = $this->console(['action' => 'list']);
            $this->assertRefused($result, 'Re-run with --attest to record a console attestation in the audit log before any capture is read.', 1);
            $this->assertStringNotContainsString(self::SYNTHETIC, $result['text']);
            $result = $this->console(['action' => 'print', 'id' => $body['id']]);
            $this->assertRefused($result, 'Re-run with --attest', 1);
            $this->assertStringNotContainsString($body['proof'], $result['text']);
            $this->assertSame($before[1], AuditEvent::count());
            $this->assertRefused($this->console(['action' => 'list', '--attest' => true], 'wrong-password'), 'the staff password was not confirmed', 1);
            $this->assertSame($before[1], AuditEvent::count());

            $result = $this->console(['action' => 'list', '--attest' => true]);
            $this->assertSame(0, $result['code'], $result['text']);
            $this->assertStringContainsString('attestation=recorded audit_event=', $result['text']);
            $this->assertStringContainsString('Environment: staging', $result['text']);
            $this->assertStringContainsString($body['id'], $result['text']);
            $this->assertStringContainsString(self::SYNTHETIC, $result['text']);
            $this->assertNothingPrivate($result['text'], [$body]);

            $result = $this->console(['action' => 'print', 'id' => $body['id'], '--attest' => true]);
            $this->assertSame(0, $result['code'], $result['text']);
            $this->assertStringContainsString('http://localhost/account/access#enroll.'.$body['id'].'.'.$body['proof'], $result['text']);

            $attestations = $this->audits(StaffWriteAuthority::ATTESTATION_ACTION);
            $this->assertCount(2, $attestations);
            $this->assertSame([['test_identity_links.list', 'test-identity-messages'], ['test_identity_links.print', 'test-identity-message:'.$body['id']]],
                array_map(fn (AuditEvent $row): array => [$row->context['action'], $row->context['scope_key']], $attestations));
            foreach ($attestations as $row) {
                $this->assertSame([$operator->id, 'vasey:test-identity-links', 'staging', true, true],
                    [$row->actor_id, $row->context['command'], $row->context['environment'], $row->context['mfa_required'], $row->context['mfa_enrolled']]);
            }
            [$listed] = $this->audits(TestIdentityLinkRelay::LISTED);
            [$relayed] = $this->audits(TestIdentityLinkRelay::RELAYED);
            $this->assertSame($attestations[0]->id, $listed->context['attestation_audit_id']);
            $this->assertSame($attestations[1]->id, $relayed->context['attestation_audit_id']);
            $this->assertTrue($attestations[0]->id < $listed->id && $attestations[1]->id < $relayed->id, 'Each attestation precedes its read.');
            $this->assertSame($before[0], $this->state());
        });
    }

    public function test_the_list_shows_only_verified_synthetic_messages_without_paths_raw_json_or_links(): void
    {
        $operator = $this->operator();
        $enroll = F::request('enroll', self::SYNTHETIC);
        $f = CustomerFixtures::account(['email' => 'retained@example.invalid']);
        $this->travel(5)->seconds();
        $recover = F::request('recover', 'retained@example.invalid');
        $foreign = F::request('enroll', self::NON_SYNTHETIC);
        $before = [$this->state(), AuditEvent::count()];

        $result = $this->console(['action' => 'list']);
        $this->assertSame(0, $result['code'], $result['text']);
        $text = $result['text'];
        $this->assertStringContainsString('TEST ONLY. Environment: testing.', $text);
        foreach ([[$enroll, self::SYNTHETIC, 'create account'], [$recover, 'retained@example.invalid', 'reset password']] as [$body, $email, $kind]) {
            $challenge = CustomerIdentityChallenge::where('public_id', $body['id'])->sole();
            $this->assertMatchesRegularExpression('/\|\s*'.preg_quote($body['id'], '/').'\s*\|\s*'.preg_quote($email, '/').'\s*\|\s*'.$kind
                .'\s*\|\s*'.preg_quote($challenge->created_at->utc()->format('Y-m-d H:i:s'), '/')
                .'\s*\|\s*'.preg_quote($challenge->expires_at->utc()->format('Y-m-d H:i:s'), '/').'\s*\|\s*pending\s*\|/', $text);
        }
        $this->assertLessThan(strpos($text, $enroll['id']), strpos($text, $recover['id']), 'Newest first.');
        $this->assertStringNotContainsString(self::NON_SYNTHETIC, $text);
        $this->assertStringNotContainsString($foreign['id'], $text);
        $this->assertStringNotContainsString('/account/access', $text);
        $this->assertStringContainsString('1 capture(s) withheld: not a verified message for a synthetic test address', $text);
        $this->assertNothingPrivate($text, [$enroll, $recover, $foreign]);

        $this->assertSame($before[0], $this->state());
        $this->assertSame($before[1] + 1, AuditEvent::count());
        [$listed] = $this->audits(TestIdentityLinkRelay::LISTED);
        $this->assertSame([$operator->id, User::class, $operator->id], [$listed->actor_id, $listed->subject_type, $listed->subject_id]);
        $this->assertSame(['schema_version' => 1, 'command' => 'vasey:test-identity-links', 'shown' => 2, 'pending' => 2, 'withheld' => 1,
            'test_only' => true, 'environment' => 'testing', 'attestation_audit_id' => null], $listed->context);
        $this->assertStringContainsString('listing audit_event='.$listed->id, $text);
        $this->assertSame($f['account']->id, CustomerIdentityChallenge::where('public_id', $recover['id'])->value('account_id'));
    }

    public function test_print_relays_one_selected_pending_link_without_consuming_or_changing_anything(): void
    {
        $operator = $this->operator();
        $enroll = F::request('enroll', self::SYNTHETIC);
        $other = F::request('enroll', 'tester-two@example.com');
        $before = [$this->state(), AuditEvent::count()];

        $result = $this->console(['action' => 'print', 'id' => $enroll['id']]);
        $this->assertSame(0, $result['code'], $result['text']);
        $link = 'http://localhost/account/access#enroll.'.$enroll['id'].'.'.$enroll['proof'];
        $this->assertSame(1, substr_count($result['text'], $link));
        $this->assertContains($link, explode(PHP_EOL, $result['text']), 'The link is on a line of its own.');
        $this->assertStringContainsString('Relay this create account link only to the tester who requested it for '.self::SYNTHETIC, $result['text']);
        $this->assertNothingPrivate($result['text'], [$enroll, $other], [$enroll['id']]);
        $this->assertStringNotContainsString($other['id'], $result['text']);

        // Read-only: no capture, challenge, account or user changed; only the relay's audit row was appended.
        $this->assertSame($before[0], $this->state());
        $this->assertSame($before[1] + 1, AuditEvent::count());
        [$relayed] = $this->audits(TestIdentityLinkRelay::RELAYED);
        $challenge = CustomerIdentityChallenge::where('public_id', $enroll['id'])->sole();
        $this->assertSame([$operator->id, CustomerIdentityChallenge::class, $challenge->id], [$relayed->actor_id, $relayed->subject_type, $relayed->subject_id]);
        $this->assertSame(['schema_version' => 1, 'command' => 'vasey:test-identity-links', 'challenge_id' => $enroll['id'], 'purpose' => 'enroll',
            'policy_version' => 'customer-local-identity-v1', 'account_id' => null, 'expires_at' => $challenge->expires_at->toIso8601String(),
            'test_only' => true, 'environment' => 'testing', 'attestation_audit_id' => null], $relayed->context);
        $evidence = json_encode($relayed->getAttributes());
        foreach ([$enroll['proof'], self::SYNTHETIC, '/account/access'] as $private) {
            $this->assertStringNotContainsString($private, $evidence);
        }
        $this->assertStringContainsString('relay audit_event='.$relayed->id, $result['text']);

        // The relayed link still works exactly once for the tester: the relay neither consumed nor re-signed it.
        F::complete($enroll);
        $this->assertSame('completed', CustomerIdentityChallenge::where('public_id', $enroll['id'])->value('state'));
        $this->assertSame([self::SYNTHETIC], User::where('is_admin', false)->pluck('email')->all());
    }

    public function test_expired_and_used_messages_are_listed_as_such_and_refused_for_printing(): void
    {
        $this->operator();
        $used = F::request('enroll', self::SYNTHETIC);
        F::complete($used);
        $expired = F::request('enroll', 'tester-late@example.test');
        $this->travel(601)->seconds();
        $fresh = F::request('enroll', 'tester-fresh@example.test');
        $before = [$this->state(), AuditEvent::count()];

        $result = $this->console(['action' => 'list']);
        $this->assertSame(0, $result['code'], $result['text']);
        $this->assertMatchesRegularExpression('/'.preg_quote($used['id'], '/').'.*\|\s*used\s*\|/', $result['text']);
        $this->assertMatchesRegularExpression('/'.preg_quote($expired['id'], '/').'.*\|\s*expired\s*\|/', $result['text']);
        $this->assertMatchesRegularExpression('/'.preg_quote($fresh['id'], '/').'.*\|\s*pending\s*\|/', $result['text']);
        $this->assertSame(['shown' => 3, 'pending' => 1, 'withheld' => 0],
            array_intersect_key($this->audits(TestIdentityLinkRelay::LISTED)[0]->context, array_flip(['shown', 'pending', 'withheld'])));

        $result = $this->console(['action' => 'print', 'id' => $used['id']]);
        $this->assertRefused($result, 'That link was already used at ', 1);
        $this->assertStringNotContainsString($used['proof'], $result['text']);
        $expiry = CustomerIdentityChallenge::where('public_id', $expired['id'])->sole()->expires_at->utc()->format('Y-m-d H:i:s');
        $result = $this->console(['action' => 'print', 'id' => $expired['id']]);
        $this->assertRefused($result, 'That link expired at '.$expiry.' UTC.', 1);
        $this->assertStringNotContainsString($expired['proof'], $result['text']);
        // A pending link that expires between listing and printing is refused too.
        $this->travel(601)->seconds();
        $result = $this->console(['action' => 'print', 'id' => $fresh['id']]);
        $this->assertRefused($result, 'That link expired at ', 1);
        $this->assertStringNotContainsString($fresh['proof'], $result['text']);

        $this->assertSame([], $this->audits(TestIdentityLinkRelay::RELAYED));
        $this->assertSame($before[0], $this->state());
        $this->assertSame($before[1] + 1, AuditEvent::count());
    }

    public function test_a_non_synthetic_or_staff_recipient_is_never_listed_or_printed_even_with_a_crafted_capture(): void
    {
        $this->operator();
        $genuine = F::request('enroll', 'second-buyer@nonreserved-customer-domain.com');
        $foreign = F::request('enroll', self::NON_SYNTHETIC);
        $staff = F::request('enroll', 'later-staff@example.test');
        User::factory()->create(['email' => 'later-staff@example.test', 'is_admin' => true, 'email_verified_at' => now()]);
        // A crafted capture that claims a synthetic recipient for the non-synthetic challenge, with its genuine proof.
        $crafted = $this->capture($foreign['id']);
        $crafted['to'] = self::SYNTHETIC;
        file_put_contents($this->capturePath($foreign['id']), json_encode($crafted, JSON_THROW_ON_ERROR));
        // A crafted capture for a synthetic address with no retained challenge at all.
        $orphan = (string) Str::uuid();
        file_put_contents($this->capturePath($orphan), json_encode(['testOnly' => true, 'id' => $orphan, 'purpose' => 'enroll', 'to' => self::SYNTHETIC,
            'subject' => 'Your VASEY.AUDIO test account request', 'url' => 'http://localhost/account/access#enroll.'.$orphan.'.'.str_repeat('a', 64),
            'expiresAt' => now()->addMinutes(5)->toISOString()], JSON_THROW_ON_ERROR));
        $before = [$this->state(), AuditEvent::count()];

        $result = $this->console(['action' => 'list']);
        $this->assertSame(0, $result['code'], $result['text']);
        $this->assertStringContainsString('No captured message for a synthetic test address.', $result['text']);
        $this->assertStringContainsString('4 capture(s) withheld', $result['text']);
        foreach ([self::NON_SYNTHETIC, 'second-buyer@', self::SYNTHETIC, 'later-staff@example.test', $genuine['id'], $foreign['id'], $staff['id'], $orphan] as $hidden) {
            $this->assertStringNotContainsString($hidden, $result['text']);
        }
        foreach ([$genuine['id'], $foreign['id'], $staff['id'], $orphan] as $id) {
            $result = $this->console(['action' => 'print', 'id' => $id]);
            $this->assertRefused($result, 'No relayable capture has that id.', 1);
            $this->assertNothingPrivate($result['text'], [$genuine, $foreign, $staff]);
            $this->assertStringNotContainsString('nonreserved-customer-domain', $result['text']);
        }
        $this->assertSame([], $this->audits(TestIdentityLinkRelay::RELAYED));
        $this->assertSame($before[0], $this->state());
    }

    public function test_tampered_capture_json_is_withheld_and_never_printed(): void
    {
        $this->operator();
        $body = F::request('enroll', self::SYNTHETIC);
        $path = $this->capturePath($body['id']);
        $original = file_get_contents($path);
        $valid = $this->capture($body['id']);
        $other = (string) Str::uuid();
        $variants = [
            'invalid json' => '{"testOnly":true,',
            'json scalar' => '"'.$valid['url'].'"',
            'json list' => json_encode(array_values($valid)),
            'extra key' => json_encode($valid + ['extra' => 'x']),
            'missing key' => json_encode(array_diff_key($valid, ['subject' => true])),
            'not test only' => json_encode(['testOnly' => false] + $valid),
            'test only as string' => json_encode(['testOnly' => 'true'] + $valid),
            'id traversal' => json_encode(['id' => '../../../../etc/passwd'] + $valid),
            'id of another message' => json_encode(['id' => $other] + $valid),
            'unknown purpose' => json_encode(['purpose' => 'admin'] + $valid),
            'swapped purpose' => json_encode(['purpose' => 'recover'] + $valid),
            'unnormalized recipient' => json_encode(['to' => strtoupper(self::SYNTHETIC)] + $valid),
            'nested recipient' => json_encode(['to' => ['address' => self::SYNTHETIC]] + $valid),
            'foreign origin' => json_encode(['url' => str_replace('http://localhost', 'https://attacker.invalid', $valid['url'])] + $valid),
            'path traversal in link' => json_encode(['url' => str_replace('/account/access', '/../account/access', $valid['url'])] + $valid),
            'wrong proof' => json_encode(['url' => substr($valid['url'], 0, -64).str_repeat('a', 64)] + $valid),
            'extended expiry' => json_encode(['expiresAt' => now()->addDay()->toISOString()] + $valid),
            'oversized' => $original.str_repeat(' ', 8193),
        ];
        foreach ($variants as $label => $bytes) {
            file_put_contents($path, $bytes);
            $before = [$this->state(), AuditEvent::count()];
            $list = $this->console(['action' => 'list']);
            $this->assertSame(0, $list['code'], $label.': '.$list['text']);
            $this->assertStringContainsString('1 capture(s) withheld', $list['text'], $label);
            $this->assertStringNotContainsString($body['id'], $list['text'], $label);
            $this->assertStringNotContainsString(self::SYNTHETIC, $list['text'], $label);
            $print = $this->console(['action' => 'print', 'id' => $body['id']]);
            $this->assertRefused($print, 'No relayable capture has that id.', 1);
            foreach ([$list['text'], $print['text']] as $text) {
                $this->assertNothingPrivate($text, [$body]);
                $this->assertStringNotContainsString('/etc/passwd', $text, $label);
                $this->assertStringNotContainsString('attacker', $text, $label);
            }
            $this->assertSame($before[0], $this->state(), $label);
            $this->assertSame($before[1] + 1, AuditEvent::count(), $label);
        }
        // The untouched capture is relayable again, so each refusal above came from the tampering alone.
        file_put_contents($path, $original);
        $this->assertSame(0, $this->console(['action' => 'print', 'id' => $body['id']])['code']);
        $this->assertCount(1, $this->audits(TestIdentityLinkRelay::RELAYED));
    }

    public function test_linked_misnamed_and_non_file_captures_refuse_safely(): void
    {
        $this->operator();
        $linked = F::request('enroll', self::SYNTHETIC);
        $plain = F::request('enroll', 'tester-two@example.test');
        $root = rtrim(Storage::disk('local')->path(''), '/');
        mkdir($root.'/elsewhere', 0700);
        // A symbolic link in place of a genuine capture, pointing at its own valid bytes outside the directory.
        rename($this->capturePath($linked['id']), $root.'/elsewhere/'.$linked['id'].'.json');
        $this->assertTrue(symlink($root.'/elsewhere/'.$linked['id'].'.json', $this->capturePath($linked['id'])));
        // A link to another file on the host, a misnamed copy of a genuine capture and a directory with a capture name.
        $this->assertTrue(symlink('/etc/hostname', $this->directory().'/'.Str::uuid().'.json'));
        copy($this->capturePath($plain['id']), $this->directory().'/notes.json');
        mkdir($this->directory().'/'.Str::uuid().'.json');
        $before = [$this->state(), AuditEvent::count()];

        $result = $this->console(['action' => 'list']);
        $this->assertSame(0, $result['code'], $result['text']);
        $this->assertStringContainsString($plain['id'], $result['text']);
        $this->assertStringContainsString('4 capture(s) withheld', $result['text']);
        $this->assertStringNotContainsString($linked['id'], $result['text']);
        $this->assertStringNotContainsString(self::SYNTHETIC, $result['text']);
        $this->assertNothingPrivate($result['text'], [$linked, $plain]);
        $result = $this->console(['action' => 'print', 'id' => $linked['id']]);
        $this->assertRefused($result, 'No relayable capture has that id.', 1);
        $this->assertNothingPrivate($result['text'], [$linked, $plain]);
        $this->assertSame($before[0], $this->state());

        // A linked capture directory refuses the whole relay, even though every capture behind it is genuine.
        unlink($this->capturePath($linked['id']));
        rename($this->directory(), $root.'/moved');
        $this->assertTrue(symlink($root.'/moved', $this->directory()));
        $before = [$this->state(), AuditEvent::count()];
        foreach ([['action' => 'list'], ['action' => 'print', 'id' => $plain['id']]] as $arguments) {
            $result = $this->console($arguments);
            $this->assertRefused($result, 'The private capture directory is a symbolic link or not a directory.', 1);
            $this->assertNothingPrivate($result['text'], [$linked, $plain]);
            $this->assertStringNotContainsString('tester-two@example.test', $result['text']);
        }
        $this->assertSame($before, [$this->state(), AuditEvent::count()]);
    }

    public function test_only_reserved_test_names_count_as_synthetic_addresses(): void
    {
        foreach (['a@example.test', 'a@sub.example.test', 'a@foo.example', 'a@foo.invalid', 'a@foo.localhost', 'a@example.com',
            'a@mail.example.net', 'a@example.org'] as $synthetic) {
            $this->assertTrue(TestIdentityLinkRelay::syntheticAddress($synthetic), $synthetic);
        }
        foreach (['a@vasey.audio', 'a@gmail.com', 'a@test.com', 'a@notexample.com', 'a@example.com.attacker.net', 'a@example.test.attacker.net',
            'a@testexample.org', 'a@example.co', 'a@EXAMPLE.TEST', 'a@test', 'a@[127.0.0.1]', 'example.test', ''] as $other) {
            $this->assertFalse(TestIdentityLinkRelay::syntheticAddress($other), $other);
        }
    }

    public function test_formatter_tags_in_a_tester_chosen_recipient_print_literally(): void
    {
        $this->operator();
        $hidden = F::request('enroll', '"<fg=black;bg=black>hide</>"@example.test');
        $linked = F::request('enroll', '"<href=https://e.vil>x</>"@example.test');

        $list = $this->console(['action' => 'list']);
        $this->assertSame(0, $list['code'], $list['text']);
        $this->assertStringContainsString('"<fg=black;bg=black>hide</>"@example.test', $list['text']);
        $this->assertStringContainsString('"<href=https://e.vil>x</>"@example.test', $list['text']);
        $this->assertStringNotContainsString('"hide"@example.test', $list['text']);

        $print = $this->console(['action' => 'print', 'id' => $linked['id']]);
        $this->assertSame(0, $print['code'], $print['text']);
        $this->assertStringContainsString('requested it for "<href=https://e.vil>x</>"@example.test.', $print['text']);
        $this->assertStringNotContainsString($hidden['id'], $print['text']);
    }

    public function test_no_captures_yet_lists_nothing_and_records_the_listing(): void
    {
        $operator = $this->operator();
        $result = $this->console(['action' => 'list']);
        $this->assertSame(0, $result['code'], $result['text']);
        $this->assertStringContainsString('No captured message for a synthetic test address.', $result['text']);
        $this->assertStringNotContainsString('withheld', $result['text']);
        $this->assertSame([$operator->id], array_map(fn (AuditEvent $row): int => $row->actor_id, $this->audits(TestIdentityLinkRelay::LISTED)));
        $this->assertRefused($this->console(['action' => 'print', 'id' => (string) Str::uuid()]), 'No relayable capture has that id.', 1);
        $this->assertFalse(is_dir($this->directory()), 'The relay creates no directory.');
    }
}
