<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRequestIdentity;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRows;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review of PR #57 against Free256: the customer command contract (lock -> durableBinding -> work ->
 * proveCurrent, IdentityException -> identity_refused) after an APP_KEY rotation of the identity layer.
 */
final class ReviewFree256IdentityRotationTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private const EMAIL = 'free-owner@example.test';

    private const PASSWORD = 'MailboxPassword123';

    private string $keyF;

    private array $owner;

    private array $origin;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->keyF = (string) config('app.key');
        $definition = $this->openDefinition();
        $this->owner = $this->customer(self::EMAIL);
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $this->owner['principal'], $this->owner['user']);
        $this->input = $this->assentInput($review);
        $this->origin = $grants->accept($definition['id'], $this->input, $this->owner['principal'], $this->owner['user']);
        (new ProductionFreeGrantDocuments)->render($this->origin['id']);
        $this->definitionId = $definition['id'];
    }

    private string $definitionId;

    public function test_customer_commands_after_rotation_succeed_with_the_old_key_listed_and_refuse_once_it_is_removed(): void
    {
        $this->rotate([$this->keyF]);
        // The pre-rotation principal (old-key private digests) no longer matches: identity_refused, never a grant read.
        $this->identityRefused(fn () => (new ProductionFreeGrantLibrary)->index($this->owner['principal'], $this->owner['user']));

        $signed = (new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD);
        $this->assertNotNull($signed);
        $principal = $signed['principal'];
        $actor = $signed['user'];
        $this->assertSame($this->owner['binding'], (new ProductionCustomerAccess)->durableBinding($principal));
        $index = (new ProductionFreeGrantLibrary)->index($principal, $actor);
        $this->assertSame(1, $index['total']);
        $this->assertSame($this->origin['id'], $index['items'][0]['id']);
        $detail = (new ProductionFreeGrantLibrary)->show($this->origin['id'], $principal, $actor);
        $this->assertSame($this->origin['id'], (new ProductionFreeGrants)->accept($this->definitionId, $this->input, $principal, $actor)['id']);
        $artifact = $detail['artifacts'][0];
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($this->origin['id'], ['originSeal' => $detail['originSeal'], 'role' => $artifact['role']], $principal, $actor);
        $transfer = $downloads->redeem($authorization['id'], $authorization['token'], $principal, $actor);
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });
        $this->assertSame($artifact['sha256'], hash('sha256', $bytes));
        $second = $downloads->authorize($this->origin['id'], ['originSeal' => $detail['originSeal'], 'role' => $artifact['role']], $principal, $actor);

        $before = $this->freeRows();
        $this->rotate([]);
        $this->assertNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD));
        $this->identityRefused(fn () => (new ProductionFreeGrantLibrary)->index($principal, $actor));
        $this->identityRefused(fn () => (new ProductionFreeGrantLibrary)->show($this->origin['id'], $principal, $actor));
        $this->identityRefused(fn () => $downloads->authorize($this->origin['id'], ['originSeal' => $detail['originSeal'], 'role' => $artifact['role']], $principal, $actor));
        $this->identityRefused(fn () => $downloads->redeem($second['id'], $second['token'], $principal, $actor));
        $this->identityRefused(fn () => (new ProductionFreeGrants)->accept($this->definitionId, $this->input, $principal, $actor));
        $this->assertSame($before, $this->freeRows());
    }

    public function test_rotation_inside_a_customer_command_after_lock_is_identity_refused_by_prove_current(): void
    {
        $ran = false;
        // A plain closure (not an arrow function) so the by-reference flag reaches this scope.
        $this->identityRefused(function () use (&$ran): void {
            (new ProductionFreeGrants)->customerCommand($this->owner['principal'], $this->owner['user'],
                function (array $policy, ProductionFreeGrantRows $rows, array $binding) use (&$ran): array {
                    $ran = true;
                    $this->rotate([$this->keyF]);

                    return ['account' => $binding['account_id']];
                });
        });
        $this->assertTrue($ran, 'lock() and durableBinding() ran before the rotation.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_pre_rotation_session_marker_is_identity_refused_and_a_new_sign_in_is_admitted(): void
    {
        $marker = $this->owner['principal']->sessionBindingDigest();
        $this->assertSame($this->owner['principal']->userId, ProductionFreeGrantRequestIdentity::from($this->request($this->owner['user'], $marker))->principal->userId);
        $this->rotate([$this->keyF]);
        $this->identityRefused(fn () => ProductionFreeGrantRequestIdentity::from($this->request($this->owner['user'], $marker)));
        $signed = (new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD);
        $identity = ProductionFreeGrantRequestIdentity::from($this->request($signed['user'], $signed['principal']->sessionBindingDigest()));
        $this->assertSame(1, (new ProductionFreeGrantLibrary)->index($identity->principal, $identity->actor)['total']);
        $this->rotate([]);
        $this->identityRefused(fn () => ProductionFreeGrantRequestIdentity::from($this->request($signed['user'], $signed['principal']->sessionBindingDigest())));
    }

    private function request(User $user, string $marker): Request
    {
        Auth::guard('customer')->setUser($user);
        $request = Request::create('/customer');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $marker]);
        $request->setUserResolver(fn (?string $guard = null) => $guard === 'customer' ? $user : null);

        return $request;
    }

    private function freeRows(): array
    {
        $rows = [];
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $name = is_string($table) ? preg_replace('/^.*\./', '', $table) : $table;
            if (str_starts_with($name, 'production_free_') || str_starts_with($name, 'production_identity_')) {
                $rows[$name] = DB::table($name)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
        }
        ksort($rows);

        return $rows;
    }

    private function rotate(array $previous): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('G', 32)), 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function identityRefused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse identity_refused');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('identity_refused', $error->reason);
        }
    }
}
