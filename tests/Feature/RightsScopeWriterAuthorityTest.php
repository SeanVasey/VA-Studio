<?php

namespace Tests\Feature;

use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RightsScopeWriterAuthorityTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    public static function writers(): array
    {
        return ['register' => ['register'], 'link' => ['link'], 'block' => ['block']];
    }

    private function fixture(string $writer): array
    {
        $fixture = QuoteFixtures::selection();
        $fixture['scope'] = app(ManageRightsScope::class)->register('synthetic-existing', 'SYNTHETIC-SCOPE', $fixture['actor']);

        return $fixture;
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
        return match ($writer) {
            'register' => app(ManageRightsScope::class)->register('synthetic-created', 'SYNTHETIC-SCOPE', $actor),
            'link' => app(ManageRightsScope::class)->link($fixture['scope']->id, $fixture['revision']->id, 'SYNTHETIC-LINK', $actor),
            'block' => app(ManageRightsScope::class)->block($fixture['scope']->id, true, 0, 'SYNTHETIC-BLOCK', $actor),
        };
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'offers', 'offer_revisions', 'rights_declarations', 'rights_scopes', 'rights_scope_offers', 'audit_events']);
    }

    #[DataProvider('writers')]
    public function test_current_actor_is_read_inside_the_transaction_before_any_catalog_read(string $writer): void
    {
        $fixture = $this->fixture($writer);
        $active = true;
        $trace = [];
        DB::listen(function (QueryExecuted $query) use (&$active, &$trace): void {
            if ($active && preg_match('/\Aselect\b.*\bfrom\s+["`]?(users|tracks|offers|rights_scopes|offer_revisions)["`]?(?:\s|$)/i', $query->sql, $matches)) {
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
            $this->assertSame($audits + 1, DB::table('audit_events')->count());
            $this->assertNotSame($before, $this->evidence());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }
}
