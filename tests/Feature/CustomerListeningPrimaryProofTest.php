<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerListeningPrimaryProofTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function terminalChanges(): array
    {
        $cases = [];
        foreach (['read', 'create', 'no-op'] as $operation) {
            foreach (['account', 'password', 'policy', 'role', 'email', 'library'] as $change) {
                $cases[$operation.' '.$change] = [$operation, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('terminalChanges')]
    public function test_genuine_last_account_query_callbacks_cannot_release_private_state_or_commit_a_change(string $operation, string $change): void
    {
        $customer = CustomerFixtures::account();
        $library = app(ListeningLibrary::class);
        if ($operation !== 'create') {
            $library->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'PRIVATE retained playlist']);
        }
        $pdo = DB::connection()->getPdo();
        $before = $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC);
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired, $customer, $change, $pdo): void {
            if ($fired || ! str_contains($query->sql, 'from "customer_accounts"') || ++$seen !== 2) {
                return;
            }
            $fired = true;
            if ($change === 'policy') {
                config(['customer.test_accounts_enabled' => false]);
            } elseif ($change === 'account') {
                $statement = $pdo->prepare('UPDATE customer_accounts SET active=0, access_version=access_version+1 WHERE id=?');
                $statement->execute([$customer['account']->id]);
            } elseif ($change === 'library') {
                $statement = $pdo->prepare('UPDATE customer_saved_tracks SET version=version+1 WHERE customer_account_id=?');
                $statement->execute([$customer['account']->id]);
            } else {
                [$field, $value] = match ($change) {
                    'password' => ['password', 'SYNTHETIC revoked credential'],
                    'role' => ['is_admin', 1],
                    'email' => ['email_verified_at', null],
                };
                $statement = $pdo->prepare('UPDATE users SET '.$field.'=? WHERE id=?');
                $statement->execute([$value, $customer['user']->id]);
            }
        });
        $refused = false;
        try {
            match ($operation) {
                'read' => $library->read($customer['principal'], $customer['user']),
                'create' => $library->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'PRIVATE new playlist']),
                'no-op' => $library->change($customer['principal'], $customer['user'], ['action' => 'remove-saved-track', 'version' => 1, 'trackId' => '999']),
            };
        } catch (CustomerAccessException|ListeningException) {
            $refused = true;
        }
        $this->assertTrue($fired, 'The genuine terminal ORM callback must fire.');
        $this->assertTrue($refused, 'A terminal callback must prevent release and commit.');
        $this->assertSame($before, $pdo->query('SELECT * FROM customer_saved_tracks')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(0, DB::transactionLevel());
    }
}
