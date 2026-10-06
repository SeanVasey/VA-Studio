<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class OfferWriterAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function successor(): array
    {
        $fixture = QuoteFixtures::selection();
        $fixture['offer'] = app(SaveOfferDraft::class)->handle($fixture['offer'], ['price_minor' => 5000], $fixture['actor']);

        return $fixture;
    }

    private function evidence(): array
    {
        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'offers', 'offer_revisions', 'rights_declarations', 'audit_events']);
    }

    private function selects(string $sql, string $table): bool
    {
        return preg_match('/\Aselect\b/i', $sql) === 1
            && preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?(?:\s|$)/i', $sql) === 1;
    }

    public static function withdrawals(): array
    {
        return ['admin role' => ['is_admin', false], 'verified email' => ['email_verified_at', null]];
    }

    #[DataProvider('withdrawals')]
    public function test_publication_never_uses_the_old_pretransaction_authority_gap(string $field, mixed $value): void
    {
        ['actor' => $actor, 'offer' => $offer] = $this->successor();
        $before = $this->evidence();
        $active = true;
        $withdrew = false;
        $levels = [];
        DB::listen(function (QueryExecuted $query) use ($actor, $field, $value, &$active, &$withdrew, &$levels): void {
            if (! $active || ! $this->selects($query->sql, 'users') || ! in_array($actor->id, $query->bindings, true)) {
                return;
            }
            $levels[] = DB::transactionLevel();
            // A same-connection listener reproduces the former authorization gap, not a MySQL race.
            if (! $withdrew && DB::transactionLevel() === 0) {
                $withdrew = true;
                DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            }
        });
        $denied = false;
        try {
            app(PublishOffer::class)->handle($offer, $actor);
        } catch (AuthorizationException) {
            $denied = true;
        } finally {
            $active = false;
        }

        $this->assertNotEmpty($levels, 'The real publisher must perform current persisted authority reads.');
        if ($withdrew) {
            $this->assertTrue($denied, 'Publication still used authority withdrawn after its old pretransaction Gate read.');
            $this->assertSame($before, $this->evidence());
        } else {
            $this->assertFalse($denied);
            $this->assertSame([], array_values(array_filter($levels, fn (int $level) => $level < 1)));
            $this->assertSame(2, $offer->fresh()->currentRevision->revision);
            $this->assertSame($actor->id, $offer->fresh()->currentRevision->published_by);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function actors(): array
    {
        return ['withdrawn role' => ['role'], 'withdrawn email' => ['email'], 'deleted actor' => ['deleted'],
            'unsaved actor' => ['unsaved'], 'locally elevated customer' => ['customer']];
    }

    #[DataProvider('actors')]
    public function test_missing_or_withdrawn_persisted_authority_cannot_publish_any_effect(string $state): void
    {
        ['actor' => $actor, 'offer' => $offer] = $this->successor();
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
            app(PublishOffer::class)->handle($offer, $actor);
            $this->fail('Unavailable persisted authority published a commercial revision.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_existing_caller_transaction_remains_owned_by_the_caller_and_rollback_retains_original(): void
    {
        ['actor' => $actor, 'offer' => $offer] = $this->successor();
        $actor->forceFill(['is_admin' => false, 'email_verified_at' => null]);
        $before = $this->evidence();
        $active = true;
        $trace = [];
        DB::listen(function (QueryExecuted $query) use (&$active, &$trace): void {
            if ($active) {
                foreach (['users', 'offers', 'tracks'] as $table) {
                    if ($this->selects($query->sql, $table)) {
                        $trace[] = ['table' => $table, 'transaction_level' => DB::transactionLevel()];
                    }
                }
            }
        });
        DB::beginTransaction();
        try {
            $revision = app(PublishOffer::class)->handle($offer, $actor);
            $active = false;
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame(2, $revision->revision);
            $this->assertSame($actor->id, $revision->published_by);
            $this->assertSame($revision->id, $offer->fresh()->current_revision_id);
            $this->assertSame($actor->id, AuditEvent::where('action', 'catalog.offer.revision_published')->latest('id')->firstOrFail()->actor_id);
            $this->assertSame('users', $trace[0]['table']);
            $this->assertSame(2, $trace[0]['transaction_level']);
        } finally {
            $active = false;
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_failed_publication_audit_rolls_back_revision_and_current_offer_without_rewriting_history(): void
    {
        ['actor' => $actor, 'offer' => $offer] = $this->successor();
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic offer publication audit failure'));
        try {
            app(PublishOffer::class)->handle($offer, $actor);
            $this->fail('A failed publication audit allowed a commercial revision to commit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic offer publication audit failure', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }
}
