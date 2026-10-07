<?php

namespace Tests\IndependentServiceFollowup;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Services\Projects\ServiceProjectException;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

final class ServiceCustomerResolverCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_real_customer_access_resolver_cannot_withdraw_credentials_after_final_physical_user_proof(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        $f = F::setup();
        $offered = F::author($f);
        $pdo = DB::connection()->getPdo();
        $before = $this->snapshot($pdo);
        $armed = false;
        $fired = false;
        $this->app->resolving(CustomerAccess::class, function () use (&$armed, &$fired, $pdo, $f): void {
            if ($armed && ! $fired) {
                $fired = true;
                $pdo->exec("UPDATE users SET password='SYNTHETIC withdrawn customer credential' WHERE id=".(int) $f['customer']['user']->id);
            }
        });
        AuditEvent::created(function (AuditEvent $event) use (&$armed): void {
            if ($event->action === 'service_project.accept_quote') {
                $armed = true;
            }
        });
        $refused = false;
        try {
            $f['journey']->customerCommand($offered['id'], F::command($offered, 'accept_quote', ['quoteId' => $offered['quoteId'], 'quoteHash' => $offered['quoteHash']]), $f['customer']['principal'], $f['customer']['user']);
        } catch (AuthorizationException|ServiceProjectException) {
            $refused = true;
        } finally {
            AuditEvent::flushEventListeners();
        }
        $after = $this->snapshot($pdo);
        file_put_contents((string) getenv('SERVICE_CUSTOMER_RESOLVER_PROBE'), json_encode(['tested_source' => getenv('SERVICE_FOLLOWUP_SOURCE'), 'driver' => DB::getDriverName(),
            'genuine_customer_access_resolver_fired' => $fired, 'acceptance_audit_created' => $armed, 'command_refused' => $refused,
            'retained_rows_exact' => $before === $after, 'prior_service_event_count' => count($before['service_project_events']),
            'final_service_event_count' => count($after['service_project_events'])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $this->assertTrue($armed);
        $this->assertTrue($fired);
        $this->assertTrue($refused, 'Resolving the credential-stamp service must precede the final permanent user proof.');
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
}
