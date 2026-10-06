<?php

namespace Tests\Feature;

use App\Domain\Catalog\ActivateExclusiveOffer;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ExclusiveOfferFixtures;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ExclusiveWriterAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    public static function writers(): array
    {
        return ['prepare' => ['prepare'], 'activate' => ['activate']];
    }

    private function fixture(string $writer): array
    {
        ExclusiveSelectionFixtures::configure();

        return $writer === 'prepare' ? ExclusiveOfferFixtures::draft() : ExclusiveSelectionFixtures::prepared();
    }

    public static function withdrawnActors(): array
    {
        $cases = [];
        foreach (self::writers() as $label => [$writer]) {
            foreach (['role', 'email', 'deleted', 'unsaved', 'customer'] as $state) {
                $cases[$label.' / '.$state] = [$writer, $state];
            }
        }

        return $cases;
    }

    private function write(string $writer, array $fixture, User $actor): mixed
    {
        return $writer === 'prepare'
            ? app(PrepareExclusiveOffer::class)->handle($fixture['offer'], $fixture['scope']->id, 'SYNTHETIC-AUTHORITY', $actor)
            : app(ActivateExclusiveOffer::class)->handle($fixture['offer'], $fixture['revision']->id, $actor);
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'offers', 'offer_revisions', 'rights_declarations', 'rights_scope_offers', 'exclusive_activations', 'audit_events']);
    }

    #[DataProvider('writers')]
    public function test_current_actor_is_read_inside_the_transaction_before_any_catalog_read(string $writer): void
    {
        $fixture = $this->fixture($writer);
        $active = true;
        $trace = [];
        DB::listen(function (QueryExecuted $query) use (&$active, &$trace): void {
            if ($active && preg_match('/\Aselect\b.*\bfrom\s+["`]?(users|tracks|offers)["`]?(?:\s|$)/i', $query->sql, $matches)) {
                $trace[] = ['table' => $matches[1], 'level' => DB::transactionLevel()];
            }
        });
        try {
            $saved = $this->write($writer, $fixture, $fixture['actor']);
        } finally {
            $active = false;
        }
        $this->assertNotEmpty($trace);
        $this->assertSame('users', $trace[0]['table']);
        $this->assertSame([], array_values(array_filter($trace, fn ($row) => $row['level'] < 1)),
            'Current authority and catalog reads must belong to the mutation transaction.');
        $this->assertTrue($saved->exists);
        $this->assertSame($fixture['actor']->id, DB::table('audit_events')->orderByDesc('id')->value('actor_id'));
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('withdrawnActors')]
    public function test_missing_or_withdrawn_persisted_authority_has_no_catalog_or_audit_effect(string $writer, string $state): void
    {
        $fixture = $this->fixture($writer);
        $actor = $fixture['actor'];
        if ($state === 'role' || $state === 'email') {
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['email_verified_at' => null]);
        } elseif ($state === 'deleted') {
            $actor = LicenseFixtures::admin();
            User::findOrFail($actor->id)->delete();
        } elseif ($state === 'unsaved') {
            $actor = (new User)->forceFill(['id' => $actor->id, 'is_admin' => true, 'email_verified_at' => now()]);
        } else {
            $actor = User::factory()->create();
            $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        }
        $before = $this->evidence();
        try {
            $this->write($writer, $fixture, $actor);
            $this->fail('Unavailable persisted authority mutated the catalog.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writers')]
    public function test_existing_caller_transaction_can_rollback_mutation_and_attributed_audit_together(string $writer): void
    {
        $fixture = $this->fixture($writer);
        $fixture['actor']->forceFill(['is_admin' => false, 'email_verified_at' => null]);
        $before = $this->evidence();
        $audits = DB::table('audit_events')->count();
        DB::beginTransaction();
        try {
            $this->write($writer, $fixture, $fixture['actor']);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($audits + ($writer === 'prepare' ? 2 : 1), DB::table('audit_events')->count());
            $this->assertNotSame($before, $this->evidence());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }
}
