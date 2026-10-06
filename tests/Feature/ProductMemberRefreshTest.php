<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductDraftVersion;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\ProductDrafts;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ProductMemberRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $actor = LicenseFixtures::admin();
        $tracks = [Track::create(['title' => 'Synthetic first track', 'slug' => 'first']),
            Track::create(['title' => 'Synthetic second track', 'slug' => 'second'])];
        $draft = app(ProductDrafts::class)->save(null, ['kind' => 'album', 'title' => 'Private album',
            'description' => 'Retained private description', 'track_ids' => [$tracks[1]->id, $tracks[0]->id]], $actor);

        return compact('actor', 'tracks', 'draft');
    }

    private function rename(Track $track, User $actor, string $title = 'Synthetic updated title'): void
    {
        app(SaveTrackMetadata::class)->handle($track, ['metadata_version' => $track->fresh()->metadata_version, 'title' => $title], $actor);
    }

    private function evidence(): array
    {
        return array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(),
            ['product_drafts', 'product_draft_versions', 'product_draft_members', 'tracks', 'audit_events']);
    }

    private function rejects(callable $call): void
    {
        try {
            $call();
            $this->fail('Changed or unbound member review was accepted.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
    }

    public function test_review_compares_ordered_retained_snapshots_without_mutating_or_claiming_readiness(): void
    {
        ['actor' => $actor, 'tracks' => $tracks, 'draft' => $draft] = $this->fixture();
        $this->rename($tracks[1], $actor);
        DB::table('tracks')->where('id', $tracks[0]->id)->update(['publication_version' => 2]);
        $before = $this->evidence();
        $review = app(ProductDrafts::class)->reviewMembers($draft->id, $actor);
        $this->assertSame($before, $this->evidence());
        $this->assertSame([$tracks[1]->id, $tracks[0]->id], array_column($review['members'], 'track_id'));
        $this->assertSame([1, 2], array_column($review['members'], 'position'));
        $this->assertSame(2, $review['changed_count']);
        $this->assertSame(['title', 'metadata_version'], $review['members'][0]['changed_fields']);
        $this->assertSame(['publication_version'], $review['members'][1]['changed_fields']);
        $this->assertSame(['title' => 'Synthetic second track', 'metadata_version' => 0, 'publication_version' => 0], $review['members'][0]['saved']);
        $this->assertSame(['title' => 'Synthetic updated title', 'metadata_version' => 1, 'publication_version' => 0], $review['members'][0]['current']);
        $this->assertSame(ProductDraftVersion::sole()->manifest_sha256, $review['manifest_sha256']);
        $this->assertNotSame($review['manifest_sha256'], $review['refreshed_manifest_sha256']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $review['review_hash']);
        $this->assertSame($review, app(ProductDrafts::class)->reviewMembers($draft->id, $actor));
        foreach (['ready', 'price', 'license', 'storage_path', 'download', 'provenance'] as $privateOrUnproven) {
            $this->assertStringNotContainsString('"'.$privateOrUnproven.'"', json_encode($review));
        }
    }

    public function test_refresh_appends_exact_reviewed_members_preserving_order_metadata_history_and_source_tracks(): void
    {
        ['actor' => $actor, 'tracks' => $tracks, 'draft' => $draft] = $this->fixture();
        $original = ProductDraftVersion::sole()->getAttributes();
        $this->rename($tracks[1], $actor);
        $sourceRows = DB::table('tracks')->orderBy('id')->get()->toJson();
        $service = app(ProductDrafts::class);
        $review = $service->reviewMembers($draft->id, $actor);
        $saved = $service->refreshMembers($draft, 1, $review['review_hash'], $actor);
        $current = ProductDraftVersion::where('number', 2)->sole();
        $this->assertSame(2, $saved->version);
        $this->assertSame($original, ProductDraftVersion::where('number', 1)->sole()->getAttributes());
        $this->assertSame($draft->id, $current->product_draft_id);
        $this->assertNull($current->source_version_id);
        $this->assertSame($review['refreshed_manifest_sha256'], $current->manifest_sha256);
        $this->assertSame($review['title'], $current->manifest['title']);
        $this->assertSame($review['description'], $current->manifest['description']);
        $this->assertSame([$tracks[1]->id, $tracks[0]->id], array_column($current->manifest['members'], 'track_id'));
        $this->assertSame($sourceRows, DB::table('tracks')->orderBy('id')->get()->toJson());
        $audit = AuditEvent::where('action', 'catalog.product_draft.members_refreshed')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($draft->id, $audit->subject_id);
        $this->assertSame($review['review_hash'], $audit->context['member_review_hash']);
        $this->assertSame($review['manifest_sha256'], $audit->context['before_hash']);
        $this->assertSame($current->manifest_sha256, $audit->context['after_hash']);
        foreach (['Private album', 'Synthetic', 'Retained private'] as $text) {
            $this->assertStringNotContainsString($text, json_encode($audit->context));
        }
        foreach (['offers', 'orders', 'media_assets', 'license_grants'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $beforeReplay = $this->evidence();
        $this->rejects(fn () => $service->refreshMembers($draft, 1, $review['review_hash'], $actor));
        $this->assertSame($beforeReplay, $this->evidence());
    }

    public function test_unchanged_review_and_repeated_no_op_do_not_create_versions_or_audits(): void
    {
        ['actor' => $actor, 'draft' => $draft] = $this->fixture();
        $service = app(ProductDrafts::class);
        $review = $service->reviewMembers($draft->id, $actor);
        $before = $this->evidence();
        $this->assertSame(0, $review['changed_count']);
        $this->assertSame($review['manifest_sha256'], $review['refreshed_manifest_sha256']);
        $this->assertSame([[], []], array_column($review['members'], 'changed_fields'));
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(1, $service->refreshMembers($draft, 1, $review['review_hash'], $actor)->version);
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function staleStates(): array
    {
        return array_map(fn (string $state): array => [$state], ['title', 'metadata_version', 'publication_version', 'draft', 'history']);
    }

    #[DataProvider('staleStates')]
    public function test_changed_source_or_draft_cannot_reuse_a_review(string $state): void
    {
        ['actor' => $actor, 'tracks' => $tracks, 'draft' => $draft] = $this->fixture();
        $service = app(ProductDrafts::class);
        $review = $service->reviewMembers($draft->id, $actor);
        if ($state === 'title') {
            $this->rename($tracks[0], $actor);
        } elseif ($state === 'metadata_version' || $state === 'publication_version') {
            DB::table('tracks')->where('id', $tracks[0]->id)->increment($state);
        } else {
            $source = ProductDraftVersion::sole();
            $service->save($draft, ['version' => 1, 'title' => 'Another draft title', 'track_ids' => [$tracks[0]->id]], $actor);
            if ($state === 'history') {
                $service->select($draft, $source->id, 2, $actor);
            }
        }
        $before = $this->evidence();
        $this->rejects(fn () => $service->refreshMembers($draft, $draft->fresh()->version, $review['review_hash'], $actor));
        $this->assertSame($before, $this->evidence());
    }

    public function test_review_is_bound_to_actor_product_and_expected_version_even_with_identical_contents(): void
    {
        ['actor' => $actor, 'tracks' => $tracks, 'draft' => $draft] = $this->fixture();
        $service = app(ProductDrafts::class);
        $otherActor = LicenseFixtures::admin();
        $other = $service->save(null, ['kind' => 'album', 'title' => $draft->title, 'description' => 'Retained private description',
            'track_ids' => [$tracks[1]->id, $tracks[0]->id]], $actor);
        $review = $service->reviewMembers($draft->id, $actor);
        $this->assertNotSame($review['review_hash'], $service->reviewMembers($draft->id, $otherActor)['review_hash']);
        $this->assertNotSame($review['review_hash'], $service->reviewMembers($other->id, $actor)['review_hash']);
        $before = $this->evidence();
        $this->rejects(fn () => $service->refreshMembers($other, 1, $review['review_hash'], $actor));
        $this->rejects(fn () => $service->refreshMembers($draft, 1, $review['review_hash'], $otherActor));
        $this->rejects(fn () => $service->refreshMembers($draft, 0, $review['review_hash'], $actor));
        $this->rejects(fn () => $service->refreshMembers($draft, 2, $review['review_hash'], $actor));
        foreach (['', str_repeat('a', 63), str_repeat('A', 64), str_repeat('f', 65), $review['review_hash']."\n"] as $hash) {
            $this->rejects(fn () => $service->refreshMembers($draft, 1, $hash, $actor));
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function withdrawals(): array
    {
        $cases = [];
        foreach (['review', 'refresh'] as $operation) {
            foreach (['role', 'email', 'mfa', 'unsaved'] as $state) {
                $cases[$operation.' / '.$state] = [$operation, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('withdrawals')]
    public function test_each_operation_checks_current_persisted_authority(string $operation, string $state): void
    {
        ['actor' => $actor, 'draft' => $draft] = $this->fixture();
        $service = app(ProductDrafts::class);
        $review = $service->reviewMembers($draft->id, $actor);
        if ($state === 'mfa') {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        } elseif ($state === 'unsaved') {
            $actor = (new User)->forceFill($actor->getAttributes());
        } else {
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['email_verified_at' => null]);
        }
        $before = $this->evidence();
        try {
            $operation === 'review' ? $service->reviewMembers($draft->id, $actor) : $service->refreshMembers($draft, 1, $review['review_hash'], $actor);
            $this->fail('Unavailable authority used member refresh.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_audit_failure_rolls_back_the_new_version_members_and_parent_together(): void
    {
        ['actor' => $actor, 'tracks' => $tracks, 'draft' => $draft] = $this->fixture();
        $this->rename($tracks[0], $actor);
        $service = app(ProductDrafts::class);
        $review = $service->reviewMembers($draft->id, $actor);
        $before = $this->evidence();
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && preg_match('/\Ainsert into ["`]?audit_events["`]?/i', $query->sql)) {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $service->refreshMembers($draft, 1, $review['review_hash'], $actor);
            $this->fail('Audit failure did not fail the mutation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame($before, $this->evidence());
    }
}
