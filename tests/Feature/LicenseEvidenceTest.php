<?php

namespace Tests\Feature;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\LicenseDiff;
use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Domain\Rights\VerifiedLicense;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class LicenseEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_json_sorts_nested_objects_preserves_lists_and_unicode_bytes(): void
    {
        $a = ['z' => [['b' => 2, 'a' => 1]], 'a' => 'Café', 'nil' => null];
        $b = ['nil' => null, 'a' => 'Café', 'z' => [['a' => 1, 'b' => 2]]];
        $this->assertSame(CanonicalJson::hash($a), CanonicalJson::hash($b));
        $this->assertSame('{"a":"Café","nil":null,"z":[{"a":1,"b":2}]}', CanonicalJson::encode($a));
        $this->assertNotSame(CanonicalJson::hash([1, 2]), CanonicalJson::hash([2, 1]));
        $this->assertNotSame(CanonicalJson::hash([]), CanonicalJson::hash((object) []));
        $this->assertNotSame(CanonicalJson::hash(['a' => null]), CanonicalJson::hash([]));
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::encode(['price' => 1.5]);
    }

    public function test_terms_reject_unknown_keys_types_empty_and_duplicate_features_and_public_roles(): void
    {
        $valid = ['schema_version' => 1, 'features' => ['Synthetic'], 'required_asset_roles' => ['master_wav']];
        $bad = [
            $valid + ['stream_cap' => 100], array_replace($valid, ['schema_version' => '1']),
            array_replace($valid, ['features' => []]), array_replace($valid, ['features' => ['A', 'A']]),
            array_replace($valid, ['features' => [['description' => 'nested']]]), array_replace($valid, ['features' => [' lead']]),
            array_replace($valid, ['features' => ["bad\nline"]]), array_replace($valid, ['features' => [str_repeat('x', 241)]]),
            array_replace($valid, ['required_asset_roles' => ['preview_tagged']]), array_replace($valid, ['required_asset_roles' => ['master_wav', 'master_wav']]),
            array_replace($valid, ['required_asset_roles' => []]), array_replace($valid, ['required_asset_roles' => ['named' => 'master_wav']]),
        ];
        foreach ($bad as $terms) {
            try {
                app(LicenseTerms::class)->validate($terms);
                $this->fail('Invalid terms were accepted: '.json_encode($terms));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($valid, app(LicenseTerms::class)->validate($valid));
    }

    public function test_draft_service_whitelists_author_status_version_and_evidence(): void
    {
        $first = LicenseFixtures::draft();
        $actor = LicenseFixtures::admin();
        $second = app(CreateLicenseDraft::class)->handle($first->template, [
            'authored_source' => 'Synthetic successor', 'structured_terms' => $first->structured_terms,
            'version' => 999, 'status' => 'published', 'author_id' => $first->author_id,
            'approved_by' => $first->author_id, 'submission_hash' => str_repeat('a', 64),
        ], $actor, $first);
        $this->assertSame(2, $second->version);
        $this->assertSame($actor->id, $second->author_id);
        $this->assertSame('draft', $second->status);
        $this->assertNull($second->submission_hash);
        $this->assertSame($first->id, $second->predecessor_id);
        $updated = app(UpdateLicenseDraft::class)->handle($second, [
            'authored_source' => 'Edited synthetic source', 'structured_terms' => $second->structured_terms,
            'author_id' => $first->author_id, 'status' => 'approved',
        ], $actor);
        $this->assertSame($actor->id, $updated->author_id);
        $this->assertSame('draft', $updated->status);
    }

    public function test_source_preview_escapes_active_markup_and_is_reproducible(): void
    {
        $draft = LicenseFixtures::draft(content: ['authored_source' => '<script>fetch("https://attacker.invalid")</script> & {{buyer}}']);
        $preview = app(LicensePreview::class)->render($draft);
        $this->assertSame($preview, app(LicensePreview::class)->render($draft->fresh()));
        $this->assertStringNotContainsString('<script>', $preview['html']);
        $this->assertStringContainsString('&lt;script&gt;', $preview['html']);
        $this->assertStringContainsString('{{buyer}}', $preview['html']);
        $this->assertStringContainsString('nonbinding', $preview['html']);
        $this->assertSame(hash('sha256', $preview['html']), $preview['sha256']);
        $this->assertStringNotContainsString('<link', $preview['html']);
        $this->assertStringNotContainsString('<img', $preview['html']);
    }

    public function test_submission_binds_content_template_renderer_and_approval_to_exact_hash(): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($author);
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($draft, $author);
        $this->assertSame('legal_review', $submitted->status);
        $this->assertSame(hash('sha256', $draft->authored_source), $submitted->source_hash);
        $this->assertSame(CanonicalJson::hash($draft->structured_terms), $submitted->model_hash);
        $this->assertSame(CanonicalJson::hash($submitted->submission_payload), $submitted->submission_hash);
        foreach ([
            ['review_hash' => str_repeat('a', 64), 'summary_consistency_confirmed' => true],
            ['review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => false],
        ] as $bad) {
            try {
                $review->approve($submitted, $reviewer, ['approval_reference' => 'TEST'] + $bad);
                $this->fail('Unbound review was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('license_review_evidence', 0);
            }
        }
        try {
            $review->approve($submitted, $author, ['approval_reference' => 'TEST', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);
            $this->fail('Self approval was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('license_review_evidence', 0);
        }
        $approved = $review->approve($submitted, $reviewer, [
            'approval_reference' => 'SYNTHETIC-REVIEW', 'review_hash' => $submitted->submission_hash,
            'summary_consistency_confirmed' => true, 'renderer_version' => 'spoofed', 'render_fixture_hash' => str_repeat('b', 64),
        ]);
        $this->assertSame(LicensePreview::VERSION, $approved->renderer_version);
        $this->assertSame($reviewer->id, $approved->reviewEvidence->reviewer_id);
        $published = app(PublishLicense::class)->handle($approved, $author);
        $this->assertTrue(app(VerifiedLicense::class)->available($published));
        $this->assertDatabaseHas('audit_events', ['subject_id' => $published->id, 'actor_id' => $reviewer->id, 'action' => 'rights.license.approved']);
    }

    public function test_schema_hash_survives_database_json_object_key_order(): void
    {
        $draft = LicenseFixtures::draft(terms: ['required_asset_roles' => ['master_wav'], 'schema_version' => 1, 'features' => ['Synthetic']]);
        $submitted = app(ReviewLicense::class)->submit($draft, User::findOrFail($draft->author_id));
        app(VerifiedLicense::class)->assertSubmitted($submitted->fresh());
        $this->assertSame(CanonicalJson::hash(['features' => ['Synthetic'], 'required_asset_roles' => ['master_wav'], 'schema_version' => 1]), $submitted->model_hash);
    }

    public function test_model_and_database_guard_reviewed_source_template_approval_and_evidence(): void
    {
        $approved = LicenseFixtures::approved();
        foreach ([['authored_source' => 'tampered'], ['approved_by' => $approved->author_id], ['approval_reference' => 'replaced'], ['status' => 'draft']] as $change) {
            try {
                $approved->fresh()->update($change);
                $this->fail('Model accepted immutable changes.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $approved->id)->update($change));
        }
        $this->assertSqlBlocked(fn () => DB::table('license_templates')->where('id', $approved->license_template_id)->update(['type' => 'exclusive']));
        $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $approved->id)->delete());
        $this->assertSqlBlocked(fn () => DB::table('license_review_evidence')->where('license_version_id', $approved->id)->update(['approval_reference' => 'tampered']));
        $this->assertSqlBlocked(fn () => DB::table('license_review_evidence')->where('license_version_id', $approved->id)->delete());
        $this->assertSame('SYNTHETIC-TEST-ONLY', $approved->fresh()->reviewEvidence->approval_reference);
    }

    public function test_bulk_submission_and_approval_cannot_skip_evidence(): void
    {
        $draft = LicenseFixtures::draft();
        foreach ([['status' => 'legal_review'], ['status' => 'approved'], ['status' => 'published', 'published_at' => now()], ['approved_by' => LicenseFixtures::admin()->id]] as $change) {
            $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $draft->id)->update($change));
        }
        $submitted = app(ReviewLicense::class)->submit($draft, User::findOrFail($draft->author_id));
        $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $draft->id)->update(['authored_source' => 'changed during review']));
        $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $draft->id)->update(['status' => 'approved', 'approved_by' => LicenseFixtures::admin()->id, 'approved_at' => now(), 'approval_reference' => 'FORGED']));
        $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $submitted->id)->delete());
    }

    public function test_published_version_and_review_evidence_are_immutable_in_all_orm_and_bulk_paths(): void
    {
        $published = LicenseFixtures::published();
        $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $published->id)->update(['effective_until' => now()]));
        $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $published->id)->delete());
        $this->expectException(ValidationException::class);
        $published->reviewEvidence->delete();
    }

    public function test_successor_diff_detects_content_not_object_key_order_and_preserves_prior_evidence(): void
    {
        $published = LicenseFixtures::published();
        $content = ['authored_source' => $published->authored_source, 'structured_terms' => array_reverse($published->structured_terms, true)];
        $next = app(CreateLicenseDraft::class)->handle($published->template, $content, LicenseFixtures::admin(), $published);
        $this->assertSame([], app(LicenseDiff::class)->between($published, $next));
        $changed = app(UpdateLicenseDraft::class)->handle($next, array_replace($content, ['authored_source' => 'Changed synthetic text.']), LicenseFixtures::admin());
        $this->assertSame(['authored_source'], array_keys(app(LicenseDiff::class)->between($published, $changed)));
        $this->assertSame(2, $changed->version);
        $this->assertTrue(app(VerifiedLicense::class)->available($published));
    }

    public function test_effective_window_is_utc_half_open_and_invalid_intervals_fail(): void
    {
        $from = now()->startOfSecond()->addDay();
        $until = $from->copy()->addDays(2);
        $license = LicenseFixtures::published(content: ['effective_from' => $from->toIso8601String(), 'effective_until' => $until->toIso8601String()]);
        $verifier = app(VerifiedLicense::class);
        $this->assertFalse($verifier->available($license, $from->copy()->subSecond()));
        $this->assertTrue($verifier->available($license, $from));
        $this->assertTrue($verifier->available($license, $until->copy()->subSecond()));
        $this->assertFalse($verifier->available($license, $until));
        $this->expectException(ValidationException::class);
        LicenseFixtures::draft(content: ['effective_from' => $until, 'effective_until' => $from]);
    }

    public function test_customer_cannot_create_submit_approve_update_or_publish(): void
    {
        $customer = User::factory()->create();
        $draft = LicenseFixtures::draft();
        foreach ([
            fn () => app(CreateLicenseDraft::class)->handle($draft->template, $draft->toArray(), $customer),
            fn () => app(UpdateLicenseDraft::class)->handle($draft, $draft->toArray(), $customer),
            fn () => app(ReviewLicense::class)->submit($draft, $customer),
            fn () => app(ReviewLicense::class)->approve($draft, $customer, []),
            fn () => app(PublishLicense::class)->handle($draft, $customer),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Customer invoked staff licensing operation.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_draft_editor_becomes_content_author_and_cannot_self_approve(): void
    {
        $draft = LicenseFixtures::draft();
        $editor = LicenseFixtures::admin();
        $edited = app(UpdateLicenseDraft::class)->handle($draft, [
            'authored_source' => 'A different synthetic author rewrote this source.',
            'structured_terms' => $draft->structured_terms,
        ], $editor);
        $this->assertSame($editor->id, $edited->author_id);
        $submitted = app(ReviewLicense::class)->submit($edited, $editor);
        $this->expectException(ValidationException::class);
        app(ReviewLicense::class)->approve($submitted, $editor, ['approval_reference' => 'TEST', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);
    }

    public function test_original_author_cannot_approve_after_a_second_operator_edits_the_draft(): void
    {
        $originalAuthor = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($originalAuthor);
        $edited = app(UpdateLicenseDraft::class)->handle($draft, [
            'authored_source' => $draft->authored_source.' One minor edit.',
            'structured_terms' => $draft->structured_terms,
            'content_author_ids' => [$editor->id],
        ], $editor);
        $this->assertSame([$originalAuthor->id, $editor->id], $edited->content_author_ids);
        $submitted = app(ReviewLicense::class)->submit($edited, $editor);
        $this->assertSame([$originalAuthor->id, $editor->id], $submitted->submission_payload['content_author_ids']);
        $this->assertSqlBlocked(fn () => DB::table('license_review_evidence')->insert([
            'license_version_id' => $submitted->id, 'submission_hash' => $submitted->submission_hash,
            'reviewer_id' => $originalAuthor->id, 'approval_reference' => 'TEST FORGED',
            'summary_consistency_confirmed' => true, 'reviewed_at' => now(), 'evidence_hash' => str_repeat('a', 64),
        ]));
        $this->expectException(ValidationException::class);
        app(ReviewLicense::class)->approve($submitted, $originalAuthor, ['approval_reference' => 'TEST', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);
    }

    public function test_successor_inherits_source_contributors_and_rejects_original_author_approval(): void
    {
        $originalAuthor = LicenseFixtures::admin();
        $published = LicenseFixtures::published($originalAuthor);
        $creator = LicenseFixtures::admin();
        $next = app(CreateLicenseDraft::class)->handle($published->template, $published->toArray(), $creator, $published);
        $this->assertSame([$originalAuthor->id, $creator->id], $next->content_author_ids);
        $submitted = app(ReviewLicense::class)->submit($next, $creator);
        $this->expectException(ValidationException::class);
        app(ReviewLicense::class)->approve($submitted, $originalAuthor, ['approval_reference' => 'TEST', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);
    }

    public function test_draft_versions_cannot_be_deleted_and_their_numbers_reused(): void
    {
        $draft = LicenseFixtures::draft();
        $this->assertSqlBlocked(fn () => DB::table('license_versions')->where('id', $draft->id)->delete());
        $next = app(CreateLicenseDraft::class)->handle($draft->template, $draft->toArray(), LicenseFixtures::admin(), $draft);
        $this->assertSame(2, $next->version);
        $this->expectException(ValidationException::class);
        $next->delete();
    }

    private function assertSqlBlocked(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Database accepted an immutable evidence change.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
