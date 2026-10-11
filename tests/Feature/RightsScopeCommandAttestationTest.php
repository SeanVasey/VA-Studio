<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/**
 * #68 review condition C2 at the operator console: where the panel requires staff MFA, `vasey:rights-scope`
 * writes proceed only through an explicit `--attest` flag that the command records (who, when, which scope)
 * before the domain write. Local and testing keep today's optional behaviour.
 */
class RightsScopeCommandAttestationTest extends TestCase
{
    use RefreshDatabase;

    /** RefreshDatabase wraps each test in a transaction; refusals must leave that level untouched. */
    private int $restingLevel;

    private const PROMPT = 'Staff password (hidden)';

    private const SCOPE_REFERENCE = 'PRIVATE-SCOPE-EVIDENCE-MARKER';

    private const LINK_REFERENCE = 'PRIVATE-LINK-EVIDENCE-MARKER';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->restingLevel = DB::transactionLevel();
    }

    private function in(string $environment, Closure $probe): mixed
    {
        $this->app['env'] = $environment;
        try {
            return $probe();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /** Runs a write action as the given actor, answering the hidden password prompt; never prints references. */
    private function write(array $arguments, User $actor, bool $attest, string $password = 'password'): PendingCommand
    {
        return $this->artisan('vasey:rights-scope', $arguments + ['--actor-id' => (string) $actor->id] + ($attest ? ['--attest' => true] : []))
            ->expectsQuestion(self::PROMPT, $password)
            ->doesntExpectOutputToContain(self::SCOPE_REFERENCE)
            ->doesntExpectOutputToContain(self::LINK_REFERENCE)
            ->doesntExpectOutputToContain($password);
    }

    private function register(User $actor, bool $attest, string $scope = 'synthetic-track-scope'): PendingCommand
    {
        return $this->write(['action' => 'register', '--scope' => $scope, '--reference' => self::SCOPE_REFERENCE], $actor, $attest);
    }

    private function link(User $actor, bool $attest, int $revisionId, string $scope = 'synthetic-track-scope'): PendingCommand
    {
        return $this->write(['action' => 'link', '--scope' => $scope, '--revision' => (string) $revisionId,
            '--reference' => self::LINK_REFERENCE], $actor, $attest);
    }

    /** Staging accepts only ClamAV scan evidence, so the fixture media carry a synthetic host-scan stand-in. */
    private function selection(): array
    {
        return QuoteFixtures::selection(scanner: new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'synthetic-host-scan-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['rights_scopes', 'rights_scope_offers', 'audit_events']);
    }

    private function attestations(): Collection
    {
        return AuditEvent::where('action', StaffWriteAuthority::ATTESTATION_ACTION)->orderBy('id')->get();
    }

    public function test_staging_refuses_writes_without_an_attestation_and_records_nothing(): void
    {
        $f = $this->selection();
        $unenrolled = LicenseFixtures::admin();
        $before = $this->evidence();

        $this->in('staging', function () use ($f, $unenrolled): void {
            foreach ([$f['actor'], $unenrolled] as $actor) {
                // One expectation per output line: the harness matches each written line against one substring.
                $this->register($actor, false)
                    ->expectsOutputToContain('Re-run with --attest to record your attestation (who, when, which scope) in the audit log before the write. No changes were made.')
                    ->assertExitCode(1);
                $this->link($actor, false, $f['revision']->id)
                    ->expectsOutputToContain('Re-run with --attest to record your attestation (who, when, which scope) in the audit log before the write. No changes were made.')
                    ->assertExitCode(1);
            }
        });

        $this->assertSame($before, $this->evidence());
        $this->assertCount(0, $this->attestations());
    }

    public function test_staging_admits_attested_writes_and_records_who_when_and_which_scope(): void
    {
        $f = $this->selection();
        $unenrolled = LicenseFixtures::admin();

        $this->in('staging', function () use ($f, $unenrolled): void {
            $this->register($f['actor'], true)
                ->expectsOutputToContain('attestation=recorded audit_event=')
                ->expectsOutputToContain('status=registered')
                ->assertExitCode(0);
            $this->link($f['actor'], true, $f['revision']->id)
                ->expectsOutputToContain('attestation=recorded audit_event=')
                ->expectsOutputToContain('revision='.$f['revision']->id.' scope=synthetic-track-scope status=linked')
                ->assertExitCode(0);
            // Repeats are still idempotent; each attempt leaves its own attestation and no duplicate write audit.
            $this->register($f['actor'], true)->expectsOutputToContain('status=unchanged')->assertExitCode(0);
            // An unenrolled administrator may attest at the console too; the record says so.
            $this->register($unenrolled, true, 'synthetic-second-scope')->expectsOutputToContain('status=registered')->assertExitCode(0);
        });

        $attestations = $this->attestations();
        $this->assertCount(4, $attestations);
        $at = now()->utc()->toIso8601String();
        $this->assertSame([
            [$f['actor']->id, 'register', 'synthetic-track-scope', null, true],
            [$f['actor']->id, 'link', 'synthetic-track-scope', $f['revision']->id, true],
            [$f['actor']->id, 'register', 'synthetic-track-scope', null, true],
            [$unenrolled->id, 'register', 'synthetic-second-scope', null, false],
        ], $attestations->map(fn (AuditEvent $row) => [$row->actor_id, $row->context['action'], $row->context['scope_key'],
            $row->context['offer_revision_id'], $row->context['mfa_enrolled']])->all());
        foreach ($attestations as $row) {
            $this->assertSame('vasey:rights-scope', $row->context['command']);
            $this->assertSame('trusted_interactive_console', $row->context['authority']);
            $this->assertSame('staging', $row->context['environment']);
            $this->assertTrue($row->context['mfa_required']);
            $this->assertSame($at, $row->context['attested_at']);
            $this->assertTrue($row->created_at->equalTo(now()));
            $this->assertSame(User::class, $row->subject_type);
            $this->assertSame($row->actor_id, $row->subject_id);
            $this->assertStringNotContainsString(self::SCOPE_REFERENCE, json_encode($row->context));
            $this->assertStringNotContainsString(self::LINK_REFERENCE, json_encode($row->context));
        }

        $writes = AuditEvent::whereIn('action', ['commerce.inventory.scope_registered', 'commerce.inventory.offer_linked'])->orderBy('id')->get();
        $this->assertSame([$attestations[0]->id, $attestations[1]->id, $attestations[3]->id],
            $writes->map(fn (AuditEvent $row) => $row->context['attestation_audit_id'])->all());
        $this->assertDatabaseCount('rights_scopes', 2);
        $this->assertDatabaseCount('rights_scope_offers', 1);
    }

    public function test_an_attestation_is_recorded_only_after_identity_and_environment_are_confirmed(): void
    {
        $f = $this->selection();
        $customer = User::factory()->create();
        $before = $this->evidence();

        $this->in('staging', function () use ($f, $customer): void {
            $this->write(['action' => 'register', '--scope' => 'synthetic-track-scope', '--reference' => self::SCOPE_REFERENCE], $f['actor'], true, 'wrong-password')
                ->expectsOutputToContain('Operator authority was refused')->assertExitCode(1);
            $this->register($customer, true)->expectsOutputToContain('Operator authority was refused')->assertExitCode(1);
            $this->artisan('vasey:rights-scope', ['action' => 'register', '--actor-id' => '999999', '--attest' => true,
                '--scope' => 'synthetic-track-scope', '--reference' => self::SCOPE_REFERENCE])
                ->expectsQuestion(self::PROMPT, 'password')
                ->expectsOutputToContain('Operator authority was refused')->assertExitCode(1);
            $this->artisan('vasey:rights-scope', ['action' => 'register', '--actor-id' => (string) $f['actor']->id, '--attest' => true,
                '--scope' => 'synthetic-track-scope', '--reference' => self::SCOPE_REFERENCE, '--no-interaction' => true])
                ->expectsOutputToContain('interactive')->assertExitCode(1);
        });
        $this->in('production', function () use ($f): void {
            $this->register($f['actor'], true)->expectsOutputToContain('INVENTORY_UNAVAILABLE')->assertExitCode(1);
            $this->register($f['actor'], false)->expectsOutputToContain('INVENTORY_UNAVAILABLE')->assertExitCode(1);
        });

        $this->assertSame($before, $this->evidence());
        $this->assertCount(0, $this->attestations());
    }

    public function test_a_domain_refusal_after_the_attestation_keeps_the_attempt_on_record_without_a_write(): void
    {
        $f = $this->selection();
        $before = $this->evidence();

        $this->in('staging', function () use ($f): void {
            // Codex P2 (r4240117095): the attestation row was written, so the refusal must not say nothing changed.
            $this->link($f['actor'], true, $f['revision']->id, 'unregistered-scope')
                ->expectsOutputToContain('Rights scope unregistered-scope is not registered. Register it first. '
                    .'No rights-scope write occurred; the attestation audit record (audit_event=')
                ->doesntExpectOutputToContain('No changes were made')->assertExitCode(1);
            $this->register($f['actor'], true)->assertExitCode(0);
            DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false]);
            $this->link($f['actor'], true, $f['revision']->id)
                ->expectsOutputToContain('Refused (SELECTION_CHANGED): the revision is not the current revision of an active offer on a published track '
                    .'that passes publication readiness. No rights-scope write occurred; the attestation audit record (audit_event=')
                ->doesntExpectOutputToContain('No changes were made')->assertExitCode(1);
        });

        $this->assertCount(3, $this->attestations());
        $this->assertSame(['link', 'register', 'link'], $this->attestations()->map(fn (AuditEvent $row) => $row->context['action'])->all());
        $this->assertSame(json_decode($before[1], true), json_decode($this->evidence()[1], true), 'No link was created.');
        $this->assertDatabaseCount('rights_scopes', 1);
    }

    public function test_a_refusal_without_an_attestation_keeps_the_original_wording(): void
    {
        $f = $this->selection();
        $before = $this->evidence();

        $this->link($f['actor'], false, $f['revision']->id, 'unregistered-scope')
            ->expectsOutputToContain('Rights scope unregistered-scope is not registered. Register it first. No changes were made.')
            ->doesntExpectOutputToContain('attestation')->assertExitCode(1);
        $this->register($f['actor'], false)->assertExitCode(0);
        DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false]);
        $this->link($f['actor'], false, $f['revision']->id)
            ->expectsOutputToContain('Refused (SELECTION_CHANGED): the revision is not the current revision of an active offer on a published track '
                .'that passes publication readiness. No changes were made.')
            ->doesntExpectOutputToContain('attestation')->assertExitCode(1);

        $this->assertSame(json_decode($before[1], true), json_decode($this->evidence()[1], true), 'No link was created.');
        $this->assertCount(0, $this->attestations());
    }

    public function test_attest_does_not_apply_to_the_read_only_list_action(): void
    {
        $f = $this->selection();
        $this->artisan('vasey:rights-scope', ['action' => 'list', '--attest' => true])
            ->expectsOutputToContain('Invalid input: --attest does not apply to list.')->assertExitCode(2);
        $this->artisan('vasey:rights-scope', ['action' => 'list'])
            ->expectsOutputToContain('unlinked revision='.$f['revision']->id)->assertExitCode(0);
        $this->assertCount(0, $this->attestations());
    }

    public function test_testing_keeps_writes_optional_and_records_an_explicit_attestation(): void
    {
        $f = $this->selection();
        $unenrolled = LicenseFixtures::admin();

        $this->register($unenrolled, false)->expectsOutputToContain('status=registered')
            ->doesntExpectOutputToContain('attestation=recorded')->assertExitCode(0);
        $this->assertCount(0, $this->attestations());

        $this->link($unenrolled, true, $f['revision']->id)->expectsOutputToContain('attestation=recorded audit_event=')
            ->expectsOutputToContain('status=linked')->assertExitCode(0);
        $attestation = $this->attestations()->sole();
        $this->assertSame('testing', $attestation->context['environment']);
        $this->assertFalse($attestation->context['mfa_required']);
        $this->assertFalse($attestation->context['mfa_enrolled']);
        $this->assertSame($attestation->id, AuditEvent::where('action', 'commerce.inventory.offer_linked')->sole()->context['attestation_audit_id']);
        $this->assertArrayNotHasKey('attestation_audit_id', AuditEvent::where('action', 'commerce.inventory.scope_registered')->sole()->context);
    }
}
