<?php

namespace Tests\Feature\ProductionFreeIdentity;

use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantIdentity;
use App\Domain\Grants\Free\FreeGrantOriginalIdentity;
use App\Domain\Grants\Free\FreeGrantRows;
use App\Domain\Grants\Free\TestFreeGrantIdentity;
use App\Models\User;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

/** Actual old test-origin mutation exercises only the optional contract integration, not operative fulfillment. */
final class FreeGrantOriginalFenceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function originalAvailability(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('originalAvailability')]
    public function test_optional_original_authority_refusal_rolls_back_actual_origin_and_work(bool $withdraw): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $definition = FreeGrantFixtures::publish($f);
        $identity = new class($withdraw) implements FreeGrantIdentity, FreeGrantOriginalIdentity
        {
            public bool $captured = false;

            public bool $terminal = false;

            private TestFreeGrantIdentity $current;

            public function __construct(private readonly bool $withdraw)
            {
                $this->current = new TestFreeGrantIdentity;
            }

            public function principal(User $actor): object
            {
                return $this->current->principal($actor);
            }

            public function lock(object $principal, User $actor, FreeGrantRows $rows): array
            {
                return $this->current->lock($principal, $actor, $rows);
            }

            public function proveCurrent(object $principal, User $actor, FreeGrantRows $rows, array $expected): void
            {
                FreeGrantException::require($this->captured, 403);
                $this->current->proveCurrent($principal, $actor, $rows, $expected);
            }

            public function provePrimary(object $principal, User $actor, FreeGrantRows $rows, array $expected): void
            {
                FreeGrantException::require($this->terminal, 403);
                $this->current->provePrimary($principal, $actor, $rows, $expected);
            }

            public function durableBinding(object $principal): array
            {
                return $this->current->durableBinding($principal);
            }

            public function lockOriginal(object $principal, User $actor, array $binding, FreeGrantRows $rows): array
            {
                $this->captured = true;
                FreeGrantException::require(CanonicalJson::encode($binding) === CanonicalJson::encode($this->durableBinding($principal)), 403);

                return ['binding' => $binding];
            }

            public function proveOriginalPrimary(object $principal, User $actor, array $binding, FreeGrantRows $rows, array $expected): void
            {
                $this->terminal = true;
                FreeGrantException::require(! $this->withdraw && $expected === ['binding' => $binding], 403);
            }
        };
        app()->instance(FreeGrantIdentity::class, $identity);
        try {
            $accepted = FreeGrantFixtures::accept($f, $definition);
            $this->assertFalse($withdraw);
            $this->assertSame('free-license-grant', $accepted['origin']['purpose']);
        } catch (FreeGrantException $error) {
            $this->assertTrue($withdraw);
            $this->assertSame(403, $error->status);
        }
        $this->assertTrue($identity->captured);
        $this->assertTrue($identity->terminal);
        $this->assertDatabaseCount('free_origins', $withdraw ? 0 : 1);
        $this->assertDatabaseCount('free_document_work', $withdraw ? 0 : 1);
        $this->assertDatabaseCount('orders', 0);
    }
}
