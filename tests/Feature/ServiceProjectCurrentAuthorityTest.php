<?php

namespace Tests\Feature;

use App\Domain\Services\Projects\ServiceProjectException;
use App\Support\Audit\AuditEvent;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use PDO;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

final class ServiceProjectCurrentAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_temporary_users_copy_cannot_mask_permanent_required_mfa_withdrawal_after_real_audit_callback(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires native MySQL temporary-table namespace behavior.');
        }
        $this->assertSame('mysql', DB::getDriverName());
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        $panel = Filament::getPanel('admin');
        $panel->requiresMultiFactorAuthentication(true);
        $f = F::setup();
        $pdo = DB::connection()->getPdo();
        $before = $this->snapshot($pdo);
        $users = $before['users'];
        $ddl = $pdo->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_ASSOC)['Create Table'];
        $temporary = preg_replace('/\ACREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl, 1);
        $fired = false;
        $shadow = false;
        AuditEvent::created(function (AuditEvent $event) use (&$fired, &$shadow, $f, $pdo, $temporary, $users): void {
            if ($event->action !== 'service_project.author_quote') {
                return;
            }
            $fired = true;
            $pdo->exec('UPDATE users SET app_authentication_secret=NULL WHERE id='.(int) $f['operator']->id);
            $pdo->exec($temporary);
            $shadow = true;
            $columns = array_keys($users[0]);
            $insert = $pdo->prepare('INSERT INTO users (`'.implode('`,`', $columns).'`) VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
            foreach ($users as $row) {
                $insert->execute(array_values($row));
            }
        });
        $refused = false;
        try {
            F::author($f);
        } catch (AuthorizationException|ServiceProjectException) {
            $refused = true;
        } finally {
            if ($shadow) {
                $pdo->exec('DROP TEMPORARY TABLE users');
            }
            AuditEvent::flushEventListeners();
            $panel->requiresMultiFactorAuthentication(false);
        }
        $after = $this->snapshot($pdo);
        if (is_string(getenv('SERVICE_FOLLOWUP_PROBE')) && getenv('SERVICE_FOLLOWUP_PROBE') !== '') {
            file_put_contents((string) getenv('SERVICE_FOLLOWUP_PROBE'), json_encode(['tested_source' => getenv('SERVICE_FOLLOWUP_SOURCE'),
                'server' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'real_callback_fired' => $fired, 'temporary_users_copy_created' => $shadow,
                'command_refused' => $refused, 'permanent_user_mfa_after' => array_values(array_filter($after['users'], fn ($user) => (int) $user['id'] === $f['operator']->id))[0]['app_authentication_secret'] === null ? 'withdrawn' : 'retained',
                'retained_rows_exact' => $before === $after, 'service_event_count' => count($after['service_project_events'])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
        $this->assertTrue($fired);
        $this->assertTrue($shadow);
        $this->assertTrue($refused, 'Final authority must refuse the actual permanent MFA withdrawal even when a temporary same-name users copy retains the old values.');
        $this->assertSame($before, $after);
    }

    private function snapshot(PDO $pdo): array
    {
        $result = [];
        foreach (['users', 'customer_accounts', 'service_projects', 'service_project_events', 'audit_events'] as $table) {
            $result[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    public function test_real_mfa_provider_callback_cannot_withdraw_enrollment_after_the_verified_user_read(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        $f = F::setup();
        $panel = Filament::getPanel('admin');
        $providers = $panel->getMultiFactorAuthenticationProviders();
        $required = $panel->isMultiFactorAuthenticationRequired();
        $pdo = DB::connection()->getPdo();
        $before = $this->snapshot($pdo);
        $armed = false;
        $fired = false;
        $provider = new class(app(Google2FA::class)) extends AppAuthentication
        {
            public ?\Closure $callback = null;

            public function isEnabled(Authenticatable $user): bool
            {
                $enabled = parent::isEnabled($user);
                ($this->callback)();

                return $enabled;
            }
        };
        $provider->callback = function () use (&$armed, &$fired, $pdo, $f): void {
            if ($armed && ! $fired) {
                $fired = true;
                $pdo->exec('UPDATE users SET app_authentication_secret=NULL WHERE id='.(int) $f['operator']->id);
            }
        };
        $panel->multiFactorAuthentication([$provider], isRequired: true);
        AuditEvent::created(function (AuditEvent $event) use (&$armed): void {
            if ($event->action === 'service_project.author_quote') {
                $armed = true;
            }
        });
        $refused = false;
        try {
            F::author($f);
        } catch (AuthorizationException|ServiceProjectException) {
            $refused = true;
        } finally {
            AuditEvent::flushEventListeners();
            $panel->multiFactorAuthentication($providers, isRequired: $required);
        }
        $after = $this->snapshot($pdo);
        if (is_string(getenv('SERVICE_PROVIDER_PROBE')) && getenv('SERVICE_PROVIDER_PROBE') !== '') {
            file_put_contents((string) getenv('SERVICE_PROVIDER_PROBE'), json_encode(['tested_source' => getenv('SERVICE_FOLLOWUP_SOURCE'),
                'driver' => DB::getDriverName(), 'genuine_provider_callback_fired' => $fired, 'quote_audit_was_created' => $armed,
                'command_refused' => $refused, 'retained_rows_exact' => $before === $after, 'service_event_count' => count($after['service_project_events'])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
        $this->assertTrue($armed);
        $this->assertTrue($fired);
        $this->assertTrue($refused, 'The MFA provider must run before the final current user proof; its stored enrollment withdrawal cannot follow verified authority.');
        $this->assertSame($before, $after);
    }
}
