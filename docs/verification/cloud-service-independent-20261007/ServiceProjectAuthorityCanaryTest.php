<?php

namespace Tests\Feature;

use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

final class ServiceProjectAuthorityCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_audit_callback_mfa_withdrawal_aborts_quote_and_preserves_all_retained_rows(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        $panel = Filament::getPanel('admin');
        $panel->requiresMultiFactorAuthentication(true);
        $f = F::setup();
        $before = $this->rows();
        AuditEvent::created(function (AuditEvent $event) use ($f): void {
            if ($event->action === 'service_project.author_quote') {
                DB::table('users')->where('id', $f['operator']->id)->update(['app_authentication_secret' => null]);
            }
        });
        try {
            F::author($f);
            $this->fail('Removed required MFA must abort the quote transaction.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->rows());
            $this->assertDatabaseCount('service_project_events', 0);
        } finally {
            AuditEvent::flushEventListeners();
            $panel->requiresMultiFactorAuthentication(false);
        }
    }

    private function rows(): array
    {
        return array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            ['users', 'customer_accounts', 'service_projects', 'service_project_events', 'audit_events']);
    }
}
