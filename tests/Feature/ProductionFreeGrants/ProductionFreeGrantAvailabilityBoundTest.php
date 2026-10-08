<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (5): the availability chain was read through a 1,000-row id-ordered window while the schema allowed 10,000
 * ordinals. The chain is now bounded to 1,000 events (ordinals 0-999) by the schema, read completely by ordinal, and
 * the command that would exceed it is refused `availability_exhausted` before anything is written.
 */
final class ProductionFreeGrantAvailabilityBoundTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_the_event_at_the_bound_is_projected_correctly_and_the_next_one_is_refused_before_the_insert(): void
    {
        $this->freeSetup();
        $staff = $this->staff();
        $definition = $this->openDefinition($staff, $this->staff());
        $definitions = new ProductionFreeGrantDefinitions;
        $this->seedEvents($definition, $staff, 1, 997);
        $this->assertSame(998, DB::table('production_free_availability')->count());
        $hash = ['definitionHash' => $definition['definitionHash']];

        $open = $definitions->open($definition['id'], $hash + ['expectedOrdinal' => 998], $staff);
        $this->assertTrue($open['open']);
        $this->assertSame(998, $open['availabilityOrdinal']);
        $closed = $definitions->close($definition['id'], $hash + ['expectedOrdinal' => 999], $staff);
        $this->assertFalse($closed['open']);
        $this->assertSame(999, $closed['availabilityOrdinal']);
        $this->assertSame(1000, DB::table('production_free_availability')->count());

        $this->refuses(fn () => $definitions->open($definition['id'], $hash + ['expectedOrdinal' => 1000], $staff), 'availability_exhausted');
        $this->assertSame(1000, DB::table('production_free_availability')->count());
        $this->assertSame(999, (int) DB::table('production_free_availability')->max('ordinal'));
        $read = $definitions->read($definition['id'], $staff);
        $this->assertFalse($read['open']);
        $this->assertSame(999, $read['availabilityOrdinal']);
        $this->assertSame('closed', DB::table('production_free_availability')->where('ordinal', 999)->value('kind'));
    }

    /** Append-only events written through the same sealed insert the command uses, in one transaction. */
    private function seedEvents(array $definition, $actor, int $from, int $to): void
    {
        $reviewId = DB::table('production_free_reviews')->value('id');
        DB::transaction(function () use ($definition, $actor, $from, $to, $reviewId): void {
            $rows = new ProductionFreeGrantRows;
            for ($ordinal = $from; $ordinal <= $to; $ordinal++) {
                $at = ProductionFreeGrantInput::now();
                $id = (string) Str::uuid();
                $kind = $ordinal % 2 === 0 ? 'open' : 'closed';
                $rows->insert('production_free_availability', ['id' => $id, 'definition_id' => $definition['id'], 'review_id' => $reviewId,
                    'ordinal' => $ordinal, 'kind' => $kind, 'actor_user_id' => (int) $actor->getKey(), 'created_at' => ProductionFreeGrantInput::stored($at)],
                    ['schema_version' => 'production-free-availability-v1', 'availability_id' => $id, 'definition_id' => $definition['id'],
                        'definition_hash' => $definition['definitionHash'], 'kind' => $kind, 'ordinal' => $ordinal,
                        'actor_user_id' => (int) $actor->getKey(), 'at' => ProductionFreeGrantInput::iso($at)]);
            }
        });
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
