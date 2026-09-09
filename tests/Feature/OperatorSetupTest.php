<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OperatorSetupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-console-only-42!';

    private function provision(string $email = 'synthetic-operator@example.test', string $password = self::PASSWORD)
    {
        return $this->artisan('vasey:create-admin')
            ->expectsQuestion('Operator name', 'Synthetic Operator')
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password (at least 16 characters)', $password);
    }

    public function test_interactive_operator_creation_hashes_credentials_and_records_console_authority(): void
    {
        $this->provision()->assertExitCode(0);
        $user = User::sole();
        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $audit = AuditEvent::where('action', 'access.operator.created')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertSame('trusted_interactive_console', $audit->context['authority']);
        $this->assertSame('console_attested', $audit->context['email_verification']);
        $this->assertStringNotContainsString($user->email, json_encode($audit->context));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit->context));
    }

    public function test_non_interactive_creation_fails_without_prompting_or_creating_a_user(): void
    {
        $this->artisan('vasey:create-admin', ['--no-interaction' => true])
            ->expectsOutputToContain('requires an interactive trusted console')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_invalid_or_duplicate_credentials_do_not_grant_access(): void
    {
        $this->provision(password: 'short')->assertExitCode(1);
        $this->provision(email: 'invalid-address')->assertExitCode(1);
        $customer = User::factory()->create(['email' => 'synthetic-operator@example.test']);
        $this->provision()->assertExitCode(1);
        $this->assertFalse($customer->fresh()->is_admin);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_audit_failure_rolls_back_provisioning_and_does_not_print_exception_details(): void
    {
        AuditEvent::creating(fn () => throw new RuntimeException('PRIVATE-CONNECTION-DETAIL'));
        $this->provision()->expectsOutputToContain('No account or audit changes were committed')
            ->doesntExpectOutputToContain('PRIVATE-CONNECTION-DETAIL')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
