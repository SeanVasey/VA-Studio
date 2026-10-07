<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Models\ConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

class CustomerConsentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public static function lateChanges(): array
    {
        $cases = [];
        foreach (['read', 'grant', 'withdraw'] as $operation) {
            foreach (['account', 'credential', 'recipient', 'purpose', 'graph'] as $change) {
                $cases[$operation.' '.$change] = [$operation, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('lateChanges')]
    public function test_final_account_query_callback_cannot_release_or_commit_stale_consent(string $operation, string $change): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        if ($operation !== 'grant') {
            $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        }
        $before = $this->raw();
        $accountReads = 0;
        $fired = false;
        DB::listen(function ($query) use (&$accountReads, &$fired, $customer, $change, $service): void {
            if (! $fired && preg_match('/\bfrom\s+["`]?customer_accounts\b/i', $query->sql) && ++$accountReads === 2) {
                $fired = true;
                match ($change) {
                    'account' => CustomerFixtures::withdraw($customer),
                    'credential' => DB::table('users')->where('id', $customer['user']->id)->update(['password' => 'SYNTHETIC changed credential']),
                    'recipient' => DB::table('users')->where('id', $customer['user']->id)->update(['email' => 'late-synthetic@example.test']),
                    'purpose' => config(['customer-preferences.test_grants_enabled' => false]),
                    'graph' => $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(ConsentState::sole()->revision)),
                };
            }
        });
        try {
            match ($operation) {
                'read' => $service->read($customer['principal'], $customer['user']),
                'grant' => $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant()),
                'withdraw' => $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(1)),
            };
            $this->fail('Late stale authority/graph escaped the terminal proof.');
        } catch (CustomerAccessException|ConsentException $error) {
            $this->assertTrue($fired);
            if ($error instanceof ConsentException) {
                $this->assertSame(503, $error->status);
            }
        }
        $this->assertSame($before, $this->raw(), 'The entire attempted graph transition and callback must roll back.');
    }

    public function test_same_saved_state_model_cannot_be_refreshed_to_a_different_intended_revision(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $before = $this->raw();
        $fired = false;
        ConsentState::saved(function (ConsentState $state) use (&$fired, $service, $customer): void {
            if (! $fired) {
                $fired = true;
                $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(1));
                $state->refresh();
            }
        });
        try {
            $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
            $this->fail('The intended grant revision was rebound to a later withdrawal.');
        } catch (ConsentException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertTrue($fired);
        $this->assertSame($before, $this->raw());
    }

    public function test_revoked_principal_and_disabled_customer_access_cannot_read_or_change_preferences(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        CustomerFixtures::withdraw($customer);
        $before = $this->raw();
        foreach (['read', 'withdraw'] as $operation) {
            try {
                $operation === 'read' ? $service->read($customer['principal'], $customer['user']) : $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(1));
                $this->fail('Revoked authority succeeded.');
            } catch (CustomerAccessException) {
                $this->assertSame($before, $this->raw());
            }
        }
        config(['customer.test_accounts_enabled' => false]);
        $this->expectException(CustomerAccessException::class);
        $service->read($customer['principal'], $customer['user']);
    }

    private function raw(): array
    {
        $pdo = DB::connection()->getPdo();

        return array_map(fn ($table) => $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), ['users', 'customer_accounts', 'customer_consent_policies', 'customer_consent_events', 'customer_consent_states']);
    }
}
