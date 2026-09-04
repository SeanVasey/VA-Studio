<?php

namespace Tests\Support;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Models\User;
use Illuminate\Support\Str;

/** Synthetic, nonbinding test records; never production seeds or legal approval. */
final class LicenseFixtures
{
    public static function admin(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    public static function draft(?User $author = null, array $terms = [], array $content = []): LicenseVersion
    {
        $author ??= self::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING TEST FIXTURE', 'slug' => 'fixture-'.Str::uuid(), 'type' => 'non-exclusive']);

        return app(CreateLicenseDraft::class)->handle($template, $content + [
            'authored_source' => 'NONBINDING SYNTHETIC REVIEW FIXTURE. This is test evidence only.',
            'structured_terms' => $terms ?: ['schema_version' => 1, 'features' => ['Synthetic test WAV deliverable'], 'required_asset_roles' => ['master_wav']],
        ], $author);
    }

    public static function approved(?User $author = null, ?User $reviewer = null, array $terms = [], array $content = []): LicenseVersion
    {
        $draft = self::draft($author, $terms, $content);
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($draft, $author ?? User::findOrFail($draft->author_id));

        return $review->approve($submitted, $reviewer ?? self::admin(), [
            'approval_reference' => 'SYNTHETIC-TEST-ONLY',
            'review_hash' => $submitted->submission_hash,
            'summary_consistency_confirmed' => true,
        ]);
    }

    public static function published(?User $author = null, ?User $reviewer = null, array $terms = [], array $content = []): LicenseVersion
    {
        $approved = self::approved($author, $reviewer, $terms, $content);

        return app(PublishLicense::class)->handle($approved, $author ?? User::findOrFail($approved->author_id));
    }
}
