<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class SiteEditorialContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_snapshots_remain_exact_and_only_explicit_editing_adds_disabled_editorial_sections(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $legacy = SiteEditorialFixtures::legacy();
        $this->assertSame($legacy, SiteContentSchema::defaults());
        $release = $site->create($legacy, 'Retained version one', $actor);
        $hash = $release->content_hash;
        $this->assertSame(1, $release->schema_version);
        $this->assertSame($legacy, $site->preview($release->id, $actor));
        $editing = SiteContentSchema::forEditing($site->preview($release->id, $actor));
        $this->assertSame(['about' => null, 'contact' => null, 'blog' => null, 'videos' => null], array_intersect_key($editing, array_flip(['about', 'contact', 'blog', 'videos'])));
        $this->assertSame(2, $editing['schema_version']);
        $this->assertSame($legacy, array_replace(array_intersect_key($editing, $legacy), ['schema_version' => 1]));
        $this->assertSame($editing, SiteContentSchema::forEditing($editing));
        $copy = $site->create($editing, 'New version two copy', $actor);
        $this->assertSame(2, $copy->schema_version);
        $this->assertSame($editing, $site->preview($copy->id, $actor));
        $this->assertSame($legacy, $release->fresh()->content);
        $this->assertSame($hash, $release->fresh()->content_hash);
        $this->assertSame(CanonicalJson::hash($legacy), $hash);
        $this->assertSame('2dbb6cd65de0435534c7280b19083c03723fd7ea13d0658e1c4f78e731e0eb81', $hash);
        $this->assertSame($legacy, $site->current());
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertSame(2, AuditEvent::where('subject_id', $copy->id)->where('action', 'site.release.created')->sole()->context['schema_version']);
    }

    public function test_first_editorial_publication_retains_exact_v1_baseline_and_cross_version_rollback(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $content = SiteEditorialFixtures::content();
        $release = $site->create($content, 'Private editorial label', $actor);
        $site->publish($release->id, 0, $actor);
        $this->assertSame($content, $site->current());
        $baseline = SiteRelease::findOrFail(SitePublicationRevision::where('revision', 0)->sole()->release_id);
        $this->assertSame(1, $baseline->schema_version);
        $this->assertSame(SiteEditorialFixtures::legacy(), $baseline->content);
        $this->assertSame(CanonicalJson::hash(SiteEditorialFixtures::legacy()), $baseline->content_hash);
        $site->rollback($baseline->id, 1, $actor);
        $this->assertSame(SiteEditorialFixtures::legacy(), $site->current());
        $site->rollback($release->id, 2, $actor);
        $this->assertSame($content, $site->current());
        $this->assertSame($content, $release->fresh()->content);
        $this->assertSame(3, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 4);
        foreach (['tracks', 'orders', 'license_grants', 'pending_entitlements', 'stripe_webhook_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $context = AuditEvent::where('action', 'site.release.created')->sole()->context;
        $this->assertSame(2, $context['schema_version']);
        $this->assertStringNotContainsString('EDITORIAL', json_encode($context));
        $this->assertStringNotContainsString('example.test', json_encode($context));
    }

    public static function corruptEvidence(): array
    {
        return [
            'v1 row v2 content' => [1, 2, CanonicalJson::VERSION, false],
            'v2 row v1 content' => [2, 1, CanonicalJson::VERSION, false],
            'unsupported row version' => [3, 2, CanonicalJson::VERSION, false],
            'unsupported canonicalization' => [2, 2, 'unsupported-json-v2', false],
            'content hash mismatch' => [2, 2, CanonicalJson::VERSION, true],
        ];
    }

    #[DataProvider('corruptEvidence')]
    public function test_mismatched_retained_evidence_fails_preview_publication_and_public_reads(int $rowVersion, int $contentVersion, string $canonicalization, bool $wrongHash): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $content = $contentVersion === 1 ? SiteEditorialFixtures::legacy() : SiteEditorialFixtures::content();
        $hash = $wrongHash ? str_repeat('f', 64) : CanonicalJson::hash($content);
        $id = DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic restored evidence', 'schema_version' => $rowVersion,
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_hash' => $hash,
            'canonicalization_version' => $canonicalization, 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        foreach ([fn () => $site->preview($id, $actor), fn () => $site->publish($id, 0, $actor)] as $read) {
            try { $read(); $this->fail('Corrupt retained content was accepted.'); } catch (ValidationException $exception) {
                $this->assertArrayHasKey('publication', $exception->errors());
            }
        }
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $this->assertDatabaseCount('audit_events', 0);
        // A restored pointer with matching hash still must not bypass the release's version/canonicalization checks.
        DB::table('site_publication_revisions')->insert([
            'revision' => 1, 'release_id' => $id, 'previous_release_id' => null, 'operation' => 'publish',
            'content_hash' => $hash, 'actor_id' => $actor->id, 'created_at' => now(),
        ]);
        DB::table('site_publications')->where('id', 1)->update(['revision' => 1, 'active_release_id' => $id, 'updated_at' => now()]);
        $this->expectException(ValidationException::class);
        $site->current();
    }

    public function test_editorial_validation_rejects_unsafe_and_ambiguous_input_without_retaining_any_draft(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $changes = [
            ['schema_version', '2'], ['schema_version', 2.0], ['schema_version', true], ['schema_version', 3],
            ['about.html', '<p>Injected</p>'], ['about.title', '<script>alert(1)</script>'],
            ['about.title', "Hidden\0copy"], ['about.title', '   '], ['about.title', str_repeat('t', 121)],
            ['contact.description', str_repeat('d', 301)], ['about.paragraphs', []],
            ['about.paragraphs', ['body' => 'Object instead of list']], ['about.paragraphs', array_fill(0, 13, 'Too many')],
            ['about.paragraphs.0', str_repeat('p', 1501)], ['blog.entries', []], ['videos.entries', []],
            ['blog.entries', array_fill(0, 31, SiteEditorialFixtures::content()['blog']['entries'][0])],
            ['blog.entries.0.raw_html', 'Unexpected'], ['videos.entries.0.embed', '<iframe>'],
            ['blog.entries.0.slug', '../hidden'], ['blog.entries.0.slug', '/hidden'], ['blog.entries.0.slug', 'Bad-Slug'],
            ['blog.entries.0.slug', 'bad--slug'], ['blog.entries.0.slug', 'bad?draft=1'], ['blog.entries.0.slug', 'café'],
            ['blog.entries.0.slug', str_repeat('s', 81)], ['blog.entries.1.slug', 'first-note'],
            ['videos.entries.1.slug', 'first-film'], ['videos.entries.0.provider', 'arbitrary'],
            ['videos.entries.0.provider', 'YOUTUBE'], ['videos.entries.0.video_id', 'https://youtube.com/watch?v=AbCdEfGhI_1'],
            ['videos.entries.0.video_id', 'AbCdEfGhI_1?autoplay=1'], ['videos.entries.0.video_id', 'AbCdEfGhI_'],
            ['videos.entries.1.video_id', '0'], ['videos.entries.1.video_id', '0123456'], ['videos.entries.1.video_id', '1234567890123'],
            ['videos.entries.1.video_id', 123456789], ['contact.email', 'person@example.test?subject=injected'],
            ['contact.email', "person@example.test\r\nBcc: other@example.test"], ['contact.email', 'Name <person@example.test>'],
            ['contact.email', 'one@example.test,two@example.test'], ['contact.email', 'é@example.test'],
            ['contact.email', str_repeat('a', 245).'@example.test'], ['contact.email', 'mailto:person@example.test'],
            ['navigation.0.href', '/blog/first-note'], ['navigation.0.href', '//outside.example.test'],
            ['navigation.0.href', '/about?preview=1'], ['navigation.0.href', '/admin'],
        ];
        foreach ($changes as [$key, $value]) {
            $content = SiteEditorialFixtures::content();
            data_set($content, $key, $value);
            try { $site->create($content, 'Rejected synthetic draft', $actor); $this->fail('Invalid editorial content accepted: '.$key); } catch (ValidationException) {
                $this->assertDatabaseCount('site_releases', 0);
            }
        }
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
    }

    public function test_disabled_sections_are_explicit_and_navigation_cannot_target_unavailable_pages(): void
    {
        foreach (['about', 'contact', 'blog', 'videos'] as $section) {
            $missing = SiteEditorialFixtures::content();
            unset($missing[$section]);
            $linked = SiteEditorialFixtures::content();
            $linked[$section] = null;
            foreach ([$missing, $linked] as $invalid) {
                try { SiteContentSchema::validate($invalid); $this->fail('Missing/disabled section accepted with a navigation target.'); } catch (ValidationException) { }
            }
        }
        $disabled = SiteContentSchema::forEditing(SiteEditorialFixtures::legacy());
        $this->assertSame($disabled, SiteContentSchema::validate($disabled));
        $legacy = SiteEditorialFixtures::legacy();
        $legacy['navigation'][0]['href'] = '/about';
        $this->expectException(ValidationException::class);
        SiteContentSchema::validate($legacy);
    }

    public function test_editorial_text_and_independent_slug_namespaces_are_retained_without_normalization(): void
    {
        $content = SiteEditorialFixtures::content();
        $content['about']['paragraphs'] = ["Sean's sound & music — \"Original\".\nSecond line."];
        $content['videos']['entries'][0]['slug'] = $content['blog']['entries'][0]['slug'];
        $content['contact']['email'] = 'editorial+synthetic@example.test';
        $this->assertSame($content, SiteContentSchema::validate($content));
        $actor = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create($content, 'Verbatim synthetic content', $actor);
        $this->assertSame($content, app(SiteContent::class)->preview($release->id, $actor));
        $this->assertSame(CanonicalJson::hash($content), $release->content_hash);
    }
}
